<?php
// api/requisitions.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/../config/database.php';

$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?: [];

try {
    $db   = new Database();
    $conn = $db->getConnection();

    switch ($method) {

        // ============================================
        // GET — list or single
        // ============================================
        case 'GET':
            if (isset($_GET['id'])) {
                $stmt = $conn->prepare("
                    SELECT r.*,
                           u.full_name AS requested_by_name,
                           a.full_name AS approved_by_name,
                           p.name AS product_name_real
                    FROM requisitions r
                    LEFT JOIN users u ON r.requested_by = u.id
                    LEFT JOIN users a ON r.approved_by = a.id
                    LEFT JOIN products p ON r.product_id = p.id
                    WHERE r.id = ?
                ");
                $stmt->execute([$_GET['id']]);
                echo json_encode(['success' => true, 'data' => $stmt->fetch(PDO::FETCH_ASSOC)]);
                break;
            }

            $sql = "
                SELECT r.*,
                       u.full_name AS requested_by_name,
                       a.full_name AS approved_by_name,
                       p.name AS product_name_real
                FROM requisitions r
                LEFT JOIN users u ON r.requested_by = u.id
                LEFT JOIN users a ON r.approved_by = a.id
                LEFT JOIN products p ON r.product_id = p.id
            ";
            $where = []; $params = [];

            if (!empty($_GET['status'])) {
                $where[] = "r.status = ?";
                $params[] = $_GET['status'];
            }
            if (!empty($_GET['requested_by'])) {
                $where[] = "r.requested_by = ?";
                $params[] = $_GET['requested_by'];
            }
            if (!empty($_GET['department'])) {
                $where[] = "r.department = ?";
                $params[] = $_GET['department'];
            }

            if ($where) $sql .= " WHERE " . implode(" AND ", $where);
            $sql .= " ORDER BY r.created_at DESC";

            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        // ============================================
        // POST — create requisition
        // ============================================
        case 'POST':
            $required = ['requested_by', 'quantity'];
            foreach ($required as $f) {
                if (empty($input[$f])) throw new Exception("Missing required field: $f");
            }

            $qty   = (int)$input['quantity'];
            $unit  = (float)($input['estimated_unit_cost'] ?? 0);
            $total = $qty * $unit;
            $reqNo = 'REQ-' . date('Ymd') . '-' . rand(1000, 9999);

            $stmt = $conn->prepare("
                INSERT INTO requisitions
                    (req_number, requested_by, department, product_id, product_name,
                     quantity, estimated_unit_cost, estimated_total, cost_center,
                     justification, urgency, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending_finance')
            ");
            $stmt->execute([
                $reqNo,
                (int)$input['requested_by'],
                $input['department'] ?? 'General',
                !empty($input['product_id']) ? (int)$input['product_id'] : null,
                $input['product_name'] ?? null,
                $qty,
                $unit,
                $total,
                $input['cost_center'] ?? null,
                $input['justification'] ?? null,
                $input['urgency'] ?? 'normal'
            ]);

            $id = $conn->lastInsertId();

            // Notify all finance users
            notifyRole(
                $conn,
                ['finance', 'super_admin', 'admin'],
                '📋 New Requisition ' . $reqNo,
                'A new requisition for ' . ($input['product_name'] ?? 'item') .
                ' x' . $qty . ' (₱' . number_format($total, 2) . ') needs budget approval.',
                'warning',
                'warning',
                (int)$input['requested_by']
            );

            echo json_encode([
                'success'    => true,
                'id'         => $id,
                'req_number' => $reqNo
            ]);
            break;

        // ============================================
        // PUT — approve / reject / cancel
        // ============================================
        case 'PUT':
            $id = $_GET['id'] ?? null;
            if (!$id) throw new Exception('ID required');

            $action = $input['action'] ?? null;

            if ($action === 'approve') {
                $stmt = $conn->prepare("
                    UPDATE requisitions
                    SET status = 'approved',
                        approved_by = ?,
                        approved_at = NOW()
                    WHERE id = ? AND status = 'pending_finance'
                ");
                $stmt->execute([(int)$input['approved_by'], $id]);

                // Fetch row for notification
                $r = $conn->prepare("SELECT requested_by, req_number FROM requisitions WHERE id = ?");
                $r->execute([$id]);
                $row = $r->fetch(PDO::FETCH_ASSOC);

                if ($row) {
                    createNotification(
                        $conn,
                        (int)$row['requested_by'],
                        '✅ Requisition Approved',
                        'Your requisition ' . $row['req_number'] . ' has been approved by Finance.',
                        'success',
                        'success'
                    );
                }

                echo json_encode(['success' => true, 'message' => 'Requisition approved']);
            } elseif ($action === 'reject') {
                $stmt = $conn->prepare("
                    UPDATE requisitions
                    SET status = 'rejected',
                        approved_by = ?,
                        approved_at = NOW(),
                        rejection_reason = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    (int)$input['approved_by'],
                    $input['rejection_reason'] ?? 'No reason given',
                    $id
                ]);

                $r = $conn->prepare("SELECT requested_by, req_number FROM requisitions WHERE id = ?");
                $r->execute([$id]);
                $row = $r->fetch(PDO::FETCH_ASSOC);

                if ($row) {
                    createNotification(
                        $conn,
                        (int)$row['requested_by'],
                        '❌ Requisition Rejected',
                        'Your requisition ' . $row['req_number'] . ' was rejected: ' .
                        ($input['rejection_reason'] ?? 'No reason given'),
                        'warning',
                        'warning'
                    );
                }

                echo json_encode(['success' => true, 'message' => 'Requisition rejected']);
            } elseif ($action === 'cancel') {
                $stmt = $conn->prepare("UPDATE requisitions SET status = 'cancelled' WHERE id = ?");
                $stmt->execute([$id]);
                echo json_encode(['success' => true, 'message' => 'Requisition cancelled']);
            } else {
                // Generic field update
                $allowed = ['product_id','product_name','quantity','estimated_unit_cost',
                            'estimated_total','cost_center','justification','urgency','status'];
                $fields = []; $params = [];
                foreach ($allowed as $f) {
                    if (isset($input[$f])) { $fields[] = "$f = ?"; $params[] = $input[$f]; }
                }
                if (!$fields) throw new Exception('No fields to update');
                $params[] = $id;
                $stmt = $conn->prepare("UPDATE requisitions SET " . implode(', ', $fields) . " WHERE id = ?");
                $stmt->execute($params);
                echo json_encode(['success' => true]);
            }
            break;

        // ============================================
        // DELETE
        // ============================================
        case 'DELETE':
            $id = $_GET['id'] ?? null;
            if (!$id) throw new Exception('ID required');
            $stmt = $conn->prepare("DELETE FROM requisitions WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true]);
            break;
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}