<?php
// api/index.php - Main API Router
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Max-Age: 86400');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ============================================
// ERROR REPORTING
// ============================================
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// ============================================
// ROUTER
// ============================================
// Get the endpoint from query parameter or path
$endpoint = isset($_GET['endpoint']) ? $_GET['endpoint'] : null;

// If endpoint is not in query, try to get from path
if (!$endpoint) {
    $requestUri = $_SERVER['REQUEST_URI'];
    $path = parse_url($requestUri, PHP_URL_PATH);
    $pathParts = explode('/', trim($path, '/'));
    // Get the last part of the path (e.g., /api/users.php -> users.php)
    $endpoint = end($pathParts);
}

// Remove .php extension if present
$endpoint = str_replace('.php', '', $endpoint);

// Map endpoints to files
$apiFiles = [
    'attendance' => 'attendance.php',
    'auth' => 'auth.php',
    'budgets' => 'budgets.php',
    'budget_approvals' => 'budget_approvals.php',
    'budget_requests' => 'budget_requests.php',
    'categories' => 'categories.php',
    'customers' => 'customers.php',
    'dashboard' => 'dashboard.php',
    'finance_dashboard' => 'finance_dashboard.php',
    'goals' => 'goals.php',
    'hr_dashboard' => 'hr_dashboard.php',
    'hr_reports' => 'hr_reports.php',
    'jobs' => 'jobs.php',
    'notifications' => 'notifications.php',
    'payroll' => 'payroll.php',
    'payroll_process' => 'payroll_process.php',
    'products' => 'products.php',
    'product_approvals' => 'product_approvals.php',
    'reports' => 'reports.php',
    'sales' => 'sales.php',
    'settings' => 'settings.php',
    'shift_schedules' => 'shift_schedules.php',
    'suppliers' => 'suppliers.php',
    'supply_chain' => 'supply_chain.php',
    'transactions' => 'transactions.php',
    'users' => 'users.php',
    'wallets' => 'wallets.php',
    'wallet_transactions' => 'wallet_transactions.php'
];

// Check if the endpoint exists
if (isset($apiFiles[$endpoint])) {
    $file = __DIR__ . '/' . $apiFiles[$endpoint];
    if (file_exists($file)) {
        require_once $file;
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'API endpoint file not found: ' . $file]);
    }
} else {
    http_response_code(404);
    echo json_encode([
        'success' => false, 
        'message' => 'Invalid API endpoint: ' . $endpoint,
        'available_endpoints' => array_keys($apiFiles)
    ]);
}
?>