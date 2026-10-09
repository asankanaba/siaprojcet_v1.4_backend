<?php
// ============================================
// 📁 File: api/attendance.php
// 🔧 SMART POS API - Attendance with Notifications
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
// 4. CONSTANTS
// ============================================
define('MAX_ATTEMPTS', 7);

// ============================================
// 5. HELPER FUNCTIONS
// ============================================
function formatTime($time) {
    if (!$time || $time === '00:00:00' || $time === '' || $time === '0000-00-00 00:00:00') {
        return null;
    }
    try {
        $cleanTime = preg_replace('/\s+/', ' ', trim($time));
        if (strpos($cleanTime, ' ') !== false || strpos($cleanTime, 'T') !== false) {
            $dt = new DateTime($cleanTime, new DateTimeZone('Asia/Manila'));
            return $dt->format('H:i:s');
        }
        if (preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $cleanTime)) {
            $parts = explode(':', $cleanTime);
            $hours = str_pad($parts[0], 2, '0', STR_PAD_LEFT);
            return $hours . ':' . $parts[1] . ':' . $parts[2];
        }
        $dt = new DateTime($cleanTime, new DateTimeZone('Asia/Manila'));
        return $dt->format('H:i:s');
    } catch (Exception $e) {
        if (preg_match('/(\d{1,2}):(\d{2}):(\d{2})/', $time, $matches)) {
            $hours = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            return $hours . ':' . $matches[2] . ':' . $matches[3];
        }
        return null;
    }
}

function calculateHours($clockIn, $clockOut) {
    if (!$clockIn || !$clockOut) return '0h 0m';
    try {
        $tz = new DateTimeZone('Asia/Manila');
        $in = new DateTime($clockIn, $tz);
        $out = new DateTime($clockOut, $tz);
        $diff = $in->diff($out);
        $hours = $diff->h + ($diff->i / 60);
        $minutes = $diff->i;
        return floor($hours) . 'h ' . $minutes . 'm';
    } catch (Exception $e) {
        return '0h 0m';
    }
}

// ============================================
// NOTIFICATION HELPER FUNCTION
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

function sendAttendanceNotification($conn, $user_id, $action, $status, $time, $hours = null) {
    // Get user details
    $query = "SELECT full_name, department FROM users WHERE id = :user_id";
    $stmt = $conn->prepare($query);
    $stmt->bindValue(':user_id', $user_id);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) return false;
    
    $fullName = $user['full_name'];
    $department = $user['department'] ?: 'General';
    
    if ($action === 'clock_in') {
        $statusText = $status === 'late' ? '⚠️ LATE' : '✅ ON TIME';
        $title = '📋 Clock In - ' . $fullName;
        $message = $fullName . ' from ' . $department . ' clocked in at ' . $time . ' (' . $statusText . ')';
        $severity = $status === 'late' ? 'warning' : 'success';
        $type = 'attendance';
        
        // If late, send additional alert
        if ($status === 'late') {
            $lateTitle = '⚠️ Late Arrival Alert';
            $lateMessage = $fullName . ' from ' . $department . ' arrived late at ' . $time;
            $hrQuery = "SELECT id FROM users WHERE role IN ('hr', 'admin', 'super_admin') AND status = 'active'";
            $hrStmt = $conn->prepare($hrQuery);
            $hrStmt->execute();
            $hrUsers = $hrStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($hrUsers as $hrUser) {
                createNotification($conn, $hrUser['id'], $lateTitle, $lateMessage, 'alert', 'warning');
            }
        }
    } elseif ($action === 'clock_out') {
        $title = '📋 Clock Out - ' . $fullName;
        $message = $fullName . ' from ' . $department . ' clocked out at ' . $time . ' (Hours: ' . $hours . ')';
        $severity = 'info';
        $type = 'attendance';
    } else {
        return false;
    }
    
    // Notify HR/Admin users (except for late alerts which were already sent)
    if (!($action === 'clock_in' && $status === 'late')) {
        $hrQuery = "SELECT id FROM users WHERE role IN ('hr', 'admin', 'super_admin') AND status = 'active'";
        $hrStmt = $conn->prepare($hrQuery);
        $hrStmt->execute();
        $hrUsers = $hrStmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($hrUsers as $hrUser) {
            createNotification($conn, $hrUser['id'], $title, $message, $type, $severity);
        }
    }
    
    // Notify the employee themselves
    $employeeTitle = $action === 'clock_in' ? '✅ You clocked in' : '✅ You clocked out';
    $employeeMessage = $action === 'clock_in' 
        ? 'You clocked in at ' . $time . ' (' . ($status === 'late' ? '⚠️ Late' : '✅ On Time') . ')'
        : 'You clocked out at ' . $time . ' (Total hours: ' . $hours . ')';
    createNotification($conn, $user_id, $employeeTitle, $employeeMessage, $type, $severity);
    
    return true;
}

