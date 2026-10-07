<?php
// api/products.php
// ✅ CORS + Authorization
// ✅ Portable paths (__DIR__)
// ✅ Soft-delete when FK references exist
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

// ============================================
// HELPER — convert empty category_id to NULL
// ============================================
function normalize_category_id($conn, $raw) {
    if ($raw === null || $raw === '' || $raw === 'null' || $raw === 'undefined' || (int)$raw === 0) {
        return null;
    }
    $catId = (int)$raw;
    $chk = $conn->prepare("SELECT id FROM categories WHERE id = ?");
    $chk->execute([$catId]);
    if (!$chk->fetch()) return null;
    return $catId;
}

// ============================================
// HELPER — is the product referenced anywhere?
// Returns the table name that references it, or null
// ============================================
function find_product_reference($conn, $productId) {
    $refs = [
        'supply_chain_requests' => 'product_id',
        'purchase_orders'       => 'product_id',
        'product_approvals'     => 'product_id',
        'sale_items'            => 'product_id',
        'inventory_logs'        => 'product_id',
    ];

    foreach ($refs as $table => $col) {
        try {
            $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM `$table` WHERE `$col` = ?");
            $stmt->execute([$productId]);
            $count = (int)$stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
            if ($count > 0) return ['table' => $table, 'count' => $count];
        } catch (Throwable $ignored) {
            // table may not exist in some environments — skip
        }
    }
    return null;
}

// ============================================
// GET — list or single product
// ============================================
if ($method === 'GET') {
    try {
        $id       = isset($_GET['id'])       ? $_GET['id']       : null;
        $category = isset($_GET['category']) ? $_GET['category'] : null;
        $status   = isset($_GET['status'])   ? $_GET['status']   : null;
        $search   = isset($_GET['search'])   ? $_GET['search']   : null;
        $lowStock = isset($_GET['low_stock']) && $_GET['low_stock'] == '1';

        $sql = "SELECT p.*, c.name AS category_name
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                WHERE 1=1";
        $params = [];

        if ($id) {
            $sql .= " AND p.id = :id";
            $params[':id'] = $id;
        }
        if ($category) {
            $sql .= " AND p.category_id = :category";
            $params[':category'] = $category;
        }
        if ($status) {
            $sql .= " AND p.status = :status";
            $params[':status'] = $status;
        }
        if ($search) {
            $sql .= " AND p.name LIKE :search";
            $params[':search'] = "%$search%";
        }
        if ($lowStock) {
            $sql .= " AND p.stock <= COALESCE(p.low_stock_threshold, 5) AND p.status = 'active'";
        }

        $sql .= " ORDER BY p.id DESC";

        $stmt = $conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($products);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

// ============================================
// POST — create product (JSON or FormData)
// ============================================
if ($method === 'POST') {
    try {
        if (!empty($_POST)) {
            $data = $_POST;
        } else {
            $data = json_decode(file_get_contents("php://input"), true) ?: [];
        }

        $name        = isset($data['name'])        ? trim($data['name']) : '';
        $price       = isset($data['price'])       ? floatval($data['price']) : 0;
        $stock       = isset($data['stock'])       ? intval($data['stock']) : 0;
        $description = isset($data['description']) ? $data['description'] : '';
        $barcode     = isset($data['barcode'])     ? $data['barcode'] : '';
        $status      = isset($data['status'])      ? $data['status'] : 'active';
        $image_url   = '';

        // ✅ Sanitize category_id — this fixes the FK error
        $category_id = normalize_category_id($conn, $data['category_id'] ?? null);

        // Handle image upload
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $isVercel = getenv('VERCEL') === '1';
            $uploadDir = $isVercel
                ? sys_get_temp_dir() . '/uploads/products/'
                : __DIR__ . '/../uploads/products/';

            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

            $fileExtension = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
            $newFileName = uniqid('product_') . '.' . $fileExtension;
            $targetPath = $uploadDir . $newFileName;

            if (move_uploaded_file($_FILES['image']['tmp_name'], $targetPath)) {
                $image_url = '/uploads/products/' . $newFileName;
            }
        }

        if (empty($name)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Product name is required']);
            exit();
        }

        $sql = "INSERT INTO products (name, description, price, stock, category_id, image_url, barcode, status)
                VALUES (:name, :description, :price, :stock, :category_id, :image_url, :barcode, :status)";

        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':name',        $name);
        $stmt->bindValue(':description', $description);
        $stmt->bindValue(':price',       $price);
        $stmt->bindValue(':stock',       $stock);
        if ($category_id === null) {
            $stmt->bindValue(':category_id', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':category_id', $category_id, PDO::PARAM_INT);
        }
        $stmt->bindValue(':image_url',   $image_url);
        $stmt->bindValue(':barcode',     $barcode);
        $stmt->bindValue(':status',      $status);

        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'id' => $conn->lastInsertId()]);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to create product']);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

