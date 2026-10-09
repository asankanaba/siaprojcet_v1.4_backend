<?php
// ============================================
// 📁 File: api/payroll_process.php
// 🧮 Payroll Processor — Computes from attendance + shifts + holidays + leave
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

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

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
// HELPERS
// ============================================
function parseHours($hoursStr) {
    if (!$hoursStr) return 0.0;
    if (preg_match('/(\d+)h\s*(\d+)m/', $hoursStr, $m)) {
        return (float)$m[1] + ((float)$m[2] / 60);
    }
    if (is_numeric($hoursStr)) return (float)$hoursStr;
    return 0.0;
}

function isWeekend(string $date): bool {
    $dow = (int)date('N', strtotime($date));
    return $dow >= 6; // Sat = 6, Sun = 7
}

function dateRange(string $start, string $end): array {
    $dates = [];
    $cur   = strtotime($start);
    $last  = strtotime($end);
    while ($cur <= $last) {
        $dates[] = date('Y-m-d', $cur);
        $cur = strtotime('+1 day', $cur);
    }
    return $dates;
}

/**
 * Resolve shift config for a user
 * Priority:
 *  1. users.shift_start / users.grace_minutes / users.work_hours_per_day
 *  2. shift_schedules row (most recent active)
 *  3. Default: 09:00 / 10 min / 8h
 */
