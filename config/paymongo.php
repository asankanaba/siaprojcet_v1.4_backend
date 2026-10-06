<?php
// ============================================
// File: config/paymongo.php
// PayMongo configuration + helpers
// ============================================

// ---------- Keys ----------
define('PAYMONGO_SECRET_KEY',     getenv('PAYMONGO_SECRET_KEY')     ?: 'sk_test_BkGrUXg4fZGcHdohDbkNQj2N');
define('PAYMONGO_PUBLIC_KEY',     getenv('PAYMONGO_PUBLIC_KEY')     ?: 'pk_test_gzzQQfyHXCzfEAqhDiD8WZQL');
define('PAYMONGO_WEBHOOK_SECRET', getenv('PAYMONGO_WEBHOOK_SECRET') ?: '');

// ---------- API ----------
define('PAYMONGO_API_BASE', 'https://api.paymongo.com/v1');
define('PAYMONGO_TIMEOUT', 30);
define('PAYMONGO_LIVEMODE', strpos(PAYMONGO_SECRET_KEY, 'sk_live_') === 0);

// ---------- URLs ----------
define('APP_FRONTEND_BASE', rtrim(getenv('APP_FRONTEND_BASE') ?: 'http://192.168.12.3:5173', '/'));
define('APP_BACKEND_BASE',  rtrim(getenv('APP_BACKEND_BASE')  ?: 'http://192.168.12.3/smart-pos-api', '/'));

define('PAYMONGO_SUCCESS_URL', APP_FRONTEND_BASE . '/payments/return?status=success');
define('PAYMONGO_CANCEL_URL',  APP_FRONTEND_BASE . '/payments/return?status=cancelled');
define('PAYMONGO_WEBHOOK_URL', APP_BACKEND_BASE  . '/api/paymongo_webhook.php');

// ---------- Method whitelist ----------
define('PAYMONGO_ALLOWED_METHODS', [
    'card'     => 'Credit / Debit Card',
    'gcash'    => 'GCash',
    'paymaya'  => 'Maya',
    'grab_pay' => 'GrabPay',
    'qrph'     => 'QR Ph',
    'billease' => 'Billease',
    'dob'      => 'Online Banking',
]);

define('PAYMONGO_DEFAULT_METHODS', ['card','gcash','paymaya','grab_pay','qrph']);

// ---------- Logging ----------
define('PAYMONGO_LOG_FILE', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'paymongo.log');

if (!function_exists('paymongo_log')) {
    function paymongo_log(string $level, string $message, array $context = []): void {
        $dir = dirname(PAYMONGO_LOG_FILE);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $line = sprintf(
            "[%s] [%s] %s %s\n",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            $context ? json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : ''
        );
        @file_put_contents(PAYMONGO_LOG_FILE, $line, FILE_APPEND | LOCK_EX);
    }
}
?>