<?php
// ============================================
// 📁 File: api/supply_chain.php
// 🔧 Supply Chain Requests — v3
// ✅ Role enforcement
// ✅ Transition validation
// ✅ Audit trail (by + at for each stage)
// ✅ Stock check on order
// ✅ Auto-create PO on order
// ✅ Auto-create GRN on receive
// ✅ Supplier notify on unavailable
// ============================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';

$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?: [];

// ============================================
// Helpers
// ============================================
function generate_po_number($conn) {
    $prefix = 'PO-' . date('Ymd') . '-';
    $stmt = $conn->prepare("SELECT po_number FROM purchase_orders WHERE po_number LIKE :p ORDER BY po_number DESC LIMIT 1");
    $stmt->execute([':p' => $prefix . '%']);
    $last = $stmt->fetchColumn();
    $seq = $last ? ((int)substr($last, -4) + 1) : 1001;
    return $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
}

function generate_grn_number($conn) {
    $prefix = 'GRN-' . date('Ymd') . '-';
    $stmt = $conn->prepare("SELECT grn_number FROM po_deliveries WHERE grn_number LIKE :p ORDER BY grn_number DESC LIMIT 1");
    $stmt->execute([':p' => $prefix . '%']);
    $last = $stmt->fetchColumn();
    $seq = $last ? ((int)substr($last, -4) + 1) : 1001;
    return $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
}

function get_user_roles($conn, $userId) {
    if (!$userId) return [];
    $stmt = $conn->prepare("SELECT role, roles FROM users WHERE id = ?");
    $stmt->execute([(int)$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return [];
    $roles = [];
    if (!empty($row['role'])) $roles[] = $row['role'];
    if (!empty($row['roles'])) {
        $extra = json_decode($row['roles'], true);
        if (is_array($extra)) $roles = array_merge($roles, $extra);
    }
    return array_unique($roles);
}

function has_role($roles, $allowed) {
    foreach ($allowed as $r) {
        if (in_array($r, $roles, true)) return true;
    }
    return false;
}

function require_role($conn, $userId, $allowed, $actionLabel) {
    $roles = get_user_roles($conn, $userId);
    if (!has_role($roles, $allowed)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => "Access denied: {$actionLabel} requires one of: " . implode(', ', $allowed)
        ]);
        exit();
    }
}

// ============================================
// Transition rules
// ============================================
function allowed_transitions($from, $to) {
    $map = [
        'pending'    => ['approved', 'rejected', 'cancelled'],
        'approved'   => ['ordered', 'unavailable'],
        'ordered'    => ['received'],
        'received'   => ['paid'],
        'paid'       => ['completed'],
        'rejected'   => [],
        'unavailable'=> [],
        'completed'  => [],
        'cancelled'  => [],
    ];
    $fromKey = $from ?: 'pending';
    $allowed = $map[$fromKey] ?? [];
    return in_array($to, $allowed, true);
}

