<?php
// ============================================
// 📁 File: api/check_absences.php
// 🔧 SMART POS API - Check Absences (Cron Job)
// ============================================

// ✅ SET PHILIPPINES TIMEZONE
date_default_timezone_set('Asia/Manila');

// ============================================
// 1. CORS HEADERS
// ============================================
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
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
function createNotification($conn, $user_id, $title, $message, $type = 'info', $severity = 'info') {
    $query = "INSERT INTO notifications (user_id, title, message, type, severity, is_read, created_at) 
              VALUES (:user_id, :title, :message, :type, :severity, 0, NOW())";
    $stmt = $conn->prepare($query);
    $stmt->bindValue(':user_id', $user_id);
    $stmt->bindValue(':title', $title);
    $stmt->bindValue(':message', $message);
    $stmt->bindValue(':type', $type);
    $stmt->bindValue(':severity', $severity);
    return $stmt->execute();
}

function sendAbsenceAlert($conn) {
    // Get today's date
    $today = date('Y-m-d');
    $currentHour = (int)date('H');
    
    // Only send absence alerts after 10 AM
    if ($currentHour < 10) {
        return [
            'success' => true, 
            'message' => 'Too early to check absences (before 10 AM)',
            'skipped' => true,
            'absent_count' => 0
        ];
    }
    
    // Get all active employees
    $query = "SELECT id, full_name, department FROM users WHERE status = 'active'";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get employees who have clocked in today
    $attendanceQuery = "SELECT DISTINCT user_id FROM attendance WHERE date = :date AND clock_in_time IS NOT NULL";
    $attStmt = $conn->prepare($attendanceQuery);
    $attStmt->bindValue(':date', $today);
    $attStmt->execute();
    $attended = $attStmt->fetchAll(PDO::FETCH_COLUMN);
    
    $absentCount = 0;
    $absentEmployees = [];
    
    foreach ($employees as $employee) {
        if (!in_array($employee['id'], $attended)) {
            $absentCount++;
            $absentEmployees[] = $employee;
            
            // Notify HR/Admins
            $title = "⚠️ Absence Alert: " . $employee['full_name'];
            $message = $employee['full_name'] . " from " . $employee['department'] . " has not clocked in today (" . $today . ")";
            
            $hrQuery = "SELECT id FROM users WHERE role IN ('hr', 'admin', 'super_admin') AND status = 'active'";
            $hrStmt = $conn->prepare($hrQuery);
            $hrStmt->execute();
            $hrUsers = $hrStmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($hrUsers as $hrUser) {
                createNotification($conn, $hrUser['id'], $title, $message, 'alert', 'error');
            }
            
            // Notify the employee
            createNotification($conn, $employee['id'], "📢 You haven't clocked in today", 
                "Please remember to clock in when you arrive at work. Date: " . $today, 
                'reminder', 'warning');
        }
    }
    
    return [
        'success' => true,
        'message' => 'Absence check completed successfully',
        'absent_count' => $absentCount,
        'absent_employees' => $absentEmployees,
        'skipped' => false
    ];
}

// ============================================
// 5. HANDLE REQUESTS
// ============================================
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET' && $method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET or POST.']);
    exit();
}

try {
    $result = sendAbsenceAlert($conn);
    
    echo json_encode([
        'success' => $result['success'],
        'message' => $result['message'],
        'data' => [
            'absent_count' => $result['absent_count'] ?? 0,
            'timestamp' => date('Y-m-d H:i:s'),
            'skipped' => $result['skipped'] ?? false,
            'absent_employees' => $result['absent_employees'] ?? []
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>