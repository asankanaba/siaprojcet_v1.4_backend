<?php
// ============================================================
// 📁 File: api/purchase_orders.php
// 📦 Purchase Orders API — Phase 3 (payment-aware)
// ============================================================
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit(); }

require_once __DIR__ . '/../config/database.php';

$db     = new Database();
$conn   = $db->getConnection();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input  = json_decode(file_get_contents('php://input'), true) ?: [];

// ------------------------------------------------------------
// Helper: current user (from JWT / session / body)
// ------------------------------------------------------------
function po_current_user(array $body): ?array {
    $u = getAuthUser();              // from config/database.php
    if ($u && !empty($u['id'])) return $u;

    if (!empty($body['ordered_by'])) {
        return ['id' => (int)$body['ordered_by'], 'role' => null];
    }
    if (!empty($_GET['ordered_by'])) {
        return ['id' => (int)$_GET['ordered_by'], 'role' => null];
    }
    return null;
}

// ------------------------------------------------------------
// Helper: which lifecycle states can be paid?
// ------------------------------------------------------------
function po_is_payable(string $lifecycle, string $status): bool {
    // Payment is allowed once goods have been received or matched
    $ok_states = ['grn_posted', 'invoiced', 'matched'];
    $ok_statuses = ['received'];   // fall back to simple status column

    return in_array($lifecycle, $ok_states, true)
        || in_array($status, $ok_statuses, true);
}

// ------------------------------------------------------------
// Helper: compute days past due from expected_delivery
// ------------------------------------------------------------
function po_days_overdue(?string $expected, string $payment_status): int {
    if (!$expected) return 0;
    if (in_array($payment_status, ['paid','partial'], true)) return 0;
    $exp = strtotime($expected);
    if (!$exp) return 0;
    $diff = time() - $exp;
    return $diff > 0 ? (int)floor($diff / 86400) : 0;
}

