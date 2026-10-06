<?php
// api/users.php - Complete working version with profile picture support
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

require_once __DIR__ . '/../config/database.php';

$method = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? $_GET['id'] : null;
$action = isset($_GET['action']) ? $_GET['action'] : null;

// ============================================
// GET - Handle all GET requests
// ============================================
if ($method === 'GET') {
    try {
        // Handle action=me (get current user)
        if ($action === 'me') {
            // For demo, get the first user or use session
            // In production, use JWT token
            $query = "SELECT id, username, full_name, email, role, roles, status, department, phone, 
                             profile_picture, created_at 
                      FROM users 
                      WHERE status = 'active' OR status IS NULL
                      LIMIT 1";
            
            $stmt = $conn->prepare($query);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                // Parse roles
                if (!empty($result['roles'])) {
                    $roles = json_decode($result['roles'], true);
                    if (!is_array($roles)) {
                        $roles = [$result['role'] ?? 'staff'];
                    }
                } else {
                    $roles = [$result['role'] ?? 'staff'];
                }
                $result['roles'] = $roles;
                
                // Fix profile picture URL
                if (!empty($result['profile_picture'])) {
                    if (!str_starts_with($result['profile_picture'], 'http')) {
                        $result['profile_picture'] = 'http://localhost/smart-pos-api' . $result['profile_picture'];
                    }
                }
                
                echo json_encode($result);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'User not found']);
            }
            exit();
        }
        
        // Get user by ID
        if ($id) {
            $query = "SELECT id, username, full_name, email, role, roles, status, department, phone, 
                             profile_picture, created_at 
                      FROM users 
                      WHERE id = :id";
            $stmt = $conn->prepare($query);
            $stmt->bindValue(':id', $id);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                // Parse roles
                if (!empty($result['roles'])) {
                    $roles = json_decode($result['roles'], true);
                    if (!is_array($roles)) {
                        $roles = [$result['role'] ?? 'staff'];
                    }
                } else {
                    $roles = [$result['role'] ?? 'staff'];
                }
                $result['roles'] = $roles;
                
                // Fix profile picture URL
                if (!empty($result['profile_picture'])) {
                    if (!str_starts_with($result['profile_picture'], 'http')) {
                        $result['profile_picture'] = 'http://localhost/smart-pos-api' . $result['profile_picture'];
                    }
                }
                
                echo json_encode($result);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'User not found']);
            }
            exit();
        }
        
        // Get all users
        $query = "SELECT id, username, full_name, email, role, roles, status, department, phone,
                         profile_picture, created_at 
                  FROM users 
                  WHERE status = 'active' OR status IS NULL
                  ORDER BY full_name ASC";
        
        $stmt = $conn->prepare($query);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Parse roles for each user
        foreach ($results as &$user) {
            if (!empty($user['roles'])) {
                $roles = json_decode($user['roles'], true);
                if (!is_array($roles)) {
                    $roles = [$user['role'] ?? 'staff'];
                }
            } else {
                $roles = [$user['role'] ?? 'staff'];
            }
            $user['roles'] = $roles;
            
            // Fix profile picture URL
            if (!empty($user['profile_picture'])) {
                if (!str_starts_with($user['profile_picture'], 'http')) {
                    $user['profile_picture'] = 'http://localhost/smart-pos-api' . $user['profile_picture'];
                }
            }
        }
        
        echo json_encode($results);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

