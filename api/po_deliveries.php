<?php
// api/po_deliveries.php — Goods Receipt Note (Accept Delivery)
// ✅ Fixes the double stock count bug in supply_chain.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/../config/database.php';

$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?: [];

try {
    $db   = new Database();
    $conn = $db->getConnection();

    switch ($method) {

        case 'GET':
            if (isset($_GET['po_id'])) {
                $stmt = $conn->prepare("
                    SELECT d.*, u.full_name AS received_by_name, po.po_number
                    FROM po_deliveries d
                    LEFT JOIN users u ON d.received_by = u.id
                    LEFT JOIN purchase_orders po ON d.po_id = po.id
                    WHERE d.po_id = ?
                    ORDER BY d.received_at DESC
                ");
                $stmt->execute([$_GET['po_id']]);
                echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
                break;
            }

            $stmt = $conn->prepare("
                SELECT d.*, u.full_name AS received_by_name, po.po_number, s.name AS supplier_name
                FROM po_deliveries d
                LEFT JOIN users u ON d.received_by = u.id
                LEFT JOIN purchase_orders po ON d.po_id = po.id
                LEFT JOIN suppliers s ON po.supplier_id = s.id
                ORDER BY d.received_at DESC
                LIMIT 200
            ");
            $stmt->execute();
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        // ============================================
        // POST — Accept Delivery (creates GRN, updates stock ONCE)
        // ============================================
        case 'POST':
            $required = ['po_id', 'received_by', 'qty_received'];
            foreach ($required as $f) {
                if (empty($input[$f])) throw new Exception("Missing required field: $f");
            }

            $poId     = (int)$input['po_id'];
            $recvBy   = (int)$input['received_by'];
            $qtyRecv  = (int)$input['qty_received'];
            $qtyRej   = (int)($input['qty_rejected'] ?? 0);
            $grnNo    = 'GRN-' . date('Ymd') . '-' . rand(1000, 9999);

            $conn->beginTransaction();

            try {
                // Lock PO row
                $stmt = $conn->prepare("
                    SELECT po.*, p.name AS product_name
                    FROM purchase_orders po
                    LEFT JOIN products p ON po.product_id = p.id
                    WHERE po.id = ? FOR UPDATE
                ");
                $stmt->execute([$poId]);
                $po = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$po) throw new Exception('Purchase order not found');
                if (in_array($po['lifecycle_status'], ['paid','closed','cancelled'])) {
                    throw new Exception('PO is already ' . $po['lifecycle_status']);
                }

                $status = ($qtyRecv >= (int)$po['quantity']) ? 'complete' : 'partial';

                // 1. Create GRN
                $stmt = $conn->prepare("
                    INSERT INTO po_deliveries
                        (po_id, grn_number, received_by, qty_ordered,
                         qty_received, qty_rejected, condition_notes, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $poId, $grnNo, $recvBy, (int)$po['quantity'],
                    $qtyRecv, $qtyRej,
                    $input['condition_notes'] ?? null,
                    $status
                ]);
                $grnId = $conn->lastInsertId();

                // 2. Update product stock (ONCE — only the received qty)
                if ($qtyRecv > 0) {
                    $stmt = $conn->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
                    $stmt->execute([$qtyRecv, (int)$po['product_id']]);

                    // Inventory log
                    $stmt = $conn->prepare("
                        INSERT INTO inventory_logs
                            (product_id, quantity_change, type, note, user_id)
                        VALUES (?, ?, 'restock', ?, ?)
                    ");
                    $stmt->execute([
                        (int)$po['product_id'],
                        $qtyRecv,
                        "Goods received — GRN $grnNo (PO {$po['po_number']})",
                        $recvBy
                    ]);
                }

                // 3. Update PO lifecycle
                $stmt = $conn->prepare("
                    UPDATE purchase_orders
                    SET lifecycle_status = 'grn_posted',
                        status = 'received',
                        received_date = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$poId]);

                // 4. Notify finance
                notifyRole(
                    $conn,
                    ['finance','super_admin','admin'],
                    '📦 Delivery Received — ' . $po['po_number'],
                    $qtyRecv . ' of ' . $po['quantity'] . ' units of ' .
                    $po['product_name'] . ' received. GRN ' . $grnNo .
                    '. Ready for invoice matching.',
                    'info',
                    'info',
                    $recvBy
                );

                // 5. Notify requester
                if ($po['ordered_by']) {
                    createNotification(
                        $conn,
                        (int)$po['ordered_by'],
                        '📦 Your Order Arrived',
                        'PO ' . $po['po_number'] . ' has been delivered. GRN ' . $grnNo . '.',
                        'success',
                        'success'
                    );
                }

                $conn->commit();
                echo json_encode([
                    'success'    => true,
                    'grn_id'     => $grnId,
                    'grn_number' => $grnNo,
                    'status'     => $status,
                    'message'    => 'Delivery accepted. Inventory updated once.'
                ]);
            } catch (Exception $e) {
                $conn->rollBack();
                throw $e;
            }
            break;
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}