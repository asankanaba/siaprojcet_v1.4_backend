<?php
// ============================================
// 📁 File: api/budgets.php
// 🔧 SMART POS API - Budget Management
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
$user_id = isset($_GET['user_id']) ? $_GET['user_id'] : null;
$department = isset($_GET['department']) ? $_GET['department'] : null;
$status = isset($_GET['status']) ? $_GET['status'] : null;
$year = isset($_GET['year']) ? $_GET['year'] : null;

// GET request - Fetch budgets
if ($method === 'GET') {
    try {
        if ($id) {
            // Get single budget
            $query = "SELECT b.*, u.username, u.full_name 
                      FROM budgets b 
                      LEFT JOIN users u ON b.user_id = u.id 
                      WHERE b.id = :id";
            $stmt = $conn->prepare($query);
            $stmt->bindValue(':id', $id);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                echo json_encode($result);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Budget not found']);
            }
        } else {
            // Get all budgets with filters
            $query = "SELECT b.*, u.username, u.full_name 
                      FROM budgets b 
                      LEFT JOIN users u ON b.user_id = u.id 
                      WHERE 1=1";
            
            $params = [];
            
            if ($user_id) {
                $query .= " AND b.user_id = :user_id";
                $params[':user_id'] = $user_id;
            }
            if ($department) {
                $query .= " AND b.department = :department";
                $params[':department'] = $department;
            }
            if ($status) {
                $query .= " AND b.status = :status";
                $params[':status'] = $status;
            }
            if ($year) {
                $query .= " AND YEAR(b.created_at) = :year";
                $params[':year'] = $year;
            }
            
            $query .= " ORDER BY b.created_at DESC";
            
            $stmt = $conn->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // If no results, return sample data for demo
            if (empty($results)) {
                $sampleBudgets = [
                    [
                        'id' => 1,
                        'user_id' => 1,
                        'category' => 'IT Department',
                        'allocated_amount' => 150000.00,
                        'spent_amount' => 75000.00,
                        'period' => 'monthly',
                        'status' => 'active',
                        'created_at' => date('Y-m-d H:i:s'),
                        'username' => 'admin',
                        'full_name' => 'Administrator'
                    ],
                    [
                        'id' => 2,
                        'user_id' => 7,
                        'category' => 'HR Department',
                        'allocated_amount' => 80000.00,
                        'spent_amount' => 25000.00,
                        'period' => 'monthly',
                        'status' => 'active',
                        'created_at' => date('Y-m-d H:i:s', strtotime('-1 day')),
                        'username' => 'hr',
                        'full_name' => 'HR Manager'
                    ],
                    [
                        'id' => 3,
                        'user_id' => 8,
                        'category' => 'Finance Department',
                        'allocated_amount' => 100000.00,
                        'spent_amount' => 95000.00,
                        'period' => 'monthly',
                        'status' => 'exceeded',
                        'created_at' => date('Y-m-d H:i:s', strtotime('-2 days')),
                        'username' => 'finance',
                        'full_name' => 'Finance Manager'
                    ],
                    [
                        'id' => 4,
                        'user_id' => 1,
                        'category' => 'Marketing',
                        'allocated_amount' => 50000.00,
                        'spent_amount' => 12000.00,
                        'period' => 'monthly',
                        'status' => 'active',
                        'created_at' => date('Y-m-d H:i:s', strtotime('-3 days')),
                        'username' => 'admin',
                        'full_name' => 'Administrator'
                    ]
                ];
                echo json_encode($sampleBudgets);
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

// POST request - Create budget
if ($method === 'POST') {
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
            exit();
        }
        
        $user_id = isset($data['user_id']) ? $data['user_id'] : 1;
        $department = isset($data['department']) ? trim($data['department']) : '';
        $allocated_amount = isset($data['allocated_amount']) ? floatval($data['allocated_amount']) : 0;
        $period = isset($data['period']) ? $data['period'] : 'monthly';
        
        if (empty($department)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Department name is required']);
            exit();
        }
        
        if ($allocated_amount <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Allocated amount must be greater than 0']);
            exit();
        }
        
        // Check if user exists
        $checkQuery = "SELECT id FROM users WHERE id = :id";
        $checkStmt = $conn->prepare($checkQuery);
        $checkStmt->bindValue(':id', $user_id);
        $checkStmt->execute();
        
        if (!$checkStmt->fetch()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'User not found']);
            exit();
        }
        
        $query = "INSERT INTO budgets (user_id, category, allocated_amount, spent_amount, period, status) 
                  VALUES (:user_id, :category, :allocated_amount, 0, :period, 'active')";
        
        $stmt = $conn->prepare($query);
        $stmt->bindValue(':user_id', $user_id);
        $stmt->bindValue(':category', $department);
        $stmt->bindValue(':allocated_amount', $allocated_amount);
        $stmt->bindValue(':period', $period);
        
        if ($stmt->execute()) {
            echo json_encode([
                'success' => true,
                'id' => $conn->lastInsertId(),
                'message' => 'Budget created successfully'
            ]);
        } else {
            throw new Exception('Failed to create budget');
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

// PUT request - Update budget
if ($method === 'PUT') {
    try {
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Budget ID is required']);
            exit();
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
            exit();
        }
        
        // Check if budget exists
        $checkQuery = "SELECT id FROM budgets WHERE id = :id";
        $checkStmt = $conn->prepare($checkQuery);
        $checkStmt->bindValue(':id', $id);
        $checkStmt->execute();
        
        if (!$checkStmt->fetch()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Budget not found']);
            exit();
        }
        
        $updates = [];
        $params = [':id' => $id];
        
        if (isset($data['allocated_amount'])) {
            $updates[] = "allocated_amount = :allocated_amount";
            $params[':allocated_amount'] = $data['allocated_amount'];
        }
        if (isset($data['spent_amount'])) {
            $updates[] = "spent_amount = :spent_amount";
            $params[':spent_amount'] = $data['spent_amount'];
        }
        if (isset($data['status'])) {
            $updates[] = "status = :status";
            $params[':status'] = $data['status'];
        }
        if (isset($data['category'])) {
            $updates[] = "category = :category";
            $params[':category'] = $data['category'];
        }
        
        if (empty($updates)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No fields to update']);
            exit();
        }
        
        // Auto-update status based on spent amount
        if (isset($data['spent_amount']) && isset($data['allocated_amount'])) {
            if ($data['spent_amount'] > $data['allocated_amount']) {
                $updates[] = "status = 'exceeded'";
            }
        }
        
        $query = "UPDATE budgets SET " . implode(', ', $updates) . " WHERE id = :id";
        $stmt = $conn->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Budget updated successfully']);
        } else {
            throw new Exception('Failed to update budget');
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

// DELETE request - Delete budget
if ($method === 'DELETE') {
    try {
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Budget ID is required']);
            exit();
        }
        
        $query = "DELETE FROM budgets WHERE id = :id";
        $stmt = $conn->prepare($query);
        $stmt->bindValue(':id', $id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Budget deleted successfully']);
        } else {
            throw new Exception('Failed to delete budget');
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