<?php
// ============================================
// 📁 File: api/salary_history.php
// 💰 Salary Promotions — Track rate changes
// ============================================
declare(strict_types=1);

date_default_timezone_set('Asia/Manila');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input  = json_decode(file_get_contents('php://input'), true) ?: [];

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
    // GET — History for a user OR all
    // ============================================
    if ($method === 'GET') {
        $user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;

        if ($user_id) {
            $stmt = $conn->prepare("
                SELECT sh.*, u.full_name, u.username, p.full_name AS promoted_by_name
                FROM salary_history sh
                LEFT JOIN users u ON sh.user_id = u.id
                LEFT JOIN users p ON sh.promoted_by = p.id
                WHERE sh.user_id = ?
                ORDER BY sh.created_at DESC
            ");
            $stmt->execute([$user_id]);
        } else {
            $stmt = $conn->query("
                SELECT sh.*, u.full_name, u.username, p.full_name AS promoted_by_name
                FROM salary_history sh
                LEFT JOIN users u ON sh.user_id = u.id
                LEFT JOIN users p ON sh.promoted_by = p.id
                ORDER BY sh.created_at DESC
                LIMIT 200
            ");
        }

        echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit();
    }

    // ============================================
    // POST — Promote employee
    // ============================================
    if ($method === 'POST') {
        $action = $_GET['action'] ?? $input['action'] ?? null;

        if ($action !== 'promote') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'action=promote required']);
            exit();
        }

        $userId      = (int)($input['user_id'] ?? 0);
        $newRate     = (float)($input['new_salary_rate'] ?? 0);
        $newType     = $input['new_salary_type'] ?? null;
        $reason      = trim((string)($input['reason'] ?? ''));
        $promotedBy  = (int)($input['promoted_by'] ?? 0) ?: null;

        if ($userId <= 0 || $newRate <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'user_id and new_salary_rate required']);
            exit();
        }

        if ($newType && !in_array($newType, ['hourly', 'daily', 'monthly'], true)) {
            $newType = null;
        }

        // Fetch current user
        $chk = $conn->prepare("SELECT id, full_name, username, salary_rate, salary_type FROM users WHERE id = ?");
        $chk->execute([$userId]);
        $user = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'User not found']);
            exit();
        }

        $oldRate = (float)$user['salary_rate'];
        $oldType = $user['salary_type'];
        $finalType = $newType ?: $oldType;

        if ($oldRate === $newRate && $finalType === $oldType) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No change detected']);
            exit();
        }

        $conn->beginTransaction();
        try {
            // 1. Update user
            $upd = $conn->prepare("
                UPDATE users
                SET salary_rate = ?, salary_type = ?
                WHERE id = ?
            ");
            $upd->execute([$newRate, $finalType, $userId]);

            // 2. Log history
            $ins = $conn->prepare("
                INSERT INTO salary_history
                    (user_id, old_salary_rate, old_salary_type,
                     new_salary_rate, new_salary_type, reason, promoted_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $ins->execute([
                $userId, $oldRate, $oldType,
                $newRate, $finalType, $reason, $promotedBy,
            ]);
            $historyId = (int)$conn->lastInsertId();

            $conn->commit();

            // 3. Notify employee
            createNotification(
                $conn,
                $userId,
                '🎉 Salary Updated',
                "Your salary has been updated: " .
                "₱" . number_format($oldRate, 2) . " → ₱" . number_format($newRate, 2) .
                " (" . strtoupper($finalType) . ")" .
                ($reason ? " — {$reason}" : ""),
                'success',
                'success'
            );

            // 4. Notify HR + Finance
            notifyRole(
                $conn,
                ['hr', 'finance', 'admin', 'super_admin'],
                '📈 Salary Change — ' . $user['full_name'],
                "New rate: ₱" . number_format($newRate, 2) . " (" . strtoupper($finalType) . ")" .
                ($reason ? " — {$reason}" : ""),
                'info',
                'info'
            );

            echo json_encode([
                'success'    => true,
                'history_id' => $historyId,
                'message'    => 'Salary updated successfully',
                'old_rate'   => $oldRate,
                'new_rate'   => $newRate,
            ]);
            exit();
        } catch (Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);

} catch (Throwable $e) {
    error_log('salary_history.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>