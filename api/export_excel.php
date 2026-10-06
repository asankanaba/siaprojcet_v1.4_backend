<?php
// ============================================
// 📁 File: api/export_excel.php
// 🔧 SMART POS API - Export to Excel
// ============================================

// ============================================
// 1. CORS HEADERS
// ============================================
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

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
        $data = json_decode(file_get_contents('php://input'), true);
        $type = isset($data['type']) ? $data['type'] : 'sales';
        $period = isset($data['period']) ? $data['period'] : 'month';
        
        // Date range
        $dateCondition = "";
        if ($period === 'month') {
            $dateCondition = "AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        } elseif ($period === 'quarter') {
            $dateCondition = "AND created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)";
        } elseif ($period === 'year') {
            $dateCondition = "AND created_at >= DATE_SUB(NOW(), INTERVAL 365 DAY)";
        }
        
        // Build CSV data
        $csvData = [];
        
        if ($type === 'sales' || $type === 'all') {
            // Sales data
            $query = "SELECT id, invoice_number, created_at as date, 
                      COALESCE(customer_id, 0) as customer_id,
                      subtotal, tax, total, payment_method, status 
                      FROM sales 
                      WHERE status = 'completed' $dateCondition
                      ORDER BY created_at DESC";
            $stmt = $conn->prepare($query);
            $stmt->execute();
            $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $csvData[] = ['Sales Report'];
            $csvData[] = ['Invoice #', 'Date', 'Customer ID', 'Subtotal', 'Tax', 'Total', 'Payment Method', 'Status'];
            foreach ($sales as $sale) {
                $csvData[] = [
                    $sale['invoice_number'],
                    $sale['date'],
                    $sale['customer_id'],
                    number_format($sale['subtotal'], 2),
                    number_format($sale['tax'], 2),
                    number_format($sale['total'], 2),
                    $sale['payment_method'],
                    $sale['status']
                ];
            }
        }
        
        if ($type === 'products' || $type === 'all') {
            // Products data
            $query = "SELECT p.id, p.name, p.price, p.stock, c.name as category 
                      FROM products p 
                      LEFT JOIN categories c ON p.category_id = c.id 
                      WHERE p.status IS NULL OR p.status != 'archived'
                      ORDER BY p.name ASC";
            $stmt = $conn->prepare($query);
            $stmt->execute();
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $csvData[] = [];
            $csvData[] = ['Products Report'];
            $csvData[] = ['ID', 'Name', 'Category', 'Price', 'Stock'];
            foreach ($products as $product) {
                $csvData[] = [
                    $product['id'],
                    $product['name'],
                    $product['category'] ?? 'Uncategorized',
                    number_format($product['price'], 2),
                    $product['stock']
                ];
            }
        }
        
        if ($type === 'customers' || $type === 'all') {
            // Customers data
            $query = "SELECT id, name, email, phone, address, status 
                      FROM customers 
                      ORDER BY name ASC";
            $stmt = $conn->prepare($query);
            $stmt->execute();
            $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $csvData[] = [];
            $csvData[] = ['Customers Report'];
            $csvData[] = ['ID', 'Name', 'Email', 'Phone', 'Address', 'Status'];
            foreach ($customers as $customer) {
                $csvData[] = [
                    $customer['id'],
                    $customer['name'],
                    $customer['email'] ?? '',
                    $customer['phone'] ?? '',
                    $customer['address'] ?? '',
                    $customer['status']
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
        
        // Return as CSV file
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="report_' . date('Y-m-d') . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        echo $csvContent;
        exit();
        
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
            'message' => $e->getMessage()
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