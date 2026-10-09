<?php
// ============================================
// 📁 File: api/wallet_transactions.php
// 💰 Wallet Transactions — read-only history
//    GET only. Writes are handled in wallets.php
// ============================================
declare(strict_types=1);

date_default_timezone_set('Asia/Manila');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit();
}

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../config/database.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    $db   = new Database();
    $conn = $db->getConnection();
    if (!$conn) throw new Exception('Database connection failed');

    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed. Use wallets.php for writes.']);
        exit();
    }

    // ============================================
    // Filters
    // ============================================
    $id       = isset($_GET['id'])       ? (int)$_GET['id']       : null;
    $walletId = isset($_GET['wallet_id']) ? (int)$_GET['wallet_id'] : null;
    $userId   = isset($_GET['user_id'])   ? (int)$_GET['user_id']   : null;
    $type     = isset($_GET['type'])      ? trim($_GET['type'])     : null;
    $limit    = isset($_GET['limit'])     ? min(500, max(1, (int)$_GET['limit'])) : 100;

    // ============================================
    // Single transaction by ID
    // ============================================
    if ($id) {
        $stmt = $conn->prepare("
            SELECT wt.*,
                   w.user_id,
                   u.full_name,
                   u.username,
                   fw.balance AS from_wallet_balance,
                   tw.balance AS to_wallet_balance
            FROM wallet_transactions wt
            LEFT JOIN wallets w ON wt.wallet_id = w.id
            LEFT JOIN users u ON w.user_id = u.id
            LEFT JOIN wallets fw ON wt.from_wallet = fw.id
            LEFT JOIN wallets tw ON wt.to_wallet = tw.id
            WHERE wt.id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Transaction not found']);
            exit();
        }

        echo json_encode(['success' => true, 'data' => $row]);
        exit();
    }

    // ============================================
    // Resolve user_id → wallet_id
    // ============================================
    if ($userId && !$walletId) {
        $w = $conn->prepare("SELECT id FROM wallets WHERE user_id = ? LIMIT 1");
        $w->execute([$userId]);
        $wallet = $w->fetch(PDO::FETCH_ASSOC);
        if ($wallet) {
            $walletId = (int)$wallet['id'];
        } else {
            // No wallet yet → return empty list (not an error)
            echo json_encode([
                'success' => true,
                'data'    => [],
                'total'   => 0,
                'message' => 'No wallet for this user',
            ]);
            exit();
        }
    }

    // ============================================
    // List transactions with optional filters
    // ============================================
    $sql = "
        SELECT wt.*,
               w.user_id,
               u.full_name,
               u.username
        FROM wallet_transactions wt
        LEFT JOIN wallets w ON wt.wallet_id = w.id
        LEFT JOIN users u ON w.user_id = u.id
        WHERE 1=1
    ";
    $params = [];

    if ($walletId) {
        $sql .= " AND wt.wallet_id = ?";
        $params[] = $walletId;
    }

    if ($type && in_array($type, ['deposit', 'withdraw', 'transfer', 'refund', 'payment'], true)) {
        $sql .= " AND wt.type = ?";
        $params[] = $type;
    }

    $sql .= " ORDER BY wt.created_at DESC, wt.id DESC LIMIT {$limit}";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ============================================
    // Return flat array for compatibility
    // (FinanceWallet.vue expects Array.isArray(data))
    // ============================================
    echo json_encode([
        'success' => true,
        'data'    => $rows,
        'total'   => count($rows),
    ]);
    exit();

} catch (Throwable $e) {
    error_log('wallet_transactions.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage(),
    ]);
}
?>