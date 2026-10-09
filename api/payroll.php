<?php
// ============================================
// 📁 File: api/payroll.php
// 💰 Payroll Records — 2-stage approval (Finance → HR → Paid)
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

function notifyRole($conn, $roles, $title, $message, $type = 'info', $severity = 'info', $excludeUserId = null) {
    try {
        $placeholders = implode(',', array_fill(0, count($roles), '?'));
        $sql = "SELECT id FROM users WHERE role IN ($placeholders) AND status = 'active'";
        $params = $roles;
        if ($excludeUserId) {
            $sql .= " AND id != ?";
            $params[] = $excludeUserId;
        }
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
            createNotification($conn, (int)$u['id'], $title, $message, $type, $severity);
        }
    } catch (Throwable $e) { /* silent */ }
}

// ============================================
// ROLE CHECK — Ensure the actor has the required role
// ============================================
function getUserRoles(PDO $conn, int $userId): array {
    if (!$userId) return [];
    $stmt = $conn->prepare("SELECT role, roles FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return [];
    $roles = [];
    if (!empty($row['role']))  $roles[] = $row['role'];
    if (!empty($row['roles'])) {
        $extra = json_decode($row['roles'], true);
        if (is_array($extra)) $roles = array_merge($roles, $extra);
    }
    return array_unique($roles);
}

function requireRole(PDO $conn, int $userId, array $allowed, string $actionLabel): void {
    $roles = getUserRoles($conn, $userId);
    foreach ($allowed as $r) {
        if (in_array($r, $roles, true)) return;
    }
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => "Access denied: {$actionLabel} requires one of: " . implode(', ', $allowed),
    ]);
    exit();
}

