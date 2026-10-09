<?php
// ============================================
// 📁 File: api/leave_requests.php
// 🌴 Leave Requests — File, Approve, Track
// ============================================
declare(strict_types=1);

date_default_timezone_set('Asia/Manila');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input  = json_decode(file_get_contents('php://input'), true) ?: [];
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;

// ============================================
// NOTIFICATION HELPERS
// ============================================
function createNotification($conn, $user_id, $title, $message, $type = 'info', $severity = 'info') {
    try {
        $stmt = $conn->prepare("
            INSERT INTO notifications (user_id, title, message, type, severity, is_read, created_at)
            VALUES (?, ?, ?, ?, ?, 0, NOW())
        ");
        return $stmt->execute([$user_id, $title, $message, $type, $severity]);
    } catch (Throwable $e) { return false; }
}

function notifyRole($conn, $roles, $title, $message, $type = 'info', $severity = 'info') {
    try {
        $placeholders = implode(',', array_fill(0, count($roles), '?'));
        $stmt = $conn->prepare("SELECT id FROM users WHERE role IN ($placeholders) AND status = 'active'");
        $stmt->execute($roles);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
            createNotification($conn, (int)$u['id'], $title, $message, $type, $severity);
        }
    } catch (Throwable $e) { /* silent */ }
}

try {
    $db   = new Database();
    $conn = $db->getConnection();
    if (!$conn) throw new Exception('Database connection failed');

    // ============================================
    // GET — List leave requests
    // ============================================
    if ($method === 'GET') {
        // Single
        if ($id) {
            $stmt = $conn->prepare("
                SELECT lr.*, u.full_name, u.username, u.department, u.role
                FROM leave_requests lr
                LEFT JOIN users u ON lr.user_id = u.id
                WHERE lr.id = ? LIMIT 1
            ");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Leave request not found']);
                exit();
            }
            echo json_encode(['success' => true, 'data' => $row]);
            exit();
        }

        // List with filters
        $sql    = "SELECT lr.*, u.full_name, u.username, u.department
                   FROM leave_requests lr
                   LEFT JOIN users u ON lr.user_id = u.id
                   WHERE 1=1";
        $params = [];

        if (!empty($_GET['user_id'])) {
            $sql .= " AND lr.user_id = ?";
            $params[] = (int)$_GET['user_id'];
        }
        if (!empty($_GET['status'])) {
            $sql .= " AND lr.status = ?";
            $params[] = $_GET['status'];
        }
        if (!empty($_GET['year'])) {
            $sql .= " AND YEAR(lr.start_date) = ?";
            $params[] = (int)$_GET['year'];
        }

        $sql .= " ORDER BY lr.created_at DESC LIMIT 200";

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit();
    }

    // ============================================
    // POST — File a leave request
    // ============================================
    if ($method === 'POST') {
        $userId      = (int)($input['user_id'] ?? 0);
        $startDate   = trim((string)($input['start_date'] ?? ''));
        $endDate     = trim((string)($input['end_date'] ?? ''));
        $leaveTypeId = (int)($input['leave_type_id'] ?? 0) ?: null;
        $reason      = trim((string)($input['reason'] ?? ''));

        if ($userId <= 0 || $startDate === '' || $endDate === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'user_id, start_date, end_date required']);
            exit();
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Dates must be YYYY-MM-DD']);
            exit();
        }

        if (strtotime($endDate) < strtotime($startDate)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'end_date must be after start_date']);
            exit();
        }

        // Calculate days (working days only — skip weekends)
        $days = 0;
        $cur  = strtotime($startDate);
        $end  = strtotime($endDate);
        while ($cur <= $end) {
            $dow = (int)date('N', $cur); // 1 = Mon, 7 = Sun
            if ($dow <= 5) $days++;      // count weekdays only
            $cur = strtotime('+1 day', $cur);
        }
        if ($days === 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No working days in this range']);
            exit();
        }

        // Check overlap
        $overlap = $conn->prepare("
            SELECT id FROM leave_requests
            WHERE user_id = ?
              AND status IN ('pending','approved')
              AND (start_date <= ? AND end_date >= ?)
            LIMIT 1
        ");
        $overlap->execute([$userId, $endDate, $startDate]);
        if ($overlap->fetch()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Overlapping leave request already exists']);
            exit();
        }

        $stmt = $conn->prepare("
            INSERT INTO leave_requests
                (user_id, leave_type_id, start_date, end_date, days_count, reason, status)
            VALUES (?, ?, ?, ?, ?, ?, 'pending')
        ");
        $stmt->execute([$userId, $leaveTypeId, $startDate, $endDate, $days, $reason]);
        $newId = (int)$conn->lastInsertId();

        // Notify HR + Admin
        notifyRole(
            $conn,
            ['hr', 'admin', 'super_admin'],
            '🌴 New Leave Request',
            "New leave request from {$startDate} to {$endDate} ({$days} days)",
            'info',
            'info'
        );

        echo json_encode([
            'success'    => true,
            'id'         => $newId,
            'days_count' => $days,
            'message'    => 'Leave request submitted',
        ]);
        exit();
    }

    // ============================================
    // PUT — Approve / Reject / Update
    // ============================================
    if ($method === 'PUT') {
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'id required']);
            exit();
        }

        $action     = $_GET['action'] ?? $input['action'] ?? null;
        $approvedBy = (int)($input['approved_by'] ?? 0) ?: null;

        // Fetch current request
        $chk = $conn->prepare("SELECT * FROM leave_requests WHERE id = ?");
        $chk->execute([$id]);
        $lr = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$lr) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Leave request not found']);
            exit();
        }

        // ---------- ACTION: approve / reject ----------
        if (in_array($action, ['approve', 'reject'], true)) {
            $newStatus = $action === 'approve' ? 'approved' : 'rejected';

            if ($lr['status'] !== 'pending') {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => "Cannot {$action} — request already {$lr['status']}"]);
                exit();
            }

            $stmt = $conn->prepare("
                UPDATE leave_requests
                SET status = ?, approved_by = ?, approved_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$newStatus, $approvedBy, $id]);

            // Notify employee
            createNotification(
                $conn,
                (int)$lr['user_id'],
                $newStatus === 'approved' ? '✅ Leave Approved' : '❌ Leave Rejected',
                "Your leave request ({$lr['start_date']} → {$lr['end_date']}) was {$newStatus}",
                $newStatus === 'approved' ? 'success' : 'warning',
                $newStatus === 'approved' ? 'success' : 'warning'
            );

            echo json_encode(['success' => true, 'status' => $newStatus, 'message' => "Leave {$newStatus}"]);
            exit();
        }

        // ---------- DEFAULT: Update ----------
        $allowed = ['start_date', 'end_date', 'reason', 'leave_type_id'];
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
        $stmt = $conn->prepare("UPDATE leave_requests SET " . implode(', ', $fields) . " WHERE id = ?");
        $stmt->execute($params);

        echo json_encode(['success' => true, 'message' => 'Leave request updated']);
        exit();
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);

} catch (Throwable $e) {
    error_log('leave_requests.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>