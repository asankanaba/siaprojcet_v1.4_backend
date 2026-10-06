<?php
// api/supplier_performance.php — ratings + stats
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
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
            if (isset($_GET['supplier_id'])) {
                $stmt = $conn->prepare("
                    SELECT sp.*, u.full_name AS rated_by_name, po.po_number
                    FROM supplier_performance sp
                    LEFT JOIN users u ON sp.rated_by = u.id
                    LEFT JOIN purchase_orders po ON sp.po_id = po.id
                    WHERE sp.supplier_id = ?
                    ORDER BY sp.created_at DESC
                ");
                $stmt->execute([$_GET['supplier_id']]);
                $ratings = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $agg = $conn->prepare("
                    SELECT
                        COUNT(*) AS total_ratings,
                        AVG(overall_score) AS avg_score,
                        AVG(on_time) * 100 AS on_time_rate
                    FROM supplier_performance
                    WHERE supplier_id = ?
                ");
                $agg->execute([$_GET['supplier_id']]);
                $summary = $agg->fetch(PDO::FETCH_ASSOC);

                echo json_encode([
                    'success' => true,
                    'data'    => ['ratings' => $ratings, 'summary' => $summary]
                ]);
                break;
            }

            $stmt = $conn->prepare("
                SELECT s.id, s.name, s.avg_rating, s.total_orders, s.on_time_rate,
                       (SELECT AVG(overall_score) FROM supplier_performance WHERE supplier_id = s.id) AS live_avg,
                       (SELECT COUNT(*) FROM supplier_performance WHERE supplier_id = s.id) AS rating_count
                FROM suppliers s
                WHERE s.status = 'active'
                ORDER BY s.name ASC
            ");
            $stmt->execute();
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'POST':
            $required = ['supplier_id','rated_by','quality_score','responsiveness_score'];
            foreach ($required as $f) {
                if (empty($input[$f])) throw new Exception("Missing required field: $f");
            }

            $q = (int)$input['quality_score'];
            $r = (int)$input['responsiveness_score'];
            $onTime = !empty($input['on_time']) ? 1 : 0;
            $overall = round((($q + $r + ($onTime ? 5 : 1)) / 3), 2);

            $stmt = $conn->prepare("
                INSERT INTO supplier_performance
                    (supplier_id, po_id, on_time, quality_score,
                     responsiveness_score, overall_score, notes, rated_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                (int)$input['supplier_id'],
                !empty($input['po_id']) ? (int)$input['po_id'] : null,
                $onTime, $q, $r, $overall,
                $input['notes'] ?? null,
                (int)$input['rated_by']
            ]);

            // Refresh supplier aggregates
            $conn->prepare("
                UPDATE suppliers s
                SET avg_rating = (
                        SELECT AVG(overall_score) FROM supplier_performance WHERE supplier_id = s.id
                    ),
                    on_time_rate = (
                        SELECT AVG(on_time) * 100 FROM supplier_performance WHERE supplier_id = s.id
                    ),
                    total_orders = (
                        SELECT COUNT(*) FROM supplier_performance WHERE supplier_id = s.id
                    )
                WHERE s.id = ?
            ")->execute([(int)$input['supplier_id']]);

            // If this closes a PO, close it
            if (!empty($input['close_po_id'])) {
                $conn->prepare("
                    UPDATE purchase_orders
                    SET lifecycle_status = 'closed', closed_at = NOW(), closed_by = ?
                    WHERE id = ?
                ")->execute([(int)$input['rated_by'], (int)$input['close_po_id']]);
            }

            echo json_encode(['success' => true, 'id' => $conn->lastInsertId()]);
            break;
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}