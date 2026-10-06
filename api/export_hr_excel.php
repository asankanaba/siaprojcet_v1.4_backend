<?php
// ============================================
// 📁 File: api/export_hr_excel.php
// 🔧 SMART POS API - Export HR Report to Excel
// ============================================

// ============================================
// 1. CORS HEADERS
// ============================================
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: text/csv");

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ============================================
// 2. DATABASE CONNECTION
// ============================================
require_once __DIR__ . '/../config/database.php';

// ============================================
// 3. EXPORT DATA
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $type = isset($_POST['type']) ? $_POST['type'] : 'employees';
        
        $csvData = [];
        
        if ($type === 'employees' || $type === 'all') {
            // Export employees
            $query = "SELECT id, username, full_name, email, role, department, status, phone, created_at FROM users ORDER BY full_name ASC";
            $stmt = $conn->prepare($query);
            $stmt->execute();
            $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $csvData[] = ['HR Report - Employees'];
            $csvData[] = [];
            $csvData[] = ['ID', 'Username', 'Full Name', 'Email', 'Role', 'Department', 'Status', 'Phone', 'Joined Date'];
            foreach ($employees as $emp) {
                $csvData[] = [
                    $emp['id'],
                    $emp['username'],
                    $emp['full_name'],
                    $emp['email'],
                    $emp['role'],
                    $emp['department'],
                    $emp['status'],
                    $emp['phone'],
                    $emp['created_at']
                ];
            }
        }
        
        if ($type === 'attendance' || $type === 'all') {
            // Export attendance
            $query = "SELECT a.*, u.full_name, u.department 
                      FROM attendance a 
                      LEFT JOIN users u ON a.user_id = u.id 
                      ORDER BY a.date DESC LIMIT 100";
            $stmt = $conn->prepare($query);
            $stmt->execute();
            $attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $csvData[] = [];
            $csvData[] = ['HR Report - Attendance'];
            $csvData[] = [];
            $csvData[] = ['Employee', 'Department', 'Date', 'Clock In', 'Clock Out', 'Status'];
            foreach ($attendance as $att) {
                $csvData[] = [
                    $att['full_name'],
                    $att['department'],
                    $att['date'],
                    $att['clock_in_time'],
                    $att['clock_out_time'],
                    $att['status']
                ];
            }
        }
        
        if ($type === 'budget' || $type === 'all') {
            // Export budget requests
            $query = "SELECT br.*, u.full_name as requested_by 
                      FROM budget_requests br
                      LEFT JOIN users u ON br.requested_by = u.id
                      ORDER BY br.created_at DESC";
            $stmt = $conn->prepare($query);
            $stmt->execute();
            $budgets = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $csvData[] = [];
            $csvData[] = ['HR Report - Budget Requests'];
            $csvData[] = [];
            $csvData[] = ['Department', 'Amount', 'Purpose', 'Requested By', 'Status', 'Date'];
            foreach ($budgets as $b) {
                $csvData[] = [
                    $b['department'],
                    number_format($b['amount'], 2),
                    $b['purpose'],
                    $b['requested_by'],
                    $b['status'],
                    $b['created_at']
                ];
            }
        }
        
        // Generate CSV
        $output = fopen('php://temp', 'w');
        foreach ($csvData as $row) {
            fputcsv($output, $row);
        }
        rewind($output);
        $csvContent = stream_get_contents($output);
        fclose($output);
        
        // Set headers for download
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="hr_report_' . date('Y-m-d') . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        echo $csvContent;
        exit();
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

// Method not allowed
http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed']);
exit();
?>