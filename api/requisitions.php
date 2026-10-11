<?php
// ============================================================
// 📁 File: api/requisitions.php
// 🎯 Budget-aware requisition lifecycle
//
// Lifecycle: pending_finance → approved → converted_to_po → paid
//                              ↘ rejected
//                              ↘ cancelled (refunds budget)
// ============================================================
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
        // GET
        // ============================================
        case 'GET':
            if (isset($_GET['id'])) {
                $stmt = $conn->prepare("
                    SELECT r.*,
                           u.full_name AS requested_by_name,
                           a.full_name AS approved_by_name,
                           p.name AS product_name_real,
                           po.po_number AS po_number,
                           po.id AS po_id
                    FROM requisitions r
                    LEFT JOIN users u ON r.requested_by = u.id
                    LEFT JOIN users a ON r.approved_by = a.id
                    LEFT JOIN products p ON r.product_id = p.id
                    LEFT JOIN purchase_orders po ON po.requisition_id = r.id
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
                       p.name AS product_name_real,
                       po.po_number AS po_number,
                       po.id AS po_id
                FROM requisitions r
                LEFT JOIN users u ON r.requested_by = u.id
                LEFT JOIN users a ON r.approved_by = a.id
                LEFT JOIN products p ON r.product_id = p.id
                LEFT JOIN purchase_orders po ON po.requisition_id = r.id
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
        // POST — create requisition WITH BUDGET CHECK
        // ============================================
        case 'POST':
            $required = ['requested_by', 'quantity'];
            foreach ($required as $f) {
                if (empty($input[$f])) throw new Exception("Missing required field: $f");
            }

            $qty   = (int)$input['quantity'];
            $unit  = (float)($input['estimated_unit_cost'] ?? 0);
            $total = $qty * $unit;
            $dept  = $input['department'] ?? 'General';

            // ---- Find active budget for department ----
            $b = $conn->prepare("
                SELECT id, allocated_amount, spent_amount, status
                FROM budgets
                WHERE category = ? AND status IN ('active','exhausted')
                LIMIT 1
            ");
            $b->execute([$dept]);
            $budget = $b->fetch(PDO::FETCH_ASSOC);

            if (!$budget) {
                throw new Exception(
                    "No active budget found for department: $dept. " .
                    "Please ask Finance to allocate a budget first."
                );
            }

            // ---- Check remaining budget ----
            $remaining = (float)$budget['allocated_amount'] - (float)$budget['spent_amount'];
            if ($total > $remaining) {
                throw new Exception(
                    "Insufficient budget for $dept. " .
                    "Requested: ₱" . number_format($total, 2) . ", " .
                    "Remaining: ₱" . number_format($remaining, 2)
                );
            }

            $reqNo = 'REQ-' . date('Ymd') . '-' . rand(1000, 9999);

            $stmt = $conn->prepare("
                INSERT INTO requisitions
                    (req_number, requested_by, department, budget_id, product_id, product_name,
                     quantity, estimated_unit_cost, estimated_total, cost_center,
                     justification, urgency, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending_finance')
            ");
            $stmt->execute([
                $reqNo,
                (int)$input['requested_by'],
                $dept,
                (int)$budget['id'],
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

            // Notify finance
            if (function_exists('notifyRole')) {
                notifyRole(
                    $conn,
                    ['finance', 'super_admin', 'admin'],
                    '📋 New Requisition ' . $reqNo,
                    'New requisition for ' . ($input['product_name'] ?? 'item') .
                    ' x' . $qty . ' (₱' . number_format($total, 2) . ') needs approval.',
                    'warning',
                    'warning',
                    (int)$input['requested_by']
                );
            }

            echo json_encode([
                'success'          => true,
                'id'               => $id,
                'req_number'       => $reqNo,
                'budget_remaining' => number_format($remaining - $total, 2, '.', '')
            ]);
            break;

        // ============================================
        // PUT — approve / reject / cancel / convert
        // ============================================
        case 'PUT':
            $id = $_GET['id'] ?? null;
            if (!$id) throw new Exception('ID required');

            $action = $input['action'] ?? null;

            // -------- APPROVE --------
            if ($action === 'approve') {
                $conn->beginTransaction();
                try {
                    $r = $conn->prepare("
                        SELECT r.*,
                               b.id AS b_id, b.allocated_amount, b.spent_amount
                        FROM requisitions r
                        LEFT JOIN budgets b ON r.budget_id = b.id
                        WHERE r.id = ? FOR UPDATE
                    ");
                    $r->execute([$id]);
                    $req = $r->fetch(PDO::FETCH_ASSOC);

                    if (!$req) throw new Exception('Requisition not found');
                    if ($req['status'] !== 'pending_finance') {
                        throw new Exception("Cannot approve. Requisition is already '{$req['status']}'");
                    }

                    // Re-verify budget still available
                    if ($req['b_id']) {
                        $remaining = (float)$req['allocated_amount'] - (float)$req['spent_amount'];
                        if ((float)$req['estimated_total'] > $remaining) {
                            throw new Exception(
                                "Budget depleted. Requested: ₱" . number_format((float)$req['estimated_total'], 2) .
                                ", Remaining: ₱" . number_format($remaining, 2)
                            );
                        }

                        $conn->prepare("
                            UPDATE budgets
                            SET spent_amount = spent_amount + ?,
                                status = IF(spent_amount + ? >= allocated_amount, 'exhausted', status)
                            WHERE id = ?
                        ")->execute([
                            (float)$req['estimated_total'],
                            (float)$req['estimated_total'],
                            (int)$req['b_id']
                        ]);
                    }

                    $conn->prepare("
                        UPDATE requisitions
                        SET status = 'approved',
                            approved_by = ?,
                            approved_at = NOW()
                        WHERE id = ?
                    ")->execute([(int)$input['approved_by'], $id]);

                    $conn->commit();

                    if (function_exists('createNotification')) {
                        createNotification(
                            $conn,
                            (int)$req['requested_by'],
                            '✅ Requisition Approved',
                            'Your requisition ' . $req['req_number'] . ' has been approved.',
                            'success',
                            'success'
                        );
                    }

                    if (function_exists('notifyRole')) {
                        notifyRole(
                            $conn,
                            ['supply_chain', 'super_admin', 'admin'],
                            '📨 Ready for Sourcing — ' . $req['req_number'],
                            'Approved requisition for ' . ($req['product_name'] ?? 'item') .
                            ' x' . $req['quantity'] . '. Create a PO to proceed.',
                            'info',
                            'info'
                        );
                    }

                    echo json_encode([
                        'success' => true,
                        'message' => 'Requisition approved & budget deducted'
                    ]);
                } catch (Throwable $e) {
                    $conn->rollBack();
                    throw $e;
                }

            // -------- REJECT --------
            } elseif ($action === 'reject') {
                $stmt = $conn->prepare("
                    UPDATE requisitions
                    SET status = 'rejected',
                        approved_by = ?,
                        approved_at = NOW(),
                        rejection_reason = ?
                    WHERE id = ? AND status = 'pending_finance'
                ");
                $stmt->execute([
                    (int)$input['approved_by'],
                    $input['rejection_reason'] ?? 'No reason given',
                    $id
                ]);

                $r = $conn->prepare("SELECT requested_by, req_number FROM requisitions WHERE id = ?");
                $r->execute([$id]);
                $row = $r->fetch(PDO::FETCH_ASSOC);

                if ($row && function_exists('createNotification')) {
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

            // -------- CANCEL (refunds budget) --------
            } elseif ($action === 'cancel') {
                $conn->beginTransaction();
                try {
                    $r = $conn->prepare("
                        SELECT budget_id, estimated_total, status
                        FROM requisitions WHERE id = ? FOR UPDATE
                    ");
                    $r->execute([$id]);
                    $req = $r->fetch(PDO::FETCH_ASSOC);

                    if (!$req) throw new Exception('Requisition not found');
                    if (in_array($req['status'], ['paid','completed','converted_to_po'], true)) {
                        throw new Exception("Cannot cancel a requisition that is {$req['status']}");
                    }

                    // Refund budget if it was approved
                    if ($req['status'] === 'approved' && $req['budget_id']) {
                        $conn->prepare("
                            UPDATE budgets
                            SET spent_amount = GREATEST(0, spent_amount - ?),
                                status = 'active'
                            WHERE id = ?
                        ")->execute([
                            (float)$req['estimated_total'],
                            (int)$req['budget_id']
                        ]);
                    }

                    $conn->prepare("UPDATE requisitions SET status = 'cancelled' WHERE id = ?")
                         ->execute([$id]);

                    $conn->commit();
                    echo json_encode([
                        'success' => true,
                        'message' => 'Requisition cancelled & budget refunded'
                    ]);
                } catch (Throwable $e) {
                    $conn->rollBack();
                    throw $e;
                }

            // -------- CONVERT (called by purchase_orders.php) --------
            } elseif ($action === 'convert') {
                $conn->prepare("UPDATE requisitions SET status = 'converted_to_po' WHERE id = ?")
                     ->execute([$id]);
                echo json_encode(['success' => true, 'message' => 'Marked as converted']);

            // -------- GENERIC UPDATE --------
            } else {
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
            $stmt = $conn->prepare("
                DELETE FROM requisitions
                WHERE id = ? AND status IN ('pending_finance','rejected','cancelled')
            ");
            $stmt->execute([$id]);
            echo json_encode(['success' => true]);
            break;
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}