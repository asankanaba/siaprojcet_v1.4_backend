<?php
// ============================================
// 📁 File: api/holidays.php
// 🇵🇭 Holiday Calendar — Regular / Special / Double Pay
// ============================================
declare(strict_types=1);

date_default_timezone_set('Asia/Manila');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit();
}

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../config/database.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input  = json_decode(file_get_contents('php://input'), true) ?: [];
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;
$year   = isset($_GET['year']) ? (int)$_GET['year'] : null;

// ============================================
// NOTIFICATION HELPER
// ============================================
function createNotification($conn, $user_id, $title, $message, $type = 'info', $severity = 'info') {
    try {
        $stmt = $conn->prepare("
            INSERT INTO notifications (user_id, title, message, type, severity, is_read, created_at)
            VALUES (?, ?, ?, ?, ?, 0, NOW())
        ");
        return $stmt->execute([$user_id, $title, $message, $type, $severity]);
    } catch (Throwable $e) {
        return false;
    }
}

function notifyRole($conn, $roles, $title, $message, $type = 'info', $severity = 'info') {
    try {
        $placeholders = implode(',', array_fill(0, count($roles), '?'));
        $stmt = $conn->prepare("
            SELECT id FROM users WHERE role IN ($placeholders) AND status = 'active'
        ");
        $stmt->execute($roles);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($users as $u) {
            createNotification($conn, (int)$u['id'], $title, $message, $type, $severity);
        }
    } catch (Throwable $e) { /* silent */ }
}

try {
    $db   = new Database();
    $conn = $db->getConnection();
    if (!$conn) throw new Exception('Database connection failed');

    // ============================================
    // GET — List holidays
    // ============================================
    if ($method === 'GET') {

        // Single
        if ($id) {
            $stmt = $conn->prepare("SELECT * FROM holidays WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Holiday not found']);
                exit();
            }
            echo json_encode(['success' => true, 'data' => $row]);
            exit();
        }

        // Check if specific date is a holiday
        if (!empty($_GET['date'])) {
            $stmt = $conn->prepare("SELECT * FROM holidays WHERE holiday_date = ? LIMIT 1");
            $stmt->execute([$_GET['date']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode([
                'success'    => true,
                'is_holiday' => (bool)$row,
                'data'       => $row ?: null,
            ]);
            exit();
        }

        // List (optionally filter by year)
        $sql    = "SELECT h.*, u.full_name AS created_by_name
                   FROM holidays h
                   LEFT JOIN users u ON h.created_by = u.id
                   WHERE 1=1";
        $params = [];
        if ($year) {
            $sql .= " AND YEAR(h.holiday_date) = ?";
            $params[] = $year;
        }
        $sql .= " ORDER BY h.holiday_date ASC";

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit();
    }

    // ============================================
    // POST — Create holiday
    // ============================================
    if ($method === 'POST') {
        $name     = trim((string)($input['name'] ?? ''));
        $date     = trim((string)($input['holiday_date'] ?? ''));
        $type     = $input['type'] ?? 'regular';
        $rate     = (float)($input['rate_multiplier'] ?? 2.00);
        $notes    = trim((string)($input['notes'] ?? ''));
        $createdBy = (int)($input['created_by'] ?? 0) ?: null;

        if ($name === '' || $date === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'name and holiday_date required']);
            exit();
        }

        // Validate date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'holiday_date must be YYYY-MM-DD']);
            exit();
        }

        if (!in_array($type, ['regular', 'special', 'double'], true)) {
            $type = 'regular';
        }

        // Auto-set rate from type if not overridden
        if (!isset($input['rate_multiplier'])) {
            $rate = match ($type) {
                'regular' => 2.00,
                'special' => 1.30,
                'double'  => 3.00,
                default   => 2.00,
            };
        }

        // Duplicate check
        $check = $conn->prepare("SELECT id FROM holidays WHERE holiday_date = ?");
        $check->execute([$date]);
        if ($check->fetch()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Holiday already exists on this date']);
            exit();
        }

        $stmt = $conn->prepare("
            INSERT INTO holidays (name, holiday_date, type, rate_multiplier, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$name, $date, $type, $rate, $notes, $createdBy]);
        $newId = (int)$conn->lastInsertId();

        // Notify HR + Finance + Admin
        notifyRole(
            $conn,
            ['hr', 'finance', 'admin', 'super_admin'],
            '🎉 New Holiday Added',
            "{$name} on {$date} ({$type} — {$rate}x pay)",
            'info',
            'info'
        );

        echo json_encode([
            'success' => true,
            'id'      => $newId,
            'message' => 'Holiday added successfully',
        ]);
        exit();
    }

    // ============================================
    // PUT — Update holiday
    // ============================================
    if ($method === 'PUT') {
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'id required']);
            exit();
        }

        $chk = $conn->prepare("SELECT id FROM holidays WHERE id = ?");
        $chk->execute([$id]);
        if (!$chk->fetch()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Holiday not found']);
            exit();
        }

        $allowed = ['name', 'holiday_date', 'type', 'rate_multiplier', 'notes'];
        $fields  = [];
        $params  = [];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $input)) {
                $fields[] = "$f = ?";
                $params[] = $input[$f];
            }
        }
        if (!$fields) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No fields to update']);
            exit();
        }
        $params[] = $id;
        $stmt = $conn->prepare("UPDATE holidays SET " . implode(', ', $fields) . " WHERE id = ?");
        $stmt->execute($params);

        echo json_encode(['success' => true, 'message' => 'Holiday updated']);
        exit();
    }

    // ============================================
    // DELETE — Remove holiday
    // ============================================
    if ($method === 'DELETE') {
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'id required']);
            exit();
        }
        $stmt = $conn->prepare("DELETE FROM holidays WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true, 'message' => 'Holiday deleted']);
        exit();
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);

} catch (Throwable $e) {
    error_log('holidays.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>