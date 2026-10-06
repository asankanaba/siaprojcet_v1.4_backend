<?php
// api/supply_chain.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once __DIR__ . '/../config/database.php';

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    switch($method) {
        case 'GET':
            if (isset($_GET['product_id'])) {
                $stmt = $conn->prepare("
                    SELECT id, name, stock, low_stock_threshold 
                    FROM products 
                    WHERE id = ?
                ");
                $stmt->execute([$_GET['product_id']]);
                $product = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $needsRestock = $product['stock'] <= $product['low_stock_threshold'];
                
                echo json_encode([
                    'success' => true,
                    'data' => [
                        'product' => $product,
                        'needs_restock' => $needsRestock,
                        'stock_status' => $needsRestock ? 'low' : 'adequate'
                    ]
                ]);
            } elseif (isset($_GET['low_stock'])) {
                $stmt = $conn->prepare("
                    SELECT p.*, s.id as supplier_id, s.name as supplier_name, 
                           s.stock_available as supplier_stock, s.price_per_unit
                    FROM products p
                    LEFT JOIN suppliers s ON p.id = s.product_id
                    WHERE p.stock <= p.low_stock_threshold AND p.status = 'active'
                    ORDER BY p.stock ASC
                ");
                $stmt->execute();
                $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'data' => $result]);
            } else {
                $stmt = $conn->prepare("
                    SELECT r.*, p.name as product_name, s.name as supplier_name,
                           u.full_name as requester_name
                    FROM supply_chain_requests r
                    LEFT JOIN products p ON r.product_id = p.id
                    LEFT JOIN suppliers s ON r.supplier_id = s.id
                    LEFT JOIN users u ON r.requested_by = u.id
                    ORDER BY r.created_at DESC
                ");
                $stmt->execute();
                $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'data' => $result]);
            }
            break;
            
        case 'POST':
            $productId = $input['product_id'];
            $quantity = $input['quantity'];
            $requestedBy = $input['requested_by'] ?? 1;
            
            $conn->beginTransaction();
            
            try {
                $stmt = $conn->prepare("SELECT id, name, stock, cost FROM products WHERE id = ?");
                $stmt->execute([$productId]);
                $product = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$product) {
                    throw new Exception('Product not found');
                }
                
                if ($product['stock'] >= $quantity) {
                    echo json_encode([
                        'success' => true,
                        'status' => 'available',
                        'message' => 'Stock is sufficient',
                        'stock' => $product['stock']
                    ]);
                    return;
                }
                
                $stmt = $conn->prepare("
                    SELECT allocated_amount, spent_amount 
                    FROM budgets 
                    WHERE category = 'Supply Chain' OR category = 'Operations'
                    ORDER BY id DESC LIMIT 1
                ");
                $stmt->execute();
                $budget = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $totalCost = ($product['cost'] ?? 0) * $quantity;
                $hasBudget = $budget && ($budget['allocated_amount'] - $budget['spent_amount'] >= $totalCost);
                
                if (!$hasBudget) {
                    $stmt = $conn->prepare("
                        INSERT INTO budget_requests 
                        (requested_by, department, purpose, amount, status)
                        VALUES (?, 'Supply Chain', ?, ?, 'pending')
                    ");
                    $stmt->execute([
                        $requestedBy,
                        "Purchase {$quantity} units of {$product['name']} - Budget needed",
                        $totalCost
                    ]);
                    
                    $conn->commit();
                    echo json_encode([
                        'success' => true,
                        'status' => 'pending_approval',
                        'message' => 'Budget request created. Waiting for finance approval.',
                        'request_id' => $conn->lastInsertId()
                    ]);
                    return;
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
                
                if (!$supplier) {
                    throw new Exception('No active supplier found for this product');
                }
                
                if ($supplier['stock_available'] < $quantity) {
                    $stmt = $conn->prepare("
                        INSERT INTO supply_chain_requests 
                        (product_id, supplier_id, quantity, reason, status, requested_by, total_cost, notes)
                        VALUES (?, ?, ?, ?, 'pending', ?, ?, ?)
                    ");
                    $stmt->execute([
                        $productId,
                        $supplier['id'],
                        $quantity,
                        "Supplier stock insufficient: {$supplier['stock_available']} available",
                        $requestedBy,
                        $supplier['price_per_unit'] * $quantity,
                        "Supplier needs to restock. Lead time: {$supplier['lead_time_days']} days"
                    ]);
                    
                    $conn->commit();
                    echo json_encode([
                        'success' => true,
                        'status' => 'supplier_insufficient',
                        'message' => 'Supplier does not have enough stock. Order placed for restock.',
                        'request_id' => $conn->lastInsertId()
                    ]);
                    return;
                }
                
                $stmt = $conn->prepare("
                    UPDATE suppliers 
                    SET stock_available = stock_available - ? 
                    WHERE id = ?
                ");
                $stmt->execute([$quantity, $supplier['id']]);
                
                $stmt = $conn->prepare("
                    UPDATE products 
                    SET stock = stock + ? 
                    WHERE id = ?
                ");
                $stmt->execute([$quantity, $productId]);
                
                $stmt = $conn->prepare("
                    INSERT INTO supply_chain_requests 
                    (product_id, supplier_id, quantity, status, requested_by, total_cost, order_date)
                    VALUES (?, ?, ?, 'received', ?, ?, NOW())
                ");
                $stmt->execute([
                    $productId,
                    $supplier['id'],
                    $quantity,
                    $requestedBy,
                    $supplier['price_per_unit'] * $quantity
                ]);
                
                $conn->commit();
                echo json_encode([
                    'success' => true,
                    'status' => 'ordered',
                    'message' => 'Order placed successfully! Stock has been updated.',
                    'supplier' => $supplier['name'],
                    'quantity' => $quantity,
                    'total_cost' => $supplier['price_per_unit'] * $quantity
                ]);
                
            } catch (Exception $e) {
                $conn->rollBack();
                throw $e;
            }
            break;
            
        case 'PUT':
            $stmt = $conn->prepare("
                UPDATE supply_chain_requests 
                SET status = ?, approved_by = ?, notes = CONCAT(notes, ' ', ?), 
                    received_date = IF(? = 'received', NOW(), received_date)
                WHERE id = ?
            ");
            
            $stmt->execute([
                $input['status'],
                $input['approved_by'] ?? null,
                $input['notes'] ?? '',
                $input['status'],
                $input['id']
            ]);
            
            if ($input['status'] === 'received') {
                $stmt = $conn->prepare("
                    SELECT product_id, quantity FROM supply_chain_requests WHERE id = ?
                ");
                $stmt->execute([$input['id']]);
                $request = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($request) {
                    $stmt = $conn->prepare("
                        UPDATE products 
                        SET stock = stock + ? 
                        WHERE id = ?
                    ");
                    $stmt->execute([$request['quantity'], $request['product_id']]);
                }
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Supply chain request updated successfully'
            ]);
            break;
    }
} catch(Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}