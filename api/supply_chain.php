<?php
// ============================================
// 📁 File: api/supply_chain.php
// 🔧 Supply Chain Requests Flow
// Flow: pending → approved (Finance) → ordered → received (stock++)
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

try {
    $db   = new Database();
    $conn = $db->getConnection();

    // ============================================
    // GET — fetch requests / products / low stock
    // ============================================
    if ($method === 'GET') {

        // --- Product stock check ---
        if (isset($_GET['product_id'])) {
            $stmt = $conn->prepare("
                SELECT id, name, stock, low_stock_threshold
                FROM products
                WHERE id = ?
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
                    'product' => $product,
                    'needs_restock' => $needsRestock,
                    'stock_status' => $needsRestock ? 'low' : 'adequate',
                ],
            ]);
            exit();
        }

        // --- Low stock list ---
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

        // --- Single request by id ---
        if (isset($_GET['id'])) {
            $stmt = $conn->prepare("
                SELECT r.*, p.name AS product_name, s.name AS supplier_name,
                       u.full_name AS requester_name,
                       a.full_name AS approver_name
                FROM supply_chain_requests r
                LEFT JOIN products  p ON r.product_id    = p.id
                LEFT JOIN suppliers s ON r.supplier_id   = s.id
                LEFT JOIN users     u ON r.requested_by  = u.id
                LEFT JOIN users     a ON r.approved_by   = a.id
                WHERE r.id = ?
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

        // --- List all requests ---
        $stmt = $conn->prepare("
            SELECT r.*, p.name AS product_name, s.name AS supplier_name,
                   u.full_name AS requester_name,
                   a.full_name AS approver_name
            FROM supply_chain_requests r
            LEFT JOIN products  p ON r.product_id   = p.id
            LEFT JOIN suppliers s ON r.supplier_id  = s.id
            LEFT JOIN users     u ON r.requested_by = u.id
            LEFT JOIN users     a ON r.approved_by  = a.id
            ORDER BY r.created_at DESC
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Ensure frontend receives an array (either response.data or response.data.data)
        echo json_encode(['success' => true, 'data' => $rows]);
        exit();
    }

    // ============================================
    // POST — create new supply chain request
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
            // Fetch product
            $stmt = $conn->prepare("SELECT id, name, stock, cost, low_stock_threshold FROM products WHERE id = ?");
            $stmt->execute([$productId]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$product) {
                throw new Exception('Product not found');
            }

            // If stock is sufficient, no restock needed
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

            // Check budget
            $stmt = $conn->prepare("
                SELECT allocated_amount, spent_amount
                FROM budgets
                WHERE category IN ('Supply Chain', 'Operations')
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmt->execute();
            $budget = $stmt->fetch(PDO::FETCH_ASSOC);

            $totalCost = ((float)($product['cost'] ?? 0)) * $quantity;
            $hasBudget = $budget && (($budget['allocated_amount'] - $budget['spent_amount']) >= $totalCost);

            // Find a supplier for this product
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

            // ---- No budget → create a budget request, keep status = pending ----
            if (!$hasBudget) {
                $stmt = $conn->prepare("
                    INSERT INTO budget_requests
                        (requested_by, department, purpose, amount, status)
                    VALUES (?, 'Supply Chain', ?, ?, 'pending')
                ");
                $stmt->execute([
                    $requestedBy,
                    "Restock {$quantity} units of {$product['name']}",
                    $finalCost,
                ]);
                $budgetRequestId = (int)$conn->lastInsertId();

                // Still create the supply chain request as pending, linked to budget request
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
                    trim($notes . " [Budget Request #{$budgetRequestId}]"),
                ]);
                $scRequestId = (int)$conn->lastInsertId();

                // Notify Finance
                if (function_exists('notifyRole')) {
                    notifyRole(
                        $conn,
                        ['finance', 'super_admin', 'admin'],
                        '⚠️ Budget Approval Needed',
                        "{$product['name']} restock requested ({$quantity} units). Est. ₱" . number_format($finalCost, 2),
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
                    'budget_request_id' => $budgetRequestId,
                ]);
                exit();
            }

            // ---- Has budget → create request as approved, ready for Supply Chain to order ----
            $stmt = $conn->prepare("
                INSERT INTO supply_chain_requests
                    (product_id, supplier_id, quantity, status, requested_by, total_cost, notes, created_at)
                VALUES (?, ?, ?, 'approved', ?, ?, ?, NOW())
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

            // Notify Supply Chain + Finance
            if (function_exists('notifyRole')) {
                notifyRole(
                    $conn,
                    ['supply_chain', 'super_admin', 'admin'],
                    '📦 Restock Approved — Ready to Order',
                    "{$product['name']} × {$quantity} from " . ($supplier['name'] ?? 'supplier'),
                    'info',
                    'info'
                );
            }

            $conn->commit();
            echo json_encode([
                'success'    => true,
                'status'     => 'approved',
                'message'    => 'Budget available. Request auto-approved — Supply Chain can now place the order.',
                'request_id' => $scRequestId,
            ]);
            exit();

        } catch (Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    // ============================================
    // PUT — update status (approve / order / receive)
    // ============================================
    if ($method === 'PUT') {
        $id     = (int)($_GET['id'] ?? ($input['id'] ?? 0));
        $status = trim((string)($input['status'] ?? ''));

        if ($id <= 0 || !$status) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'id and status are required']);
            exit();
        }

        // Allowed transitions
        $allowed = ['pending', 'approved', 'ordered', 'received', 'cancelled'];
        if (!in_array($status, $allowed, true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Invalid status: {$status}"]);
            exit();
        }

        $conn->beginTransaction();
        try {
            // Fetch current request
            $stmt = $conn->prepare("
                SELECT r.*, p.name AS product_name
                FROM supply_chain_requests r
                LEFT JOIN products p ON r.product_id = p.id
                WHERE r.id = ?
                FOR UPDATE
            ");
            $stmt->execute([$id]);
            $req = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$req) {
                throw new Exception('Supply chain request not found');
            }

            // Prevent duplicate receipt
            if ($status === 'received' && $req['status'] === 'received') {
                $conn->commit();
                echo json_encode(['success' => true, 'message' => 'Already received']);
                exit();
            }

            // Update the request row
            $approvedBy = $input['approved_by'] ?? null;
            $newNotes   = trim((string)($input['notes'] ?? ''));

            $stmt = $conn->prepare("
                UPDATE supply_chain_requests
                SET status       = ?,
                    approved_by  = COALESCE(?, approved_by),
                    notes        = CONCAT(COALESCE(notes, ''), ' ', ?),
                    received_date = IF(? = 'received', NOW(), received_date)
                WHERE id = ?
            ");
            $stmt->execute([$status, $approvedBy, $newNotes, $status, $id]);

            // If transitioning to "received", increase product stock
            if ($status === 'received' && $req['status'] !== 'received') {
                $stmt = $conn->prepare("
                    UPDATE products
                    SET stock = stock + ?
                    WHERE id = ?
                ");
                $stmt->execute([(int)$req['quantity'], (int)$req['product_id']]);

                if (function_exists('notifyRole')) {
                    notifyRole(
                        $conn,
                        ['finance', 'supply_chain', 'super_admin', 'admin'],
                        '✅ Stock Received',
                        "{$req['product_name']} × {$req['quantity']} added to inventory.",
                        'success',
                        'success'
                    );
                }
            }

            // Notify on approval
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

            // Notify on order placed
            if ($status === 'ordered' && function_exists('notifyRole')) {
                notifyRole(
                    $conn,
                    ['finance', 'super_admin', 'admin'],
                    '🚚 Purchase Order Placed',
                    "{$req['product_name']} × {$req['quantity']} ordered from supplier.",
                    'info',
                    'info'
                );
            }

            $conn->commit();
            echo json_encode([
                'success' => true,
                'message' => "Request status updated to '{$status}'",
            ]);
            exit();

        } catch (Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    // ============================================
    // Unsupported method
    // ============================================
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