// ============================================
// PUT — update product
// ============================================
if ($method === 'PUT') {
    try {
        $id = isset($_GET['id']) ? $_GET['id'] : null;
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Product ID is required']);
            exit();
        }

        if (!empty($_POST)) {
            $data = $_POST;
        } else {
            $data = json_decode(file_get_contents("php://input"), true) ?: [];
        }

        $updates = [];
        $params = [':id' => $id];

        if (isset($data['name']))        { $updates[] = "name = :name";               $params[':name'] = $data['name']; }
        if (isset($data['description'])) { $updates[] = "description = :description"; $params[':description'] = $data['description']; }
        if (isset($data['price']))       { $updates[] = "price = :price";             $params[':price'] = $data['price']; }
        if (isset($data['stock']))       { $updates[] = "stock = :stock";             $params[':stock'] = $data['stock']; }
        if (isset($data['status']))      { $updates[] = "status = :status";           $params[':status'] = $data['status']; }

        // ✅ Sanitize category_id on update too
        if (array_key_exists('category_id', $data)) {
            $catId = normalize_category_id($conn, $data['category_id']);
            $updates[] = "category_id = :category_id";
            if ($catId === null) {
                $params[':category_id'] = null;
            } else {
                $params[':category_id'] = $catId;
            }
        }

        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $isVercel = getenv('VERCEL') === '1';
            $uploadDir = $isVercel
                ? sys_get_temp_dir() . '/uploads/products/'
                : __DIR__ . '/../uploads/products/';

            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

            $fileExtension = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
            $newFileName = uniqid('product_') . '.' . $fileExtension;
            $targetPath = $uploadDir . $newFileName;

            if (move_uploaded_file($_FILES['image']['tmp_name'], $targetPath)) {
                $image_url = '/uploads/products/' . $newFileName;
                $updates[] = "image_url = :image_url";
                $params[':image_url'] = $image_url;
            }
        }

        if (empty($updates)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No data provided to update']);
            exit();
        }

        $sql = "UPDATE products SET " . implode(", ", $updates) . " WHERE id = :id";
        $stmt = $conn->prepare($sql);
        foreach ($params as $key => $value) {
            if ($value === null) {
                $stmt->bindValue($key, null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue($key, $value);
            }
        }

        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Product updated successfully']);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to update product']);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

// ============================================
// DELETE — soft-delete when referenced
// ============================================
if ($method === 'DELETE') {
    try {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Product ID is required']);
            exit();
        }

        // Verify product exists
        $chk = $conn->prepare("SELECT id, name, status FROM products WHERE id = ?");
        $chk->execute([$id]);
        $product = $chk->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Product not found']);
            exit();
        }

        // 🔍 Check for references
        $ref = find_product_reference($conn, $id);

        if ($ref !== null) {
            // 🛡️ Soft-delete: archive instead of deleting
            $upd = $conn->prepare("UPDATE products SET status = 'archived' WHERE id = ?");
            $upd->execute([$id]);

            echo json_encode([
                'success'  => true,
                'archived' => true,
                'message'  => "Product has {$ref['count']} linked record(s) in `{$ref['table']}` and was archived instead of deleted.",
                'ref_table' => $ref['table'],
                'ref_count' => $ref['count'],
            ]);
            exit();
        }

        // ✅ No references → safe to hard delete
        $stmt = $conn->prepare("DELETE FROM products WHERE id = :id");
        $stmt->bindParam(':id', $id);
        $stmt->execute();

        echo json_encode([
            'success'  => true,
            'archived' => false,
            'message'  => 'Product deleted successfully',
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
?>