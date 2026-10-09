<?php
// api/index.php - Main API Router
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Max-Age: 86400');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Get the endpoint from query parameter or path
$endpoint = isset($_GET['endpoint']) ? $_GET['endpoint'] : null;

if (!$endpoint) {
    $requestUri = $_SERVER['REQUEST_URI'];
    $path = parse_url($requestUri, PHP_URL_PATH);
    $pathParts = explode('/', trim($path, '/'));
    $endpoint = end($pathParts);
}

$endpoint = str_replace('.php', '', $endpoint);

// Map endpoints to files — COMPLETE list
$apiFiles = [
    'analytics'                  => 'analytics.php',
    'attendance'                 => 'attendance.php',
    'auth'                       => 'auth.php',
    'backup'                     => 'backup.php',
    'budgets'                    => 'budgets.php',
    'budget_approvals'           => 'budget_approvals.php',
    'budget_requests'            => 'budget_requests.php',
    'categories'                 => 'categories.php',
    'check_absences'             => 'check_absences.php',
    'customers'                  => 'customers.php',
    'dashboard'                  => 'dashboard.php',
    'export_excel'               => 'export_excel.php',
    'export_hr_excel'            => 'export_hr_excel.php',
    'finance_dashboard'          => 'finance_dashboard.php',
    'goals'                      => 'goals.php',
    'holidays'                   => 'holidays.php',              // ✅ ADDED
    'hr_dashboard'               => 'hr_dashboard.php',
    'hr_reports'                 => 'hr_reports.php',
    'image'                      => 'image.php',
    'jobs'                       => 'jobs.php',
    'leave_requests'             => 'leave_requests.php',        // ✅ ADDED
    'notifications'              => 'notifications.php',
    'payments'                   => 'payments.php',
    'paymongo_return'            => 'paymongo_return.php',
    'paymongo_webhook'           => 'paymongo_webhook.php',
    'payroll'                    => 'payroll.php',
    'payroll_process'            => 'payroll_process.php',
    'po_deliveries'              => 'po_deliveries.php',
    'products'                   => 'products.php',
    'product_approvals'          => 'product_approvals.php',
    'purchase_orders'            => 'purchase_orders.php',
    'refunds'                    => 'refunds.php',
    'reports'                    => 'reports.php',
    'requisitions'               => 'requisitions.php',
    'rfqs'                       => 'rfqs.php',
    'salary_history'             => 'salary_history.php',        // ✅ ADDED
    'sales'                      => 'sales.php',
    'settings'                   => 'settings.php',
    'shift_schedules'            => 'shift_schedules.php',
    'stock_approvals'            => 'stock_approvals.php',
    'suppliers'                  => 'suppliers.php',
    'supplier_invoices'          => 'supplier_invoices.php',
    'supplier_performance'       => 'supplier_performance.php',
    'supplier_ratings'           => 'supplier_ratings.php',
    'supply_chain'               => 'supply_chain.php',
    'supply_chain_notifications' => 'supply_chain_notifications.php',
    'transactions'               => 'transactions.php',
    'users'                      => 'users.php',
    'wallets'                    => 'wallets.php',
    'wallet_transactions'        => 'wallet_transactions.php',
];

if (isset($apiFiles[$endpoint])) {
    $file = __DIR__ . '/' . $apiFiles[$endpoint];
    if (file_exists($file)) {
        require_once $file;
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'API endpoint file not found: ' . $apiFiles[$endpoint]]);
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