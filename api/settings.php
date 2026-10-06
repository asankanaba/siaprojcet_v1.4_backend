<?php
// ✅ FINAL CORS FIX: Authorization header is now allowed
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ✅ FIXED: use __DIR__ for portability
require_once __DIR__ . '/../config/database.php';

$method = $_SERVER['REQUEST_METHOD'];

// ============================================
// GET: Fetch settings
// ============================================
if ($method === 'GET') {
    try {
        $sql = "SELECT * FROM settings LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$settings) {
            $settings = [
                'store_name' => 'Smart POS Store',
                'store_address' => '123 Main Street, City',
                'store_phone' => '+63 912 345 6789',
                'store_email' => 'info@smartpos.com',
                'currency' => '₱',
                'tax_rate' => 12,
                'tax_type' => 'inclusive',
                'default_discount' => 0,
                'low_stock_threshold' => 5,
                'receipt_footer' => 'Thank you for shopping with us!',
                'show_tax_on_receipt' => true,
                'show_discount_on_receipt' => true,
                'auto_print_receipt' => false,
                'payment_cash' => true,
                'payment_gcash' => true,
                'payment_maya' => true,
                'payment_card' => true,
                'gcash_number' => '',
                'maya_number' => '',
                'invoice_prefix' => 'INV',
                'next_invoice_number' => 1000,
                'include_barcode_on_invoice' => false,
                'include_qr_on_invoice' => false,
                'theme' => 'dark',
                'primary_color' => '#4F46E5'
            ];
        }
        
        echo json_encode($settings);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

// ============================================
// POST: Update settings
// ============================================
if ($method === 'POST') {
    try {
        if (!empty($_POST)) {
            $data = $_POST;
        } else {
            $data = json_decode(file_get_contents("php://input"), true);
        }
        
        if (!$data) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No data provided']);
            exit();
        }
        
        $checkSql = "SELECT COUNT(*) as count FROM settings";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->execute();
        $exists = $checkStmt->fetch(PDO::FETCH_ASSOC)['count'] > 0;
        
        if ($exists) {
            $sql = "UPDATE settings SET 
                store_name = :store_name,
                store_address = :store_address,
                store_phone = :store_phone,
                store_email = :store_email,
                currency = :currency,
                tax_rate = :tax_rate,
                tax_type = :tax_type,
                default_discount = :default_discount,
                low_stock_threshold = :low_stock_threshold,
                receipt_footer = :receipt_footer,
                show_tax_on_receipt = :show_tax_on_receipt,
                show_discount_on_receipt = :show_discount_on_receipt,
                auto_print_receipt = :auto_print_receipt,
                payment_cash = :payment_cash,
                payment_gcash = :payment_gcash,
                payment_maya = :payment_maya,
                payment_card = :payment_card,
                gcash_number = :gcash_number,
                maya_number = :maya_number,
                invoice_prefix = :invoice_prefix,
                next_invoice_number = :next_invoice_number,
                include_barcode_on_invoice = :include_barcode_on_invoice,
                include_qr_on_invoice = :include_qr_on_invoice,
                theme = :theme,
                primary_color = :primary_color
            ";
        } else {
            $sql = "INSERT INTO settings (
                store_name, store_address, store_phone, store_email, currency, tax_rate, tax_type,
                default_discount, low_stock_threshold, receipt_footer, show_tax_on_receipt,
                show_discount_on_receipt, auto_print_receipt, payment_cash, payment_gcash,
                payment_maya, payment_card, gcash_number, maya_number, invoice_prefix,
                next_invoice_number, include_barcode_on_invoice, include_qr_on_invoice,
                theme, primary_color
            ) VALUES (
                :store_name, :store_address, :store_phone, :store_email, :currency, :tax_rate, :tax_type,
                :default_discount, :low_stock_threshold, :receipt_footer, :show_tax_on_receipt,
                :show_discount_on_receipt, :auto_print_receipt, :payment_cash, :payment_gcash,
                :payment_maya, :payment_card, :gcash_number, :maya_number, :invoice_prefix,
                :next_invoice_number, :include_barcode_on_invoice, :include_qr_on_invoice,
                :theme, :primary_color
            )";
        }
        
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':store_name', $data['store_name'] ?? 'Smart POS Store');
        $stmt->bindValue(':store_address', $data['store_address'] ?? '');
        $stmt->bindValue(':store_phone', $data['store_phone'] ?? '');
        $stmt->bindValue(':store_email', $data['store_email'] ?? '');
        $stmt->bindValue(':currency', $data['currency'] ?? '₱');
        $stmt->bindValue(':tax_rate', $data['tax_rate'] ?? 12);
        $stmt->bindValue(':tax_type', $data['tax_type'] ?? 'inclusive');
        $stmt->bindValue(':default_discount', $data['default_discount'] ?? 0);
        $stmt->bindValue(':low_stock_threshold', $data['low_stock_threshold'] ?? 5);
        $stmt->bindValue(':receipt_footer', $data['receipt_footer'] ?? '');
        $stmt->bindValue(':show_tax_on_receipt', $data['show_tax_on_receipt'] ?? 1);
        $stmt->bindValue(':show_discount_on_receipt', $data['show_discount_on_receipt'] ?? 1);
        $stmt->bindValue(':auto_print_receipt', $data['auto_print_receipt'] ?? 0);
        $stmt->bindValue(':payment_cash', $data['payment_cash'] ?? 1);
        $stmt->bindValue(':payment_gcash', $data['payment_gcash'] ?? 1);
        $stmt->bindValue(':payment_maya', $data['payment_maya'] ?? 1);
        $stmt->bindValue(':payment_card', $data['payment_card'] ?? 1);
        $stmt->bindValue(':gcash_number', $data['gcash_number'] ?? '');
        $stmt->bindValue(':maya_number', $data['maya_number'] ?? '');
        $stmt->bindValue(':invoice_prefix', $data['invoice_prefix'] ?? 'INV');
        $stmt->bindValue(':next_invoice_number', $data['next_invoice_number'] ?? 1000);
        $stmt->bindValue(':include_barcode_on_invoice', $data['include_barcode_on_invoice'] ?? 0);
        $stmt->bindValue(':include_qr_on_invoice', $data['include_qr_on_invoice'] ?? 0);
        $stmt->bindValue(':theme', $data['theme'] ?? 'dark');
        $stmt->bindValue(':primary_color', $data['primary_color'] ?? '#4F46E5');
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Settings saved successfully']);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to save settings']);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
?>