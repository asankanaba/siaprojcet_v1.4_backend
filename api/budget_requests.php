<?php
// ============================================
// 📁 File: api/budget_requests.php
// 🔧 SMART POS API - Budget Requests
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
$status = isset($_GET['status']) ? $_GET['status'] : null;
$department = isset($_GET['department']) ? $_GET['department'] : null;

// GET request - Fetch budget requests
if ($method === 'GET') {
    try {
        if ($id) {
            $query = "SELECT br.*, u.username as requested_by_name, u.full_name 
                      FROM budget_requests br
                      LEFT JOIN users u ON br.requested_by = u.id
                      WHERE br.id = :id";
            $stmt = $conn->prepare($query);
            $stmt->bindValue(':id', $id);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                echo json_encode($result);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Budget request not found']);
            }
        } else {
            $query = "SELECT br.*, u.username as requested_by_name, u.full_name 
                      FROM budget_requests br
                      LEFT JOIN users u ON br.requested_by = u.id
                      WHERE 1=1";
            
            $params = [];
            
            if ($status) {
                $query .= " AND br.status = :status";
                $params[':status'] = $status;
            }
            if ($department) {
                $query .= " AND br.department = :department";
                $params[':department'] = $department;
            }
            
            $query .= " ORDER BY br.created_at DESC";
            
            $stmt = $conn->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($results)) {
                $sampleData = [
                    [
                        'id' => 1,
                        'department' => 'Secret',
                        'amount' => 500000.00,
                        'purpose' => 'secret',
                        'status' => 'approved',
                        'requested_by_name' => 'HR Manager',
                        'created_at' => date('Y-m-d H:i:s')
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

// POST request - Create budget request (HR submits)
if ($method === 'POST') {
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
            exit();
        }
        
        $department = isset($data['department']) ? trim($data['department']) : '';
        $amount = isset($data['amount']) ? floatval($data['amount']) : 0;
        $purpose = isset($data['purpose']) ? $data['purpose'] : '';
        $requested_by = isset($data['requested_by']) ? $data['requested_by'] : 1;
        
        if (empty($department)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Department is required']);
            exit();
        }
        
        if ($amount <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Amount must be greater than 0']);
            exit();
        }
        
        if (empty($purpose)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Purpose is required']);
            exit();
        }
        
        $query = "INSERT INTO budget_requests (department, amount, purpose, requested_by, status) 
                  VALUES (:department, :amount, :purpose, :requested_by, 'pending')";
        
        $stmt = $conn->prepare($query);
        $stmt->bindValue(':department', $department);
        $stmt->bindValue(':amount', $amount);
        $stmt->bindValue(':purpose', $purpose);
        $stmt->bindValue(':requested_by', $requested_by);
        
        if ($stmt->execute()) {
            echo json_encode([
                'success' => true,
                'id' => $conn->lastInsertId(),
                'message' => 'Budget request submitted successfully'
            ]);
        } else {
            throw new Exception('Failed to submit budget request');
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

// PUT request - Update budget request (For Finance to approve/reject)
if ($method === 'PUT') {
    try {
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Request ID is required']);
            exit();
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
            exit();
        }
        
        $status = isset($data['status']) ? $data['status'] : null;
        
        if (!$status) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Status is required']);
            exit();
        }
        
        $checkQuery = "SELECT id FROM budget_requests WHERE id = :id";
        $checkStmt = $conn->prepare($checkQuery);
        $checkStmt->bindValue(':id', $id);
        $checkStmt->execute();
        
        if (!$checkStmt->fetch()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Budget request not found']);
            exit();
        }
        
        $query = "UPDATE budget_requests SET status = :status WHERE id = :id";
        $stmt = $conn->prepare($query);
        $stmt->bindValue(':status', $status);
        $stmt->bindValue(':id', $id);
        
        if ($stmt->execute()) {
            echo json_encode([
                'success' => true,
                'message' => 'Budget request ' . $status . ' successfully'
            ]);
        } else {
            throw new Exception('Failed to update budget request');
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