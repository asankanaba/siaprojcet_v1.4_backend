<?php
// api/supplier_invoices.php — Invoice entry + 3-way match
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once '../config/database.php';

$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?: [];

try {
    $db   = new Database();
    $conn = $db->getConnection();

    switch ($method) {

        case 'GET':
            if (isset($_GET['id'])) {
                $stmt = $conn->prepare("
                    SELECT i.*, po.po_number, po.total_cost AS po_total,
                           po.quantity AS po_qty, s.name AS supplier_name
                    FROM supplier_invoices i
                    LEFT JOIN purchase_orders po ON i.po_id = po.id
                    LEFT JOIN suppliers s ON i.supplier_id = s.id
                    WHERE i.id = ?
                ");
                $stmt->execute([$_GET['id']]);
                $inv = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($inv) {
                    $d = $conn->prepare("
                        SELECT * FROM po_deliveries
                        WHERE po_id = ?
                        ORDER BY received_at DESC
                    ");
                    $d->execute([$inv['po_id']]);
                    $inv['deliveries'] = $d->fetchAll(PDO::FETCH_ASSOC);
                }

                echo json_encode(['success' => true, 'data' => $inv]);
                break;
            }

            $sql = "
                SELECT i.*, po.po_number, s.name AS supplier_name
                FROM supplier_invoices i
                LEFT JOIN purchase_orders po ON i.po_id = po.id
                LEFT JOIN suppliers s ON i.supplier_id = s.id
            ";
            $where = []; $params = [];
            if (!empty($_GET['status'])) { $where[] = "i.status = ?"; $params[] = $_GET['status']; }
            if (!empty($_GET['po_id']))  { $where[] = "i.po_id = ?"; $params[] = $_GET['po_id']; }
            if ($where) $sql .= " WHERE " . implode(" AND ", $where);
            $sql .= " ORDER BY i.created_at DESC";

            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        // ============================================
        // POST — record invoice + auto 3-way match
        // ============================================
        case 'POST':
            $required = ['invoice_number','po_id','supplier_id','invoice_date','subtotal','total'];
            foreach ($required as $f) {
                if (empty($input[$f]) && $input[$f] !== 0) throw new Exception("Missing required field: $f");
            }

            $poId = (int)$input['po_id'];

            // Fetch PO
            $stmt = $conn->prepare("
                SELECT po.*, p.name AS product_name FROM purchase_orders po
                LEFT JOIN products p ON po.product_id = p.id
                WHERE po.id = ?
            ");
            $stmt->execute([$poId]);
            $po = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$po) throw new Exception('PO not found');

            // Fetch total received
            $stmt = $conn->prepare("
                SELECT COALESCE(SUM(qty_received),0) AS total_received
                FROM po_deliveries WHERE po_id = ?
            ");
            $stmt->execute([$poId]);
            $received = (int)$stmt->fetch(PDO::FETCH_ASSOC)['total_received'];

            // 3-way match
            $poTotal   = (float)$po['total_cost'];
            $invTotal  = (float)$input['total'];
            $poQty     = (int)$po['quantity'];

            $amountMatch = abs($poTotal - $invTotal) < 0.01;
            $qtyMatch    = ($received >= $poQty);
            $matchOK     = $amountMatch && $qtyMatch;

            $matchNotes = [];
            if (!$amountMatch) $matchNotes[] = "Amount mismatch: PO ₱{$poTotal} vs Invoice ₱{$invTotal}";
            if (!$qtyMatch)    $matchNotes[] = "Qty mismatch: PO {$poQty} vs Received {$received}";
            if ($matchOK)      $matchNotes[] = "3-way match OK (PO ↔ GRN ↔ Invoice)";

            $status = $matchOK ? 'matched' : 'mismatch';

            $stmt = $conn->prepare("
                INSERT INTO supplier_invoices
                    (invoice_number, po_id, supplier_id, invoice_date, due_date,
                     subtotal, tax, total, status, match_notes, matched_by, matched_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $input['invoice_number'],
                $poId,
                (int)$input['supplier_id'],
                $input['invoice_date'],
                $input['due_date'] ?? null,
                (float)$input['subtotal'],
                (float)($input['tax'] ?? 0),
                $invTotal,
                $status,
                implode(' | ', $matchNotes),
                $matchOK ? (int)($input['matched_by'] ?? 0) : null
            ]);

            $invId = $conn->lastInsertId();

            // Update PO lifecycle if matched
            if ($matchOK) {
                $stmt = $conn->prepare("
                    UPDATE purchase_orders
                    SET lifecycle_status = 'matched'
                    WHERE id = ? AND lifecycle_status IN ('grn_posted','delivered','invoiced')
                ");
                $stmt->execute([$poId]);
            }

            // Notify finance
            notifyRole(
                $conn,
                ['finance','super_admin','admin'],
                ($matchOK ? '✅ Invoice Matched' : '⚠️ Invoice Mismatch') . ' — ' . $input['invoice_number'],
                'PO ' . $po['po_number'] . '. ' . implode(' | ', $matchNotes),
                $matchOK ? 'success' : 'warning',
                $matchOK ? 'success' : 'warning'
            );

            echo json_encode([
                'success' => true,
                'id'      => $invId,
                'status'  => $status,
                'notes'   => implode(' | ', $matchNotes),
                'matched' => $matchOK
            ]);
            break;

        case 'PUT':
            $id = $_GET['id'] ?? null;
            if (!$id) throw new Exception('ID required');
            $allowed = ['status','match_notes','matched_by','due_date'];
            $fields = []; $params = [];
            foreach ($allowed as $f) {
                if (isset($input[$f])) { $fields[] = "$f = ?"; $params[] = $input[$f]; }
            }
            if (!$fields) throw new Exception('No fields to update');
            $params[] = $id;
            $stmt = $conn->prepare("UPDATE supplier_invoices SET " . implode(', ', $fields) . " WHERE id = ?");
            $stmt->execute($params);
            echo json_encode(['success' => true]);
            break;
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}