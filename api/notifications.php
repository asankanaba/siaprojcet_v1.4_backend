<?php
// ============================================
// 📁 File: api/notifications.php
// 🔧 SMART POS API - JWT-Secured Notifications
// ============================================

date_default_timezone_set('Asia/Manila');

// ============================================
// 1. CORS HEADERS
// ============================================
$allowed_origins = [
    'http://localhost:5173',
    'http://localhost:3000',
    'https://smartpossiaaaa.kesug.com',
    'https://smartpos.netlify.app',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    header("Access-Control-Allow-Origin: " . ($origin ?: '*'));
}
header("Vary: Origin");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Max-Age: 86400");
header("Content-Type: application/json");

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
// 3. DB + JWT + HELPERS
// ============================================
require_once __DIR__ . '/../config/database.php';

// ============================================
// 4. AUTHENTICATE (JWT)
// ============================================
$authUser = getAuthUser();
if (!$authUser || empty($authUser['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Please login again.']);
    exit();
}

$currentUserId = (int)$authUser['user_id'];
$currentRole   = $authUser['role'] ?? 'staff';
$isAdmin       = in_array($currentRole, ['super_admin', 'admin'], true);

// ============================================
// 5. PARAMS
// ============================================
$method       = $_SERVER['REQUEST_METHOD'];
$id           = isset($_GET['id']) ? (int)$_GET['id'] : null;
$limit        = isset($_GET['limit']) ? max(1, min(200, (int)$_GET['limit'])) : 50;
$unreadOnly   = isset($_GET['unread_only']) && $_GET['unread_only'] === 'true';

try {

    // ============================================
    // GET — Fetch notifications (only current user)
    // ============================================
    if ($method === 'GET') {

        // Get single notification by ID (must belong to current user)
        if ($id) {
            $stmt = $conn->prepare("SELECT * FROM notifications WHERE id = :id AND user_id = :uid LIMIT 1");
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->bindValue(':uid', $currentUserId, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $row ?: null]);
            exit();
        }

        // Build query for current user
        $sql = "SELECT * FROM notifications WHERE user_id = :uid";
        if ($unreadOnly) $sql .= " AND is_read = 0";
        $sql .= " ORDER BY created_at DESC LIMIT :lim";

        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':uid', $currentUserId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Normalize is_read to int
        foreach ($results as &$r) {
            $r['is_read'] = (int)$r['is_read'];
        }
        unset($r);

        // Unread count
        $countStmt = $conn->prepare("SELECT COUNT(*) AS unread FROM notifications WHERE user_id = :uid AND is_read = 0");
        $countStmt->bindValue(':uid', $currentUserId, PDO::PARAM_INT);
        $countStmt->execute();
        $unreadCount = (int)($countStmt->fetch(PDO::FETCH_ASSOC)['unread'] ?? 0);

        echo json_encode([
            'success'      => true,
            'data'         => $results,
            'unread_count' => $unreadCount
        ]);
        exit();
    }

    // ============================================
    // POST — Create notification (admin/system)
    // ============================================
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
            exit();
        }

        $targetUserId = isset($input['user_id']) ? (int)$input['user_id'] : null;
        $title        = $input['title']   ?? 'Notification';
        $message      = $input['message'] ?? '';
        $type         = $input['type']    ?? 'info';
        $severity     = $input['severity']?? 'info';

        if (!$targetUserId || empty($message)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'user_id and message are required']);
            exit();
        }

        // Only admins can send notifications to arbitrary users
        if (!$isAdmin && $targetUserId !== $currentUserId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Forbidden']);
            exit();
        }

        $ok = createNotification($conn, $targetUserId, $title, $message, $type, $severity);
        echo json_encode(['success' => (bool)$ok, 'message' => $ok ? 'Notification created' : 'Failed']);
        exit();
    }

    // ============================================
    // PUT — Mark as read (only own notifications)
    // ============================================
    if ($method === 'PUT') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
            exit();
        }

        $notificationId = isset($input['notification_id']) ? (int)$input['notification_id'] : null;

        if ($notificationId) {
            $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :uid");
            $stmt->bindValue(':id', $notificationId, PDO::PARAM_INT);
            $stmt->bindValue(':uid', $currentUserId, PDO::PARAM_INT);
        } else {
            $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = :uid AND is_read = 0");
            $stmt->bindValue(':uid', $currentUserId, PDO::PARAM_INT);
        }

        $ok = $stmt->execute();
        echo json_encode(['success' => (bool)$ok, 'message' => $ok ? 'Marked as read' : 'Failed']);
        exit();
    }

    // ============================================
    // DELETE — Delete own notification
    // ============================================
    if ($method === 'DELETE') {
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'id is required']);
            exit();
        }

        $stmt = $conn->prepare("DELETE FROM notifications WHERE id = :id AND user_id = :uid");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':uid', $currentUserId, PDO::PARAM_INT);
        $ok = $stmt->execute();

        echo json_encode(['success' => (bool)$ok, 'message' => $ok ? 'Deleted' : 'Failed']);
        exit();
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
    exit();
}