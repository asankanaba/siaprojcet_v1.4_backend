<?php
// api/supply_chain_notifications.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once '../config/database.php';

$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?: [];

try {
    $db   = new Database();
    $conn = $db->getConnection();

    switch ($method) {

        case 'GET':
            if (isset($_GET['user_id'])) {
                $stmt = $conn->prepare("
                    SELECT id, user_id, title, message, type, severity,
                           is_read, created_at,
                           NULL AS request_id
                    FROM notifications
                    WHERE user_id = ?
                    ORDER BY created_at DESC
                    LIMIT 100
                ");
                $stmt->execute([(int)$_GET['user_id']]);
                echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
                break;
            }

            $stmt = $conn->prepare("
                SELECT n.*, u.full_name AS user_name
                FROM notifications n
                LEFT JOIN users u ON n.user_id = u.id
                ORDER BY n.created_at DESC
                LIMIT 200
            ");
            $stmt->execute();
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        case 'POST':
            if (empty($input['user_id']) || empty($input['message'])) {
                throw new Exception('user_id and message are required');
            }

            $title    = $input['title'] ?? 'Notification';
            $message  = $input['message'];
            $type     = in_array($input['type'] ?? '', ['success','info','warning']) ? $input['type'] : 'info';
            $severity = in_array($input['severity'] ?? '', ['info','success','warning','error']) ? $input['severity'] : 'info';

            $stmt = $conn->prepare("
                INSERT INTO notifications
                    (user_id, title, message, type, severity, is_read, created_at)
                VALUES (?, ?, ?, ?, ?, 0, NOW())
            ");
            $stmt->execute([
                (int)$input['user_id'],
                $title,
                $message,
                $type,
                $severity
            ]);

            $id = $conn->lastInsertId();
            $stmt = $conn->prepare("SELECT * FROM notifications WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
            break;

        case 'PUT':
            $id = $_GET['id'] ?? null;
            if (!$id) throw new Exception('ID required');
            $isRead = isset($input['is_read']) ? (int)$input['is_read'] : 1;
            $stmt = $conn->prepare("UPDATE notifications SET is_read = ? WHERE id = ?");
            $stmt->execute([$isRead, (int)$id]);
            echo json_encode(['success' => true]);
            break;

        case 'DELETE':
            $id = $_GET['id'] ?? null;
            if (!$id) throw new Exception('ID required');
            $stmt = $conn->prepare("DELETE FROM notifications WHERE id = ?");
            $stmt->execute([(int)$id]);
            echo json_encode(['success' => true]);
            break;
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}