try {
    $db   = new Database();
    $conn = $db->getConnection();

    // ============================================
    // GET
    // ============================================
    if ($method === 'GET') {

        if (isset($_GET['product_id'])) {
            $stmt = $conn->prepare("SELECT id, name, stock, low_stock_threshold FROM products WHERE id = ?");
            $stmt->execute([(int)$_GET['product_id']]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$product) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Product not found']);
                exit();
            }
            $needsRestock = (int)$product['stock'] <= (int)$product['low_stock_threshold'];
            echo json_encode([
                'success' => true,
                'data' => [
                    'product'       => $product,
                    'needs_restock' => $needsRestock,
                    'stock_status'  => $needsRestock ? 'low' : 'adequate',
                ],
            ]);
            exit();
        }

        if (isset($_GET['low_stock'])) {
            $stmt = $conn->prepare("
                SELECT p.*, s.id AS supplier_id, s.name AS supplier_name,
                       s.stock_available AS supplier_stock, s.price_per_unit
                FROM products p
                LEFT JOIN suppliers s ON p.id = s.product_id
                WHERE p.stock <= p.low_stock_threshold AND p.status = 'active'
                ORDER BY p.stock ASC
            ");
            $stmt->execute();
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            exit();
        }

        if (isset($_GET['id'])) {
            $stmt = $conn->prepare("
                SELECT r.*,
                       p.name AS product_name, p.stock AS product_stock,
                       s.name AS supplier_name, s.stock_available AS supplier_stock,
                       u.full_name AS requester_name,
                       a.full_name AS approver_name,
                       o.full_name AS orderer_name,
                       v.full_name AS receiver_name,
                       pay.full_name AS payer_name,
                       po.id AS po_id, po.po_number, po.lifecycle_status AS po_lifecycle,
                       po.payment_status
                FROM supply_chain_requests r
                LEFT JOIN products p ON r.product_id = p.id
                LEFT JOIN suppliers s ON r.supplier_id = s.id
                LEFT JOIN users u ON r.requested_by = u.id
                LEFT JOIN users a ON r.approved_by = a.id
                LEFT JOIN users o ON r.ordered_by = o.id
                LEFT JOIN users v ON r.received_by = v.id
                LEFT JOIN users pay ON r.paid_by = pay.id
                LEFT JOIN purchase_orders po ON po.requisition_id = r.id
                WHERE r.id = ?
                ORDER BY po.id DESC
                LIMIT 1
            ");
            $stmt->execute([(int)$_GET['id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Request not found']);
                exit();
            }
            echo json_encode(['success' => true, 'data' => $row]);
            exit();
        }

        $stmt = $conn->prepare("
            SELECT r.*,
                   p.name AS product_name,
                   s.name AS supplier_name,
                   u.full_name AS requester_name,
                   a.full_name AS approver_name,
                   o.full_name AS orderer_name,
                   po.id AS po_id, po.po_number, po.lifecycle_status AS po_lifecycle,
                   po.payment_status
            FROM supply_chain_requests r
            LEFT JOIN products p ON r.product_id = p.id
            LEFT JOIN suppliers s ON r.supplier_id = s.id
            LEFT JOIN users u ON r.requested_by = u.id
            LEFT JOIN users a ON r.approved_by = a.id
            LEFT JOIN users o ON r.ordered_by = o.id
            LEFT JOIN purchase_orders po ON po.requisition_id = r.id
            ORDER BY r.created_at DESC
        ");
        $stmt->execute();
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit();
    }

    // ============================================
    // POST — create request
    // ============================================
    if ($method === 'POST') {
        $productId   = (int)($input['product_id']   ?? 0);
        $quantity    = (int)($input['quantity']     ?? 0);
        $requestedBy = (int)($input['requested_by'] ?? 0);
        $notes       = trim((string)($input['notes'] ?? ''));

        if ($productId <= 0 || $quantity <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'product_id and quantity are required']);
            exit();
        }

        // Q1=B : Only Staff, Admin, Super Admin can create
        require_role($conn, $requestedBy, ['staff', 'admin', 'super_admin'], 'creating a request');

        $conn->beginTransaction();
        try {
            $stmt = $conn->prepare("SELECT id, name, stock, cost FROM products WHERE id = ?");
            $stmt->execute([$productId]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$product) throw new Exception('Product not found');

            if ((int)$product['stock'] >= $quantity) {
                $conn->commit();
                echo json_encode([
                    'success' => true,
                    'status'  => 'available',
                    'message' => 'Stock is sufficient. No restock needed.',
                    'stock'   => (int)$product['stock'],
                ]);
                exit();
            }

            $stmt = $conn->prepare("
                SELECT id, name, stock_available, price_per_unit, lead_time_days
                FROM suppliers
                WHERE product_id = ? AND status = 'active'
                ORDER BY price_per_unit ASC
                LIMIT 1
            ");
            $stmt->execute([$productId]);
            $supplier = $stmt->fetch(PDO::FETCH_ASSOC);

            $unitPrice = $supplier['price_per_unit'] ?? ($product['cost'] ?? 0);
            $finalCost = $unitPrice * $quantity;

            $stmt = $conn->prepare("
                INSERT INTO supply_chain_requests
                    (product_id, supplier_id, quantity, status, requested_by, total_cost, notes, created_at)
                VALUES (?, ?, ?, 'pending', ?, ?, ?, NOW())
            ");
            $stmt->execute([$productId, $supplier['id'] ?? null, $quantity, $requestedBy, $finalCost, $notes]);
            $scRequestId = (int)$conn->lastInsertId();

            if (function_exists('notifyRole')) {
                notifyRole(
                    $conn,
                    ['finance', 'super_admin', 'admin'],
                    '⚠️ Budget Approval Needed',
                    "{$product['name']} × {$quantity} requested — Est. ₱" . number_format($finalCost, 2),
                    'warning', 'warning'
                );
            }

            $conn->commit();
            echo json_encode([
                'success'    => true,
                'status'     => 'pending',
                'message'    => 'Request submitted. Waiting for Finance approval.',
                'request_id' => $scRequestId,
            ]);
            exit();

        } catch (Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    // ============================================
    // PUT — transition with role + rule enforcement
    // ============================================
    if ($method === 'PUT') {
        $id     = (int)($_GET['id'] ?? ($input['id'] ?? 0));
        $status = trim((string)($input['status'] ?? ''));
        $actor  = (int)($input['actor_id'] ?? $input['approved_by'] ?? 0);

        if ($id <= 0 || !$status) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'id and status are required']);
            exit();
        }

        $allowed = ['pending','approved','rejected','ordered','unavailable','received','paid','completed','cancelled'];
        if (!in_array($status, $allowed, true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Invalid status: {$status}"]);
            exit();
        }

        if ($actor <= 0) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'actor_id is required']);
            exit();
        }

        $conn->beginTransaction();
        try {
            $stmt = $conn->prepare("
                SELECT r.*, p.name AS product_name, p.id AS product_id,
                       s.name AS supplier_name
                FROM supply_chain_requests r
                LEFT JOIN products p ON r.product_id = p.id
                LEFT JOIN suppliers s ON r.supplier_id = s.id
                WHERE r.id = ?
                FOR UPDATE
            ");
            $stmt->execute([$id]);
            $req = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$req) throw new Exception('Supply chain request not found');

            // Transition guard
            if (!allowed_transitions($req['status'], $status)) {
                throw new Exception("Illegal transition: {$req['status']} → {$status}");
            }

            // Role guard per action
            if (in_array($status, ['approved','rejected'], true)) {
                require_role($conn, $actor, ['finance', 'admin', 'super_admin'], 'approving or rejecting');
            }
            if (in_array($status, ['ordered','unavailable','received'], true)) {
                require_role($conn, $actor, ['supply_chain', 'admin', 'super_admin'], 'ordering or receiving');
            }
            if ($status === 'paid') {
                require_role($conn, $actor, ['finance', 'super_admin', 'admin'], 'marking as paid');
            }
            if ($status === 'completed') {
                require_role($conn, $actor, ['finance', 'super_admin', 'admin'], 'closing the request');
            }

            $newNotes    = trim((string)($input['notes'] ?? ''));
            $newSupplier = isset($input['supplier_id']) ? (int)$input['supplier_id'] : null;
            $newQuantity = isset($input['quantity'])    ? (int)$input['quantity']    : null;
            $rejectReason= trim((string)($input['reject_reason'] ?? $input['notes'] ?? ''));

            // STOCK CHECK on order (Q2)
            if ($status === 'ordered') {
                $sid = $newSupplier ?: $req['supplier_id'];
                if (!$sid) throw new Exception('Please select a supplier before ordering.');

                $sStmt = $conn->prepare("SELECT id, name, stock_available, price_per_unit, lead_time_days, payment_terms FROM suppliers WHERE id = ?");
                $sStmt->execute([$sid]);
                $sup = $sStmt->fetch(PDO::FETCH_ASSOC);
                if (!$sup) throw new Exception('Supplier not found');

                $qty = $newQuantity ?: (int)$req['quantity'];
                if ((int)$sup['stock_available'] < $qty) {
                    throw new Exception("Supplier '{$sup['name']}' only has {$sup['stock_available']} in stock (need {$qty}). Mark as unavailable or pick another supplier.");
                }
            }

            // Build update
            $fields = ['status = :status', 'updated_at = NOW()'];
            $params = [':status' => $status, ':id' => $id];

            if ($newSupplier)     { $fields[] = 'supplier_id = :sid';       $params[':sid'] = $newSupplier; }
            if ($newQuantity)     { $fields[] = 'quantity = :qty';          $params[':qty'] = $newQuantity; }
            if ($newNotes !== '') { $fields[] = "notes = CONCAT(COALESCE(notes,''), ' ', :notes)"; $params[':notes'] = $newNotes; }

            // Stage-specific audit writes
            if ($status === 'approved') {
                $fields[] = 'approved_by = :actor';    $params[':actor'] = $actor;
                $fields[] = 'approved_at = NOW()';
            }
            if ($status === 'rejected') {
                $fields[] = 'approved_by = :actor';    $params[':actor'] = $actor;
                $fields[] = 'rejected_reason = :rr';   $params[':rr'] = $rejectReason;
            }
            if ($status === 'ordered') {
                $fields[] = 'ordered_by = :actor';     $params[':actor'] = $actor;
                $fields[] = 'ordered_at = NOW()';
                $fields[] = 'order_date = NOW()';
            }
            if ($status === 'unavailable') {
                $fields[] = 'ordered_by = :actor';     $params[':actor'] = $actor;
                $fields[] = 'rejected_reason = :rr';   $params[':rr'] = $rejectReason ?: 'Supplier has no stock';
            }
            if ($status === 'received') {
                $fields[] = 'received_by = :actor';    $params[':actor'] = $actor;
                $fields[] = 'received_at = NOW()';
                $fields[] = 'received_date = NOW()';
            }
            if ($status === 'paid') {
                $fields[] = 'paid_by = :actor';        $params[':actor'] = $actor;
                $fields[] = 'paid_at = NOW()';
            }

            $sql = "UPDATE supply_chain_requests SET " . implode(', ', $fields) . " WHERE id = :id";
            $stmt = $conn->prepare($sql);
            foreach ($params as $k => $v) $stmt->bindValue($k, $v);
            $stmt->execute();

            // ==========================================================
            // SIDE EFFECTS
            // ============================================

            // ORDER → create/update PO
            if ($status === 'ordered') {
                $sid = $newSupplier ?: $req['supplier_id'];
                $qty = $newQuantity ?: (int)$req['quantity'];

                $sStmt = $conn->prepare("SELECT id, name, price_per_unit, lead_time_days, payment_terms FROM suppliers WHERE id = ?");
                $sStmt->execute([$sid]);
                $sup = $sStmt->fetch(PDO::FETCH_ASSOC);

                $unitPrice = (float)($sup['price_per_unit'] ?? 0);
                $totalCost = $unitPrice * $qty;
                $leadDays  = (int)($sup['lead_time_days'] ?? 3);
                $expected  = date('Y-m-d', strtotime("+{$leadDays} days"));

                $existing = $conn->prepare("SELECT id, po_number FROM purchase_orders WHERE requisition_id = ?");
                $existing->execute([$id]);
                $existingPo = $existing->fetch(PDO::FETCH_ASSOC);

                if ($existingPo) {
                    $upd = $conn->prepare("
                        UPDATE purchase_orders
                        SET supplier_id=?, quantity=?, unit_price=?, total_cost=?,
                            status='ordered', lifecycle_status='ordered',
                            expected_delivery=?, updated_at=NOW()
                        WHERE id=?
                    ");
                    $upd->execute([$sid, $qty, $unitPrice, $totalCost, $expected, $existingPo['id']]);
                    $poNumber = $existingPo['po_number'];
                    $poId     = (int)$existingPo['id'];
                } else {
                    $poNumber = generate_po_number($conn);
                    $ins = $conn->prepare("
                        INSERT INTO purchase_orders
                            (po_number, requisition_id, product_id, supplier_id, quantity, unit_price,
                             total_cost, ordered_by, ordered_date, expected_delivery, status,
                             lifecycle_status, payment_terms, payment_status, amount_paid, notes)
                        VALUES
                            (:po, :req, :pid, :sid, :qty, :up, :tc, :oby, NOW(), :exp, 'ordered',
                             'ordered', :terms, 'unpaid', 0, :notes)
                    ");
                    $ins->execute([
                        ':po'    => $poNumber,
                        ':req'   => $id,
                        ':pid'   => (int)$req['product_id'],
                        ':sid'   => $sid,
                        ':qty'   => $qty,
                        ':up'    => $unitPrice,
                        ':tc'    => $totalCost,
                        ':oby'   => $actor,
                        ':exp'   => $expected,
                        ':terms' => $sup['payment_terms'] ?? 'Net 30',
                        ':notes' => "Auto-created from request #{$id}",
                    ]);
                    $poId = (int)$conn->lastInsertId();
                }

                if (function_exists('notifyRole')) {
                    notifyRole(
                        $conn,
                        ['finance', 'super_admin', 'admin'],
                        '🚚 Purchase Order Created',
                        "PO {$poNumber} — {$req['product_name']} × {$qty} from {$sup['name']} (₱" . number_format($totalCost, 2) . ")",
                        'info', 'info'
                    );
                }
            }

            // UNAVAILABLE → notify Staff + Finance (C2=B)
            if ($status === 'unavailable') {
                $reasonTxt = $rejectReason ?: 'Supplier has no stock';
                if (function_exists('createNotification')) {
                    createNotification(
                        $conn,
                        (int)$req['requested_by'],
                        '⚠️ Request Unavailable',
                        "Your request for {$req['product_name']} × {$req['quantity']} could not be fulfilled. Reason: {$reasonTxt}",
                        'warning', 'warning'
                    );
                }
                if (function_exists('notifyRole')) {
                    notifyRole(
                        $conn,
                        ['finance', 'super_admin', 'admin'],
                        '⚠️ Request Unavailable',
                        "{$req['product_name']} × {$req['quantity']} — {$reasonTxt}. Budget is freed up.",
                        'warning', 'warning'
                    );
                }
            }

            // RECEIVED → create GRN + stock++
            if ($status === 'received') {
                $poStmt = $conn->prepare("SELECT id, po_number, quantity FROM purchase_orders WHERE requisition_id = ? ORDER BY id DESC LIMIT 1");
                $poStmt->execute([$id]);
                $po = $poStmt->fetch(PDO::FETCH_ASSOC);

                $qty = (int)$req['quantity'];

                if ($po) {
                    $grnNumber = generate_grn_number($conn);
                    $grnStmt = $conn->prepare("
                        INSERT INTO po_deliveries
                            (po_id, grn_number, received_by, qty_ordered,
                             qty_received, qty_rejected, condition_notes, status)
                        VALUES (?, ?, ?, ?, ?, 0, ?, 'complete')
                    ");
                    $grnStmt->execute([
                        $po['id'], $grnNumber, $actor, (int)$po['quantity'], $qty,
                        "Auto-generated from request #{$id}"
                    ]);

                    $upd = $conn->prepare("
                        UPDATE purchase_orders
                        SET lifecycle_status='grn_posted', status='received',
                            received_date=NOW(), updated_at=NOW()
                        WHERE id=?
                    ");
                    $upd->execute([$po['id']]);
                } else {
                    $grnNumber = null;
                }

                $stmt = $conn->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
                $stmt->execute([$qty, (int)$req['product_id']]);

                if (function_exists('notifyRole')) {
                    notifyRole(
                        $conn,
                        ['finance', 'supply_chain', 'super_admin', 'admin'],
                        '✅ Stock Received',
                        "{$req['product_name']} × {$qty} added" . ($grnNumber ? " (GRN {$grnNumber})" : '') . '.',
                        'success', 'success'
                    );
                }
            }

            // PAID → update PO payment status + notify (A+B+C merged)
            if ($status === 'paid') {
                $method    = trim((string)($input['payment_method'] ?? 'paymongo'));
                $reference = trim((string)($input['payment_reference'] ?? ''));

                $upd = $conn->prepare("
                    UPDATE purchase_orders
                    SET lifecycle_status='paid', payment_status='paid',
                        payment_method=:pm, payment_reference=:pr,
                        amount_paid=total_cost, updated_at=NOW()
                    WHERE requisition_id=?
                ");
                $upd->execute([':pm' => $method, ':pr' => $reference, ':req' => $id]);

                if (function_exists('notifyRole')) {
                    notifyRole(
                        $conn,
                        ['supply_chain', 'super_admin', 'admin'],
                        '💰 Supplier Paid',
                        "Payment sent for {$req['product_name']} × {$req['quantity']}",
                        'success', 'success'
                    );
                }
            }

            // COMPLETED → close PO
            if ($status === 'completed') {
                $upd = $conn->prepare("
                    UPDATE purchase_orders
                    SET lifecycle_status='closed', closed_at=NOW(), closed_by=:cb, updated_at=NOW()
                    WHERE requisition_id=?
                ");
                $upd->execute([':cb' => $actor, ':req' => $id]);
            }

            $conn->commit();

            $response = ['success' => true, 'message' => "Status updated to '{$status}'"];
            if ($status === 'ordered' && isset($poNumber))  $response['po_number']  = $poNumber;
            if ($status === 'received' && isset($grnNumber)) $response['grn_number'] = $grnNumber;
            echo json_encode($response);
            exit();

        } catch (Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);

} catch (Throwable $e) {
    error_log('supply_chain.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>