// ============================================
// PUT - Update User
// ============================================
if ($method === 'PUT') {
    try {
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'User ID is required']);
            exit();
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
            exit();
        }
        
        // Log the received data for debugging
        error_log('PUT data: ' . print_r($data, true));
        
        $checkQuery = "SELECT id FROM users WHERE id = :id";
        $checkStmt = $conn->prepare($checkQuery);
        $checkStmt->bindValue(':id', $id);
        $checkStmt->execute();
        
        if (!$checkStmt->fetch()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'User not found']);
            exit();
        }
        
        $updates = [];
        $params = [':id' => $id];
        
        // Basic fields
        $fields = ['full_name', 'email', 'department', 'phone', 'status'];
        
        foreach ($fields as $field) {
            if (isset($data[$field])) {
                $updates[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }
        
        // ✅ Handle profile picture - FIXED
        if (isset($data['profile_picture']) && !empty($data['profile_picture'])) {
            $profilePicture = $data['profile_picture'];
            
            // Check if it's a base64 image
            if (strpos($profilePicture, 'data:image') === 0) {
                try {
                    // Decode base64 image
                    $image_parts = explode(';base64,', $profilePicture);
                    if (count($image_parts) >= 2) {
                        $image_type_aux = explode('image/', $image_parts[0]);
                        $image_type = $image_type_aux[1] ?? 'png';
                        $image_base64 = base64_decode($image_parts[1]);
                        
                        // Create uploads directory if not exists
                        $upload_dir = __DIR__ . '/../uploads/profiles/';
                        if (!file_exists($upload_dir)) {
                            mkdir($upload_dir, 0777, true);
                        }
                        
                        $filename = 'profile_' . $id . '_' . time() . '.' . $image_type;
                        $filepath = $upload_dir . $filename;
                        
                        if (file_put_contents($filepath, $image_base64)) {
                            $profilePath = '/uploads/profiles/' . $filename;
                            $updates[] = "profile_picture = :profile_picture";
                            $params[':profile_picture'] = $profilePath;
                            error_log('Profile picture saved: ' . $profilePath);
                        } else {
                            error_log('Failed to save profile picture');
                        }
                    }
                } catch (Exception $e) {
                    error_log('Error saving profile picture: ' . $e->getMessage());
                }
            } else {
                // It's already a path or URL
                $updates[] = "profile_picture = :profile_picture";
                $params[':profile_picture'] = $profilePicture;
            }
        }
        
        // ✅ Handle password change
        if (isset($data['current_password']) && isset($data['new_password']) && !empty($data['new_password'])) {
            // Verify current password
            $passQuery = "SELECT password FROM users WHERE id = :id";
            $passStmt = $conn->prepare($passQuery);
            $passStmt->bindValue(':id', $id);
            $passStmt->execute();
            $userData = $passStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($userData) {
                $currentPassword = $data['current_password'];
                $storedPassword = $userData['password'];
                
                $passwordMatch = false;
                if (strpos($storedPassword, '$2y$') === 0) {
                    $passwordMatch = password_verify($currentPassword, $storedPassword);
                } else {
                    $passwordMatch = ($currentPassword === $storedPassword);
                }
                
                if ($passwordMatch) {
                    $hashedPassword = password_hash($data['new_password'], PASSWORD_DEFAULT);
                    $updates[] = "password = :password";
                    $params[':password'] = $hashedPassword;
                } else {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Current password is incorrect']);
                    exit();
                }
            }
        }
        
        if (empty($updates)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No fields to update']);
            exit();
        }
        
        $query = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = :id";
        $stmt = $conn->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        if ($stmt->execute()) {
            // Get updated user data to return
            $getQuery = "SELECT id, username, full_name, email, role, roles, status, department, phone, profile_picture, created_at FROM users WHERE id = :id";
            $getStmt = $conn->prepare($getQuery);
            $getStmt->bindValue(':id', $id);
            $getStmt->execute();
            $updatedUser = $getStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($updatedUser) {
                // Parse roles
                if (!empty($updatedUser['roles'])) {
                    $roles = json_decode($updatedUser['roles'], true);
                    if (!is_array($roles)) {
                        $roles = [$updatedUser['role'] ?? 'staff'];
                    }
                } else {
                    $roles = [$updatedUser['role'] ?? 'staff'];
                }
                $updatedUser['roles'] = $roles;
                
                // Fix profile picture URL
                if (!empty($updatedUser['profile_picture'])) {
                    if (!str_starts_with($updatedUser['profile_picture'], 'http')) {
                        $updatedUser['profile_picture'] = 'http://localhost/smart-pos-api' . $updatedUser['profile_picture'];
                    }
                }
            }
            
            echo json_encode([
                'success' => true, 
                'message' => 'User updated successfully',
                'user' => $updatedUser
            ]);
        } else {
            throw new Exception('Failed to update user');
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// ============================================
// POST - Create User
// ============================================
if ($method === 'POST') {
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
            exit();
        }
        
        $username = isset($data['username']) ? trim($data['username']) : '';
        $full_name = isset($data['full_name']) ? trim($data['full_name']) : '';
        $email = isset($data['email']) ? trim($data['email']) : '';
        $password = isset($data['password']) ? $data['password'] : 'password';
        $role = isset($data['role']) ? $data['role'] : 'staff';
        $department = isset($data['department']) ? $data['department'] : 'General';
        $phone = isset($data['phone']) ? $data['phone'] : '';
        
        // Handle multiple roles
        $roles = isset($data['roles']) ? $data['roles'] : [$role];
        if (!is_array($roles)) {
            $roles = [$role];
        }
        if (!in_array($role, $roles)) {
            array_unshift($roles, $role);
        }
        $rolesJson = json_encode(array_unique($roles));
        
        if (empty($username)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Username is required']);
            exit();
        }
        
        if (empty($full_name)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Full name is required']);
            exit();
        }
        
        $checkQuery = "SELECT id FROM users WHERE username = :username";
        $checkStmt = $conn->prepare($checkQuery);
        $checkStmt->bindValue(':username', $username);
        $checkStmt->execute();
        
        if ($checkStmt->fetch()) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Username already exists']);
            exit();
        }
        
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        
        $query = "INSERT INTO users 
                  (username, full_name, email, password, role, roles, department, phone, status) 
                  VALUES 
                  (:username, :full_name, :email, :password, :role, :roles, :department, :phone, 'active')";
        
        $stmt = $conn->prepare($query);
        $stmt->bindValue(':username', $username);
        $stmt->bindValue(':full_name', $full_name);
        $stmt->bindValue(':email', $email);
        $stmt->bindValue(':password', $hashedPassword);
        $stmt->bindValue(':role', $role);
        $stmt->bindValue(':roles', $rolesJson);
        $stmt->bindValue(':department', $department);
        $stmt->bindValue(':phone', $phone);
        
        if ($stmt->execute()) {
            echo json_encode([
                'success' => true,
                'id' => $conn->lastInsertId(),
                'message' => 'User created successfully'
            ]);
        } else {
            throw new Exception('Failed to create user');
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// ============================================
// DELETE - Delete User
// ============================================
if ($method === 'DELETE') {
    try {
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'User ID is required']);
            exit();
        }
        
        $query = "DELETE FROM users WHERE id = :id";
        $stmt = $conn->prepare($query);
        $stmt->bindValue(':id', $id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'User deleted successfully']);
        } else {
            throw new Exception('Failed to delete user');
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed']);
exit();
?>