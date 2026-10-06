<?php
// api/payroll_process.php
// ============================================
// Process Payroll for Selected Employees
// ============================================

// ✅ SET PHILIPPINES TIMEZONE
date_default_timezone_set('Asia/Manila');

// ============================================
// 1. CORS HEADERS
// ============================================
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

// Handle preflight
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
// 3. DATABASE CONNECTION
// ============================================
require_once __DIR__ . '/../config/database.php';

// ============================================
// 4. HELPER FUNCTIONS
// ============================================
function getEmployeeSalary($conn, $user_id) {
    $query = "SELECT salary_rate, salary_type FROM users WHERE id = :user_id";
    $stmt = $conn->prepare($query);
    $stmt->bindValue(':user_id', $user_id);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? $result['salary_rate'] : 600;
}

function calculateDeductions($status, $hours) {
    // Basic deduction logic
    $deduction = 0;
    
    // If absent, deduct full day
    if ($status === 'absent') {
        $deduction = 600; // Default daily rate
    }
    
    // If late, deduct based on hours
    if ($status === 'late') {
        // Parse hours
        $hoursStr = $hours;
        $totalMinutes = 0;
        if (preg_match('/(\d+)h\s*(\d+)m/', $hoursStr, $matches)) {
            $totalMinutes = ($matches[1] * 60) + $matches[2];
        }
        
        // Deduct for lateness (if less than 8 hours)
        $expectedMinutes = 8 * 60; // 8 hours
        if ($totalMinutes < $expectedMinutes) {
            $shortMinutes = $expectedMinutes - $totalMinutes;
            $deduction = round(($shortMinutes / 60) * (600 / 8), 2);
        }
    }
    
    return $deduction;
}

