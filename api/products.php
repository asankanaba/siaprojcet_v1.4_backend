<?php
// api/products.php
// ✅ CORS + Authorization
// ✅ Portable paths (__DIR__)
// ✅ POST with ?id= updates (prevents duplicate on edit)
// ✅ Soft-delete when FK references exist
// ✅ Cloudinary image uploads + URL fallback
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
require_once __DIR__ . '/_cloudinary_client.php';

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
        } catch (Throwable $ignored) {}
    }
    return null;
}

// ============================================
// HELPER — resolve image URL from either a
// pasted URL (secondary) or an uploaded file (primary)
// ============================================
function resolve_product_image($data) {
    // 1. Pasted URL (only if no file uploaded — file takes priority)
    $pasted = trim((string)($data['image_url'] ?? ''));
    $hasFile = isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK;

    if (!$hasFile && $pasted !== '' && preg_match('#^https?://#i', $pasted)) {
        return $pasted;
    }

    // 2. File upload → Cloudinary
    if ($hasFile) {
        try {
            $client = new CloudinaryClient();
            $res = $client->uploadFile($_FILES['image']['tmp_name'], $_FILES['image']['name']);
            if ($res['ok'] && !empty($res['url'])) {
                return $res['url'];
            }
            cloudinary_log('error', 'products.php upload failed', [
                'error' => $res['error'] ?? 'unknown',
            ]);
        } catch (Throwable $e) {
            cloudinary_log('error', 'products.php upload exception', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    return null; // no image provided
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

        if ($id)       { $sql .= " AND p.id = :id";               $params[':id'] = $id; }
        if ($category) { $sql .= " AND p.category_id = :category"; $params[':category'] = $category; }
        if ($status)   { $sql .= " AND p.status = :status";       $params[':status'] = $status; }
        if ($search)   { $sql .= " AND p.name LIKE :search";      $params[':search'] = "%$search%"; }
        if ($lowStock) { $sql .= " AND p.stock <= COALESCE(p.low_stock_threshold, 5) AND p.status = 'active'"; }

        $sql .= " ORDER BY p.id DESC";

        $stmt = $conn->prepare($sql);
        foreach ($params as $key => $value) $stmt->bindValue($key, $value);
        $stmt->execute();

        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

// ============================================
// POST — create OR update (with ?id=)
// ============================================
if ($method === 'POST') {
    try {
        $updateId = isset($_GET['id']) ? (int)$_GET['id'] : null;

        if (!empty($_POST)) {
            $data = $_POST;
        } else {
            $data = json_decode(file_get_contents("php://input"), true) ?: [];
        }

        // ✅ Resolve image (Cloudinary upload OR pasted URL)
        $image_url = resolve_product_image($data);

        // ============================================
        // UPDATE PATH (POST with ?id=N)
        // ============================================
        if ($updateId) {
            // Verify product exists
            $chk = $conn->prepare("SELECT id FROM products WHERE id = ?");
            $chk->execute([$updateId]);
            if (!$chk->fetch()) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Product not found']);
                exit();
            }

            $updates = [];
            $params = [':id' => $updateId];

            if (isset($data['name']))        { $updates[] = "name = :name";               $params[':name'] = trim($data['name']); }
            if (isset($data['description'])) { $updates[] = "description = :description"; $params[':description'] = $data['description']; }
            if (isset($data['price']))       { $updates[] = "price = :price";             $params[':price'] = floatval($data['price']); }
            if (isset($data['stock']))       { $updates[] = "stock = :stock";             $params[':stock'] = intval($data['stock']); }
            if (isset($data['status']))      { $updates[] = "status = :status";           $params[':status'] = $data['status']; }
            if (isset($data['barcode']))     { $updates[] = "barcode = :barcode";         $params[':barcode'] = $data['barcode']; }

            // Sanitize category_id
            if (array_key_exists('category_id', $data)) {
                $updates[] = "category_id = :category_id";
                $params[':category_id'] = normalize_category_id($conn, $data['category_id']);
            }

            // Image update — only if a new image was provided
            if ($image_url !== null) {
                $updates[] = "image_url = :image_url";
                $params[':image_url'] = $image_url;
            }

            if (empty($updates)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'No data provided to update']);
                exit();
            }

            $sql = "UPDATE products SET " . implode(', ', $updates) . " WHERE id = :id";
            $stmt = $conn->prepare($sql);
            foreach ($params as $k => $v) {
                if ($v === null) $stmt->bindValue($k, null, PDO::PARAM_NULL);
                else $stmt->bindValue($k, $v);
            }
            $stmt->execute();

            echo json_encode(['success' => true, 'message' => 'Product updated successfully']);
            exit();
        }

        // ============================================
        // CREATE PATH (no ?id=)
        // ============================================
        $name        = isset($data['name'])        ? trim($data['name']) : '';
        $price       = isset($data['price'])       ? floatval($data['price']) : 0;
        $stock       = isset($data['stock'])       ? intval($data['stock']) : 0;
        $description = isset($data['description']) ? $data['description'] : '';
        $barcode     = isset($data['barcode'])     ? $data['barcode'] : '';
        $status      = isset($data['status'])      ? $data['status'] : 'active';
        $category_id = normalize_category_id($conn, $data['category_id'] ?? null);

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
        $stmt->bindValue(':image_url',   $image_url ?: '');
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
// PUT — update product (kept for API compatibility)
// ============================================
if ($method === 'PUT') {
    try {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
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

        if (isset($data['name']))        { $updates[] = "name = :name";               $params[':name'] = trim($data['name']); }
        if (isset($data['description'])) { $updates[] = "description = :description"; $params[':description'] = $data['description']; }
        if (isset($data['price']))       { $updates[] = "price = :price";             $params[':price'] = floatval($data['price']); }
        if (isset($data['stock']))       { $updates[] = "stock = :stock";             $params[':stock'] = intval($data['stock']); }
        if (isset($data['status']))      { $updates[] = "status = :status";           $params[':status'] = $data['status']; }
        if (isset($data['barcode']))     { $updates[] = "barcode = :barcode";         $params[':barcode'] = $data['barcode']; }

        if (array_key_exists('category_id', $data)) {
            $updates[] = "category_id = :category_id";
            $params[':category_id'] = normalize_category_id($conn, $data['category_id']);
        }

        // ✅ Resolve image (Cloudinary upload OR pasted URL)
        $image_url = resolve_product_image($data);
        if ($image_url !== null) {
            $updates[] = "image_url = :image_url";
            $params[':image_url'] = $image_url;
        }

        if (empty($updates)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No data provided to update']);
            exit();
        }

        $sql = "UPDATE products SET " . implode(", ", $updates) . " WHERE id = :id";
        $stmt = $conn->prepare($sql);
        foreach ($params as $key => $value) {
            if ($value === null) $stmt->bindValue($key, null, PDO::PARAM_NULL);
            else $stmt->bindValue($key, $value);
        }
        $stmt->execute();

        echo json_encode(['success' => true, 'message' => 'Product updated successfully']);
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

        $chk = $conn->prepare("SELECT id, name, status FROM products WHERE id = ?");
        $chk->execute([$id]);
        $product = $chk->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Product not found']);
            exit();
        }

        $ref = find_product_reference($conn, $id);

        if ($ref !== null) {
            $upd = $conn->prepare("UPDATE products SET status = 'archived' WHERE id = ?");
            $upd->execute([$id]);

            echo json_encode([
                'success'   => true,
                'archived'  => true,
                'message'   => "Product has {$ref['count']} linked record(s) in `{$ref['table']}` and was archived instead of deleted.",
                'ref_table' => $ref['table'],
                'ref_count' => $ref['count'],
            ]);
            exit();
        }

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