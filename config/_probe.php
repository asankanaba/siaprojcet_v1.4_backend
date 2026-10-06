<?php
require_once __DIR__ . '/paymongo.php';
header('Content-Type: text/plain');
echo "PAYMONGO_SECRET_KEY = " . PAYMONGO_SECRET_KEY . "\n";
echo "PAYMONGO_API_BASE   = " . PAYMONGO_API_BASE   . "\n";
echo "PAYMONGO_TIMEOUT    = " . PAYMONGO_TIMEOUT    . "\n";
echo "PAYMONGO_LOG_FILE   = " . PAYMONGO_LOG_FILE   . "\n";
echo "APP_FRONTEND_BASE   = " . APP_FRONTEND_BASE   . "\n";
echo "APP_BACKEND_BASE    = " . APP_BACKEND_BASE    . "\n";
echo "Methods: " . implode(', ', array_keys(PAYMONGO_ALLOWED_METHODS)) . "\n";
echo "paymongo_log exists: " . (function_exists('paymongo_log') ? 'yes' : 'no') . "\n";