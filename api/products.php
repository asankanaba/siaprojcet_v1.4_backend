<?php
// ✅ FINAL CORS FIX: Authorization header is now allowed
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ✅ ABSOLUTE PATH FIX
require_once 'C:/xampp/htdocs/smart-pos-api/config/database.php';

$method = $_SERVER['REQUEST_METHOD'];

// ============================================
// GET: Fetch products
// ============================================
if ($method === 'GET') {
    try {
        $id = isset($_GET['id']) ? $_GET['id'] : null;
        $category = isset($_GET['category']) ? $_GET['category'] : null;
        $status = isset($_GET['status']) ? $_GET['status'] : null;
        $search = isset($_GET['search']) ? $_GET['search'] : null;

        $sql = "SELECT p.*, c.name as category_name 
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
// POST: Create product (Handles JSON & FormData)
// ============================================
if ($method === 'POST') {
    try {
        // ✅ FIX: Handle both JSON and FormData
        if (!empty($_POST)) {
            $data = $_POST;
        } else {
            $data = json_decode(file_get_contents("php://input"), true);
        }
        
        $name = isset($data['name']) ? trim($data['name']) : '';
        $price = isset($data['price']) ? floatval($data['price']) : 0;
        $stock = isset($data['stock']) ? intval($data['stock']) : 0;
        $category_id = isset($data['category_id']) ? $data['category_id'] : null;
        $description = isset($data['description']) ? $data['description'] : '';
        $barcode = isset($data['barcode']) ? $data['barcode'] : '';
        $status = isset($data['status']) ? $data['status'] : 'active';
        $image_url = '';

        // Handle image upload if it exists
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = 'C:/xampp/htdocs/smart-pos-api/uploads/products/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
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
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':description', $description);
        $stmt->bindParam(':price', $price);
        $stmt->bindParam(':stock', $stock);
        $stmt->bindParam(':category_id', $category_id);
        $stmt->bindParam(':image_url', $image_url);
        $stmt->bindParam(':barcode', $barcode);
        $stmt->bindParam(':status', $status);

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
// PUT: Update product (Handles JSON & FormData)
// ============================================
if ($method === 'PUT') {
    try {
        $id = isset($_GET['id']) ? $_GET['id'] : null;
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Product ID is required']);
            exit();
        }

        // ✅ FIX: Handle both JSON and FormData
        if (!empty($_POST)) {
            $data = $_POST;
        } else {
            $data = json_decode(file_get_contents("php://input"), true);
        }
        
        $updates = [];
        $params = [':id' => $id];

        if (isset($data['name'])) { $updates[] = "name = :name"; $params[':name'] = $data['name']; }
        if (isset($data['description'])) { $updates[] = "description = :description"; $params[':description'] = $data['description']; }
        if (isset($data['price'])) { $updates[] = "price = :price"; $params[':price'] = $data['price']; }
        if (isset($data['stock'])) { $updates[] = "stock = :stock"; $params[':stock'] = $data['stock']; }
        if (isset($data['category_id'])) { $updates[] = "category_id = :category_id"; $params[':category_id'] = $data['category_id']; }
        if (isset($data['status'])) { $updates[] = "status = :status"; $params[':status'] = $data['status']; }

        // Handle image upload if it exists
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = 'C:/xampp/htdocs/smart-pos-api/uploads/products/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
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
            $stmt->bindValue($key, $value);
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
// DELETE: Delete product
// ============================================
if ($method === 'DELETE') {
    try {
        $id = isset($_GET['id']) ? $_GET['id'] : null;
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Product ID is required']);
            exit();
        }

        $sql = "DELETE FROM products WHERE id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':id', $id);

        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Product deleted successfully']);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to delete product']);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
?>