try {
    switch ($method) {

        // ============================================================
        // GET
        // ============================================================
        case 'GET':

            // ---------- ?action=summary ----------
            if (($_GET['action'] ?? '') === 'summary') {
                $row = $conn->query("
                    SELECT
                        COUNT(*) AS total_count,
                        SUM(CASE WHEN status IN ('draft','ordered')     THEN 1 ELSE 0 END) AS pending_count,
                        SUM(CASE WHEN status = 'shipped'                THEN 1 ELSE 0 END) AS shipped_count,
                        SUM(CASE WHEN status = 'received'               THEN 1 ELSE 0 END) AS received_count,
                        SUM(CASE WHEN status = 'cancelled'              THEN 1 ELSE 0 END) AS cancelled_count,
                        COALESCE(SUM(total_cost), 0)                    AS total_spent,
                        COALESCE(SUM(amount_paid), 0)                   AS total_paid,
                        COALESCE(SUM(CASE WHEN payment_status = 'unpaid'  THEN total_cost ELSE 0 END), 0) AS unpaid_amount,
                        COALESCE(SUM(CASE WHEN payment_status = 'partial' THEN total_cost - amount_paid ELSE 0 END), 0) AS partial_balance,
                        SUM(CASE WHEN payment_status = 'unpaid'  THEN 1 ELSE 0 END) AS unpaid_count,
                        SUM(CASE WHEN payment_status = 'partial' THEN 1 ELSE 0 END) AS partial_count,
                        SUM(CASE WHEN payment_status = 'paid'    THEN 1 ELSE 0 END) AS paid_count
                    FROM purchase_orders
                ")->fetch(PDO::FETCH_ASSOC);
                respond_json(['success' => true, 'data' => $row]);
            }

            // ---------- ?action=eligible_for_payment ----------
            if (($_GET['action'] ?? '') === 'eligible_for_payment') {
                $stmt = $conn->query("
                    SELECT po.*, s.name AS supplier_name, p.name AS product_name
                    FROM purchase_orders po
                    LEFT JOIN suppliers s ON po.supplier_id = s.id
                    LEFT JOIN products  p ON po.product_id  = p.id
                    WHERE po.payment_status IN ('unpaid','partial')
                      AND po.status <> 'cancelled'
                      AND (po.lifecycle_status IN ('grn_posted','invoiced','matched')
                           OR po.status = 'received')
                    ORDER BY po.expected_delivery ASC, po.id DESC
                ");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $rows = array_map('po_enrich', $rows);
                respond_json(['success' => true, 'data' => $rows]);
            }

            // ---------- ?action=payment_history&po_id=N ----------
            if (($_GET['action'] ?? '') === 'payment_history') {
                $po_id = (int)($_GET['po_id'] ?? 0);
                if (!$po_id) throw new InvalidArgumentException('po_id required');

                $stmt = $conn->prepare("
                    SELECT p.*, u.full_name AS paid_by_name
                    FROM payments p
                    LEFT JOIN users u ON p.paid_by = u.id
                    WHERE p.po_id = ?
                    ORDER BY p.created_at DESC
                ");
                $stmt->execute([$po_id]);
                respond_json(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            }

            // ---------- ?action=stats&days=N (spend trend) ----------
            if (($_GET['action'] ?? '') === 'stats') {
                $days = max(7, min(365, (int)($_GET['days'] ?? 30)));
                $stmt = $conn->prepare("
                    SELECT DATE(ordered_date) AS day,
                           COUNT(*)           AS orders,
                           COALESCE(SUM(total_cost), 0)  AS spend
                    FROM purchase_orders
                    WHERE ordered_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                    GROUP BY DATE(ordered_date)
                    ORDER BY day ASC
                ");
                $stmt->execute([$days]);
                respond_json(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            }

            // ---------- GET ?id=N (single PO) ----------
            if (isset($_GET['id'])) {
                $stmt = $conn->prepare("
                    SELECT po.*, p.name AS product_name, p.price AS product_price,
                           s.name AS supplier_name, s.contact_person, s.phone AS supplier_phone,
                           u.full_name AS ordered_by_name
                    FROM purchase_orders po
                    LEFT JOIN products  p ON po.product_id  = p.id
                    LEFT JOIN suppliers s ON po.supplier_id = s.id
                    LEFT JOIN users     u ON po.ordered_by  = u.id
                    WHERE po.id = ?
                ");
                $stmt->execute([(int)$_GET['id']]);
                $po = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$po) respond_json(['success' => false, 'message' => 'PO not found'], 404);

                $po = po_enrich($po);

                // Attach payment history
                $ph = $conn->prepare("
                    SELECT p.*, u.full_name AS paid_by_name
                    FROM payments p
                    LEFT JOIN users u ON p.paid_by = u.id
                    WHERE p.po_id = ?
                    ORDER BY p.created_at DESC
                ");
                $ph->execute([$po['id']]);
                $po['payments'] = $ph->fetchAll(PDO::FETCH_ASSOC);

                // Attach deliveries (GRN)
                $dl = $conn->prepare("
                    SELECT d.*, u.full_name AS received_by_name
                    FROM po_deliveries d
                    LEFT JOIN users u ON d.received_by = u.id
                    WHERE d.po_id = ?
                    ORDER BY d.received_at DESC
                ");
                $dl->execute([$po['id']]);
                $po['deliveries'] = $dl->fetchAll(PDO::FETCH_ASSOC);

                // Attach supplier invoice
                $iv = $conn->prepare("
                    SELECT * FROM supplier_invoices WHERE po_id = ? ORDER BY created_at DESC LIMIT 1
                ");
                $iv->execute([$po['id']]);
                $po['invoice'] = $iv->fetch(PDO::FETCH_ASSOC) ?: null;

                respond_json(['success' => true, 'data' => $po]);
            }

            // ---------- GET list (with filters + enrichment) ----------
            $sql = "
                SELECT po.*, p.name AS product_name, s.name AS supplier_name,
                       u.full_name AS ordered_by_name
                FROM purchase_orders po
                LEFT JOIN products  p ON po.product_id  = p.id
                LEFT JOIN suppliers s ON po.supplier_id = s.id
                LEFT JOIN users     u ON po.ordered_by  = u.id
            ";

            $conditions = [];
            $params     = [];

            // String filters
            foreach (['status','payment_status','lifecycle_status'] as $f) {
                if (!empty($_GET[$f])) {
                    $conditions[] = "po.$f = ?";
                    $params[]     = $_GET[$f];
                }
            }
            // Numeric filters
            foreach (['supplier_id','product_id','requisition_id'] as $f) {
                if (!empty($_GET[$f])) {
                    $conditions[] = "po.$f = ?";
                    $params[]     = (int)$_GET[$f];
                }
            }
            // Date range
            if (!empty($_GET['date_from']) && !empty($_GET['date_to'])) {
                $conditions[] = "po.ordered_date BETWEEN ? AND ?";
                $params[]     = $_GET['date_from'] . ' 00:00:00';
                $params[]     = $_GET['date_to']   . ' 23:59:59';
            }
            // Free-text search (PO number / product / supplier)
            if (!empty($_GET['q'])) {
                $conditions[] = "(po.po_number LIKE ? OR p.name LIKE ? OR s.name LIKE ?)";
                $like = '%' . $_GET['q'] . '%';
                $params[] = $like; $params[] = $like; $params[] = $like;
            }

            if ($conditions) $sql .= ' WHERE ' . implode(' AND ', $conditions);

            // Sort
            $sortable = ['po_number','ordered_date','total_cost','expected_delivery','payment_status','id'];
            $sortBy   = in_array($_GET['sort_by'] ?? '', $sortable, true) ? $_GET['sort_by'] : 'created_at';
            $sortDir  = strtoupper($_GET['sort_dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
            $sql .= " ORDER BY po.$sortBy $sortDir LIMIT 500";

            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $rows = array_map('po_enrich', $rows);
            respond_json(['success' => true, 'data' => $rows]);
            break;


        // ============================================================
        // POST — create
        // ============================================================
        case 'POST':
            $user = po_current_user($input);
            $required = ['product_id','supplier_id','quantity','unit_price'];
            foreach ($required as $f) {
                if (empty($input[$f])) throw new InvalidArgumentException("Missing required field: $f");
            }
            if (!$user && empty($input['ordered_by'])) {
                throw new InvalidArgumentException('ordered_by or authenticated user required');
            }

            $quantity   = (int)$input['quantity'];
            $unit_price = (float)$input['unit_price'];
            $total_cost = $quantity * $unit_price;
            $po_number  = 'PO-' . date('Ymd') . '-' . rand(1000, 9999);
            $ordered_by = $user['id'] ?? (int)$input['ordered_by'];

            $conn->beginTransaction();
            try {
                $stmt = $conn->prepare("
                    INSERT INTO purchase_orders
                        (po_number, requisition_id, product_id, supplier_id, quantity, unit_price,
                         total_cost, ordered_by, ordered_date, expected_delivery,
                         status, lifecycle_status, payment_terms, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $po_number,
                    !empty($input['requisition_id']) ? (int)$input['requisition_id'] : null,
                    (int)$input['product_id'],
                    (int)$input['supplier_id'],
                    $quantity,
                    $unit_price,
                    $total_cost,
                    $ordered_by,
                    $input['expected_delivery'] ?? null,
                    $input['status']            ?? 'ordered',
                    $input['lifecycle_status']  ?? 'ordered',
                    $input['payment_terms']     ?? 'Net 30',
                    $input['notes']             ?? null,
                ]);
                $orderId = (int)$conn->lastInsertId();

                // Decrement supplier stock (kept from original)
                $conn->prepare("UPDATE suppliers SET stock_available = stock_available - ? WHERE id = ?")
                     ->execute([$quantity, (int)$input['supplier_id']]);

                // Notify finance + supply chain
                if (function_exists('notifyRole')) {
                    notifyRole(
                        $conn,
                        ['finance','supply_chain','super_admin','admin'],
                        '📦 New Purchase Order — ' . $po_number,
                        'PO for ₱' . number_format($total_cost, 2) . ' placed with supplier.',
                        'info',
                        'info'
                    );
                }

                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollBack();
                throw $e;
            }

            respond_json(['success' => true, 'id' => $orderId, 'po_number' => $po_number]);
            break;


        // ============================================================
        // PUT — update
        // ============================================================
        case 'PUT':
            $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
            if (!$id) throw new InvalidArgumentException('ID required');

            $allowed = [
                'status','lifecycle_status','received_date','expected_delivery',
                'notes','payment_terms','payment_status','amount_paid',
                'closed_at','closed_by'
            ];
            $fields = [];
            $params = [];

            foreach ($allowed as $f) {
                if (array_key_exists($f, $input)) {
                    $fields[] = "$f = ?";
                    $params[] = $input[$f];
                }
            }

            // Auto-set received_date when moving to received
            if (($input['status'] ?? null) === 'received' && empty($input['received_date'])) {
                $fields[] = 'received_date = NOW()';
            }

            if (!$fields) throw new InvalidArgumentException('No fields to update');

            $params[] = $id;
            $conn->prepare("UPDATE purchase_orders SET " . implode(', ', $fields) . " WHERE id = ?")
                 ->execute($params);

            respond_json(['success' => true]);
            break;


        // ============================================================
        // DELETE
        // ============================================================
        case 'DELETE':
            $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
            if (!$id) throw new InvalidArgumentException('ID required');

            // Guard: don't allow deleting paid POs
            $chk = $conn->prepare("SELECT payment_status, amount_paid FROM purchase_orders WHERE id = ?");
            $chk->execute([$id]);
            $row = $chk->fetch(PDO::FETCH_ASSOC);
            if ($row && (float)$row['amount_paid'] > 0) {
                respond_json([
                    'success' => false,
                    'message' => 'Cannot delete a PO that has payments. Cancel it instead.'
                ], 400);
            }

            $conn->prepare("DELETE FROM purchase_orders WHERE id = ?")->execute([$id]);
            respond_json(['success' => true]);
            break;

        default:
            respond_json(['success' => false, 'message' => 'Method not allowed'], 405);
    }

} catch (InvalidArgumentException $e) {
    respond_json(['success' => false, 'message' => $e->getMessage()], 400);
} catch (Throwable $e) {
    respond_json(['success' => false, 'message' => 'Server error: ' . $e->getMessage()], 500);
}


// ============================================================
// Helpers
// ============================================================
function respond_json(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit();
}

/**
 * Enrich a PO row with computed payment fields.
 */
function po_enrich(array $po): array {
    $total  = (float)($po['total_cost']   ?? 0);
    $paid   = (float)($po['amount_paid']  ?? 0);
    $due    = max(0, $total - $paid);

    $lifecycle     = (string)($po['lifecycle_status'] ?? '');
    $status        = (string)($po['status']           ?? '');
    $paymentStatus = (string)($po['payment_status']   ?? 'unpaid');

    // Recompute payment_status if inconsistent
    if ($paid <= 0)               $paymentStatus = 'unpaid';
    elseif ($paid >= $total)      $paymentStatus = 'paid';
    else                          $paymentStatus = 'partial';

    $payable = po_is_payable($lifecycle, $status);
    $blocker = null;
    if (!$payable) {
        $blocker = 'Cannot pay before goods are received';
    } elseif ($status === 'cancelled') {
        $payable = false;
        $blocker = 'PO is cancelled';
    } elseif ($paymentStatus === 'paid') {
        $payable = false;
        $blocker = 'Already fully paid';
    }

    $po['balance_due']       = number_format($due, 2, '.', '');
    $po['payment_status']    = $paymentStatus;
    $po['can_pay']           = $payable;
    $po['payment_blocker']   = $blocker;
    $po['days_overdue']      = po_days_overdue($po['expected_delivery'] ?? null, $paymentStatus);

    // Pre-fill payment metadata the frontend will pass to PayMongoCheckout.vue
    $po['payment_meta'] = [
        'po_id'       => (int)$po['id'],
        'supplier_id' => (int)$po['supplier_id'],
        'amount'      => (float)$due,
        'description' => 'Payment for PO ' . ($po['po_number'] ?? '') . ' — ' . ($po['product_name'] ?? ''),
    ];

    return $po;
}