function resolveShift(PDO $conn, array $user): array {
    $shiftStart = $user['shift_start'] ?? null;
    $shiftEnd   = $user['shift_end']   ?? null;
    $grace      = (int)($user['grace_minutes'] ?? 0);
    $workHours  = (float)($user['work_hours_per_day'] ?? 0);

    // Fallback to shift_schedules if users columns are empty
    if (!$shiftStart || !$shiftEnd) {
        $stmt = $conn->prepare("
            SELECT shift_start, shift_end, grace_minutes
            FROM shift_schedules
            WHERE user_id = ? AND is_active = 1
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([(int)$user['id']]);
        $ss = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($ss) {
            $shiftStart = $shiftStart ?: $ss['shift_start'];
            $shiftEnd   = $shiftEnd   ?: $ss['shift_end'];
            if (!$grace && $ss['grace_minutes'] !== null) $grace = (int)$ss['grace_minutes'];
        }
    }

    // Hardcoded fallback
    $shiftStart = $shiftStart ?: '09:00:00';
    $shiftEnd   = $shiftEnd   ?: '18:00:00';
    if ($grace <= 0) $grace = 10;
    if ($workHours <= 0) $workHours = 8.0;

    return [
        'shift_start'        => $shiftStart,
        'shift_end'          => $shiftEnd,
        'grace_minutes'      => $grace,
        'work_hours_per_day' => $workHours,
    ];
}

/**
 * Compute the deduction for a late day.
 * Late = (actual clock_in) - (shift_start + grace). Deduct in hours × hourly rate.
 */
function computeLateDeduction(
    string $clockInTime,
    string $shiftStart,
    int $graceMinutes,
    float $dailyRate,
    float $workHours
): float {
    $shiftStartTs = strtotime($shiftStart);
    $clockInTs    = strtotime($clockInTime);
    if (!$shiftStartTs || !$clockInTs) return 0.0;

    $lateSeconds  = $clockInTs - $shiftStartTs;
    $graceSeconds = $graceMinutes * 60;

    if ($lateSeconds <= $graceSeconds) return 0.0;

    $billableLateSeconds = $lateSeconds - $graceSeconds;
    $billableLateHours   = $billableLateSeconds / 3600;

    if ($workHours <= 0) $workHours = 8.0;
    $hourlyRate = $dailyRate / $workHours;

    return round($billableLateHours * $hourlyRate, 2);
}

try {
    $db   = new Database();
    $conn = $db->getConnection();
    if (!$conn) throw new Exception('Database connection failed');

    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'POST required']);
        exit();
    }

    // ============================================
    // INPUT VALIDATION
    // ============================================
    $periodType  = $input['period_type']  ?? 'semi_monthly';
    $periodStart = $input['period_start'] ?? null;
    $periodEnd   = $input['period_end']   ?? null;
    $employeeIds = $input['employee_ids'] ?? null;
    $createdBy   = (int)($input['created_by'] ?? 0) ?: null;

    if (!$periodStart || !$periodEnd) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'period_start and period_end required']);
        exit();
    }
    if (strtotime($periodEnd) < strtotime($periodStart)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'period_end must be after period_start']);
        exit();
    }

    $today = date('Y-m-d'); // Used to check if a date is in the past

    // ============================================
    // RESOLVE EMPLOYEE LIST
    // ============================================
    if (is_array($employeeIds) && count($employeeIds) > 0) {
        $ids = array_map('intval', $employeeIds);
    } else {
        $stmt = $conn->prepare("
            SELECT DISTINCT user_id FROM attendance
            WHERE date BETWEEN ? AND ?
        ");
        $stmt->execute([$periodStart, $periodEnd]);
        $ids = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'user_id');
    }

    if (count($ids) === 0) {
        echo json_encode([
            'success' => true,
            'message' => 'No employees to process',
            'data'    => [
                'processed' => 0,
                'details'   => [],
                'summary'   => [
                    'total_employees'  => 0,
                    'total_deductions' => 0,
                    'total_net_pay'    => 0,
                    'total_records'    => 0,
                ],
            ],
        ]);
        exit();
    }

    // ============================================
    // PRE-LOAD HOLIDAYS IN PERIOD
    // ============================================
    $holidayStmt = $conn->prepare("
        SELECT holiday_date, type, rate_multiplier
        FROM holidays
        WHERE holiday_date BETWEEN ? AND ?
    ");
    $holidayStmt->execute([$periodStart, $periodEnd]);
    $holidays = [];
    foreach ($holidayStmt->fetchAll(PDO::FETCH_ASSOC) as $h) {
        $holidays[$h['holiday_date']] = [
            'type'       => $h['type'],
            'multiplier' => (float)$h['rate_multiplier'],
        ];
    }

    // ============================================
    // PRE-LOAD APPROVED LEAVE IN PERIOD
    // ============================================
    $leaveStmt = $conn->prepare("
        SELECT user_id, start_date, end_date
        FROM leave_requests
        WHERE status = 'approved'
          AND (start_date <= ? AND end_date >= ?)
    ");
    $leaveStmt->execute([$periodEnd, $periodStart]);
    $approvedLeaves = $leaveStmt->fetchAll(PDO::FETCH_ASSOC);

    // ============================================
    // PROCESS EACH EMPLOYEE
    // ============================================
    $details         = [];
    $totalDeductions = 0.0;
    $totalNetPay     = 0.0;
    $processedCount  = 0;

    $conn->beginTransaction();

    try {
        foreach ($ids as $userId) {
            // 1. Fetch user
            $uStmt = $conn->prepare("
                SELECT id, full_name, username, department, role,
                       salary_rate, salary_type,
                       shift_start, shift_end, grace_minutes, work_hours_per_day
                FROM users WHERE id = ?
            ");
            $uStmt->execute([$userId]);
            $user = $uStmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) continue;

            // 2. Resolve shift
            $shift = resolveShift($conn, $user);

            $dailyRate = (float)($user['salary_rate'] ?? 0);
            $salType   = $user['salary_type'] ?? 'daily';

            // Convert non-daily rates to daily equivalent
            if ($salType === 'monthly') {
                $dailyRate = $dailyRate / 22;
            } elseif ($salType === 'hourly') {
                $dailyRate = $dailyRate * $shift['work_hours_per_day'];
            }

            // 3. Fetch attendance for the period
            $aStmt = $conn->prepare("
                SELECT date, clock_in_time, clock_out_time, status, hours
                FROM attendance
                WHERE user_id = ? AND date BETWEEN ? AND ?
                ORDER BY date ASC
            ");
            $aStmt->execute([$userId, $periodStart, $periodEnd]);
            $attendance = $aStmt->fetchAll(PDO::FETCH_ASSOC);

            // Map by date for fast lookup
            $attByDate = [];
            foreach ($attendance as $a) {
                $attByDate[$a['date']] = $a;
            }

            // 4. Check leave coverage for this user
            $userLeaves = array_filter(
                $approvedLeaves,
                fn($l) => (int)$l['user_id'] === (int)$userId
            );
            $leaveDates = [];
            foreach ($userLeaves as $l) {
                foreach (dateRange($l['start_date'], $l['end_date']) as $d) {
                    if ($d >= $periodStart && $d <= $periodEnd) {
                        $leaveDates[$d] = true;
                    }
                }
            }

            // 5. Iterate every date in period
            $daysPresent = 0;
            $daysLate    = 0;
            $daysAbsent  = 0;
            $basicSalary = 0.0;
            $holidayPay  = 0.0;
            $leavePay    = 0.0;
            $deductions  = 0.0;

            foreach (dateRange($periodStart, $periodEnd) as $date) {
                $isHoliday = isset($holidays[$date]);
                $isLeave   = isset($leaveDates[$date]);
                $isWeekend = isWeekend($date);

                // Weekend with no holiday/leave → skip
                if ($isWeekend && !$isHoliday && !$isLeave) continue;

                // Holiday
                if ($isHoliday) {
                    $mult = $holidays[$date]['multiplier'];
                    $holidayPay += $dailyRate * $mult;
                    if (isset($attByDate[$date])) $daysPresent++;
                    continue;
                }

                // Approved leave
                if ($isLeave) {
                    $leavePay += $dailyRate;
                    continue;
                }

                // Regular day (weekday, no holiday, no leave)
                if (isset($attByDate[$date])) {
                    $att    = $attByDate[$date];
                    $status = strtolower($att['status'] ?? 'present');

                    if ($status === 'absent') {
                        $daysAbsent++;
                        $deductions += $dailyRate;
                        continue;
                    }

                    // present or late
                    $basicSalary += $dailyRate;

                    if ($status === 'late') {
                        $daysLate++;
                        $lateDeduction = computeLateDeduction(
                            $att['clock_in_time'] ?? '00:00:00',
                            $shift['shift_start'],
                            $shift['grace_minutes'],
                            $dailyRate,
                            $shift['work_hours_per_day']
                        );
                        $deductions += $lateDeduction;
                    } else {
                        $daysPresent++;
                    }
                } else {
                    // ✅ FIX: Only count as absent if the date is in the PAST.
                    // Future/current days with no attendance are simply skipped.
                    if ($date < $today) {
                        $daysAbsent++;
                        $deductions += $dailyRate;
                    }
                }
            }

            $gross  = $basicSalary + $holidayPay + $leavePay;
            $netPay = max(0, $gross - $deductions);

            // 6. Insert / upsert payroll row
            $check = $conn->prepare("
                SELECT id FROM payroll
                WHERE user_id = ? AND period_start = ? AND period_end = ?
            ");
            $check->execute([$userId, $periodStart, $periodEnd]);
            $existing = $check->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $upd = $conn->prepare("
                    UPDATE payroll SET
                        days_present = ?, days_late = ?, days_absent = ?,
                        basic_salary = ?, allowances = 0,
                        holiday_pay = ?, leave_pay = ?,
                        deductions = ?, net_pay = ?,
                        status = 'pending',
                        notes = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $upd->execute([
                    $daysPresent, $daysLate, $daysAbsent,
                    $basicSalary, $holidayPay, $leavePay,
                    $deductions, $netPay,
                    "Reprocessed {$periodStart} → {$periodEnd}",
                    $existing['id'],
                ]);
                $payrollId = (int)$existing['id'];
            } else {
                $ins = $conn->prepare("
                    INSERT INTO payroll (
                        user_id, period_start, period_end,
                        days_present, days_late, days_absent,
                        basic_salary, allowances, holiday_pay, leave_pay,
                        deductions, net_pay, status, notes, created_by
                    ) VALUES (
                        ?, ?, ?,
                        ?, ?, ?,
                        ?, 0, ?, ?,
                        ?, ?, 'pending', ?, ?
                    )
                ");
                $ins->execute([
                    $userId, $periodStart, $periodEnd,
                    $daysPresent, $daysLate, $daysAbsent,
                    $basicSalary, $holidayPay, $leavePay,
                    $deductions, $netPay,
                    "Auto-computed {$periodStart} → {$periodEnd}",
                    $createdBy,
                ]);
                $payrollId = (int)$conn->lastInsertId();
            }

            // 7. Track totals
            $details[] = [
                'user_id'      => $userId,
                'payroll_id'   => $payrollId,
                'full_name'    => $user['full_name'],
                'department'   => $user['department'],
                'days_present' => $daysPresent,
                'days_late'    => $daysLate,
                'days_absent'  => $daysAbsent,
                'basic_salary' => round($basicSalary, 2),
                'holiday_pay'  => round($holidayPay, 2),
                'leave_pay'    => round($leavePay, 2),
                'gross_pay'    => round($gross, 2),
                'deductions'   => round($deductions, 2),
                'net_pay'      => round($netPay, 2),
                'status'       => 'pending',
            ];

            $totalDeductions += $deductions;
            $totalNetPay     += $netPay;
            $processedCount++;
        }

        // 8. Record the period in payroll_periods (if table exists)
        try {
            $ppChk = $conn->prepare("
                SELECT id FROM payroll_periods
                WHERE period_start = ? AND period_end = ?
            ");
            $ppChk->execute([$periodStart, $periodEnd]);
            if (!$ppChk->fetch()) {
                $ppIns = $conn->prepare("
                    INSERT INTO payroll_periods
                        (period_type, period_start, period_end, status, created_by)
                    VALUES (?, ?, ?, 'processing', ?)
                ");
                $ppIns->execute([$periodType, $periodStart, $periodEnd, $createdBy]);
            }
        } catch (Throwable $e) { /* optional */ }

        // 9. Notify Finance
        notifyRole(
            $conn,
            ['finance', 'super_admin', 'admin'],
            '💼 Payroll Ready for Review',
            "Payroll for {$processedCount} employees ({$periodStart} → {$periodEnd}) is ready. Total net: ₱" . number_format($totalNetPay, 2),
            'info',
            'info'
        );

        $conn->commit();

        echo json_encode([
            'success' => true,
            'message' => "Payroll processed for {$processedCount} employees",
            'data'    => [
                'processed' => $processedCount,
                'details'   => $details,
                'summary'   => [
                    'total_employees'  => $processedCount,
                    'total_deductions' => round($totalDeductions, 2),
                    'total_net_pay'    => round($totalNetPay, 2),
                    'total_records'    => count($details),
                ],
            ],
        ]);
        exit();

    } catch (Throwable $e) {
        $conn->rollBack();
        throw $e;
    }

} catch (Throwable $e) {
    error_log('payroll_process.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>