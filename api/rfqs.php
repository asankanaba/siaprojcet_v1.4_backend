<?php
// api/rfqs.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once '../config/database.php';

$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?: [];

try {
    $db   = new Database();
    $conn = $db->getConnection();

    switch ($method) {

        // ============================================
        // GET
        // ============================================
        case 'GET':
            // Single RFQ with quotes
            if (isset($_GET['id'])) {
                $stmt = $conn->prepare("
                    SELECT r.*, u.full_name AS created_by_name,
                           req.req_number, req.product_name, req.quantity
                    FROM rfqs r
                    LEFT JOIN users u ON r.created_by = u.id
                    LEFT JOIN requisitions req ON r.requisition_id = req.id
                    WHERE r.id = ?
                ");
                $stmt->execute([$_GET['id']]);
                $rfq = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($rfq) {
                    $q = $conn->prepare("
                        SELECT q.*, s.name AS supplier_name, s.email AS supplier_email,
                               s.phone AS supplier_phone
                        FROM rfq_quotes q
                        LEFT JOIN suppliers s ON q.supplier_id = s.id
                        WHERE q.rfq_id = ?
                        ORDER BY q.unit_price ASC
                    ");
                    $q->execute([$_GET['id']]);
                    $rfq['quotes'] = $q->fetchAll(PDO::FETCH_ASSOC);
                }

                echo json_encode(['success' => true, 'data' => $rfq]);
                break;
            }

            // List
            $sql = "
                SELECT r.*, u.full_name AS created_by_name,
                       req.req_number, req.product_name, req.quantity,
                       (SELECT COUNT(*) FROM rfq_quotes q WHERE q.rfq_id = r.id) AS quote_count
                FROM rfqs r
                LEFT JOIN users u ON r.created_by = u.id
                LEFT JOIN requisitions req ON r.requisition_id = req.id
            ";
            $where = []; $params = [];
            if (!empty($_GET['status']))        { $where[] = "r.status = ?";        $params[] = $_GET['status']; }
            if (!empty($_GET['requisition_id'])){ $where[] = "r.requisition_id = ?";$params[] = $_GET['requisition_id']; }
            if ($where) $sql .= " WHERE " . implode(" AND ", $where);
            $sql .= " ORDER BY r.created_at DESC";

            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        // ============================================
        // POST — create RFQ  OR  add a quote
        // ============================================
        case 'POST':
            $kind = $input['kind'] ?? 'rfq';

            if ($kind === 'quote') {
                // Add a supplier quote to an RFQ
                $required = ['rfq_id', 'supplier_id', 'unit_price', 'quantity_offered'];
                foreach ($required as $f) {
                    if (empty($input[$f])) throw new Exception("Missing required field: $f");
                }

                $stmt = $conn->prepare("
                    INSERT INTO rfq_quotes
                        (rfq_id, supplier_id, unit_price, quantity_offered,
                         lead_time_days, payment_terms, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    (int)$input['rfq_id'],
                    (int)$input['supplier_id'],
                    (float)$input['unit_price'],
                    (int)$input['quantity_offered'],
                    (int)($input['lead_time_days'] ?? 3),
                    $input['payment_terms'] ?? 'Net 30',
                    $input['notes'] ?? null
                ]);

                echo json_encode(['success' => true, 'id' => $conn->lastInsertId()]);
                break;
            }

            // Default: create RFQ
            if (empty($input['created_by'])) throw new Exception('created_by required');

            $rfqNo = 'RFQ-' . date('Ymd') . '-' . rand(1000, 9999);

            $stmt = $conn->prepare("
                INSERT INTO rfqs
                    (rfq_number, requisition_id, created_by, deadline, status, notes)
                VALUES (?, ?, ?, ?, 'sent', ?)
            ");
            $stmt->execute([
                $rfqNo,
                !empty($input['requisition_id']) ? (int)$input['requisition_id'] : null,
                (int)$input['created_by'],
                $input['deadline'] ?? null,
                $input['notes'] ?? null
            ]);

            echo json_encode([
                'success'    => true,
                'id'         => $conn->lastInsertId(),
                'rfq_number' => $rfqNo
            ]);
            break;

        // ============================================
        // PUT — select winning quote / update RFQ
        // ============================================
        case 'PUT':
            $id = $_GET['id'] ?? null;
            if (!$id) throw new Exception('ID required');

            if (!empty($input['select_quote_id'])) {
                $qid = (int)$input['select_quote_id'];

                // Unselect others
                $stmt = $conn->prepare("UPDATE rfq_quotes SET is_selected = 0 WHERE rfq_id = ?");
                $stmt->execute([$id]);

                $stmt = $conn->prepare("UPDATE rfq_quotes SET is_selected = 1 WHERE id = ? AND rfq_id = ?");
                $stmt->execute([$qid, $id]);

                $stmt = $conn->prepare("UPDATE rfqs SET status = 'closed' WHERE id = ?");
                $stmt->execute([$id]);

                echo json_encode(['success' => true, 'message' => 'Quote selected']);
                break;
            }

            // Generic update
            $allowed = ['deadline','status','notes'];
            $fields = []; $params = [];
            foreach ($allowed as $f) {
                if (isset($input[$f])) { $fields[] = "$f = ?"; $params[] = $input[$f]; }
            }
            if (!$fields) throw new Exception('No fields to update');
            $params[] = $id;
            $stmt = $conn->prepare("UPDATE rfqs SET " . implode(', ', $fields) . " WHERE id = ?");
            $stmt->execute($params);
            echo json_encode(['success' => true]);
            break;

        // ============================================
        // DELETE
        // ============================================
        case 'DELETE':
            $id = $_GET['id'] ?? null;
            if (!$id) throw new Exception('ID required');
            $stmt = $conn->prepare("DELETE FROM rfqs WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true]);
            break;
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}