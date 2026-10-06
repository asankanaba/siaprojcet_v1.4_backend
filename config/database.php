<?php
// ============================================
// 📁 File: config/database.php
// 🔧 Database Configuration + JWT + Notification Helpers
// 🌐 Auto-detects: Local (XAMPP) vs Production (Vercel + Aiven)
// ============================================

date_default_timezone_set('Asia/Manila');

// ============================================
// 0. ENVIRONMENT DETECTION
// ============================================
function smartpos_is_production(): bool {
    $dir = __DIR__;

    if (file_exists($dir . '/.production')) return true;
    if (file_exists($dir . '/.local'))      return false;

    $env = getenv('APP_ENV');
    if ($env === 'production') return true;
    if ($env === 'development' || $env === 'local') return false;

    if (getenv('DB_HOST')) return true;

    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
    $host = strtolower(preg_replace('/:\d+$/', '', $host));

    if ($host === '') return false;

    if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) return false;
    if (strpos($host, 'localhost') !== false) return false;
    if (strpos($host, '127.0.0.1') !== false) return false;
    if (strpos($host, '::1') !== false)       return false;

    if (preg_match('/^192\.168\.\d+\.\d+$/', $host)) return false;
    if (preg_match('/^10\.\d+\.\d+\.\d+$/', $host))  return false;
    if (preg_match('/^172\.(1[6-9]|2\d|3[01])\.\d+\.\d+$/', $host)) return false;

    if (substr($host, -6) === '.local') return false;

    return true;
}

$isProduction = smartpos_is_production();

// ============================================
// 1. DATABASE CREDENTIALS
// ============================================
if (getenv('DB_HOST')) {
    $db_host     = getenv('DB_HOST');
    $db_name     = getenv('DB_NAME');
    $db_username = getenv('DB_USER');
    $db_password = getenv('DB_PASS');
    $db_port     = getenv('DB_PORT') ?: 3306;
    $use_ssl     = true;
    $ssl_ca_path = __DIR__ . '/ca.pem';
} else {
    $db_host     = '127.0.0.1';
    $db_name     = 'smart_pos';
    $db_username = 'root';
    $db_password = '';
    $db_port     = 3306;
    $use_ssl     = false;
    $ssl_ca_path = null;
}

// ============================================
// 2. JWT CONFIG
// ============================================
if (!defined('JWT_SECRET')) {
    define('JWT_SECRET', getenv('JWT_SECRET') ?: 'change_this_to_a_long_random_string_please_2026_smart_pos');
}
if (!defined('JWT_EXPIRY')) {
    define('JWT_EXPIRY', 60 * 60 * 24 * 7);
}
if (!defined('JWT_ALGO')) {
    define('JWT_ALGO', 'HS256');
}

// ============================================
// 3. DIRECT CONNECTION (with enhanced error reporting)
// ============================================
try {
    $dsn = "mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=utf8mb4";

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 10,
    ];

    // Aiven SSL configuration
    if ($use_ssl && $ssl_ca_path && file_exists($ssl_ca_path)) {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $ssl_ca_path;
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
    } elseif ($use_ssl) {
        // SSL required but no CA file — try without cert verification
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
    }

    $conn = new PDO($dsn, $db_username, $db_password, $options);

} catch (Throwable $e) {
    // Throwable catches BOTH PDOException and PHP fatal errors
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'DB connection failed: ' . $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
        'env'     => $isProduction ? 'production' : 'local',
        'host'    => $db_host,
        'port'    => $db_port,
        'db'      => $db_name,
        'user'    => $db_username,
        'ssl'     => $use_ssl,
        'ca_exists' => $ssl_ca_path ? file_exists($ssl_ca_path) : false,
    ]);
    exit();
}

// ============================================
// 4. DATABASE CLASS
// ============================================
class Database {
    private $host;
    private $db_name;
    private $username;
    private $password;
    private $port;
    private $conn;
    private $ssl_ca;

    public function __construct() {
        if (getenv('DB_HOST')) {
            $this->host     = getenv('DB_HOST');
            $this->db_name  = getenv('DB_NAME');
            $this->username = getenv('DB_USER');
            $this->password = getenv('DB_PASS');
            $this->port     = getenv('DB_PORT') ?: 3306;
            $this->ssl_ca   = __DIR__ . '/ca.pem';
        } else {
            $this->host     = '127.0.0.1';
            $this->db_name  = 'smart_pos';
            $this->username = 'root';
            $this->password = '';
            $this->port     = 3306;
            $this->ssl_ca   = null;
        }
    }

    public function getConnection() {
        try {
            $dsn = "mysql:host={$this->host};port={$this->port};dbname={$this->db_name};charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 10,
            ];
            if ($this->ssl_ca && file_exists($this->ssl_ca)) {
                $options[PDO::MYSQL_ATTR_SSL_CA] = $this->ssl_ca;
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
            }
            $this->conn = new PDO($dsn, $this->username, $this->password, $options);
            return $this->conn;
        } catch (Throwable $e) {
            error_log("Database::getConnection failed: " . $e->getMessage());
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'DB connection failed: ' . $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);
            exit();
        }
    }
}