// ============================================
// 5. HANDLE REQUESTS
// ============================================
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit();
}

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
        exit();
    }
    
    // Validate required fields
    $required = ['period_type', 'period_start', 'period_end'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || empty($input[$field])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
            exit();
        }
    }
    
    $periodType = $input['period_type'];
    $periodStart = $input['period_start'];
    $periodEnd = $input['period_end'];
    $employeeIds = isset($input['employee_ids']) ? $input['employee_ids'] : null;
    $createdBy = isset($input['created_by']) ? (int)$input['created_by'] : 1;
    
    // If employee_ids is not provided or empty, get all employees with attendance
    $employeeIdList = [];
    if ($employeeIds && is_array($employeeIds) && count($employeeIds) > 0) {
        $employeeIdList = $employeeIds;
    } else {
        // Get all employees who have attendance in this period
        $query = "SELECT DISTINCT user_id FROM attendance 
                  WHERE date BETWEEN :start AND :end";
        $stmt = $conn->prepare($query);
        $stmt->bindValue(':start', $periodStart);
        $stmt->bindValue(':end', $periodEnd);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $employeeIdList = array_column($results, 'user_id');
    }
    
    if (count($employeeIdList) === 0) {
        echo json_encode([
            'success' => true,
            'message' => 'No employees found for this period',
            'data' => [
                'processed_employees' => 0,
                'total_deductions' => 0,
                'total_net_pay' => 0,
                'details' => []
            ]
        ]);
        exit();
    }
    
    // Process each employee
    $payrollDetails = [];
    $totalDeductions = 0;
    $totalNetPay = 0;
    $processedCount = 0;
    
    foreach ($employeeIdList as $userId) {
        // Get attendance records for this employee in the period
        $query = "SELECT * FROM attendance 
                  WHERE user_id = :user_id 
                  AND date BETWEEN :start AND :end
                  ORDER BY date ASC";
        $stmt = $conn->prepare($query);
        $stmt->bindValue(':user_id', $userId);
        $stmt->bindValue(':start', $periodStart);
        $stmt->bindValue(':end', $periodEnd);
        $stmt->execute();
        $attendanceRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($attendanceRecords) === 0) {
            continue; // Skip if no attendance
        }
        
        // Get employee details
        $userQuery = "SELECT full_name, department, salary_rate, salary_type FROM users WHERE id = :user_id";
        $userStmt = $conn->prepare($userQuery);
        $userStmt->bindValue(':user_id', $userId);
        $userStmt->execute();
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            continue;
        }
        
        $salaryRate = $user['salary_rate'] ?: 600;
        $fullName = $user['full_name'];
        $department = $user['department'] ?: 'General';
        
        // Calculate total hours and deductions
        $totalHours = 0;
        $totalDeduction = 0;
        $presentDays = 0;
        $absentDays = 0;
        $lateDays = 0;
        
        foreach ($attendanceRecords as $record) {
            $status = strtolower($record['status'] ?: 'present');
            $hours = $record['hours'] ?: '0h 0m';
            
            // Parse hours to decimal
            $hoursDecimal = 0;
            if (preg_match('/(\d+)h\s*(\d+)m/', $hours, $matches)) {
                $hoursDecimal = $matches[1] + ($matches[2] / 60);
            }
            $totalHours += $hoursDecimal;
            
            // Count statuses
            if ($status === 'present') $presentDays++;
            if ($status === 'absent') $absentDays++;
            if ($status === 'late') $lateDays++;
            
            // Calculate deduction for this record
            $deduction = calculateDeductions($status, $hours);
            $totalDeduction += $deduction;
        }
        
        // Calculate net pay (daily rate * days worked - deductions)
        $workingDays = $presentDays + $lateDays; // Present and late count as working days
        $grossPay = $salaryRate * $workingDays;
        $netPay = max(0, $grossPay - $totalDeduction);
        
        // Check if payroll already exists for this period
        $checkQuery = "SELECT id FROM payroll 
                       WHERE user_id = :user_id 
                       AND period_start = :start 
                       AND period_end = :end";
        $checkStmt = $conn->prepare($checkQuery);
        $checkStmt->bindValue(':user_id', $userId);
        $checkStmt->bindValue(':start', $periodStart);
        $checkStmt->bindValue(':end', $periodEnd);
        $checkStmt->execute();
        
        if ($checkStmt->fetch()) {
            // Update existing payroll
            $updateQuery = "UPDATE payroll 
                           SET basic_salary = :basic_salary,
                               allowances = 0,
                               deductions = :deductions,
                               net_pay = :net_pay,
                               notes = :notes,
                               updated_at = NOW()
                           WHERE user_id = :user_id 
                           AND period_start = :start 
                           AND period_end = :end";
            $updateStmt = $conn->prepare($updateQuery);
            $updateStmt->bindValue(':basic_salary', $grossPay);
            $updateStmt->bindValue(':deductions', $totalDeduction);
            $updateStmt->bindValue(':net_pay', $netPay);
            $updateStmt->bindValue(':notes', "Updated: {$presentDays} present, {$lateDays} late, {$absentDays} absent");
            $updateStmt->bindValue(':user_id', $userId);
            $updateStmt->bindValue(':start', $periodStart);
            $updateStmt->bindValue(':end', $periodEnd);
            $updateStmt->execute();
            
            $payrollId = $userId; // Not the actual ID, but used for tracking
        } else {
            // Insert new payroll record
            $insertQuery = "INSERT INTO payroll 
                           (user_id, period_start, period_end, basic_salary, allowances, deductions, net_pay, status, notes, created_by)
                           VALUES 
                           (:user_id, :start, :end, :basic_salary, 0, :deductions, :net_pay, 'pending', :notes, :created_by)";
            $insertStmt = $conn->prepare($insertQuery);
            $insertStmt->bindValue(':user_id', $userId);
            $insertStmt->bindValue(':start', $periodStart);
            $insertStmt->bindValue(':end', $periodEnd);
            $insertStmt->bindValue(':basic_salary', $grossPay);
            $insertStmt->bindValue(':deductions', $totalDeduction);
            $insertStmt->bindValue(':net_pay', $netPay);
            $insertStmt->bindValue(':notes', "{$presentDays} present, {$lateDays} late, {$absentDays} absent");
            $insertStmt->bindValue(':created_by', $createdBy);
            $insertStmt->execute();
            
            $payrollId = $conn->lastInsertId();
        }
        
        // Build detail record
        $detail = [
            'user_id' => $userId,
            'full_name' => $fullName,
            'department' => $department,
            'salary_rate' => $salaryRate,
            'total_hours' => round($totalHours, 2),
            'present_days' => $presentDays,
            'absent_days' => $absentDays,
            'late_days' => $lateDays,
            'gross_pay' => round($grossPay, 2),
            'deductions' => round($totalDeduction, 2),
            'net_pay' => round($netPay, 2),
            'status' => 'pending'
        ];
        
        $payrollDetails[] = $detail;
        $totalDeductions += $totalDeduction;
        $totalNetPay += $netPay;
        $processedCount++;
    }
    
    // Return success response
    echo json_encode([
        'success' => true,
        'message' => "Payroll processed successfully for {$processedCount} employees",
        'data' => [
            'processed_employees' => $processedCount,
            'total_deductions' => round($totalDeductions, 2),
            'total_net_pay' => round($totalNetPay, 2),
            'details' => $payrollDetails
        ]
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>