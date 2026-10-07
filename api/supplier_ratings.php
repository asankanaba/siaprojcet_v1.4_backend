<?php
// api/supplier_ratings.php
// POST  — insert rating + recalc supplier aggregate
// GET   — list ratings (optionally by supplier_id or po_id)

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/../config/database.php';

$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?: [];

try {
    $db   = new Database();
    $conn = $db->getConnection();

    if ($method === 'GET') {
        $sql = "
            SELECT sr.*, s.name AS supplier_name, u.full_name AS rated_by_name
            FROM supplier_ratings sr
            LEFT JOIN suppliers s ON sr.supplier_id = s.id
            LEFT JOIN users u ON sr.rated_by = u.id
            WHERE 1=1
        ";
        $params = [];
        if (!empty($_GET['supplier_id'])) { $sql .= " AND sr.supplier_id = ?"; $params[] = (int)$_GET['supplier_id']; }
        if (!empty($_GET['po_id']))       { $sql .= " AND sr.po_id = ?";       $params[] = (int)$_GET['po_id']; }
        $sql .= " ORDER BY sr.created_at DESC LIMIT 200";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit();
    }

    if ($method === 'POST') {
        $supplierId = (int)($input['supplier_id'] ?? 0);
        $poId       = (int)($input['po_id']       ?? 0);
        $requestId  = isset($input['request_id']) ? (int)$input['request_id'] : null;
        $ratedBy    = (int)($input['rated_by']    ?? 0);
        $rating     = (int)($input['rating']      ?? 0);
        $comment    = trim((string)($input['comment'] ?? ''));

        if ($supplierId <= 0 || $poId <= 0 || $ratedBy <= 0 || $rating < 1 || $rating > 5) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'supplier_id, po_id, rated_by, rating (1-5) required']);
            exit();
        }

        $conn->beginTransaction();

        // 1. Insert rating
        $ins = $conn->prepare("
            INSERT INTO supplier_ratings
                (supplier_id, po_id, request_id, rated_by, rating, comment, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $ins->execute([$supplierId, $poId, $requestId, $ratedBy, $rating, $comment]);
        $ratingId = (int)$conn->lastInsertId();

        // 2. Recalc aggregate
        $agg = $conn->prepare("
            SELECT AVG(rating) AS avg_r, COUNT(*) AS cnt
            FROM supplier_ratings WHERE supplier_id = ?
        ");
        $agg->execute([$supplierId]);
        $stats = $agg->fetch(PDO::FETCH_ASSOC);

        $upd = $conn->prepare("
            UPDATE suppliers
            SET avg_rating = ?, rating_count = ?
            WHERE id = ?
        ");
        $upd->execute([
            round((float)$stats['avg_r'], 2),
            (int)$stats['cnt'],
            $supplierId,
        ]);

        $conn->commit();
        echo json_encode(['success' => true, 'id' => $ratingId, 'avg_rating' => $stats['avg_r'], 'rating_count' => $stats['cnt']]);
        exit();
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);

} catch (Throwable $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>