try {
    $db   = new Database();
    $conn = $db->getConnection();
    if (!$conn) throw new Exception('Database connection failed');

    // ============================================
    // GET
    // ============================================
    if ($method === 'GET') {
        if ($id) {
            $stmt = $conn->prepare("
                SELECT p.*, u.full_name, u.username, u.department, u.role, u.email,
                       u.salary_rate, u.salary_type,
                       w.balance AS wallet_balance
                FROM payroll p
                LEFT JOIN users u ON p.user_id = u.id
                LEFT JOIN wallets w ON u.id = w.user_id
                WHERE p.id = ?
            ");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Payroll not found']);
                exit();
            }
            echo json_encode(['success' => true, 'data' => $row]);
            exit();
        }

        // List with filters
        $sql    = "SELECT p.*, u.full_name, u.username, u.department, u.role
                   FROM payroll p
                   LEFT JOIN users u ON p.user_id = u.id
                   WHERE 1=1";
        $params = [];

        if (!empty($_GET['user_id'])) {
            $sql .= " AND p.user_id = ?";
            $params[] = (int)$_GET['user_id'];
        }
        if (!empty($_GET['status'])) {
            $sql .= " AND p.status = ?";
            $params[] = $_GET['status'];
        }
        if (!empty($_GET['from'])) {
            $sql .= " AND p.period_start >= ?";
            $params[] = $_GET['from'];
        }
        if (!empty($_GET['to'])) {
            $sql .= " AND p.period_end <= ?";
            $params[] = $_GET['to'];
        }
        if (!empty($_GET['year'])) {
            $sql .= " AND YEAR(p.period_start) = ?";
            $params[] = (int)$_GET['year'];
        }

        $sql .= " ORDER BY p.created_at DESC LIMIT 500";

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit();
    }

    // ============================================
    // POST — Create single payroll (manual entry)
    // ============================================
    if ($method === 'POST') {
        $userId      = (int)($input['user_id'] ?? 0);
        $periodStart = $input['period_start'] ?? null;
        $periodEnd   = $input['period_end']   ?? null;

        if (!$userId || !$periodStart || !$periodEnd) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'user_id, period_start, period_end required']);
            exit();
        }

        $check = $conn->prepare("
            SELECT id FROM payroll
            WHERE user_id = ? AND period_start = ? AND period_end = ?
        ");
        $check->execute([$userId, $periodStart, $periodEnd]);
        if ($check->fetch()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Payroll already exists for this period']);
            exit();
        }

        $ins = $conn->prepare("
            INSERT INTO payroll (
                user_id, period_start, period_end,
                days_present, days_late, days_absent,
                basic_salary, allowances, holiday_pay, leave_pay,
                deductions, net_pay, status, notes, created_by
            ) VALUES (?, ?, ?, 0, 0, 0, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)
        ");
        $ins->execute([
            $userId, $periodStart, $periodEnd,
            (float)($input['basic_salary'] ?? 0),
            (float)($input['allowances']   ?? 0),
            (float)($input['holiday_pay']  ?? 0),
            (float)($input['leave_pay']    ?? 0),
            (float)($input['deductions']   ?? 0),
            (float)($input['net_pay']      ?? 0),
            $input['notes'] ?? '',
            (int)($input['created_by'] ?? 0) ?: null,
        ]);

        echo json_encode(['success' => true, 'id' => (int)$conn->lastInsertId()]);
        exit();
    }

    // ============================================
    // PUT — Approval actions + updates
    // ============================================
    if ($method === 'PUT') {
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'id required']);
            exit();
        }

        $action = $_GET['action'] ?? $input['action'] ?? 'update';
        $actor  = (int)($input['actor_id'] ?? $input['approved_by'] ?? 0);

        // Fetch current
        $stmt = $conn->prepare("
            SELECT p.*, u.full_name, u.username
            FROM payroll p
            LEFT JOIN users u ON p.user_id = u.id
            WHERE p.id = ?
        ");
        $stmt->execute([$id]);
        $payroll = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$payroll) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Payroll not found']);
            exit();
        }

        $oldStatus = $payroll['status'];
        $netFmt    = number_format((float)$payroll['net_pay'], 2);
        $empName   = $payroll['full_name'] ?? $payroll['username'] ?? 'Employee';
        $pStart    = date('M d', strtotime($payroll['period_start']));
        $pEnd      = date('M d, Y', strtotime($payroll['period_end']));
        $userId    = (int)$payroll['user_id'];

        // ---------- FINANCE APPROVES ----------
        if ($action === 'finance_approve') {
            requireRole($conn, $actor, ['finance', 'admin', 'super_admin'], 'finance approval');

            if ($oldStatus !== 'pending') {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => "Cannot finance-approve — status is {$oldStatus}"]);
                exit();
            }

            $conn->prepare("
                UPDATE payroll SET status = 'finance_approved', updated_at = NOW()
                WHERE id = ?
            ")->execute([$id]);

            // Notify HR
            notifyRole(
                $conn,
                ['hr', 'super_admin', 'admin'],
                '📋 Payroll Ready for Final Approval',
                "{$empName} — ₱{$netFmt} ({$pStart} → {$pEnd}). Finance approved; awaiting HR.",
                'info',
                'info'
            );

            // Notify employee
            createNotification(
                $conn,
                $userId,
                '💰 Payslip Advances to HR',
                "Your payslip for {$pStart} → {$pEnd} was approved by Finance. Awaiting HR final approval.",
                'info',
                'info'
            );

            echo json_encode(['success' => true, 'status' => 'finance_approved', 'message' => 'Finance approved']);
            exit();
        }

        // ---------- HR FINAL APPROVAL ----------
        if ($action === 'hr_approve') {
            requireRole($conn, $actor, ['hr', 'super_admin', 'admin'], 'HR final approval');

            if ($oldStatus !== 'finance_approved') {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => "Cannot HR-approve — status is {$oldStatus}"]);
                exit();
            }

            $conn->prepare("
                UPDATE payroll
                SET status = 'approved', approved_by = ?, approved_at = NOW(), updated_at = NOW()
                WHERE id = ?
            ")->execute([$actor, $id]);

            // Notify Finance + employee
            notifyRole(
                $conn,
                ['finance', 'super_admin', 'admin'],
                '✅ Payroll Finalized',
                "{$empName} — ₱{$netFmt} ({$pStart} → {$pEnd}) is ready to be paid.",
                'success',
                'success'
            );

            createNotification(
                $conn,
                $userId,
                '✅ Payslip Approved',
                "Your payslip for {$pStart} → {$pEnd} (₱{$netFmt}) is approved. Payment is being processed.",
                'success',
                'success'
            );

            echo json_encode(['success' => true, 'status' => 'approved', 'message' => 'HR approved']);
            exit();
        }

        // ---------- MARK PAID (wallet credited HERE) ----------
        if ($action === 'mark_paid') {
            requireRole($conn, $actor, ['finance', 'super_admin', 'admin'], 'marking paid');

            if ($oldStatus !== 'approved') {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => "Cannot mark paid — status is {$oldStatus}"]);
                exit();
            }

            $conn->beginTransaction();
            try {
                // 1. Update status
                $conn->prepare("
                    UPDATE payroll SET status = 'paid', updated_at = NOW()
                    WHERE id = ?
                ")->execute([$id]);

                // 2. Credit wallet
                $wChk = $conn->prepare("SELECT id FROM wallets WHERE user_id = ?");
                $wChk->execute([$userId]);
                $wallet = $wChk->fetch(PDO::FETCH_ASSOC);

                if ($wallet) {
                    $conn->prepare("
                        UPDATE wallets SET balance = balance + ?
                        WHERE user_id = ?
                    ")->execute([(float)$payroll['net_pay'], $userId]);

                    $conn->prepare("
                        INSERT INTO wallet_transactions
                            (wallet_id, type, amount, description, status, created_at)
                        VALUES (?, 'deposit', ?, ?, 'completed', NOW())
                    ")->execute([
                        (int)$wallet['id'],
                        (float)$payroll['net_pay'],
                        "Salary payment — {$pStart} → {$pEnd}",
                    ]);
                } else {
                    // Create wallet if missing
                    $conn->prepare("
                        INSERT INTO wallets (user_id, balance) VALUES (?, ?)
                    ")->execute([$userId, (float)$payroll['net_pay']]);
                }

                $conn->commit();

                // Notify employee + Finance
                createNotification(
                    $conn,
                    $userId,
                    '💵 Salary Received',
                    "Your salary of ₱{$netFmt} for {$pStart} → {$pEnd} has been credited to your wallet.",
                    'salary',
                    'success'
                );

                notifyRole(
                    $conn,
                    ['finance', 'super_admin', 'admin'],
                    '💵 Payroll Paid',
                    "{$empName} — ₱{$netFmt} ({$pStart} → {$pEnd}) marked as paid.",
                    'success',
                    'success'
                );

                echo json_encode([
                    'success' => true,
                    'status'  => 'paid',
                    'amount'  => (float)$payroll['net_pay'],
                    'message' => 'Payroll marked as paid and wallet credited',
                ]);
                exit();

            } catch (Throwable $e) {
                $conn->rollBack();
                throw $e;
            }
        }

        // ---------- REJECT ----------
        if ($action === 'reject') {
            if (!in_array($oldStatus, ['pending', 'finance_approved'], true)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => "Cannot reject — status is {$oldStatus}"]);
                exit();
            }

            $reason = trim((string)($input['reason'] ?? $input['notes'] ?? 'Rejected'));

            $conn->prepare("
                UPDATE payroll
                SET status = 'rejected',
                    notes = CONCAT(COALESCE(notes, ''), ' | Rejected: ', ?),
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$reason, $id]);

            createNotification(
                $conn,
                $userId,
                '❌ Payslip Rejected',
                "Your payslip for {$pStart} → {$pEnd} was rejected. Reason: {$reason}",
                'warning',
                'warning'
            );

            echo json_encode(['success' => true, 'status' => 'rejected', 'message' => 'Payroll rejected']);
            exit();
        }

        // ---------- DEFAULT UPDATE ----------
        $allowed = [
            'basic_salary', 'allowances', 'holiday_pay', 'leave_pay',
            'deductions', 'net_pay', 'notes',
            'days_present', 'days_late', 'days_absent',
        ];
        $fields = [];
        $params = [];
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
        $conn->prepare("UPDATE payroll SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = ?")
             ->execute($params);

        echo json_encode(['success' => true, 'message' => 'Payroll updated']);
        exit();
    }

    // ============================================
    // DELETE
    // ============================================
    if ($method === 'DELETE') {
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'id required']);
            exit();
        }

        // Only allow deleting pending records
        $chk = $conn->prepare("SELECT status FROM payroll WHERE id = ?");
        $chk->execute([$id]);
        $row = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Not found']);
            exit();
        }
        if (!in_array($row['status'], ['pending', 'rejected'], true)) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Only pending or rejected payroll can be deleted']);
            exit();
        }

        $conn->prepare("DELETE FROM payroll WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true, 'message' => 'Payroll deleted']);
        exit();
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);

} catch (Throwable $e) {
    error_log('payroll.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>