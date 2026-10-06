<?php
// api/suppliers.php — proper supplier CRUD
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once '../config/database.php';

$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?: [];

try {
    $db   = new Database();
    $conn = $db->getConnection();

    switch ($method) {

        case 'GET':
            if (isset($_GET['id'])) {
                $stmt = $conn->prepare("SELECT * FROM suppliers WHERE id = ?");
                $stmt->execute([$_GET['id']]);
                echo json_encode(['success' => true, 'data' => $stmt->fetch(PDO::FETCH_ASSOC)]);
                break;
            }

            $sql = "SELECT * FROM suppliers";
            $where = []; $params = [];

            if (!empty($_GET['product_id'])) {
                $where[] = "product_id = ?";
                $params[] = $_GET['product_id'];
            }
            if (!empty($_GET['status'])) {
                $where[] = "status = ?";
                $params[] = $_GET['status'];
            }
            if (!empty($_GET['search'])) {
                $where[] = "(name LIKE ? OR contact_person LIKE ? OR email LIKE ?)";
                $s = '%' . $_GET['search'] . '%';
                array_push($params, $s, $s, $s);
            }
            if ($where) $sql .= " WHERE " . implode(" AND ", $where);
            $sql .= " ORDER BY name ASC";

            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'POST':
            $required = ['name'];
            foreach ($required as $f) {
                if (empty($input[$f])) throw new Exception("Missing required field: $f");
            }

            $stmt = $conn->prepare("
                INSERT INTO suppliers
                    (name, contact_person, phone, email, address,
                     product_id, stock_available, price_per_unit,
                     lead_time_days, payment_terms, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $input['name'],
                $input['contact_person'] ?? null,
                $input['phone'] ?? null,
                $input['email'] ?? null,
                $input['address'] ?? null,
                !empty($input['product_id']) ? (int)$input['product_id'] : null,
                (int)($input['stock_available'] ?? 0),
                (float)($input['price_per_unit'] ?? 0),
                (int)($input['lead_time_days'] ?? 3),
                $input['payment_terms'] ?? 'Net 30',
                $input['status'] ?? 'active'
            ]);

            echo json_encode(['success' => true, 'id' => $conn->lastInsertId()]);
            break;

        case 'PUT':
            $id = $_GET['id'] ?? null;
            if (!$id) throw new Exception('ID required');

            $allowed = ['name','contact_person','phone','email','address','product_id',
                        'stock_available','price_per_unit','lead_time_days',
                        'payment_terms','status','avg_rating','total_orders','on_time_rate'];
            $fields = []; $params = [];
            foreach ($allowed as $f) {
                if (array_key_exists($f, $input)) { $fields[] = "$f = ?"; $params[] = $input[$f]; }
            }
            if (!$fields) throw new Exception('No fields to update');
            $params[] = $id;
            $stmt = $conn->prepare("UPDATE suppliers SET " . implode(', ', $fields) . " WHERE id = ?");
            $stmt->execute($params);
            echo json_encode(['success' => true]);
            break;

        case 'DELETE':
            $id = $_GET['id'] ?? null;
            if (!$id) throw new Exception('ID required');
            // Soft delete → inactive
            $stmt = $conn->prepare("UPDATE suppliers SET status = 'inactive' WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true]);
            break;
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}