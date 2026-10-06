<?php
// ============================================
// 📁 File: api/wallets.php
// 🔧 SMART POS API - Wallets
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
// 4. HANDLE REQUESTS
// ============================================
$method = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? $_GET['id'] : null;
$user_id = isset($_GET['user_id']) ? $_GET['user_id'] : null;

// GET request - Fetch wallets
if ($method === 'GET') {
    try {
        if ($id) {
            // Get single wallet
            $query = "SELECT w.*, u.username, u.full_name 
                      FROM wallets w 
                      LEFT JOIN users u ON w.user_id = u.id 
                      WHERE w.id = :id";
            $stmt = $conn->prepare($query);
            $stmt->bindValue(':id', $id);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                echo json_encode($result);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Wallet not found']);
            }
        } else if ($user_id) {
            // Get wallet for specific user
            $query = "SELECT * FROM wallets WHERE user_id = :user_id";
            $stmt = $conn->prepare($query);
            $stmt->bindValue(':user_id', $user_id);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                echo json_encode($result);
            } else {
                // Create wallet for user
                $query = "INSERT INTO wallets (user_id, balance, currency) VALUES (:user_id, 0, '₱')";
                $stmt = $conn->prepare($query);
                $stmt->bindValue(':user_id', $user_id);
                $stmt->execute();
                
                $newId = $conn->lastInsertId();
                $query = "SELECT * FROM wallets WHERE id = :id";
                $stmt = $conn->prepare($query);
                $stmt->bindValue(':id', $newId);
                $stmt->execute();
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode($result);
            }
        } else {
            // Get all wallets
            $query = "SELECT w.*, u.username, u.full_name 
                      FROM wallets w 
                      LEFT JOIN users u ON w.user_id = u.id 
                      ORDER BY w.updated_at DESC";
            $stmt = $conn->prepare($query);
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($results);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

// POST request - Create wallet or make transaction
if ($method === 'POST') {
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
            exit();
        }
        
        $action = isset($data['action']) ? $data['action'] : 'deposit';
        $user_id = isset($data['user_id']) ? $data['user_id'] : null;
        $amount = isset($data['amount']) ? floatval($data['amount']) : 0;
        $description = isset($data['description']) ? $data['description'] : '';
        
        if (!$user_id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'User ID is required']);
            exit();
        }
        
        if ($amount <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Amount must be greater than 0']);
            exit();
        }
        
        // Get or create wallet
        $query = "SELECT * FROM wallets WHERE user_id = :user_id";
        $stmt = $conn->prepare($query);
        $stmt->bindValue(':user_id', $user_id);
        $stmt->execute();
        $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$wallet) {
            $query = "INSERT INTO wallets (user_id, balance, currency) VALUES (:user_id, 0, '₱')";
            $stmt = $conn->prepare($query);
            $stmt->bindValue(':user_id', $user_id);
            $stmt->execute();
            
            $walletId = $conn->lastInsertId();
            $query = "SELECT * FROM wallets WHERE id = :id";
            $stmt = $conn->prepare($query);
            $stmt->bindValue(':id', $walletId);
            $stmt->execute();
            $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        $walletId = $wallet['id'];
        $currentBalance = floatval($wallet['balance']);
        $newBalance = $currentBalance;
        
        if ($action === 'deposit') {
            $newBalance = $currentBalance + $amount;
            $transactionType = 'deposit';
        } else if ($action === 'withdraw') {
            if ($amount > $currentBalance) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Insufficient balance']);
                exit();
            }
            $newBalance = $currentBalance - $amount;
            $transactionType = 'withdraw';
        } else if ($action === 'transfer') {
            // Handle transfer
            $to_user_id = isset($data['to_user_id']) ? $data['to_user_id'] : null;
            if (!$to_user_id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Recipient user ID is required']);
                exit();
            }
            
            if ($amount > $currentBalance) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Insufficient balance']);
                exit();
            }
            
            // Get recipient wallet
            $query = "SELECT * FROM wallets WHERE user_id = :user_id";
            $stmt = $conn->prepare($query);
            $stmt->bindValue(':user_id', $to_user_id);
            $stmt->execute();
            $toWallet = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$toWallet) {
                $query = "INSERT INTO wallets (user_id, balance, currency) VALUES (:user_id, 0, '₱')";
                $stmt = $conn->prepare($query);
                $stmt->bindValue(':user_id', $to_user_id);
                $stmt->execute();
                $toWalletId = $conn->lastInsertId();
            } else {
                $toWalletId = $toWallet['id'];
            }
            
            // Update sender balance
            $newBalance = $currentBalance - $amount;
            $query = "UPDATE wallets SET balance = :balance WHERE id = :id";
            $stmt = $conn->prepare($query);
            $stmt->bindValue(':balance', $newBalance);
            $stmt->bindValue(':id', $walletId);
            $stmt->execute();
            
            // Update recipient balance
            $toNewBalance = floatval($toWallet['balance']) + $amount;
            $query = "UPDATE wallets SET balance = :balance WHERE id = :id";
            $stmt = $conn->prepare($query);
            $stmt->bindValue(':balance', $toNewBalance);
            $stmt->bindValue(':id', $toWalletId);
            $stmt->execute();
            
            // Log transaction
            $query = "INSERT INTO wallet_transactions (wallet_id, from_wallet, to_wallet, type, amount, description, status) 
                      VALUES (:wallet_id, :from_wallet, :to_wallet, 'transfer', :amount, :description, 'completed')";
            $stmt = $conn->prepare($query);
            $stmt->bindValue(':wallet_id', $walletId);
            $stmt->bindValue(':from_wallet', $walletId);
            $stmt->bindValue(':to_wallet', $toWalletId);
            $stmt->bindValue(':amount', $amount);
            $stmt->bindValue(':description', $description ?: 'Transfer to user ' . $to_user_id);
            $stmt->execute();
            
            echo json_encode([
                'success' => true,
                'message' => 'Transfer successful',
                'new_balance' => $newBalance
            ]);
            exit();
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            exit();
        }
        
        // Update wallet balance
        $query = "UPDATE wallets SET balance = :balance WHERE id = :id";
        $stmt = $conn->prepare($query);
        $stmt->bindValue(':balance', $newBalance);
        $stmt->bindValue(':id', $walletId);
        $stmt->execute();
        
        // Log transaction
        $query = "INSERT INTO wallet_transactions (wallet_id, type, amount, description, status) 
                  VALUES (:wallet_id, :type, :amount, :description, 'completed')";
        $stmt = $conn->prepare($query);
        $stmt->bindValue(':wallet_id', $walletId);
        $stmt->bindValue(':type', $transactionType);
        $stmt->bindValue(':amount', $amount);
        $stmt->bindValue(':description', $description ?: ucfirst($transactionType) . ' of ₱' . number_format($amount, 2));
        $stmt->execute();
        
        echo json_encode([
            'success' => true,
            'message' => ucfirst($action) . ' successful',
            'new_balance' => $newBalance
        ]);
        
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