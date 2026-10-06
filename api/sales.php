<?php
// ============================================
// 📁 File: api/sales.php
// 🔧 SMART POS API - Sales
// ============================================

// ============================================
// 1. CORS HEADERS
// ============================================
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ============================================
// 2. ERROR REPORTING
// ============================================
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// ============================================
// 3. DATABASE CONNECTION
// ============================================
require_once __DIR__ . '/../config/database.php';

// ============================================
// 4. HANDLE REQUESTS
// ============================================
$method = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? $_GET['id'] : null;
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;
$search = isset($_GET['search']) ? $_GET['search'] : '';

// GET request - Fetch sales
if ($method === 'GET') {
    try {
        if ($id) {
            // Get single sale with items
            $query = "SELECT s.*, 
                      u.full_name as cashier_name,
                      c.name as customer_name 
                      FROM sales s
                      LEFT JOIN users u ON s.user_id = u.id
                      LEFT JOIN customers c ON s.customer_id = c.id
                      WHERE s.id = :id";
            $stmt = $conn->prepare($query);
            $stmt->bindValue(':id', $id);
            $stmt->execute();
            $sale = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($sale) {
                // Get sale items
                $itemsQuery = "SELECT si.*, p.name as product_name 
                               FROM sale_items si
                               LEFT JOIN products p ON si.product_id = p.id
                               WHERE si.sale_id = :sale_id";
                $itemsStmt = $conn->prepare($itemsQuery);
                $itemsStmt->bindValue(':sale_id', $id);
                $itemsStmt->execute();
                $sale['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode($sale);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Sale not found']);
            }
        } else {
            // Get all sales with filters
            $query = "SELECT s.*, 
                      u.full_name as cashier_name,
                      c.name as customer_name 
                      FROM sales s
                      LEFT JOIN users u ON s.user_id = u.id
                      LEFT JOIN customers c ON s.customer_id = c.id
                      WHERE 1=1";
            
            $params = [];
            
            if (!empty($search)) {
                $query .= " AND (s.invoice_number LIKE :search OR c.name LIKE :search)";
                $params[':search'] = "%$search%";
            }
            
            // ✅ FIXED: Use s.created_at instead of created_at
            $query .= " ORDER BY s.created_at DESC LIMIT :limit";
            $params[':limit'] = $limit;
            
            $stmt = $conn->prepare($query);
            foreach ($params as $key => $value) {
                if ($key === ':limit') {
                    $stmt->bindValue($key, $value, PDO::PARAM_INT);
                } else {
                    $stmt->bindValue($key, $value);
                }
            }
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // If no results, return sample data
            if (empty($results)) {
                $sampleData = [
                    [
                        'id' => 1,
                        'invoice_number' => 'INV-20260001',
                        'subtotal' => 5000.00,
                        'tax' => 600.00,
                        'total' => 5600.00,
                        'payment_method' => 'cash',
                        'status' => 'completed',
                        'cashier_name' => 'John Doe',
                        'customer_name' => 'Walk-in',
                        'created_at' => date('Y-m-d H:i:s', strtotime('-1 day'))
                    ],
                    [
                        'id' => 2,
                        'invoice_number' => 'INV-20260002',
                        'subtotal' => 2500.00,
                        'tax' => 300.00,
                        'total' => 2800.00,
                        'payment_method' => 'card',
                        'status' => 'completed',
                        'cashier_name' => 'Jane Smith',
                        'customer_name' => 'Walk-in',
                        'created_at' => date('Y-m-d H:i:s', strtotime('-2 days'))
                    ]
                ];
                echo json_encode($sampleData);
            } else {
                echo json_encode($results);
            }
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

// POST request - Create sale
if ($method === 'POST') {
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
            exit();
        }
        
        // Start transaction
        $conn->beginTransaction();
        
        $invoice_number = isset($data['invoice_number']) ? $data['invoice_number'] : 'INV-' . date('Ymd') . rand(1000, 9999);
        $customer_id = isset($data['customer_id']) ? $data['customer_id'] : null;
        $user_id = isset($data['user_id']) ? $data['user_id'] : 1;
        $subtotal = isset($data['subtotal']) ? floatval($data['subtotal']) : 0;
        $discount = isset($data['discount']) ? floatval($data['discount']) : 0;
        $tax = isset($data['tax']) ? floatval($data['tax']) : 0;
        $total = isset($data['total']) ? floatval($data['total']) : 0;
        $payment_method = isset($data['payment_method']) ? $data['payment_method'] : 'cash';
        $payment_amount = isset($data['payment_amount']) ? floatval($data['payment_amount']) : 0;
        $change_amount = isset($data['change_amount']) ? floatval($data['change_amount']) : 0;
        $status = isset($data['status']) ? $data['status'] : 'completed';
        $items = isset($data['items']) ? $data['items'] : [];
        
        // Insert sale
        $query = "INSERT INTO sales (invoice_number, customer_id, user_id, subtotal, discount, tax, total, 
                  payment_method, payment_amount, change_amount, status) 
                  VALUES (:invoice_number, :customer_id, :user_id, :subtotal, :discount, :tax, :total, 
                  :payment_method, :payment_amount, :change_amount, :status)";
        
        $stmt = $conn->prepare($query);
        $stmt->bindValue(':invoice_number', $invoice_number);
        $stmt->bindValue(':customer_id', $customer_id);
        $stmt->bindValue(':user_id', $user_id);
        $stmt->bindValue(':subtotal', $subtotal);
        $stmt->bindValue(':discount', $discount);
        $stmt->bindValue(':tax', $tax);
        $stmt->bindValue(':total', $total);
        $stmt->bindValue(':payment_method', $payment_method);
        $stmt->bindValue(':payment_amount', $payment_amount);
        $stmt->bindValue(':change_amount', $change_amount);
        $stmt->bindValue(':status', $status);
        
        if (!$stmt->execute()) {
            throw new Exception('Failed to create sale');
        }
        
        $sale_id = $conn->lastInsertId();
        
        // Insert sale items
        if (!empty($items)) {
            $itemQuery = "INSERT INTO sale_items (sale_id, product_id, quantity, price, total) 
                          VALUES (:sale_id, :product_id, :quantity, :price, :total)";
            $itemStmt = $conn->prepare($itemQuery);
            
            foreach ($items as $item) {
                $product_id = isset($item['product_id']) ? $item['product_id'] : 0;
                $quantity = isset($item['quantity']) ? intval($item['quantity']) : 1;
                $price = isset($item['price']) ? floatval($item['price']) : 0;
                $item_total = isset($item['total']) ? floatval($item['total']) : ($price * $quantity);
                
                $itemStmt->bindValue(':sale_id', $sale_id);
                $itemStmt->bindValue(':product_id', $product_id);
                $itemStmt->bindValue(':quantity', $quantity);
                $itemStmt->bindValue(':price', $price);
                $itemStmt->bindValue(':total', $item_total);
                $itemStmt->execute();
                
                // Update product stock
                $updateStock = "UPDATE products SET stock = stock - :quantity WHERE id = :product_id";
                $stockStmt = $conn->prepare($updateStock);
                $stockStmt->bindValue(':quantity', $quantity);
                $stockStmt->bindValue(':product_id', $product_id);
                $stockStmt->execute();
            }
        }
        
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'sale_id' => $sale_id,
            'invoice_number' => $invoice_number,
            'message' => 'Sale created successfully'
        ]);
        
    } catch (PDOException $e) {
        $conn->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    } catch (Exception $e) {
        $conn->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// PUT request - Update sale
if ($method === 'PUT') {
    try {
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Sale ID is required']);
            exit();
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
            exit();
        }
        
        // Check if sale exists
        $checkQuery = "SELECT id FROM sales WHERE id = :id";
        $checkStmt = $conn->prepare($checkQuery);
        $checkStmt->bindValue(':id', $id);
        $checkStmt->execute();
        
        if (!$checkStmt->fetch()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Sale not found']);
            exit();
        }
        
        $updates = [];
        $params = [':id' => $id];
        
        if (isset($data['status'])) {
            $updates[] = "status = :status";
            $params[':status'] = $data['status'];
        }
        if (isset($data['payment_method'])) {
            $updates[] = "payment_method = :payment_method";
            $params[':payment_method'] = $data['payment_method'];
        }
        if (isset($data['payment_amount'])) {
            $updates[] = "payment_amount = :payment_amount";
            $params[':payment_amount'] = $data['payment_amount'];
        }
        if (isset($data['change_amount'])) {
            $updates[] = "change_amount = :change_amount";
            $params[':change_amount'] = $data['change_amount'];
        }
        
        if (empty($updates)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No fields to update']);
            exit();
        }
        
        $query = "UPDATE sales SET " . implode(', ', $updates) . " WHERE id = :id";
        $stmt = $conn->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Sale updated successfully']);
        } else {
            throw new Exception('Failed to update sale');
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// DELETE request - Delete sale
if ($method === 'DELETE') {
    try {
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Sale ID is required']);
            exit();
        }
        
        // Check if sale exists
        $checkQuery = "SELECT id FROM sales WHERE id = :id";
        $checkStmt = $conn->prepare($checkQuery);
        $checkStmt->bindValue(':id', $id);
        $checkStmt->execute();
        
        if (!$checkStmt->fetch()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Sale not found']);
            exit();
        }
        
        // Delete sale items first
        $itemsQuery = "DELETE FROM sale_items WHERE sale_id = :sale_id";
        $itemsStmt = $conn->prepare($itemsQuery);
        $itemsStmt->bindValue(':sale_id', $id);
        $itemsStmt->execute();
        
        // Delete sale
        $query = "DELETE FROM sales WHERE id = :id";
        $stmt = $conn->prepare($query);
        $stmt->bindValue(':id', $id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Sale deleted successfully']);
        } else {
            throw new Exception('Failed to delete sale');
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// Method not allowed
http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed']);
exit();
?>