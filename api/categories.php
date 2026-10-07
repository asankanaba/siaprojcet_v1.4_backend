<?php
// api/categories.php — Full CRUD
// ✅ Vercel + XAMPP compatible
// ✅ Returns both wrapped {success, data} and raw array for legacy callers

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';

$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;

// Parse input body (JSON or form-encoded) — reused across POST/PUT/DELETE
$rawInput = file_get_contents('php://input');
$jsonInput = json_decode($rawInput, true);
$data = is_array($jsonInput) ? $jsonInput : [];
if (empty($data) && !empty($_POST)) $data = $_POST;
if (empty($data) && !empty($_GET))  $data = array_merge($data, $_GET);

// ============================================
// GET — list all OR single
// ============================================
if ($method === 'GET') {
    try {
        // Single category
        if ($id) {
            $stmt = $conn->prepare("
                SELECT c.*,
                       (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS product_count
                FROM categories c
                WHERE c.id = :id
            ");
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Category not found']);
                exit();
            }

            echo json_encode(['success' => true, 'data' => $row]);
            exit();
        }

        // All categories with product count
        $stmt = $conn->prepare("
            SELECT c.*,
                   (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS product_count
            FROM categories c
            ORDER BY c.name ASC
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Return BOTH shapes so frontends keep working
        // If ?raw=1 → return bare array (old behavior)
        if (isset($_GET['raw']) && $_GET['raw'] == '1') {
            echo json_encode($rows);
        } else {
            echo json_encode([
                'success' => true,
                'data'    => $rows
            ]);
        }
        exit();

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        exit();
    }
}

// ============================================
// POST — create new category
// ============================================
if ($method === 'POST') {
    try {
        $name = isset($data['name']) ? trim($data['name']) : '';

        if ($name === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Category name is required']);
            exit();
        }

        // Duplicate check (case-insensitive)
        $check = $conn->prepare("SELECT id FROM categories WHERE LOWER(name) = LOWER(:name) LIMIT 1");
        $check->bindValue(':name', $name);
        $check->execute();
        if ($check->fetch()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Category already exists']);
            exit();
        }

        $stmt = $conn->prepare("INSERT INTO categories (name) VALUES (:name)");
        $stmt->bindValue(':name', $name);
        $stmt->execute();

        $newId = (int)$conn->lastInsertId();

        // Optional notification
        if (function_exists('notifyRole')) {
            notifyRole(
                $conn,
                ['admin', 'super_admin', 'supply_chain', 'finance'],
                '🆕 Category Added',
                "New category '{$name}' was created.",
                'info',
                'info'
            );
        }

        echo json_encode([
            'success' => true,
            'id'      => $newId,
            'name'    => $name,
            'message' => 'Category created successfully'
        ]);
        exit();

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        exit();
    }
}

// ============================================
// PUT — update category name
// ============================================
if ($method === 'PUT') {
    try {
        // ID can come from ?id= or from body
        if (!$id && isset($data['id'])) $id = (int)$data['id'];

        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Category ID is required']);
            exit();
        }

        $name = isset($data['name']) ? trim($data['name']) : '';

        if ($name === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Category name is required']);
            exit();
        }

        // Check exists
        $check = $conn->prepare("SELECT id FROM categories WHERE id = :id");
        $check->bindValue(':id', $id, PDO::PARAM_INT);
        $check->execute();
        if (!$check->fetch()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Category not found']);
            exit();
        }

        // Duplicate check (excluding self, case-insensitive)
        $dup = $conn->prepare("SELECT id FROM categories WHERE LOWER(name) = LOWER(:name) AND id != :id LIMIT 1");
        $dup->bindValue(':name', $name);
        $dup->bindValue(':id', $id, PDO::PARAM_INT);
        $dup->execute();
        if ($dup->fetch()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Another category with this name already exists']);
            exit();
        }

        $stmt = $conn->prepare("UPDATE categories SET name = :name WHERE id = :id");
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        echo json_encode(['success' => true, 'message' => 'Category updated successfully']);
        exit();

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        exit();
    }
}

// ============================================
// DELETE — remove category (block if in use)
// ============================================
if ($method === 'DELETE') {
    try {
        if (!$id && isset($data['id'])) $id = (int)$data['id'];

        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Category ID is required']);
            exit();
        }

        // Check if any product uses this category
        $used = $conn->prepare("SELECT COUNT(*) AS cnt FROM products WHERE category_id = :id");
        $used->bindValue(':id', $id, PDO::PARAM_INT);
        $used->execute();
        $count = (int)$used->fetch(PDO::FETCH_ASSOC)['cnt'];

        if ($count > 0) {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'message' => "Cannot delete — {$count} product(s) are still using this category."
            ]);
            exit();
        }

        $stmt = $conn->prepare("DELETE FROM categories WHERE id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        echo json_encode(['success' => true, 'message' => 'Category deleted successfully']);
        exit();

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        exit();
    }
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed']);
?>