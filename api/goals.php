<?php
// ============================================
// 📁 File: api/goals.php
// 🔧 SMART POS API - Financial Goals
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
$status = isset($_GET['status']) ? $_GET['status'] : null;

// GET request - Fetch goals
if ($method === 'GET') {
    try {
        if ($id) {
            $query = "SELECT g.*, u.username 
                      FROM goals g
                      LEFT JOIN users u ON g.user_id = u.id
                      WHERE g.id = :id";
            $stmt = $conn->prepare($query);
            $stmt->bindValue(':id', $id);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                echo json_encode($result);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Goal not found']);
            }
        } else {
            $query = "SELECT g.*, u.username 
                      FROM goals g
                      LEFT JOIN users u ON g.user_id = u.id
                      WHERE 1=1";
            
            $params = [];
            
            if ($user_id) {
                $query .= " AND g.user_id = :user_id";
                $params[':user_id'] = $user_id;
            }
            if ($status) {
                $query .= " AND g.status = :status";
                $params[':status'] = $status;
            }
            
            $query .= " ORDER BY g.created_at DESC";
            
            $stmt = $conn->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // If no results, return empty array
            if (empty($results)) {
                echo json_encode([]);
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

// POST request - Create goal
if ($method === 'POST') {
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
            exit();
        }
        
        $user_id = isset($data['user_id']) ? $data['user_id'] : 1;
        $title = isset($data['title']) ? trim($data['title']) : '';
        $target_amount = isset($data['target_amount']) ? floatval($data['target_amount']) : 0;
        $saved_amount = isset($data['saved_amount']) ? floatval($data['saved_amount']) : 0;
        $target_date = isset($data['target_date']) ? $data['target_date'] : date('Y-m-d', strtotime('+1 year'));
        $status = isset($data['status']) ? $data['status'] : 'in_progress';
        
        if (empty($title)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Goal title is required']);
            exit();
        }
        
        if ($target_amount <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Target amount must be greater than 0']);
            exit();
        }
        
        $query = "INSERT INTO goals (user_id, title, target_amount, saved_amount, target_date, status) 
                  VALUES (:user_id, :title, :target_amount, :saved_amount, :target_date, :status)";
        
        $stmt = $conn->prepare($query);
        $stmt->bindValue(':user_id', $user_id);
        $stmt->bindValue(':title', $title);
        $stmt->bindValue(':target_amount', $target_amount);
        $stmt->bindValue(':saved_amount', $saved_amount);
        $stmt->bindValue(':target_date', $target_date);
        $stmt->bindValue(':status', $status);
        
        if ($stmt->execute()) {
            echo json_encode([
                'success' => true,
                'id' => $conn->lastInsertId(),
                'message' => 'Goal created successfully'
            ]);
        } else {
            throw new Exception('Failed to create goal');
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

// PUT request - Update goal
if ($method === 'PUT') {
    try {
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Goal ID is required']);
            exit();
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
            exit();
        }
        
        // Check if goal exists
        $checkQuery = "SELECT id FROM goals WHERE id = :id";
        $checkStmt = $conn->prepare($checkQuery);
        $checkStmt->bindValue(':id', $id);
        $checkStmt->execute();
        
        if (!$checkStmt->fetch()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Goal not found']);
            exit();
        }
        
        $updates = [];
        $params = [':id' => $id];
        
        if (isset($data['title'])) {
            $updates[] = "title = :title";
            $params[':title'] = $data['title'];
        }
        if (isset($data['target_amount'])) {
            $updates[] = "target_amount = :target_amount";
            $params[':target_amount'] = $data['target_amount'];
        }
        if (isset($data['saved_amount'])) {
            $updates[] = "saved_amount = :saved_amount";
            $params[':saved_amount'] = $data['saved_amount'];
        }
        if (isset($data['target_date'])) {
            $updates[] = "target_date = :target_date";
            $params[':target_date'] = $data['target_date'];
        }
        if (isset($data['status'])) {
            $updates[] = "status = :status";
            $params[':status'] = $data['status'];
        }
        
        if (empty($updates)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No fields to update']);
            exit();
        }
        
        $query = "UPDATE goals SET " . implode(', ', $updates) . " WHERE id = :id";
        $stmt = $conn->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Goal updated successfully']);
        } else {
            throw new Exception('Failed to update goal');
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

// DELETE request - Delete goal
if ($method === 'DELETE') {
    try {
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Goal ID is required']);
            exit();
        }
        
        $query = "DELETE FROM goals WHERE id = :id";
        $stmt = $conn->prepare($query);
        $stmt->bindValue(':id', $id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Goal deleted successfully']);
        } else {
            throw new Exception('Failed to delete goal');
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