<?php
// ============================================
// 📁 File: api/customers.php
// 🔧 SMART POS API - Customers
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
$status = isset($_GET['status']) ? $_GET['status'] : '';
$search = isset($_GET['search']) ? $_GET['search'] : '';

// GET request - Fetch customers
if ($method === 'GET') {
    try {
        if ($id) {
            // Get single customer
            $query = "SELECT * FROM customers WHERE id = :id";
            $stmt = $conn->prepare($query);
            $stmt->bindValue(':id', $id);
            $stmt->execute();
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($customer) {
                echo json_encode($customer);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Customer not found']);
            }
        } else {
            // Get all customers with filters
            $query = "SELECT * FROM customers WHERE 1=1";
            $params = [];
            
            if (!empty($status)) {
                $query .= " AND status = :status";
                $params[':status'] = $status;
            }
            
            if (!empty($search)) {
                $query .= " AND (name LIKE :search OR email LIKE :search OR phone LIKE :search)";
                $params[':search'] = "%$search%";
            }
            
            $query .= " ORDER BY name ASC";
            
            $stmt = $conn->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // If no results and status filter is 'archived', return empty array with sample
            if (empty($results) && $status === 'archived') {
                // Try with 'inactive' status
                $query = "SELECT * FROM customers WHERE status = 'inactive' ORDER BY name ASC";
                $stmt = $conn->prepare($query);
                $stmt->execute();
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (empty($results)) {
                    // Return empty array - frontend will show empty state
                    echo json_encode([]);
                    exit();
                }
            }
            
            echo json_encode($results);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    }
    exit();
}

// POST request - Create customer
if ($method === 'POST') {
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
            exit();
        }
        
        $name = isset($data['name']) ? trim($data['name']) : '';
        if (empty($name)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Customer name is required']);
            exit();
        }
        
        $query = "INSERT INTO customers (name, email, phone, address, status) 
                  VALUES (:name, :email, :phone, :address, 'active')";
        
        $stmt = $conn->prepare($query);
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':email', isset($data['email']) ? $data['email'] : '');
        $stmt->bindValue(':phone', isset($data['phone']) ? $data['phone'] : '');
        $stmt->bindValue(':address', isset($data['address']) ? $data['address'] : '');
        
        if ($stmt->execute()) {
            echo json_encode([
                'success' => true,
                'id' => $conn->lastInsertId(),
                'message' => 'Customer created successfully'
            ]);
        } else {
            throw new Exception('Failed to create customer');
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit();
}

// PUT request - Update customer
if ($method === 'PUT') {
    try {
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Customer ID is required']);
            exit();
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
            exit();
        }
        
        // Check if customer exists
        $checkQuery = "SELECT id FROM customers WHERE id = :id";
        $checkStmt = $conn->prepare($checkQuery);
        $checkStmt->bindValue(':id', $id);
        $checkStmt->execute();
        
        if (!$checkStmt->fetch()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Customer not found']);
            exit();
        }
        
        $updates = [];
        $params = [':id' => $id];
        
        if (isset($data['name'])) {
            $updates[] = "name = :name";
            $params[':name'] = $data['name'];
        }
        if (isset($data['email'])) {
            $updates[] = "email = :email";
            $params[':email'] = $data['email'];
        }
        if (isset($data['phone'])) {
            $updates[] = "phone = :phone";
            $params[':phone'] = $data['phone'];
        }
        if (isset($data['address'])) {
            $updates[] = "address = :address";
            $params[':address'] = $data['address'];
        }
        // ✅ FIXED: Support status update
        if (isset($data['status'])) {
            $updates[] = "status = :status";
            $params[':status'] = $data['status'];
        }
        
        if (empty($updates)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No fields to update']);
            exit();
        }
        
        $query = "UPDATE customers SET " . implode(', ', $updates) . " WHERE id = :id";
        $stmt = $conn->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        if ($stmt->execute()) {
            echo json_encode([
                'success' => true,
                'message' => 'Customer updated successfully'
            ]);
        } else {
            throw new Exception('Failed to update customer');
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit();
}

// DELETE request - Delete customer
if ($method === 'DELETE') {
    try {
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Customer ID is required']);
            exit();
        }
        
        $query = "DELETE FROM customers WHERE id = :id";
        $stmt = $conn->prepare($query);
        $stmt->bindValue(':id', $id);
        
        if ($stmt->execute()) {
            echo json_encode([
                'success' => true,
                'message' => 'Customer deleted successfully'
            ]);
        } else {
            throw new Exception('Failed to delete customer');
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit();
}

// Method not allowed
http_response_code(405);
echo json_encode([
    'success' => false,
    'message' => 'Method not allowed'
]);
exit();
?>