<?php
// ============================================
// 📁 File: api/auth.php
// 🔧 SMART POS API - Authentication with JWT
// ============================================

date_default_timezone_set('Asia/Manila');

// ============================================
// 1. CORS HEADERS — MUST BE FIRST, BEFORE ANY OUTPUT
// ============================================
// RULE: When Access-Control-Allow-Credentials is true, we CANNOT use "*"
// as the origin — it must be the exact origin. So we only send credentials
// headers when we matched the origin against the whitelist.

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

$allowed_origins = [
    'http://localhost:5173',
    'http://localhost:3000',
    'http://192.168.12.3:5173',
    'http://127.0.0.1:5173',
    'https://siaposkok.netlify.app',           // ← your actual Netlify site
    'https://smartpossiaaaa.kesug.com',        // ← your backend domain
];

// Also allow any *.netlify.app preview URL
$is_netlify_preview = (bool) preg_match('#^https://[a-z0-9-]+\.netlify\.app$#i', $origin);

$is_allowed = in_array($origin, $allowed_origins, true) || $is_netlify_preview;

if ($is_allowed) {
    // Full credentialed CORS
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Credentials: true");
    header("Vary: Origin");
} else {
    // Wildcard fallback — credentials must NOT be sent with "*"
    header("Access-Control-Allow-Origin: *");
}

header("Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin");
header("Access-Control-Max-Age: 86400");

// Handle preflight BEFORE any other logic
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
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
// 3. DATABASE + JWT + HELPERS
// ============================================
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

// ============================================
// 4. GET = test endpoint
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode([
        'success' => true,
        'message' => 'Auth API is working!',
        'method'  => 'GET',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit();
}

// ============================================
// 5. POST = Login
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $request_body = file_get_contents('php://input');
    $data = json_decode($request_body, true);

    if (!$data) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON data received']);
        exit();
    }

    $username = trim($data['username'] ?? '');
    $password = $data['password'] ?? '';

    if (empty($username) || empty($password)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Username and password are required']);
        exit();
    }

    try {
        $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'User not found: ' . $username]);
            exit();
        }

        if (isset($user['status']) && $user['status'] !== 'active') {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Account is not active. Please contact administrator.']);
            exit();
        }

        // Password check (hash or plain)
        $passwordMatch = false;
        $storedPassword = $user['password'];

        if (strpos($storedPassword, '$2y$') === 0) {
            $passwordMatch = password_verify($password, $storedPassword);
        } else {
            $passwordMatch = ($password === $storedPassword);
        }

        if (!$passwordMatch) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Invalid password']);
            exit();
        }

        // ============================================
        // Parse roles
        // ============================================
        $roles = [];

        if (!empty($user['roles'])) {
            $decodedRoles = json_decode($user['roles'], true);
            if (is_array($decodedRoles) && !empty($decodedRoles)) {
                $roles = $decodedRoles;
            } elseif (strpos($user['roles'], ',') !== false) {
                $roles = array_map('trim', explode(',', $user['roles']));
            } else {
                $roles = [$user['role'] ?? 'staff'];
            }
        }

        if (empty($roles)) {
            $roles = [$user['role'] ?? 'staff'];
        }

        $roles = array_values(array_filter(array_unique($roles)));

        $primaryRole = $user['role'] ?? 'staff';
        if (!in_array($primaryRole, $roles)) {
            array_unshift($roles, $primaryRole);
        }

        // Permissions
        $permissions = [];
        if (!empty($user['permissions'])) {
            if (is_string($user['permissions'])) {
                $permissions = json_decode($user['permissions'], true) ?: [];
            } elseif (is_array($user['permissions'])) {
                $permissions = $user['permissions'];
            }
        }

        // Profile picture URL
        $profilePicture = null;
        if (!empty($user['profile_picture'])) {
            if (strpos($user['profile_picture'], 'http') === 0) {
                $profilePicture = $user['profile_picture'];
            } else {
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $basePath = '/smart-pos-api';
                $profilePicture = $scheme . '://' . $host . $basePath . $user['profile_picture'];
            }
        }

        // ============================================
        // ✅ GENERATE JWT TOKEN
        // ============================================
        $token = jwt_encode([
            'user_id'  => (int)$user['id'],
            'username' => $user['username'],
            'role'     => $primaryRole,
            'roles'    => $roles,
        ]);

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Login successful',
            'token'   => $token,
            'expires_in' => JWT_EXPIRY,
            'user' => [
                'id' => (int)$user['id'],
                'username' => $user['username'],
                'full_name' => $user['full_name'] ?? $user['username'] ?? 'User',
                'email' => $user['email'] ?? '',
                'role' => $primaryRole,
                'roles' => $roles,
                'permissions' => $permissions,
                'department' => $user['department'] ?? 'General',
                'phone' => $user['phone'] ?? '',
                'profile_picture' => $profilePicture,
                'status' => $user['status'] ?? 'active',
                'salary_type' => $user['salary_type'] ?? 'daily',
                'salary_rate' => (float)($user['salary_rate'] ?? 0),
                'shift_start' => $user['shift_start'] ?? null,
                'shift_end' => $user['shift_end'] ?? null,
                'grace_minutes' => (int)($user['grace_minutes'] ?? 10),
                'work_hours_per_day' => (float)($user['work_hours_per_day'] ?? 8),
                'created_at' => $user['created_at'] ?? null
            ]
        ]);
        exit();

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        exit();
    }
}

// ============================================
// 6. Method not allowed
// ============================================
http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET or POST.']);
exit();