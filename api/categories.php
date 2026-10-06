<?php
// ✅ FINAL CORS FIX: Authorization header is now allowed
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ✅ ABSOLUTE PATH FIX (Matches your XAMPP structure)
require_once 'C:/xampp/htdocs/smart-pos-api/config/database.php';

$method = $_SERVER['REQUEST_METHOD'];

// ============================================
// GET: Fetch all categories
// ============================================
if ($method === 'GET') {
    try {
        $sql = "SELECT * FROM categories ORDER BY name ASC";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($categories);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

// ============================================
// POST: Create new category
// ============================================
if ($method === 'POST') {
    try {
        // Handle both JSON and FormData
        if (!empty($_POST)) {
            $data = $_POST;
        } else {
            $data = json_decode(file_get_contents("php://input"), true);
        }
        
        $name = isset($data['name']) ? trim($data['name']) : '';
        
        if (empty($name)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Category name is required']);
            exit();
        }
        
        $sql = "INSERT INTO categories (name) VALUES (:name)";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':name', $name);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'id' => $conn->lastInsertId(), 'message' => 'Category created']);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to create category']);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
?>