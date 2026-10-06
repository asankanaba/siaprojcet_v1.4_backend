<?php
// api/payroll.php
// ============================================
// Payroll API — with proper notifications
// ============================================

date_default_timezone_set('Asia/Manila');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../config/database.php';

$method   = $_SERVER['REQUEST_METHOD'];
$id       = isset($_GET['id']) ? (int)$_GET['id'] : null;
$user_id  = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;
$status   = $_GET['status'] ?? null;
$period   = $_GET['period'] ?? null;

try {
    $db = new Database();
    $conn = $db->getConnection();

    if (!$conn) {
        throw new Exception('Database connection failed');
    }

    switch ($method) {

        // ============================================
        // GET
        // ============================================
        case 'GET':
            if ($id) {
                $stmt = $conn->prepare("
                    SELECT p.*, u.full_name, u.department, u.email, u.role,
                           w.balance AS wallet_balance
                    FROM payroll p
                    JOIN users u ON p.user_id = u.id
                    LEFT JOIN wallets w ON u.id = w.user_id
                    WHERE p.id = ?
                ");
                $stmt->execute([$id]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'data' => $result]);
            } elseif ($user_id) {
                $stmt = $conn->prepare("
                    SELECT p.*, u.full_name, u.department
                    FROM payroll p
                    JOIN users u ON p.user_id = u.id
                    WHERE p.user_id = ?
                    ORDER BY p.created_at DESC
                ");
                $stmt->execute([$user_id]);
                $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'data' => $result]);
            } else {
                $sql = "
                    SELECT p.*, u.full_name, u.department, u.email,
                           w.balance AS wallet_balance
                    FROM payroll p
                    JOIN users u ON p.user_id = u.id
                    LEFT JOIN wallets w ON u.id = w.user_id
                    WHERE 1=1
                ";
                $params = [];

                if ($status) {
                    $sql .= " AND p.status = ?";
                    $params[] = $status;
                }
                if ($period) {
                    $sql .= " AND p.period_start >= ? AND p.period_end <= ?";
                    $params[] = date('Y-m-01');
                    $params[] = date('Y-m-t');
                }

                $sql .= " ORDER BY p.created_at DESC LIMIT 100";

                $stmt = $conn->prepare($sql);
                $stmt->execute($params);
                $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'data' => $result]);
            }
            break;

        // ============================================
        // POST — Create payroll
        // ============================================
        case 'POST':
            $data = json_decode(file_get_contents('php://input'), true);

            $user_id      = (int)($data['user_id'] ?? 0);
            $period_start = $data['period_start'] ?? null;
            $period_end   = $data['period_end'] ?? null;
            $basic_salary = (float)($data['basic_salary'] ?? 0);
            $allowances   = (float)($data['allowances'] ?? 0);
            $deductions   = (float)($data['deductions'] ?? 0);
            $net_pay      = (float)($data['net_pay'] ?? 0);
            $notes        = $data['notes'] ?? '';
            $created_by   = (int)($data['created_by'] ?? 1);

            if (!$user_id || !$period_start || !$period_end) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'user_id, period_start, period_end required']);
                break;
            }

            // Duplicate check
            $check = $conn->prepare("
                SELECT id FROM payroll
                WHERE user_id = ? AND period_start = ? AND period_end = ?
            ");
            $check->execute([$user_id, $period_start, $period_end]);
            if ($check->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Payroll already exists for this period']);
                break;
            }

            $stmt = $conn->prepare("
                INSERT INTO payroll
                (user_id, period_start, period_end, basic_salary, allowances, deductions, net_pay, status, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)
            ");

            $stmt->execute([
                $user_id, $period_start, $period_end,
                $basic_salary, $allowances, $deductions, $net_pay,
                $notes, $created_by
            ]);

            $payrollId = $conn->lastInsertId();

            // ============================================
            // NOTIFICATION: Payslip Generated
            // ============================================
            $empNameStmt = $conn->prepare("SELECT full_name, username FROM users WHERE id = ?");
            $empNameStmt->execute([$user_id]);
            $empRow = $empNameStmt->fetch(PDO::FETCH_ASSOC);
            $empName = $empRow['full_name'] ?? $empRow['username'] ?? 'Employee';

            $periodStartFmt = date('M d', strtotime($period_start));
            $periodEndFmt   = date('M d, Y', strtotime($period_end));
            $netFmt         = number_format($net_pay, 2);

            // Notify employee
            notifyUser(
                $conn,
                $user_id,
                '📄 Payslip Generated',
                "A payslip for {$periodStartFmt} – {$periodEndFmt} has been created. Net pay: ₱{$netFmt}. Awaiting approval.",
                'payroll',
                'info'
            );

            // Notify finance + admins
            notifyRole(
                $conn,
                ['finance', 'admin', 'super_admin'],
                '📋 New Payroll Pending',
                "Payroll for {$empName} (₱{$netFmt}) is pending approval.",
                'payroll',
                'info',
                $user_id
            );

            echo json_encode([
                'success' => true,
                'message' => 'Payroll record created successfully',
                'id' => $payrollId,
                'status' => 'pending'
            ]);
            break;

        // ============================================
        // PUT — Update status
        // ============================================
        case 'PUT':
            if (!$id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Payroll ID is required']);
                break;
            }

            $data = json_decode(file_get_contents('php://input'), true);
            $newStatus   = $data['status'] ?? null;
            $approved_by = (int)($data['approved_by'] ?? 1);
            $notes       = $data['notes'] ?? '';

            if (!$newStatus) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Status is required']);
                break;
            }

            // Fetch current payroll
            $stmt = $conn->prepare("
                SELECT p.*, u.full_name, u.username
                FROM payroll p
                LEFT JOIN users u ON p.user_id = u.id
                WHERE p.id = ?
            ");
            $stmt->execute([$id]);
            $payroll = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$payroll) {
                echo json_encode(['success' => false, 'message' => 'Payroll record not found']);
                break;
            }

            $oldStatus = $payroll['status'];

            // Update status
            $stmt = $conn->prepare("
                UPDATE payroll
                SET status = ?, approved_by = ?, approved_at = NOW(),
                    notes = CONCAT(COALESCE(notes, ''), ' ', ?)
                WHERE id = ?
            ");
            $stmt->execute([$newStatus, $approved_by, $notes, $id]);

            $empName     = $payroll['full_name'] ?? $payroll['username'] ?? 'Employee';
            $netFmt      = number_format((float)$payroll['net_pay'], 2);
            $periodStart = date('M d', strtotime($payroll['period_start']));
            $periodEnd   = date('M d, Y', strtotime($payroll['period_end']));

            // ============================================
            // WALLET CREDIT (on approved OR paid)
            // ============================================
            if (in_array($newStatus, ['approved', 'paid'], true) && !in_array($oldStatus, ['approved', 'paid'], true)) {
                $walletCheck = $conn->prepare("SELECT id FROM wallets WHERE user_id = ?");
                $walletCheck->execute([$payroll['user_id']]);
                $wallet = $walletCheck->fetch(PDO::FETCH_ASSOC);

                if ($wallet) {
                    $updateWallet = $conn->prepare("
                        UPDATE wallets SET balance = balance + ? WHERE user_id = ?
                    ");
                    $updateWallet->execute([$payroll['net_pay'], $payroll['user_id']]);

                    $logStmt = $conn->prepare("
                        INSERT INTO wallet_transactions
                        (wallet_id, type, amount, description, status)
                        VALUES (?, 'deposit', ?, ?, 'completed')
                    ");
                    $logStmt->execute([
                        $wallet['id'],
                        $payroll['net_pay'],
                        "Salary payment for period {$periodStart} to {$periodEnd}"
                    ]);
                } else {
                    $createWallet = $conn->prepare("
                        INSERT INTO wallets (user_id, balance) VALUES (?, ?)
                    ");
                    $createWallet->execute([$payroll['user_id'], $payroll['net_pay']]);
                }
            }

            // ============================================
            // NOTIFICATIONS PER STATUS
            // ============================================
            if ($newStatus === 'approved' && $oldStatus !== 'approved') {
                // Employee
                notifyUser(
                    $conn,
                    $payroll['user_id'],
                    '✅ Payslip Approved',
                    "Your salary of ₱{$netFmt} for {$periodStart} – {$periodEnd} has been approved and credited to your wallet.",
                    'payroll',
                    'success'
                );

                // Finance
                notifyRole(
                    $conn,
                    ['finance', 'admin', 'super_admin'],
                    '✅ Payroll Approved',
                    "Payroll for {$empName} (₱{$netFmt}) has been approved.",
                    'payroll',
                    'success',
                    $payroll['user_id']
                );

                $responseMsg = 'Payroll approved and salary credited to wallet';

            } elseif ($newStatus === 'paid' && $oldStatus !== 'paid') {
                // ✅ YOUR IDEA: "Salary Received"
                notifyUser(
                    $conn,
                    $payroll['user_id'],
                    '💰 Salary Received',
                    "Your salary of ₱{$netFmt} for {$periodStart} – {$periodEnd} has been paid to your wallet.",
                    'salary',
                    'success'
                );

                notifyRole(
                    $conn,
                    ['finance', 'admin', 'super_admin'],
                    '💵 Payroll Paid',
                    "Payroll for {$empName} (₱{$netFmt}, {$periodStart} – {$periodEnd}) has been marked as paid.",
                    'payroll',
                    'success',
                    $payroll['user_id']
                );

                $responseMsg = 'Payroll marked as paid';

            } elseif ($newStatus === 'rejected' && $oldStatus !== 'rejected') {
                notifyUser(
                    $conn,
                    $payroll['user_id'],
                    '❌ Payslip Rejected',
                    "Your payslip for {$periodStart} – {$periodEnd} was rejected. Please contact HR/Finance.",
                    'payroll',
                    'warning'
                );
                $responseMsg = 'Payroll rejected';

            } else {
                $responseMsg = "Payroll status updated to {$newStatus}";
            }

            echo json_encode([
                'success' => true,
                'message' => $responseMsg,
                'amount' => $payroll['net_pay']
            ]);
            break;

        // ============================================
        // DELETE
        // ============================================
        case 'DELETE':
            if (!$id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Payroll ID is required']);
                break;
            }

            $stmt = $conn->prepare("DELETE FROM payroll WHERE id = ?");
            $stmt->execute([$id]);

            echo json_encode(['success' => true, 'message' => 'Payroll record deleted']);
            break;

        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            break;
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>