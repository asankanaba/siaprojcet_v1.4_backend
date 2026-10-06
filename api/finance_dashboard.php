<?php
require_once __DIR__ . '/../config/cors.php';
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

$host = 'localhost';
$db_name = 'smart_pos';
$username = 'root';
$password = '';

try {
    $conn = new PDO("mysql:host=$host;dbname=$db_name", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// ==========================================
// FINANCE DASHBOARD DATA
// ==========================================

// 1. Total Revenue
$stmt = $conn->query("SELECT SUM(total) as total_revenue FROM sales");
$total_revenue = $stmt->fetch(PDO::FETCH_ASSOC)['total_revenue'] ?? 0;

// 2. Pending Approvals
$stmt = $conn->query("SELECT COUNT(*) as pending FROM product_approvals WHERE status = 'pending'");
$pending_approvals = $stmt->fetch(PDO::FETCH_ASSOC)['pending'] ?? 0;

// 3. Approved Today
$stmt = $conn->query("SELECT COUNT(*) as approved FROM product_approvals WHERE status = 'approved' AND DATE(created_at) = CURDATE()");
$approved_today = $stmt->fetch(PDO::FETCH_ASSOC)['approved'] ?? 0;

// 4. Rejected
$stmt = $conn->query("SELECT COUNT(*) as rejected FROM product_approvals WHERE status = 'rejected'");
$rejected = $stmt->fetch(PDO::FETCH_ASSOC)['rejected'] ?? 0;

// ==========================================
// 5. PRODUCT APPROVALS (Fixing requested_by_name)
// ==========================================
$sql = "SELECT 
            pa.id, 
            pa.product_name, 
            pa.price, 
            pa.stock, 
            pa.status, 
            pa.created_at,
            c.name as category_name,
            COALESCE(u.full_name, 'Unknown') as requested_by_name
        FROM product_approvals pa
        LEFT JOIN categories c ON pa.category_id = c.id
        LEFT JOIN users u ON pa.requested_by = u.id
        ORDER BY pa.created_at DESC";

$stmt = $conn->prepare($sql);
$stmt->execute();
$product_approvals = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ==========================================
// 6. BUDGET APPROVALS (FIXING THE NAME LOOKUP)
// ==========================================
$budget_sql = "SELECT 
            ba.id, 
            ba.department, 
            ba.amount, 
            ba.purpose, 
            ba.status, 
            ba.created_at,
            COALESCE(u.full_name, 'Unknown') as requested_by_name
        FROM budget_approvals ba
        LEFT JOIN users u ON ba.requested_by = u.id
        ORDER BY ba.created_at DESC";

$stmt = $conn->prepare($budget_sql);
$stmt->execute();
$budget_approvals = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ==========================================
// SEND RESPONSE
// ==========================================
$response = [
    'total_revenue' => (float)$total_revenue,
    'pending_approvals' => (int)$pending_approvals,
    'approved_today' => (int)$approved_today,
    'rejected' => (int)$rejected,
    'product_approvals' => $product_approvals,
    'budget_approvals' => $budget_approvals
];

echo json_encode($response);
exit();
?>