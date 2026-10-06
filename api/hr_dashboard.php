<?php
require_once __DIR__ . '/../config/cors.php';
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

$host = 'localhost';
$db_name = 'smart_pos';
$username = 'root';
$password = ''; // ⚠️ CHANGE THIS IF YOUR XAMPP HAS A PASSWORD

try {
    $conn = new PDO("mysql:host=$host;dbname=$db_name", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $sql = "SELECT * FROM jobs ORDER BY created_at DESC LIMIT 10";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $pipeline = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($pipeline) === 0) {
        $pipeline = [
            ['id' => 1, 'title' => 'Senior Backend Engineer', 'lead' => 3, 'applicants' => 0, 'first_interview' => 2, 'second_interview' => 2, 'final_interview' => 1, 'offer' => 1, 'status' => 'in_progress', 'department' => 'engineering'],
            ['id' => 2, 'title' => 'Frontend Engineer', 'lead' => 4, 'applicants' => 7, 'first_interview' => 5, 'second_interview' => 1, 'final_interview' => 0, 'offer' => 0, 'status' => 'open', 'department' => 'frontend']
        ];
    }

    $sql = "SELECT a.*, u.full_name 
            FROM hr_activity_logs a 
            LEFT JOIN users u ON a.user_id = u.id 
            ORDER BY a.created_at DESC LIMIT 5";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'pipeline' => $pipeline,
        'activities' => $activities
    ]);
    exit();
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);
    
    $user_id = $data['user_id'] ?? null;
    $action = $data['action'] ?? '';
    $type = $data['type'] ?? 'application';

    if (!$user_id || !$action) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing user_id or action.']);
        exit();
    }

    // 🟢 This is the actual writing to the database
    $sql = "INSERT INTO hr_activity_logs (user_id, action, type) VALUES (:user_id, :action, :type)";
    $stmt = $conn->prepare($sql);

    // If this fails, it will throw a PDO Exception which we catch and return to the screen
    try {
        $stmt->execute([
            ':user_id' => $user_id,
            ':action' => $action,
            ':type' => $type
        ]);
        echo json_encode(['success' => true, 'message' => 'Activity logged successfully']);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database Insert Error: ' . $e->getMessage()]);
    }
    exit();
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed']);
?>