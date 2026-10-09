<?php
// ============================================
// 📁 File: api/ping.php
// 💓 Keep-alive endpoint — wakes Aiven, returns fast
// ============================================
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$start = microtime(true);
$dbOk  = false;
$err   = null;

try {
    require_once __DIR__ . '/../config/database.php';
    $db   = new Database();
    $conn = $db->getConnection();
    if ($conn) {
        $conn->query('SELECT 1');
        $dbOk = true;
    }
} catch (Throwable $e) {
    $err = $e->getMessage();
}

$elapsed = round((microtime(true) - $start) * 1000, 2);

echo json_encode([
    'success'    => $dbOk,
    'db_awake'   => $dbOk,
    'elapsed_ms' => $elapsed,
    'time'       => date('c'),
    'error'      => $err,
]);