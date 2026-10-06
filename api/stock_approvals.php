<?php
require_once __DIR__ . '/../config/cors.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

$method = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? $_GET['id'] : null;

switch($method) {
    case 'GET':
        try {
            $status = isset($_GET['status']) ? $_GET['status'] : '';
            
            $query = "SELECT s.*, p.name as product_name, u.full_name as requested_by_name 
                      FROM stock_approvals s 
                      JOIN products p ON s.product_id = p.id 
                      LEFT JOIN users u ON s.requested_by = u.id";
            
            if ($status) {
                $query .= " WHERE s.status = :status";
            }
            
            $query .= " ORDER BY s.created_at DESC";
            
            $stmt = $db->prepare($query);
            if ($status) {
                $stmt->bindValue(':status', $status);
            }
            $stmt->execute();
            
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;
        
    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        
        try {
            $query = "INSERT INTO stock_approvals (product_id, quantity, reason, requested_by, status) 
                      VALUES (:product_id, :quantity, :reason, :requested_by, 'pending')";
            
            $stmt = $db->prepare($query);
            $stmt->bindValue(':product_id', $data['product_id']);
            $stmt->bindValue(':quantity', $data['quantity']);
            $stmt->bindValue(':reason', isset($data['reason']) ? $data['reason'] : '');
            $stmt->bindValue(':requested_by', isset($data['requested_by']) ? $data['requested_by'] : 1);
            
            if ($stmt->execute()) {
                echo json_encode([
                    'success' => true,
                    'id' => $db->lastInsertId(),
                    'message' => 'Stock request submitted successfully'
                ]);
            } else {
                throw new Exception('Failed to submit stock request');
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;
        
    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true);
        
        try {
            if (!$id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Stock ID is required']);
                exit;
            }
            
            $query = "UPDATE stock_approvals SET 
                      status = :status,
                      approved_by = :approved_by,
                      approved_at = NOW()
                      WHERE id = :id";
            
            $stmt = $db->prepare($query);
            $stmt->bindValue(':id', $id);
            $stmt->bindValue(':status', isset($data['status']) ? $data['status'] : 'approved');
            $stmt->bindValue(':approved_by', isset($data['approved_by']) ? $data['approved_by'] : 1);
            
            if ($stmt->execute()) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Stock ' . $data['status'] . ' successfully'
                ]);
            } else {
                throw new Exception('Failed to update stock');
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        break;
}
?>