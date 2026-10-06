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

// Create refunds table if not exists
$createTable = "CREATE TABLE IF NOT EXISTS refunds (
    id INT PRIMARY KEY AUTO_INCREMENT,
    customer_id INT NOT NULL,
    invoice_id INT NOT NULL,
    status ENUM('success', 'processing', 'rejected') DEFAULT 'processing',
    reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (invoice_id) REFERENCES sales(id) ON DELETE CASCADE
)";

try {
    $db->exec($createTable);
} catch (Exception $e) {
    // Table might already exist
}

switch($method) {
    case 'GET':
        try {
            $customer_id = isset($_GET['customer_id']) ? $_GET['customer_id'] : null;
            
            $query = "SELECT r.*, s.invoice_number, c.name as customer_name 
                      FROM refunds r
                      LEFT JOIN sales s ON r.invoice_id = s.id
                      LEFT JOIN customers c ON r.customer_id = c.id
                      WHERE 1=1";
            
            if ($customer_id) {
                $query .= " AND r.customer_id = :customer_id";
                $stmt = $db->prepare($query);
                $stmt->bindValue(':customer_id', $customer_id);
            } else {
                $stmt = $db->prepare($query);
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
            $query = "INSERT INTO refunds (customer_id, invoice_id, status, reason) 
                      VALUES (:customer_id, :invoice_id, :status, :reason)";
            $stmt = $db->prepare($query);
            $stmt->bindValue(':customer_id', $data['customer_id']);
            $stmt->bindValue(':invoice_id', $data['invoice_id']);
            $stmt->bindValue(':status', $data['status'] ?? 'processing');
            $stmt->bindValue(':reason', $data['reason'] ?? '');
            
            if ($stmt->execute()) {
                echo json_encode([
                    'success' => true,
                    'id' => $db->lastInsertId(),
                    'message' => 'Refund processed successfully'
                ]);
            } else {
                throw new Exception('Failed to process refund');
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;
        
    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true);
        
        try {
            $query = "UPDATE refunds SET status = :status WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindValue(':status', $data['status']);
            $stmt->bindValue(':id', $id);
            
            if ($stmt->execute()) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Refund status updated'
                ]);
            } else {
                throw new Exception('Failed to update refund');
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