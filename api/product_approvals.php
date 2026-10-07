<?php
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
$id     = isset($_GET['id'])     ? $_GET['id']     : null;
$status = isset($_GET['status']) ? $_GET['status'] : null;

// ============================================
// GET
// ============================================
if ($method === 'GET') {
    try {
        if ($id) {
            $query = "SELECT pa.*, u.username as requested_by_name,
                             a.username as approved_by_name,
                             p.name as product_name_linked
                      FROM product_approvals pa
                      LEFT JOIN users u ON pa.requested_by = u.id
                      LEFT JOIN users a ON pa.approved_by = a.id
                      LEFT JOIN products p ON pa.product_id = p.id
                      WHERE pa.id = :id";
            $stmt = $conn->prepare($query);
            $stmt->bindValue(':id', $id);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            echo $result
                ? json_encode($result)
                : json_encode(['success' => false, 'message' => 'Not found']);
        } else {
            $query = "SELECT pa.*, u.username as requested_by_name,
                             a.username as approved_by_name,
                             p.name as product_name_linked
                      FROM product_approvals pa
                      LEFT JOIN users u ON pa.requested_by = u.id
                      LEFT JOIN users a ON pa.approved_by = a.id
                      LEFT JOIN products p ON pa.product_id = p.id
                      WHERE 1=1";
            $params = [];
            if ($status) {
                $query .= " AND pa.status = :status";
                $params[':status'] = $status;
            }
            $query .= " ORDER BY pa.created_at DESC";
            $stmt = $conn->prepare($query);
            foreach ($params as $k => $v) $stmt->bindValue($k, $v);
            $stmt->execute();
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

// ============================================
// POST — Staff creates a product request
// ============================================
if ($method === 'POST') {
    try {
        $product_name = isset($_POST['product_name']) ? trim($_POST['product_name']) : '';
        $description  = isset($_POST['description'])  ? $_POST['description'] : '';
        $price        = isset($_POST['price'])        ? floatval($_POST['price']) : 0;
        $stock        = isset($_POST['stock'])        ? intval($_POST['stock']) : 0;
        $category_id  = isset($_POST['category_id'])  ? $_POST['category_id'] : null;
        $requested_by = isset($_POST['requested_by']) ? $_POST['requested_by'] : 1;
        $barcode      = isset($_POST['barcode'])      ? $_POST['barcode'] : '';
        $notes        = isset($_POST['notes'])        ? $_POST['notes'] : '';
        $image_url    = '';

        if (empty($product_name)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Product name is required']);
            exit();
        }
        if ($price <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Price must be greater than 0']);
            exit();
        }

        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $isVercel  = getenv('VERCEL') === '1';
            $uploadDir = $isVercel
                ? sys_get_temp_dir() . '/uploads/products/'
                : __DIR__ . '/../uploads/products/';

            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

            $fileExtension = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
            $newFileName   = uniqid('product_') . '.' . $fileExtension;
            $targetPath    = $uploadDir . $newFileName;

            if (move_uploaded_file($_FILES['image']['tmp_name'], $targetPath)) {
                $image_url = '/uploads/products/' . $newFileName;
            }
        }

        $query = "INSERT INTO product_approvals
                  (product_name, description, price, stock, category_id, image_url,
                   barcode, requested_by, notes, status)
                  VALUES
                  (:product_name, :description, :price, :stock, :category_id, :image_url,
                   :barcode, :requested_by, :notes, 'pending')";
        $stmt = $conn->prepare($query);
        $stmt->bindValue(':product_name', $product_name);
        $stmt->bindValue(':description',  $description);
        $stmt->bindValue(':price',        $price);
        $stmt->bindValue(':stock',        $stock);
        $stmt->bindValue(':category_id',  $category_id);
        $stmt->bindValue(':image_url',    $image_url);
        $stmt->bindValue(':barcode',      $barcode);
        $stmt->bindValue(':requested_by', $requested_by);
        $stmt->bindValue(':notes',        $notes);

        if ($stmt->execute()) {
            // Notify Finance
            if (function_exists('notifyRole')) {
                notifyRole(
                    $conn,
                    ['finance', 'super_admin', 'admin'],
                    '🆕 New Product Approval Needed',
                    "{$product_name} — ₱" . number_format($price, 2) . " (initial stock: {$stock})",
                    'info',
                    'info'
                );
            }
            echo json_encode(['success' => true, 'id' => $conn->lastInsertId(), 'message' => 'Request submitted']);
        } else {
            throw new Exception('Failed to submit request');
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// ============================================
// PUT — Finance approves / rejects
// ============================================
if ($method === 'PUT') {
    try {
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Approval ID is required']);
            exit();
        }

        $data        = json_decode(file_get_contents('php://input'), true) ?: [];
        $status      = isset($data['status'])      ? $data['status']      : null;
        $approved_by = isset($data['approved_by']) ? $data['approved_by'] : 1;
        $notes       = isset($data['notes'])       ? $data['notes']       : '';

        if (!$status) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Status is required']);
            exit();
        }

        // Fetch current approval
        $checkStmt = $conn->prepare("SELECT * FROM product_approvals WHERE id = :id");
        $checkStmt->bindValue(':id', $id);
        $checkStmt->execute();
        $approval = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$approval) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Approval request not found']);
            exit();
        }

        if ($approval['status'] !== 'pending') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Approval already processed']);
            exit();
        }

        $conn->beginTransaction();

        // 1. Update approval row
        $query = "UPDATE product_approvals
                  SET status = :status,
                      approved_by = :approved_by,
                      notes = CONCAT(COALESCE(notes, ''), '\n', :notes),
                      approved_at = NOW()
                  WHERE id = :id";
        $stmt = $conn->prepare($query);
        $stmt->bindValue(':status',      $status);
        $stmt->bindValue(':approved_by', $approved_by);
        $stmt->bindValue(':notes',       $notes);
        $stmt->bindValue(':id',          $id);
        $stmt->execute();

        // 2. If approved → create product + create supply chain request
        if ($status === 'approved') {
            // 2a. Insert product with stock = 0 (stock increases after delivery)
            $insertQuery = "INSERT INTO products
                            (name, description, price, stock, category_id, image_url, barcode, status)
                            VALUES
                            (:name, :description, :price, 0, :category_id, :image_url, :barcode, 'active')";
            $insertStmt = $conn->prepare($insertQuery);
            $insertStmt->bindValue(':name',        $approval['product_name']);
            $insertStmt->bindValue(':description', $approval['description']);
            $insertStmt->bindValue(':price',       $approval['price']);
            $insertStmt->bindValue(':category_id', $approval['category_id']);
            $insertStmt->bindValue(':image_url',   $approval['image_url']);
            $insertStmt->bindValue(':barcode',     $approval['barcode']);
            $insertStmt->execute();
            $productId = (int)$conn->lastInsertId();

            // 2b. Link product_id back to approval
            $linkStmt = $conn->prepare("UPDATE product_approvals SET product_id = :pid WHERE id = :id");
            $linkStmt->bindValue(':pid', $productId);
            $linkStmt->bindValue(':id',  $id);
            $linkStmt->execute();

            // 2c. Create Supply Chain request with status='approved' (Finance already OK'd budget)
            $qty         = (int)$approval['stock'];
            $unitCost    = (float)$approval['price'];
            $totalCost   = $unitCost * $qty;

            $scStmt = $conn->prepare("
                INSERT INTO supply_chain_requests
                    (product_id, supplier_id, quantity, status, requested_by, total_cost, notes, created_at)
                VALUES
                    (:pid, NULL, :qty, 'approved', :rby, :cost, :notes, NOW())
            ");
            $scStmt->bindValue(':pid',   $productId);
            $scStmt->bindValue(':qty',   $qty);
            $scStmt->bindValue(':rby',   (int)$approval['requested_by']);
            $scStmt->bindValue(':cost',  $totalCost);
            $scStmt->bindValue(':notes', 'Auto-created from approved product request #' . $id);
            $scStmt->execute();
            $scRequestId = (int)$conn->lastInsertId();

            // 2d. Notify requester
            if (function_exists('createNotification')) {
                createNotification(
                    $conn,
                    (int)$approval['requested_by'],
                    '✅ Product Approved',
                    "Your request for '{$approval['product_name']}' has been approved and forwarded to Supply Chain for ordering.",
                    'success',
                    'success'
                );
            }

            // 2e. Notify Supply Chain
            if (function_exists('notifyRole')) {
                notifyRole(
                    $conn,
                    ['supply_chain', 'super_admin', 'admin'],
                    '📦 New Product Ready to Order',
                    "{$approval['product_name']} needs initial order of {$qty} units. Please assign a supplier and place the order.",
                    'info',
                    'info'
                );
            }

            $conn->commit();
            echo json_encode([
                'success'           => true,
                'message'           => 'Approved. Product created and Supply Chain request opened.',
                'product_id'        => $productId,
                'supply_request_id' => $scRequestId
            ]);
            exit();
        }

        // 3. If rejected → notify requester
        if ($status === 'rejected') {
            if (function_exists('createNotification')) {
                createNotification(
                    $conn,
                    (int)$approval['requested_by'],
                    '❌ Product Request Rejected',
                    "Your request for '{$approval['product_name']}' was rejected." . ($notes ? " Reason: {$notes}" : ''),
                    'error',
                    'error'
                );
            }
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Request rejected']);
            exit();
        }

        // Other statuses → just commit the update
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Approval ' . $status . ' successfully']);

    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// ============================================
// DELETE
// ============================================
if ($method === 'DELETE') {
    try {
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Approval ID is required']);
            exit();
        }
        $stmt = $conn->prepare("DELETE FROM product_approvals WHERE id = :id");
        $stmt->bindValue(':id', $id);
        $stmt->execute();
        echo json_encode(['success' => true, 'message' => 'Approval request deleted']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed']);
?>