// ============================================
// 6. HANDLE REQUESTS
// ============================================
$method = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? $_GET['id'] : null;
$user_id = isset($_GET['user_id']) ? $_GET['user_id'] : null;
$date = isset($_GET['date']) ? $_GET['date'] : null;   // ✅ FIXED: default to NULL (was date('Y-m-d'))
$status = isset($_GET['status']) ? $_GET['status'] : '';
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// ============================================
// GET request - Fetch attendance
// ============================================
if ($method === 'GET') {
    try {
        if ($user_id) {
            $requestDate = isset($_GET['date']) ? $_GET['date'] : null;
            
            if ($requestDate) {
                $query = "SELECT 
                            a.id,
                            a.user_id,
                            a.date,
                            a.clock_in_time,
                            a.clock_out_time,
                            a.status,
                            a.hours,
                            a.attempt_number,
                            a.clock_in_attempts,
                            a.clock_out_attempts,
                            a.attempt_log,
                            u.username,
                            u.full_name,
                            u.department
                          FROM attendance a 
                          LEFT JOIN users u ON a.user_id = u.id 
                          WHERE a.user_id = :user_id 
                          AND a.date = :date
                          ORDER BY a.attempt_number ASC";
                
                $stmt = $conn->prepare($query);
                $stmt->bindValue(':user_id', $user_id);
                $stmt->bindValue(':date', $requestDate);
                $stmt->execute();
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $query = "SELECT 
                            a.id,
                            a.user_id,
                            a.date,
                            a.clock_in_time,
                            a.clock_out_time,
                            a.status,
                            a.hours,
                            a.attempt_number,
                            a.clock_in_attempts,
                            a.clock_out_attempts,
                            a.attempt_log,
                            u.username,
                            u.full_name,
                            u.department
                          FROM attendance a 
                          LEFT JOIN users u ON a.user_id = u.id 
                          WHERE a.user_id = :user_id 
                          AND MONTH(a.date) = :month 
                          AND YEAR(a.date) = :year
                          ORDER BY a.date DESC, a.attempt_number ASC";
                
                $stmt = $conn->prepare($query);
                $stmt->bindValue(':user_id', $user_id);
                $stmt->bindValue(':month', $month);
                $stmt->bindValue(':year', $year);
                $stmt->execute();
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            $formattedResults = [];
            foreach ($results as $row) {
                $clockIn = formatTime($row['clock_in_time']);
                $clockOut = formatTime($row['clock_out_time']);
                
                $formattedResults[] = [
                    'id' => (int)$row['id'],
                    'user_id' => (int)$row['user_id'],
                    'date' => $row['date'],
                    'clock_in' => $clockIn,
                    'clock_out' => $clockOut,
                    'hours' => !empty($row['hours']) ? $row['hours'] : calculateHours($row['clock_in_time'], $row['clock_out_time']),
                    'status' => strtolower($row['status'] ?? 'present'),
                    'attempt_number' => (int)($row['attempt_number'] ?? 1),
                    'clock_in_attempts' => (int)($row['clock_in_attempts'] ?? 0),
                    'clock_out_attempts' => (int)($row['clock_out_attempts'] ?? 0),
                    'attempt_log' => $row['attempt_log'],
                    'full_name' => $row['full_name'] ?? 'Unknown',
                    'username' => $row['username'] ?? 'unknown',
                    'department' => $row['department'] ?? 'General'
                ];
            }
            
            echo json_encode($formattedResults);
            exit();
        }
        
        // Get all attendance for HR view
        $query = "SELECT 
                    a.id,
                    a.user_id,
                    a.date,
                    a.clock_in_time,
                    a.clock_out_time,
                    a.status,
                    a.hours,
                    a.attempt_number,
                    a.clock_in_attempts,
                    a.clock_out_attempts,
                    a.attempt_log,
                    u.username,
                    u.full_name,
                    u.department
                  FROM attendance a 
                  LEFT JOIN users u ON a.user_id = u.id 
                  WHERE 1=1";
        
        $params = [];
        
        if ($date) {
            $query .= " AND DATE(a.date) = :date";
            $params[':date'] = $date;
        }
        if ($status && $status !== 'all') {
            $query .= " AND LOWER(a.status) = :status";
            $params[':status'] = strtolower($status);
        }
        if ($month) {
            $query .= " AND MONTH(a.date) = :month";
            $params[':month'] = $month;
        }
        if ($year) {
            $query .= " AND YEAR(a.date) = :year";
            $params[':year'] = $year;
        }
        
        $query .= " ORDER BY a.date DESC, a.attempt_number ASC";
        
        $stmt = $conn->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $formattedResults = [];
        foreach ($results as $row) {
            $clockIn = formatTime($row['clock_in_time']);
            $clockOut = formatTime($row['clock_out_time']);
            
            $formattedResults[] = [
                'id' => (int)$row['id'],
                'user_id' => (int)$row['user_id'],
                'date' => $row['date'],
                'clock_in' => $clockIn,
                'clock_out' => $clockOut,
                'hours' => !empty($row['hours']) ? $row['hours'] : calculateHours($row['clock_in_time'], $row['clock_out_time']),
                'status' => strtolower($row['status'] ?? 'present'),
                'attempt_number' => (int)($row['attempt_number'] ?? 1),
                'clock_in_attempts' => (int)($row['clock_in_attempts'] ?? 0),
                'clock_out_attempts' => (int)($row['clock_out_attempts'] ?? 0),
                'attempt_log' => $row['attempt_log'],
                'full_name' => $row['full_name'] ?? 'Unknown',
                'username' => $row['username'] ?? 'unknown',
                'department' => $row['department'] ?? 'General'
            ];
        }
        
        echo json_encode($formattedResults);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

// ============================================
// POST request - Clock in/out with Notifications
// ============================================
if ($method === 'POST') {
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
            exit();
        }
        
        $user_id = isset($data['user_id']) ? (int)$data['user_id'] : null;
        $action = isset($data['action']) ? $data['action'] : 'clock_in';
        
        $currentDate = date('Y-m-d');
        $currentTime = date('H:i:s');
        $dateTime = date('Y-m-d H:i:s');
        
        if (!$user_id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'User ID is required']);
            exit();
        }
        
        // ============================================
        // CLOCK IN
        // ============================================
        if ($action === 'clock_in') {
            // Check if user is already clocked in
            $checkQuery = "SELECT id, clock_in_time FROM attendance 
                          WHERE user_id = :user_id 
                          AND DATE(date) = :date 
                          AND clock_out_time IS NULL
                          ORDER BY id DESC LIMIT 1";
            $checkStmt = $conn->prepare($checkQuery);
            $checkStmt->bindValue(':user_id', $user_id);
            $checkStmt->bindValue(':date', $currentDate);
            $checkStmt->execute();
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                echo json_encode([
                    'success' => false, 
                    'message' => 'Already clocked in today at ' . date('H:i:s', strtotime($existing['clock_in_time'])),
                    'already_clocked_in' => true,
                    'clock_in_time' => date('H:i:s', strtotime($existing['clock_in_time']))
                ]);
                exit();
            }
            
            // Get today's records count for attempt number
            $countQuery = "SELECT COUNT(*) as total FROM attendance 
                          WHERE user_id = :user_id 
                          AND DATE(date) = :date";
            $countStmt = $conn->prepare($countQuery);
            $countStmt->bindValue(':user_id', $user_id);
            $countStmt->bindValue(':date', $currentDate);
            $countStmt->execute();
            $countResult = $countStmt->fetch(PDO::FETCH_ASSOC);
            $attemptNumber = ($countResult['total'] ?? 0) + 1;
            
            // Check if max attempts reached
            if ($attemptNumber > MAX_ATTEMPTS) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Maximum clock-in attempts (' . MAX_ATTEMPTS . ') reached for today',
                    'max_attempts_reached' => true,
                    'max_attempts' => MAX_ATTEMPTS
                ]);
                exit();
            }
            
            // Determine status
            $hour = (int)date('H');
            $status = $hour >= 9 ? 'late' : 'present';
            
            // Count existing clock_in_attempts for this user today
            $inAttemptsQuery = "SELECT COUNT(*) as in_attempts FROM attendance 
                               WHERE user_id = :user_id 
                               AND DATE(date) = :date 
                               AND clock_in_time IS NOT NULL";
            $inAttemptsStmt = $conn->prepare($inAttemptsQuery);
            $inAttemptsStmt->bindValue(':user_id', $user_id);
            $inAttemptsStmt->bindValue(':date', $currentDate);
            $inAttemptsStmt->execute();
            $inAttemptsResult = $inAttemptsStmt->fetch(PDO::FETCH_ASSOC);
            $clockInAttempts = ($inAttemptsResult['in_attempts'] ?? 0) + 1;
            
            // Count existing clock_out_attempts for this user today
            $outAttemptsQuery = "SELECT COUNT(*) as out_attempts FROM attendance 
                                WHERE user_id = :user_id 
                                AND DATE(date) = :date 
                                AND clock_out_time IS NOT NULL";
            $outAttemptsStmt = $conn->prepare($outAttemptsQuery);
            $outAttemptsStmt->bindValue(':user_id', $user_id);
            $outAttemptsStmt->bindValue(':date', $currentDate);
            $outAttemptsStmt->execute();
            $outAttemptsResult = $outAttemptsStmt->fetch(PDO::FETCH_ASSOC);
            $clockOutAttempts = (int)($outAttemptsResult['out_attempts'] ?? 0);
            
            // Create new record with attempt tracking
            $query = "INSERT INTO attendance 
                      (user_id, date, clock_in_time, status, attempt_number, clock_in_attempts, clock_out_attempts) 
                      VALUES 
                      (:user_id, :date, :clock_in_time, :status, :attempt_number, :clock_in_attempts, :clock_out_attempts)";
            $stmt = $conn->prepare($query);
            $stmt->bindValue(':user_id', $user_id);
            $stmt->bindValue(':date', $currentDate);
            $stmt->bindValue(':clock_in_time', $dateTime);
            $stmt->bindValue(':status', $status);
            $stmt->bindValue(':attempt_number', $attemptNumber);
            $stmt->bindValue(':clock_in_attempts', $clockInAttempts);
            $stmt->bindValue(':clock_out_attempts', $clockOutAttempts);
            
            if ($stmt->execute()) {
                // ✅ SEND NOTIFICATIONS
                sendAttendanceNotification($conn, $user_id, 'clock_in', $status, $currentTime);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Clocked in successfully at ' . $currentTime . ' (Attempt ' . $attemptNumber . '/' . MAX_ATTEMPTS . ')',
                    'status' => $status,
                    'time' => $currentTime,
                    'date' => $currentDate,
                    'attempt' => $attemptNumber,
                    'max_attempts' => MAX_ATTEMPTS,
                    'clock_in_attempts' => $clockInAttempts,
                    'clock_out_attempts' => $clockOutAttempts
                ]);
            } else {
                throw new Exception('Failed to clock in');
            }
            
        // ============================================
        // CLOCK OUT
        // ============================================
        } else if ($action === 'clock_out') {
            // Find latest active clock-in
            $checkQuery = "SELECT id, clock_in_time, attempt_number FROM attendance 
                          WHERE user_id = :user_id 
                          AND DATE(date) = :date 
                          AND clock_out_time IS NULL
                          ORDER BY id DESC LIMIT 1";
            $checkStmt = $conn->prepare($checkQuery);
            $checkStmt->bindValue(':user_id', $user_id);
            $checkStmt->bindValue(':date', $currentDate);
            $checkStmt->execute();
            $record = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$record) {
                echo json_encode([
                    'success' => false, 
                    'message' => 'No active clock-in found for today. Please clock in first.',
                    'no_active_clock' => true
                ]);
                exit();
            }
            
            // Calculate hours
            $clockInTime = $record['clock_in_time'];
            $clockOutTime = $dateTime;
            $hoursStr = calculateHours($clockInTime, $clockOutTime);
            
            // Get current clock_out_attempts
            $outAttemptsQuery = "SELECT COUNT(*) as out_attempts FROM attendance 
                                WHERE user_id = :user_id 
                                AND DATE(date) = :date 
                                AND clock_out_time IS NOT NULL";
            $outAttemptsStmt = $conn->prepare($outAttemptsQuery);
            $outAttemptsStmt->bindValue(':user_id', $user_id);
            $outAttemptsStmt->bindValue(':date', $currentDate);
            $outAttemptsStmt->execute();
            $outAttemptsResult = $outAttemptsStmt->fetch(PDO::FETCH_ASSOC);
            $clockOutAttempts = ($outAttemptsResult['out_attempts'] ?? 0) + 1;
            
            $query = "UPDATE attendance 
                      SET clock_out_time = :clock_out_time,
                          hours = :hours,
                          clock_out_attempts = :clock_out_attempts
                      WHERE id = :id";
            $stmt = $conn->prepare($query);
            $stmt->bindValue(':clock_out_time', $clockOutTime);
            $stmt->bindValue(':hours', $hoursStr);
            $stmt->bindValue(':clock_out_attempts', $clockOutAttempts);
            $stmt->bindValue(':id', $record['id']);
            
            if ($stmt->execute()) {
                // ✅ SEND NOTIFICATIONS
                sendAttendanceNotification($conn, $user_id, 'clock_out', null, $currentTime, $hoursStr);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Clocked out successfully at ' . $currentTime . ' (Attempt ' . $record['attempt_number'] . '/' . MAX_ATTEMPTS . ')',
                    'hours' => $hoursStr,
                    'time' => $currentTime,
                    'attempt' => $record['attempt_number'],
                    'max_attempts' => MAX_ATTEMPTS,
                    'clock_out_attempts' => $clockOutAttempts
                ]);
            } else {
                throw new Exception('Failed to clock out');
            }
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action. Use "clock_in" or "clock_out"']);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// ============================================
// PUT request - Update attendance
// ============================================
if ($method === 'PUT') {
    try {
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Attendance ID is required']);
            exit();
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
            exit();
        }
        
        $status = isset($data['status']) ? $data['status'] : null;
        
        if (!$status) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Status is required']);
            exit();
        }
        
        $query = "UPDATE attendance SET status = :status WHERE id = :id";
        $stmt = $conn->prepare($query);
        $stmt->bindValue(':status', $status);
        $stmt->bindValue(':id', $id);
        
        if ($stmt->execute()) {
            // Get user info for notification
            $userQuery = "SELECT a.user_id, u.full_name FROM attendance a 
                          JOIN users u ON a.user_id = u.id 
                          WHERE a.id = :id";
            $userStmt = $conn->prepare($userQuery);
            $userStmt->bindValue(':id', $id);
            $userStmt->execute();
            $userInfo = $userStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($userInfo) {
                $title = "📋 Attendance Status Updated";
                $message = "Attendance status for " . $userInfo['full_name'] . " has been updated to: " . strtoupper($status);
                $hrQuery = "SELECT id FROM users WHERE role IN ('hr', 'admin', 'super_admin') AND status = 'active'";
                $hrStmt = $conn->prepare($hrQuery);
                $hrStmt->execute();
                $hrUsers = $hrStmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($hrUsers as $hrUser) {
                    createNotification($conn, $hrUser['id'], $title, $message, 'attendance', 'info');
                }
            }
            
            echo json_encode(['success' => true, 'message' => 'Attendance updated successfully']);
        } else {
            throw new Exception('Failed to update attendance');
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// ============================================
// DELETE request - Delete attendance
// ============================================
if ($method === 'DELETE') {
    try {
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Attendance ID is required']);
            exit();
        }
        
        $query = "DELETE FROM attendance WHERE id = :id";
        $stmt = $conn->prepare($query);
        $stmt->bindValue(':id', $id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Attendance record deleted']);
        } else {
            throw new Exception('Failed to delete attendance');
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// Method not allowed
http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed']);
exit();
?>