// ============================================
// 5. JWT HELPERS
// ============================================
if (!function_exists('jwt_b64url_encode')) {
    function jwt_b64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

if (!function_exists('jwt_b64url_decode')) {
    function jwt_b64url_decode($data) {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }
}

if (!function_exists('jwt_encode')) {
    function jwt_encode(array $payload, $expiry = null) {
        $expiry = $expiry ?: JWT_EXPIRY;
        $header = ['alg' => JWT_ALGO, 'typ' => 'JWT'];
        $payload['iat'] = time();
        $payload['exp'] = time() + $expiry;

        $segments = [
            jwt_b64url_encode(json_encode($header)),
            jwt_b64url_encode(json_encode($payload))
        ];

        $signing_input = implode('.', $segments);
        $signature = hash_hmac('sha256', $signing_input, JWT_SECRET, true);
        $segments[] = jwt_b64url_encode($signature);

        return implode('.', $segments);
    }
}

if (!function_exists('jwt_decode')) {
    function jwt_decode($token) {
        if (!$token || !is_string($token)) return null;
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;

        list($header_b64, $payload_b64, $signature_b64) = $parts;

        $expected_sig = hash_hmac('sha256', $header_b64 . '.' . $payload_b64, JWT_SECRET, true);
        $actual_sig   = jwt_b64url_decode($signature_b64);

        if (!hash_equals($expected_sig, $actual_sig)) return null;

        $payload = json_decode(jwt_b64url_decode($payload_b64), true);
        if (!is_array($payload)) return null;

        if (isset($payload['exp']) && $payload['exp'] < time()) return null;

        return $payload;
    }
}

if (!function_exists('getAuthUser')) {
    function getAuthUser() {
        $header = null;

        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        } elseif (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if (isset($headers['Authorization'])) {
                $header = $headers['Authorization'];
            } elseif (isset($headers['authorization'])) {
                $header = $headers['authorization'];
            }
        }

        if (!$header) return null;

        if (preg_match('/Bearer\s+(.*)$/i', $header, $matches)) {
            return jwt_decode(trim($matches[1]));
        }

        return null;
    }
}

if (!function_exists('requireAuth')) {
    function requireAuth() {
        $user = getAuthUser();
        if (!$user) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Unauthorized. Please login again.'
            ]);
            exit();
        }
        return $user;
    }
}

// ============================================
// 6. NOTIFICATION HELPERS
// ============================================
if (!function_exists('createNotification')) {
    function createNotification($conn, $user_id, $title, $message, $type = 'info', $severity = 'info') {
        try {
            $query = "INSERT INTO notifications (user_id, title, message, type, severity, is_read, created_at)
                      VALUES (:user_id, :title, :message, :type, :severity, 0, NOW())";
            $stmt = $conn->prepare($query);
            $stmt->bindValue(':user_id', (int)$user_id, PDO::PARAM_INT);
            $stmt->bindValue(':title', $title, PDO::PARAM_STR);
            $stmt->bindValue(':message', $message, PDO::PARAM_STR);
            $stmt->bindValue(':type', $type, PDO::PARAM_STR);
            $stmt->bindValue(':severity', $severity, PDO::PARAM_STR);
            return $stmt->execute();
        } catch (Throwable $e) {
            error_log("createNotification error: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('notifyUser')) {
    function notifyUser($conn, $user_id, $title, $message, $type = 'info', $severity = 'info') {
        return createNotification($conn, $user_id, $title, $message, $type, $severity);
    }
}

if (!function_exists('notifyRole')) {
    function notifyRole($conn, $roles, $title, $message, $type = 'info', $severity = 'info', $exclude_user_id = null) {
        if (!is_array($roles)) $roles = [$roles];
        if (empty($roles)) return 0;

        try {
            $conditions = [];
            $params = [];

            foreach ($roles as $i => $r) {
                $keyRole = ":role_$i";
                $keyJson = ":json_$i";
                $conditions[] = "role = $keyRole";
                $conditions[] = "JSON_CONTAINS(roles, JSON_QUOTE($keyJson))";
                $params[$keyRole] = $r;
                $params[$keyJson] = $r;
            }

            $where = "(" . implode(' OR ', $conditions) . ")";
            $where .= " AND status = 'active'";

            if ($exclude_user_id) {
                $where .= " AND id != :exclude_id";
                $params[':exclude_id'] = (int)$exclude_user_id;
            }

            $sql = "SELECT id FROM users WHERE $where";
            $stmt = $conn->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v);
            }
            $stmt->execute();
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $count = 0;
            foreach ($users as $u) {
                if (createNotification($conn, $u['id'], $title, $message, $type, $severity)) {
                    $count++;
                }
            }
            return $count;
        } catch (Throwable $e) {
            error_log("notifyRole error: " . $e->getMessage());
            return 0;
        }
    }
}
?>