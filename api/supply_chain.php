<?php
// ============================================
// 📁 File: api/supply_chain.php
// 🔧 Supply Chain Requests Flow with PO + GRN bridging
//
// FLOW:
//   pending     → Staff creates request
//   approved    → Finance approves budget (or auto-approved from product request)
//   rejected    → Finance denies budget
//   ordered     → Supply Chain placed order (creates purchase_orders row)
//   unavailable → Supplier has no stock
//   received    → Goods arrived, stock++, GRN created
//   paid        → Finance paid supplier via PayMongo
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
// Helper: generate unique PO number
// ============================================
function generate_po_number($conn) {
    $prefix = 'PO-' . date('Ymd') . '-';
    // Find highest existing PO number for today
    $stmt = $conn->prepare("
        SELECT po_number FROM purchase_orders
        WHERE po_number LIKE :prefix
        ORDER BY po_number DESC LIMIT 1
    ");
    $stmt->execute([':prefix' => $prefix . '%']);
    $last = $stmt->fetchColumn();

    if ($last) {
        $seq = (int)substr($last, -4) + 1;
    } else {
        $seq = 1001;
    }
    return $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
}

// ============================================
// Helper: generate unique GRN number
// ============================================
function generate_grn_number($conn) {
    $prefix = 'GRN-' . date('Ymd') . '-';
    $stmt = $conn->prepare("
        SELECT grn_number FROM po_deliveries
        WHERE grn_number LIKE :prefix
        ORDER BY grn_number DESC LIMIT 1
    ");
    $stmt->execute([':prefix' => $prefix . '%']);
    $last = $stmt->fetchColumn();

    if ($last) {
        $seq = (int)substr($last, -4) + 1;
    } else {
        $seq = 1001;
    }
    return $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
}

try {
    $db   = new Database();
    $conn = $db->getConnection();

    // ============================================
    // GET
    // ============================================
    if ($method === 'GET') {

        // Product stock check
        if (isset($_GET['product_id'])) {
            $stmt = $conn->prepare("
                SELECT id, name, stock, low_stock_threshold
                FROM products WHERE id = ?
            ");
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

        // Low stock list
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

        // Single request — includes linked PO info
        if (isset($_GET['id'])) {
            $stmt = $conn->prepare("
                SELECT r.*,
                       p.name AS product_name, p.stock AS product_stock,
                       s.name AS supplier_name, s.stock_available AS supplier_stock,
                       u.full_name AS requester_name,
                       a.full_name AS approver_name,
                       po.id AS po_id, po.po_number, po.lifecycle_status AS po_lifecycle
                FROM supply_chain_requests r
                LEFT JOIN products  p ON r.product_id   = p.id
                LEFT JOIN suppliers s ON r.supplier_id  = s.id
                LEFT JOIN users     u ON r.requested_by = u.id
                LEFT JOIN users     a ON r.approved_by  = a.id
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

        // List all requests — includes linked PO info
        $stmt = $conn->prepare("
            SELECT r.*,
                   p.name AS product_name,
                   s.name AS supplier_name,
                   u.full_name AS requester_name,
                   a.full_name AS approver_name,
                   po.id AS po_id, po.po_number, po.lifecycle_status AS po_lifecycle,
                   po.payment_status
            FROM supply_chain_requests r
            LEFT JOIN products  p ON r.product_id   = p.id
            LEFT JOIN suppliers s ON r.supplier_id  = s.id
            LEFT JOIN users     u ON r.requested_by = u.id
            LEFT JOIN users     a ON r.approved_by  = a.id
            LEFT JOIN purchase_orders po ON po.requisition_id = r.id
            ORDER BY r.created_at DESC
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'data' => $rows]);
        exit();
    }

    // ============================================
    // POST — Staff creates a restock request
    // ============================================
    if ($method === 'POST') {
        $productId   = (int)($input['product_id']   ?? 0);
        $quantity    = (int)($input['quantity']     ?? 0);
        $requestedBy = (int)($input['requested_by'] ?? 1);
        $notes       = trim((string)($input['notes'] ?? ''));

        if ($productId <= 0 || $quantity <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'product_id and quantity are required']);
            exit();
        }

        $conn->beginTransaction();
        try {
            $stmt = $conn->prepare("SELECT id, name, stock, cost, low_stock_threshold FROM products WHERE id = ?");
            $stmt->execute([$productId]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$product) throw new Exception('Product not found');

            // Stock is sufficient → no request needed
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

            // Find supplier for this product (auto-pick cheapest)
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
            $stmt->execute([
                $productId,
                $supplier['id'] ?? null,
                $quantity,
                $requestedBy,
                $finalCost,
                $notes,
            ]);
            $scRequestId = (int)$conn->lastInsertId();

            if (function_exists('notifyRole')) {
                notifyRole(
                    $conn,
                    ['finance', 'super_admin', 'admin'],
                    '⚠️ Budget Approval Needed',
                    "{$product['name']} × {$quantity} requested — Est. ₱" . number_format($finalCost, 2),
                    'warning',
                    'warning'
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
    // PUT — Update status
    // On 'ordered'  → create purchase_orders row
    // On 'received' → create po_deliveries GRN + stock++
    // ============================================
    if ($method === 'PUT') {
        $id     = (int)($_GET['id'] ?? ($input['id'] ?? 0));
        $status = trim((string)($input['status'] ?? ''));

        if ($id <= 0 || !$status) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'id and status are required']);
            exit();
        }

        $allowed = ['pending', 'approved', 'rejected', 'ordered', 'unavailable', 'received', 'paid'];
        if (!in_array($status, $allowed, true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Invalid status: {$status}"]);
            exit();
        }

        $conn->beginTransaction();
        try {
            $stmt = $conn->prepare("
                SELECT r.*, p.name AS product_name, p.id AS product_id,
                       s.name AS supplier_name
                FROM supply_chain_requests r
                LEFT JOIN products  p ON r.product_id  = p.id
                LEFT JOIN suppliers s ON r.supplier_id = s.id
                WHERE r.id = ?
                FOR UPDATE
            ");
            $stmt->execute([$id]);
            $req = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$req) throw new Exception('Supply chain request not found');

            if ($status === 'received' && $req['status'] === 'received') {
                $conn->commit();
                echo json_encode(['success' => true, 'message' => 'Already received']);
                exit();
            }

            $approvedBy   = $input['approved_by'] ?? null;
            $newNotes     = trim((string)($input['notes'] ?? ''));
            $newSupplier  = isset($input['supplier_id']) ? (int)$input['supplier_id'] : null;
            $newQuantity  = isset($input['quantity'])    ? (int)$input['quantity']    : null;

            if ($status === 'ordered' && !$newSupplier && !$req['supplier_id']) {
                throw new Exception('Please select a supplier before ordering.');
            }

            $fields = [
                'status = :status',
                'approved_by = COALESCE(:approved_by, approved_by)',
                "notes = CONCAT(COALESCE(notes, ''), ' ', :notes)",
                "received_date = IF(:status_check = 'received', NOW(), received_date)"
            ];
            $params = [
                ':status'        => $status,
                ':approved_by'   => $approvedBy,
                ':notes'         => $newNotes,
                ':status_check'  => $status,
                ':id'            => $id,
            ];

            if ($newSupplier) {
                $fields[] = 'supplier_id = :supplier_id';
                $params[':supplier_id'] = $newSupplier;
            }
            if ($newQuantity && $newQuantity > 0) {
                $fields[] = 'quantity = :quantity';
                $params[':quantity'] = $newQuantity;
            }

            // Recalculate total_cost when ordering
            if ($status === 'ordered') {
                $sid = $newSupplier ?: $req['supplier_id'];
                if ($sid) {
                    $sStmt = $conn->prepare("SELECT price_per_unit FROM suppliers WHERE id = ?");
                    $sStmt->execute([$sid]);
                    $sp = $sStmt->fetch(PDO::FETCH_ASSOC);
                    $unitPrice = (float)($sp['price_per_unit'] ?? 0);
                    $qty       = $newQuantity ?: (int)$req['quantity'];
                    $fields[]  = 'total_cost = :total_cost';
                    $params[':total_cost'] = $unitPrice * $qty;
                }
            }

            $sql = "UPDATE supply_chain_requests SET " . implode(', ', $fields) . " WHERE id = :id";
            $stmt = $conn->prepare($sql);
            foreach ($params as $k => $v) $stmt->bindValue($k, $v);
            $stmt->execute();

            // ============================================
            // 🚚 ORDERED → CREATE PURCHASE ORDER
            // ============================================
            if ($status === 'ordered') {
                $sid = $newSupplier ?: $req['supplier_id'];
                $qty = $newQuantity ?: (int)$req['quantity'];

                // Get supplier pricing + lead time
                $sStmt = $conn->prepare("
                    SELECT id, name, price_per_unit, lead_time_days, payment_terms
                    FROM suppliers WHERE id = ?
                ");
                $sStmt->execute([$sid]);
                $sup = $sStmt->fetch(PDO::FETCH_ASSOC);

                if (!$sup) throw new Exception('Supplier not found');

                $unitPrice  = (float)($sup['price_per_unit'] ?? 0);
                $totalCost  = $unitPrice * $qty;
                $leadDays   = (int)($sup['lead_time_days'] ?? 3);
                $poNumber   = generate_po_number($conn);
                $expected   = date('Y-m-d', strtotime("+{$leadDays} days"));
                $orderedBy  = $approvedBy ?: 1;

                // Check if a PO already exists for this requisition (idempotency)
                $existing = $conn->prepare("SELECT id, po_number FROM purchase_orders WHERE requisition_id = ?");
                $existing->execute([$id]);
                $existingPo = $existing->fetch(PDO::FETCH_ASSOC);

                if ($existingPo) {
                    // Update the existing PO
                    $upd = $conn->prepare("
                        UPDATE purchase_orders
                        SET supplier_id = ?, quantity = ?, unit_price = ?, total_cost = ?,
                            status = 'ordered', lifecycle_status = 'ordered',
                            expected_delivery = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $upd->execute([$sid, $qty, $unitPrice, $totalCost, $expected, $existingPo['id']]);
                    $poId     = (int)$existingPo['id'];
                    $poNumber = $existingPo['po_number'];
                } else {
                    // Create new PO
                    $ins = $conn->prepare("
                        INSERT INTO purchase_orders
                            (po_number, requisition_id, product_id, supplier_id,
                             quantity, unit_price, total_cost, ordered_by,
                             ordered_date, expected_delivery, status,
                             lifecycle_status, payment_terms, payment_status,
                             amount_paid, notes)
                        VALUES
                            (:po_number, :req_id, :pid, :sid,
                             :qty, :unit_price, :total_cost, :ordered_by,
                             NOW(), :expected, 'ordered',
                             'ordered', :terms, 'unpaid',
                             0, :notes)
                    ");
                    $ins->execute([
                        ':po_number'   => $poNumber,
                        ':req_id'      => $id,
                        ':pid'         => (int)$req['product_id'],
                        ':sid'         => $sid,
                        ':qty'         => $qty,
                        ':unit_price'  => $unitPrice,
                        ':total_cost'  => $totalCost,
                        ':ordered_by'  => $orderedBy,
                        ':expected'    => $expected,
                        ':terms'       => $sup['payment_terms'] ?? 'Net 30',
                        ':notes'       => "Auto-created from supply chain request #{$id}",
                    ]);
                    $poId = (int)$conn->lastInsertId();
                }

                if (function_exists('notifyRole')) {
                    notifyRole(
                        $conn,
                        ['finance', 'super_admin', 'admin'],
                        '🚚 Purchase Order Created',
                        "PO {$poNumber} — {$req['product_name']} × {$qty} from {$sup['name']} (₱" . number_format($totalCost, 2) . ")",
                        'info',
                        'info'
                    );
                }
            }

            // ============================================
            // 📦 RECEIVED → CREATE GRN + stock++
            // ============================================
            if ($status === 'received' && $req['status'] !== 'received') {
                // Find the linked PO
                $poStmt = $conn->prepare("
                    SELECT id, po_number, quantity FROM purchase_orders
                    WHERE requisition_id = ? ORDER BY id DESC LIMIT 1
                ");
                $poStmt->execute([$id]);
                $po = $poStmt->fetch(PDO::FETCH_ASSOC);

                $qty       = (int)$req['quantity'];
                $receivedBy = $approvedBy ?: 1;

                if ($po) {
                    // Create GRN in po_deliveries
                    $grnNumber = generate_grn_number($conn);
                    $grnStmt = $conn->prepare("
                        INSERT INTO po_deliveries
                            (po_id, grn_number, received_by, qty_ordered,
                             qty_received, qty_rejected, condition_notes, status)
                        VALUES (?, ?, ?, ?, ?, 0, ?, 'complete')
                    ");
                    $grnStmt->execute([
                        $po['id'],
                        $grnNumber,
                        $receivedBy,
                        (int)$po['quantity'],
                        $qty,
                        "Auto-generated from supply chain request #{$id}",
                    ]);
                    $grnId = (int)$conn->lastInsertId();

                    // Update PO lifecycle
                    $upd = $conn->prepare("
                        UPDATE purchase_orders
                        SET lifecycle_status = 'grn_posted',
                            status = 'received',
                            received_date = NOW(),
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $upd->execute([$po['id']]);
                } else {
                    // No PO found — still create GRN? Just bump stock.
                    $grnNumber = null;
                    $grnId = null;
                }

                // Increase product stock
                $stmt = $conn->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
                $stmt->execute([$qty, (int)$req['product_id']]);

                // Log in inventory
                try {
                    $logStmt = $conn->prepare("
                        INSERT INTO inventory_logs (product_id, quantity_change, type, note, user_id)
                        VALUES (?, ?, 'restock', ?, ?)
                    ");
                    $logStmt->execute([
                        (int)$req['product_id'],
                        $qty,
                        $grnNumber
                            ? "Goods received — GRN {$grnNumber} (PO {$po['po_number']})"
                            : "Goods received via supply chain request #{$id}",
                        $receivedBy,
                    ]);
                } catch (Throwable $ignored) { /* inventory_logs may not exist; safe to skip */ }

                if (function_exists('notifyRole')) {
                    notifyRole(
                        $conn,
                        ['finance', 'supply_chain', 'super_admin', 'admin'],
                        '✅ Stock Received',
                        "{$req['product_name']} × {$qty} added to inventory" .
                        ($grnNumber ? " (GRN {$grnNumber})" : '') . '.',
                        'success',
                        'success'
                    );
                }
            }

            // ✅ APPROVED → notify Supply Chain
            if ($status === 'approved' && $req['status'] === 'pending' && function_exists('notifyRole')) {
                notifyRole(
                    $conn,
                    ['supply_chain', 'super_admin', 'admin'],
                    '✅ Request Approved by Finance',
                    "{$req['product_name']} × {$req['quantity']} approved for ordering.",
                    'success',
                    'success'
                );
            }

            // ❌ REJECTED
            if ($status === 'rejected' && function_exists('createNotification')) {
                createNotification(
                    $conn,
                    (int)$req['requested_by'],
                    '❌ Request Rejected',
                    "Your request for {$req['product_name']} × {$req['quantity']} was rejected by Finance." .
                    ($newNotes ? " Reason: {$newNotes}" : ''),
                    'error',
                    'error'
                );
            }

            // ⚠️ UNAVAILABLE
            if ($status === 'unavailable' && function_exists('createNotification')) {
                createNotification(
                    $conn,
                    (int)$req['requested_by'],
                    '⚠️ Request Unavailable',
                    "Supplier has no stock for {$req['product_name']} × {$req['quantity']}. Please try again later.",
                    'warning',
                    'warning'
                );
            }

            // 💰 PAID → notify Supply Chain
            if ($status === 'paid' && function_exists('notifyRole')) {
                notifyRole(
                    $conn,
                    ['supply_chain', 'super_admin', 'admin'],
                    '💰 Supplier Paid',
                    "Payment sent for {$req['product_name']} × {$req['quantity']}.",
                    'success',
                    'success'
                );
            }

            $conn->commit();

            $response = [
                'success' => true,
                'message' => "Request status updated to '{$status}'",
            ];
            if ($status === 'ordered' && isset($poNumber)) {
                $response['po_number'] = $poNumber;
                $response['po_id']     = $poId;
            }
            if ($status === 'received' && isset($grnNumber)) {
                $response['grn_number'] = $grnNumber;
            }
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
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage(),
    ]);
}
?>