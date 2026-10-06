<?php
// ✅ FIXED: use shared config so it works on Vercel + Aiven
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../config/database.php';   // ← ADDED: uses Aiven credentials + SSL

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ✅ $conn is now from config/database.php (Aiven + SSL + sql_mode fix)

$method = $_SERVER['REQUEST_METHOD'];

// ==================== GET ====================
if ($method === 'GET') {
    $id = $_GET['id'] ?? null;
    $type = $_GET['type'] ?? null;
    $category = $_GET['category'] ?? null;
    $date_from = $_GET['date_from'] ?? null;
    $date_to = $_GET['date_to'] ?? null;
    
    $sql = "SELECT * FROM transactions WHERE 1=1";
    $params = [];

    if ($id) {
        $sql .= " AND id = :id";
        $params[':id'] = $id;
    }
    if ($type) {
        $sql .= " AND type = :type";
        $params[':type'] = $type;
    }
    if ($category) {
        $sql .= " AND category = :category";
        $params[':category'] = $category;
    }
    if ($date_from) {
        $sql .= " AND date >= :date_from";
        $params[':date_from'] = $date_from;
    }
    if ($date_to) {
        $sql .= " AND date <= :date_to";
        $params[':date_to'] = $date_to;
    }

    $sql .= " ORDER BY date DESC, id DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($transactions);
    exit();
}

// ==================== POST ====================
if ($method === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);
    
    if (!$data) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid data']);
        exit();
    }
    
    $user_id = $data['user_id'] ?? 1;
    $description = $data['description'] ?? '';
    $amount = $data['amount'] ?? 0;
    $type = $data['type'] ?? '';
    $category = $data['category'] ?? '';
    $date = $data['date'] ?? date('Y-m-d');
    $status = $data['status'] ?? 'completed';
    
    if (empty($description) || empty($amount) || empty($type) || empty($category)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit();
    }
    
    $sql = "INSERT INTO transactions (user_id, description, amount, type, category, date, status) 
            VALUES (:user_id, :description, :amount, :type, :category, :date, :status)";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':user_id' => $user_id,
        ':description' => $description,
        ':amount' => $amount,
        ':type' => $type,
        ':category' => $category,
        ':date' => $date,
        ':status' => $status
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Transaction created successfully',
        'id' => $conn->lastInsertId()
    ]);
    exit();
}

// ==================== PUT ====================
if ($method === 'PUT') {
    $id = $_GET['id'] ?? null;
    
    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing ID']);
        exit();
    }
    
    $data = json_decode(file_get_contents("php://input"), true);
    
    if (!$data) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid data']);
        exit();
    }
    
    $description = $data['description'] ?? '';
    $amount = $data['amount'] ?? 0;
    $type = $data['type'] ?? '';
    $category = $data['category'] ?? '';
    $date = $data['date'] ?? date('Y-m-d');
    $status = $data['status'] ?? 'completed';
    
    $sql = "UPDATE transactions SET 
            description = :description,
            amount = :amount,
            type = :type,
            category = :category,
            date = :date,
            status = :status
            WHERE id = :id";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':description' => $description,
        ':amount' => $amount,
        ':type' => $type,
        ':category' => $category,
        ':date' => $date,
        ':status' => $status,
        ':id' => $id
    ]);
    
    echo json_encode(['success' => true, 'message' => 'Transaction updated successfully']);
    exit();
}

// ==================== DELETE ====================
if ($method === 'DELETE') {
    $id = $_GET['id'] ?? null;
    
    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing ID']);
        exit();
    }
    
    $stmt = $conn->prepare("DELETE FROM transactions WHERE id = :id");
    $stmt->execute([':id' => $id]);
    
    echo json_encode(['success' => true, 'message' => 'Transaction deleted successfully']);
    exit();
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed']);
?>