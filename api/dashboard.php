<?php
// ============================================
// 📁 File: api/dashboard.php
// 🔧 SMART POS API - Dashboard
// ============================================

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
// 4. GET DASHBOARD DATA
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $type = isset($_GET['type']) ? $_GET['type'] : 'admin';
        
        // ============================================
        // Get total revenue from SALES table
        // ============================================
        $stmt = $conn->query("SELECT COALESCE(SUM(total), 0) as total FROM sales WHERE status = 'completed'");
        $revenue = $stmt->fetch(PDO::FETCH_ASSOC);
        $totalRevenue = $revenue ? (float)$revenue['total'] : 0;
        
        // ============================================
        // Get total orders
        // ============================================
        $stmt = $conn->query("SELECT COUNT(*) as count FROM sales WHERE status = 'completed'");
        $orders = $stmt->fetch(PDO::FETCH_ASSOC);
        $totalOrders = $orders ? (int)$orders['count'] : 0;
        
        // ============================================
        // Get total products
        // ============================================
        $stmt = $conn->query("SELECT COUNT(*) as count FROM products WHERE status IS NULL OR status != 'archived'");
        $products = $stmt->fetch(PDO::FETCH_ASSOC);
        $productsCount = $products ? (int)$products['count'] : 0;
        
        // ============================================
        // Get active customers
        // ============================================
        $stmt = $conn->query("SELECT COUNT(*) as count FROM customers WHERE status = 'active'");
        $customers = $stmt->fetch(PDO::FETCH_ASSOC);
        $customersCount = $customers ? (int)$customers['count'] : 0;
        
        // ============================================
        // Get staff count
        // ============================================
        $stmt = $conn->query("SELECT COUNT(*) as count FROM users WHERE status = 'active' AND role IN ('admin', 'hr', 'finance', 'staff', 'cashier')");
        $staff = $stmt->fetch(PDO::FETCH_ASSOC);
        $staffCount = $staff ? (int)$staff['count'] : 0;
        
        // ============================================
        // Get items sold
        // ============================================
        $stmt = $conn->query("SELECT COALESCE(SUM(quantity), 0) as total FROM sale_items");
        $itemsSold = $stmt->fetch(PDO::FETCH_ASSOC);
        $itemsSoldTotal = $itemsSold ? (int)$itemsSold['total'] : 0;
        
        // ============================================
        // Get monthly revenue for chart (last 6 months)
        // ============================================
        $query = "SELECT DATE_FORMAT(created_at, '%b') as month, COALESCE(SUM(total), 0) as total 
                  FROM sales 
                  WHERE status = 'completed' AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH) 
                  GROUP BY MONTH(created_at) 
                  ORDER BY created_at ASC";
        $stmt = $conn->prepare($query);
        $stmt->execute();
        $monthlyData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $chartLabels = [];
        $chartValues = [];
        
        if (empty($monthlyData)) {
            $chartLabels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'];
            $chartValues = [0, 0, 0, 0, 0, 0];
        } else {
            foreach ($monthlyData as $data) {
                $chartLabels[] = $data['month'];
                $chartValues[] = (float)$data['total'];
            }
        }
        
        // ============================================
        // Get top products (for CEO dashboard)
        // ============================================
        $query = "SELECT p.name, COALESCE(SUM(si.quantity), 0) as total_sold 
                  FROM products p 
                  LEFT JOIN sale_items si ON p.id = si.product_id 
                  LEFT JOIN sales s ON si.sale_id = s.id 
                  WHERE s.status = 'completed'
                  GROUP BY p.id 
                  ORDER BY total_sold DESC 
                  LIMIT 5";
        $stmt = $conn->prepare($query);
        $stmt->execute();
        $topProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($topProducts)) {
            $topProducts = [
                ['name' => 'Designer Bangles', 'total_sold' => 37],
                ['name' => 'Reading Books', 'total_sold' => 19],
                ['name' => 'Leather Shoe', 'total_sold' => 18],
                ['name' => 'Leather Purse', 'total_sold' => 9],
                ['name' => 'Digital Camera', 'total_sold' => 9]
            ];
        }
        
        // ============================================
        // Get recent activities
        // ============================================
        $activities = [
            [
                'type' => 'sale',
                'message' => 'New sale completed - ₱1,200.00',
                'time' => date('Y-m-d H:i:s', strtotime('-5 minutes'))
            ],
            [
                'type' => 'product',
                'message' => 'Product "iPhone" stock updated to 22',
                'time' => date('Y-m-d H:i:s', strtotime('-1 hour'))
            ],
            [
                'type' => 'user',
                'message' => 'New user "HR Manager" registered',
                'time' => date('Y-m-d H:i:s', strtotime('-2 hours'))
            ]
        ];
        
        // ============================================
        // Calculate profit (assuming 20% margin)
        // ============================================
        $profitTotal = $totalRevenue * 0.20;
        
        // ============================================
        // Build response
        // ============================================
        $response = [
            'revenue' => $totalRevenue,
            'orders' => $totalOrders,
            'products' => $productsCount,
            'customers' => $customersCount,
            'staff' => $staffCount,
            'itemsSold' => $itemsSoldTotal,
            'profit' => $profitTotal,
            'chartLabels' => $chartLabels,
            'chartValues' => $chartValues,
            'topProducts' => $topProducts,
            'activities' => $activities
        ];
        
        echo json_encode($response);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    }
    exit();
}

// Method not allowed
http_response_code(405);
echo json_encode([
    'success' => false,
    'message' => 'Method not allowed'
]);
exit();
?>