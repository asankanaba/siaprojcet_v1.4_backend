<?php
// ============================================================
// 📁 File: api/payments.php
// 💳 Payments API — PayMongo Checkout / Intent / Source / Link / Refund
//    + manual bank/cash/cheque
// ============================================================
declare(strict_types=1);
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Max-Age: 86400');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit(); }

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/paymongo.php';
require_once __DIR__ . '/_paymongo_client.php';

// ------------------------------------------------------------
// Session (optional — if using $_SESSION from auth.php)
// ------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

// ------------------------------------------------------------
// Helpers
// ------------------------------------------------------------
function respond(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit();
}

function body_json(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

function current_user_id(array $body): ?int {
    // Priority: session → header → body (backward compat)
    if (!empty($_SESSION['user_id']))       return (int) $_SESSION['user_id'];
    if (!empty($_SESSION['user']['id']))    return (int) $_SESSION['user']['id'];

    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(\d+)/i', $auth, $m)) return (int) $m[1];

    foreach (['user_id','paid_by','created_by'] as $k) {
        if (!empty($body[$k])) return (int) $body[$k];
    }
    foreach (['user_id','paid_by','created_by'] as $k) {
        if (!empty($_GET[$k])) return (int) $_GET[$k];
    }
    return null;
}

function require_fields(array $input, array $fields): void {
    foreach ($fields as $f) {
        if (!isset($input[$f]) || $input[$f] === '' || $input[$f] === null) {
            throw new InvalidArgumentException("Missing required field: {$f}");
        }
    }
}

function make_payment_ref(string $prefix = 'PAY'): string {
    return $prefix . '-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
}

function make_idempotency_key(string $ref, string $kind): string {
    return $kind . ':' . $ref . ':' . substr(hash('sha256', $ref . $kind), 0, 24);
}

function normalize_methods(?array $requested): array {
    $allowed = array_keys(PAYMONGO_ALLOWED_METHODS);
    if (!$requested) return PAYMONGO_DEFAULT_METHODS;
    $out = [];
    foreach ($requested as $m) {
        $m = strtolower(trim((string)$m));
        if (in_array($m, $allowed, true)) $out[] = $m;
    }
    return $out ?: PAYMONGO_DEFAULT_METHODS;
}

function build_line_item(string $name, float $amount, int $qty = 1): array {
    return [
        'currency' => 'PHP',
        'amount'   => (int) round($amount * 100),  // centavos
        'name'     => substr($name, 0, 120),
        'quantity' => max(1, $qty),
    ];
}

// ------------------------------------------------------------
// Notifier (used by webhook too — kept compatible with your schema)
// ------------------------------------------------------------
if (!function_exists('notifyRole')) {
    function notifyRole(PDO $conn, array $roles, string $title, string $message, string $type = 'info', string $severity = 'info'): void {
        try {
            $placeholders = implode(',', array_fill(0, count($roles), '?'));
            $stmt = $conn->prepare("SELECT id FROM users WHERE role IN ($placeholders)");
            $stmt->execute($roles);
            $users = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (!$users) return;
            $ins = $conn->prepare("
                INSERT INTO notifications (user_id, title, message, type, is_read, severity)
                VALUES (?, ?, ?, ?, 0, ?)
            ");
            foreach ($users as $uid) {
                $ins->execute([$uid, $title, $message, $type, $severity]);
            }
        } catch (Throwable $e) {
            paymongo_log('warning', 'notifyRole failed: ' . $e->getMessage());
        }
    }
}

// ------------------------------------------------------------
// Main
// ------------------------------------------------------------
try {
    $db   = new Database();
    $conn = $db->getConnection();
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $input  = body_json();
    $pm     = new PayMongoClient();

    // ============================================================
    // GET
    // ============================================================
    if ($method === 'GET') {

        // --- GET ?action=methods — list enabled methods ---
        if (($_GET['action'] ?? '') === 'methods') {
            $rows = $conn->query("
                SELECT code, label, icon, enabled, sort_order
                FROM paymongo_methods
                WHERE enabled = 1
                ORDER BY sort_order ASC, id ASC
            ")->fetchAll(PDO::FETCH_ASSOC);
            respond(200, ['success' => true, 'data' => $rows]);
        }

        // --- GET ?action=retrieve&id=NN — poll PayMongo and sync ---
        if (($_GET['action'] ?? '') === 'retrieve' && !empty($_GET['id'])) {
            $stmt = $conn->prepare("SELECT * FROM payments WHERE id = ? LIMIT 1");
            $stmt->execute([(int)$_GET['id']]);
            $pay = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$pay) respond(404, ['success' => false, 'message' => 'Payment not found']);

            $sync = null;
            if (!empty($pay['paymongo_checkout_id'])) {
                $sync = $pm->retrieveCheckoutSession($pay['paymongo_checkout_id']);
                $attr = $sync['body']['data']['attributes'] ?? [];
                $remoteStatus = $attr['status'] ?? null;
                $remotePayments = $attr['payments'] ?? [];
                if ($remoteStatus === 'paid' || !empty($remotePayments)) {
                    $conn->prepare("
                        UPDATE payments
                        SET status = 'succeeded', paymongo_status = ?, paid_at = COALESCE(paid_at, NOW())
                        WHERE id = ?
                    ")->execute([$remoteStatus, $pay['id']]);
                } elseif ($remoteStatus) {
                    $conn->prepare("UPDATE payments SET paymongo_status = ? WHERE id = ?")
                         ->execute([$remoteStatus, $pay['id']]);
                }
            } elseif (!empty($pay['paymongo_intent_id'])) {
                $sync = $pm->retrievePaymentIntent($pay['paymongo_intent_id']);
                $attr = $sync['body']['data']['attributes'] ?? [];
                $remoteStatus = $attr['status'] ?? null;
                if ($remoteStatus === 'succeeded') {
                    $conn->prepare("
                        UPDATE payments
                        SET status = 'succeeded', paymongo_status = ?, paid_at = COALESCE(paid_at, NOW())
                        WHERE id = ?
                    ")->execute([$remoteStatus, $pay['id']]);
                } elseif ($remoteStatus) {
                    $conn->prepare("UPDATE payments SET paymongo_status = ? WHERE id = ?")
                         ->execute([$remoteStatus, $pay['id']]);
                }
            }

            $stmt = $conn->prepare("SELECT * FROM payments WHERE id = ? LIMIT 1");
            $stmt->execute([$pay['id']]);
            respond(200, [
                'success' => true,
                'data'    => $stmt->fetch(PDO::FETCH_ASSOC),
                'remote'  => $sync['body'] ?? null,
            ]);
        }

        // --- GET ?id=NN ---
        if (!empty($_GET['id'])) {
            $stmt = $conn->prepare("
                SELECT p.*, s.name AS supplier_name, i.invoice_number,
                       po.po_number, u.full_name AS paid_by_name
                FROM payments p
                LEFT JOIN suppliers s         ON p.supplier_id = s.id
                LEFT JOIN supplier_invoices i ON p.invoice_id  = i.id
                LEFT JOIN purchase_orders po  ON p.po_id       = po.id
                LEFT JOIN users u             ON p.paid_by     = u.id
                WHERE p.id = ?
                LIMIT 1
            ");
            $stmt->execute([(int)$_GET['id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) respond(404, ['success' => false, 'message' => 'Payment not found']);
            respond(200, ['success' => true, 'data' => $row]);
        }

        // --- GET list ---
        $sql = "
            SELECT p.*, s.name AS supplier_name, i.invoice_number,
                   po.po_number, u.full_name AS paid_by_name
            FROM payments p
            LEFT JOIN suppliers s         ON p.supplier_id = s.id
            LEFT JOIN supplier_invoices i ON p.invoice_id  = i.id
            LEFT JOIN purchase_orders po  ON p.po_id       = po.id
            LEFT JOIN users u             ON p.paid_by     = u.id
        ";
        $where = []; $params = [];
        if (!empty($_GET['status']))      { $where[] = "p.status = ?";       $params[] = $_GET['status']; }
        if (!empty($_GET['supplier_id'])) { $where[] = "p.supplier_id = ?";  $params[] = (int)$_GET['supplier_id']; }
        if (!empty($_GET['po_id']))       { $where[] = "p.po_id = ?";        $params[] = (int)$_GET['po_id']; }
        if (!empty($_GET['invoice_id']))  { $where[] = "p.invoice_id = ?";   $params[] = (int)$_GET['invoice_id']; }
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY p.created_at DESC LIMIT 500';

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        respond(200, ['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // ============================================================
    // POST — dispatch by kind
    // ============================================================
    if ($method === 'POST') {
        $kind = strtolower(trim((string)($input['kind'] ?? 'create_checkout')));

        // ---------- kind=record_manual ----------
        if ($kind === 'record_manual') {
            require_fields($input, ['supplier_id','amount','method']);
            if (!in_array($input['method'], ['bank_transfer','cash','cheque'], true)) {
                respond(400, ['success' => false, 'message' => 'Invalid manual method']);
            }
            $uid = current_user_id($input);
            if (!$uid) respond(401, ['success' => false, 'message' => 'Authenticated user required']);

            $ref = make_payment_ref('PAY');
            $conn->beginTransaction();
            try {
                $stmt = $conn->prepare("
                    INSERT INTO payments
                        (payment_ref, invoice_id, po_id, supplier_id, amount, currency,
                         method, status, paid_at, paid_by, notes)
                    VALUES (?, ?, ?, ?, ?, 'PHP', ?, 'succeeded', NOW(), ?, ?)
                ");
                $stmt->execute([
                    $ref,
                    !empty($input['invoice_id']) ? (int)$input['invoice_id'] : null,
                    !empty($input['po_id'])      ? (int)$input['po_id']      : null,
                    (int)$input['supplier_id'],
                    (float)$input['amount'],
                    $input['method'],
                    $uid,
                    $input['notes'] ?? null,
                ]);
                $payId = (int) $conn->lastInsertId();

                if (!empty($input['po_id'])) {
                    $conn->prepare("
                        UPDATE purchase_orders
                        SET amount_paid = amount_paid + ?,
                            payment_status = IF(amount_paid + ? >= total_cost, 'paid', 'partial')
                        WHERE id = ?
                    ")->execute([
                        (float)$input['amount'],
                        (float)$input['amount'],
                        (int)$input['po_id'],
                    ]);
                }
                if (!empty($input['invoice_id'])) {
                    $conn->prepare("UPDATE supplier_invoices SET status = 'paid' WHERE id = ?")
                         ->execute([(int)$input['invoice_id']]);
                }

                $conn->prepare("
                    INSERT INTO transactions
                        (description, amount, type, category, date, status, reference, notes)
                    VALUES (?, ?, 'expense', 'procurement', CURDATE(), 'completed', ?, ?)
                ")->execute([
                    'Supplier payment ' . $ref,
                    (float)$input['amount'],
                    $ref,
                    $input['notes'] ?? null,
                ]);

                notifyRole($conn, ['finance','supply_chain','super_admin','admin'],
                    '✅ Manual payment recorded — ' . $ref,
                    'Supplier payment of ₱' . number_format((float)$input['amount'], 2) . ' via ' . $input['method'],
                    'success', 'success');

                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollBack();
                throw $e;
            }

            respond(200, ['success' => true, 'id' => $payId, 'payment_ref' => $ref]);
        }

        // ---------- kind=create_checkout ----------
        if ($kind === 'create_checkout') {
            require_fields($input, ['supplier_id','amount']);

            $amount    = (float) $input['amount'];
            $ref       = make_payment_ref('PAY');
            $idem      = make_idempotency_key($ref, 'checkout');
            $methods   = normalize_methods($input['methods'] ?? null);
            $desc      = (string) ($input['description'] ?? ('Supplier payment ' . $ref));
            $frontend  = rtrim((string) ($input['redirect_base'] ?? APP_FRONTEND_BASE), '/');
            $successUrl = $frontend . '/payments/return?status=success&ref=' . urlencode($ref);
            $cancelUrl  = $frontend . '/payments/return?status=cancelled&ref=' . urlencode($ref);

            $payload = [
                'line_items'           => [build_line_item($desc, $amount)],
                'payment_method_types' => $methods,
                'success_url'          => $successUrl,
                'cancel_url'           => $cancelUrl,
                'description'          => $desc,
                'reference_number'     => $ref,
                'send_email_receipt'   => false,
                'show_description'     => true,
                'show_line_items'      => true,
                'metadata'             => [
                    'supplier_id'  => (string) $input['supplier_id'],
                    'po_id'        => (string) ($input['po_id'] ?? ''),
                    'invoice_id'   => (string) ($input['invoice_id'] ?? ''),
                    'payment_ref'  => $ref,
                ],
            ];

            $res = $pm->createCheckoutSession($payload, $idem);

            if (!$res['ok']) {
                respond(400, [
                    'success' => false,
                    'message' => 'PayMongo: ' . PayMongoClient::extractError($res),
                    'debug'   => $res['body'],
                ]);
            }

            $checkout     = $res['body']['data'] ?? [];
            $checkoutId   = $checkout['id'] ?? null;
            $checkoutUrl  = $checkout['attributes']['checkout_url'] ?? null;
            $uid          = current_user_id($input);

            $stmt = $conn->prepare("
                INSERT INTO payments
                    (payment_ref, invoice_id, po_id, supplier_id, amount, currency,
                     method, status, paymongo_checkout_id, paymongo_status,
                     checkout_url, idempotency_key, customer_name, customer_email, customer_phone,
                     paid_by, notes, expires_at)
                VALUES (?, ?, ?, ?, ?, 'PHP',
                        'paymongo_checkout', 'pending', ?, 'pending',
                        ?, ?, ?, ?, ?,
                        ?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))
            ");
            $stmt->execute([
                $ref,
                !empty($input['invoice_id']) ? (int)$input['invoice_id'] : null,
                !empty($input['po_id'])      ? (int)$input['po_id']      : null,
                (int)$input['supplier_id'],
                $amount,
                $checkoutId,
                $checkoutUrl,
                $idem,
                $input['customer_name']  ?? null,
                $input['customer_email'] ?? null,
                $input['customer_phone'] ?? null,
                $uid,
                $input['notes'] ?? null,
            ]);
            $payId = (int) $conn->lastInsertId();

            respond(200, [
                'success'      => true,
                'id'           => $payId,
                'payment_ref'  => $ref,
                'checkout_id'  => $checkoutId,
                'checkout_url' => $checkoutUrl,
                'methods'      => $methods,
            ]);
        }

        // ---------- kind=create_intent ----------
        if ($kind === 'create_intent') {
            require_fields($input, ['supplier_id','amount']);

            $amount = (float) $input['amount'];
            $ref    = make_payment_ref('PAY');
            $idem   = make_idempotency_key($ref, 'intent');
            $desc   = (string) ($input['description'] ?? ('Supplier payment ' . $ref));

            // 1) Create intent
            $res = $pm->createPaymentIntent([
                'amount'                 => (int) round($amount * 100),
                'currency'               => 'PHP',
                'description'            => $desc,
                'statement_descriptor'   => 'SmartPOS',
                'payment_method_allowed' => normalize_methods($input['methods'] ?? null),
                'metadata'               => [
                    'payment_ref' => $ref,
                    'supplier_id' => (string) $input['supplier_id'],
                ],
            ], $idem);

            if (!$res['ok']) {
                respond(400, ['success' => false,
                    'message' => 'PayMongo: ' . PayMongoClient::extractError($res),
                    'debug'   => $res['body']]);
            }

            $intent   = $res['body']['data'] ?? [];
            $intentId = $intent['id'] ?? null;
            $clientKey = $intent['attributes']['client_key'] ?? null;
            $uid      = current_user_id($input);

            $stmt = $conn->prepare("
                INSERT INTO payments
                    (payment_ref, invoice_id, po_id, supplier_id, amount, currency,
                     method, status, paymongo_intent_id, paymongo_status,
                     idempotency_key, paid_by, notes, expires_at)
                VALUES (?, ?, ?, ?, ?, 'PHP',
                        'paymongo_intent', 'pending', ?, 'awaiting_payment_method',
                        ?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))
            ");
            $stmt->execute([
                $ref,
                !empty($input['invoice_id']) ? (int)$input['invoice_id'] : null,
                !empty($input['po_id'])      ? (int)$input['po_id']      : null,
                (int)$input['supplier_id'],
                $amount,
                $intentId,
                $idem,
                $uid,
                $input['notes'] ?? null,
            ]);
            $payId = (int) $conn->lastInsertId();

            respond(200, [
                'success'    => true,
                'id'         => $payId,
                'payment_ref'=> $ref,
                'intent_id'  => $intentId,
                'client_key' => $clientKey,
                'next_action'=> $intent['attributes']['next_action'] ?? null,
                'status'     => $intent['attributes']['status'] ?? null,
            ]);
        }

        // ---------- kind=attach_intent ----------
        if ($kind === 'attach_intent') {
            require_fields($input, ['payment_id','payment_method_id']);

            $stmt = $conn->prepare("SELECT * FROM payments WHERE id = ? LIMIT 1");
            $stmt->execute([(int)$input['payment_id']]);
            $pay = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$pay || empty($pay['paymongo_intent_id'])) {
                respond(404, ['success' => false, 'message' => 'Payment or intent not found']);
            }

            $returnUrl = $input['return_url'] ?? (APP_FRONTEND_BASE . '/payments/return?status=success&ref=' . urlencode($pay['payment_ref']));
            $res = $pm->attachPaymentIntent($pay['paymongo_intent_id'], $input['payment_method_id'], $returnUrl);

            if (!$res['ok']) {
                respond(400, ['success' => false,
                    'message' => 'PayMongo: ' . PayMongoClient::extractError($res),
                    'debug'   => $res['body']]);
            }

            $attr   = $res['body']['data']['attributes'] ?? [];
            $status = $attr['status'] ?? null;
            $conn->prepare("UPDATE payments SET paymongo_status = ?, paymongo_payload = ? WHERE id = ?")
                 ->execute([$status, $res['raw'], $pay['id']]);

            respond(200, [
                'success'     => true,
                'status'      => $status,
                'next_action' => $attr['next_action'] ?? null,
                'intent'      => $res['body']['data'] ?? null,
            ]);
        }

        // ---------- kind=create_source ----------
        if ($kind === 'create_source') {
            require_fields($input, ['supplier_id','amount','method']);
            $methodCode = strtolower((string)$input['method']);
            if (!in_array($methodCode, ['gcash','paymaya','grab_pay','qrph'], true)) {
                respond(400, ['success' => false, 'message' => 'create_source supports gcash, paymaya, grab_pay, qrph only']);
            }

            $amount = (float) $input['amount'];
            $ref    = make_payment_ref('PAY');
            $frontend = rtrim((string) ($input['redirect_base'] ?? APP_FRONTEND_BASE), '/');
            $successUrl = $frontend . '/payments/return?status=success&ref=' . urlencode($ref);

            $res = $pm->createSource([
                'amount'     => (int) round($amount * 100),
                'currency'   => 'PHP',
                'type'       => $methodCode,
                'redirect'   => ['success' => $successUrl, 'failed' => $frontend . '/payments/return?status=failed&ref=' . urlencode($ref)],
                'billing'    => [
                    'name'  => $input['customer_name']  ?? 'Customer',
                    'email' => $input['customer_email'] ?? 'noreply@example.com',
                    'phone' => $input['customer_phone'] ?? '',
                ],
                'metadata'   => ['payment_ref' => $ref, 'supplier_id' => (string)$input['supplier_id']],
            ]);

            if (!$res['ok']) {
                respond(400, ['success' => false,
                    'message' => 'PayMongo: ' . PayMongoClient::extractError($res),
                    'debug'   => $res['body']]);
            }

            $src        = $res['body']['data'] ?? [];
            $sourceId   = $src['id'] ?? null;
            $redirect   = $src['attributes']['redirect']['checkout_url'] ?? null;
            $uid        = current_user_id($input);

            $stmt = $conn->prepare("
                INSERT INTO payments
                    (payment_ref, invoice_id, po_id, supplier_id, amount, currency,
                     method, payment_method_type, status,
                     paymongo_source_id, paymongo_status, checkout_url,
                     customer_name, customer_email, customer_phone,
                     paid_by, notes, expires_at)
                VALUES (?, ?, ?, ?, ?, 'PHP',
                        'paymongo', ?, 'pending',
                        ?, 'pending', ?,
                        ?, ?, ?,
                        ?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))
            ");
            $stmt->execute([
                $ref,
                !empty($input['invoice_id']) ? (int)$input['invoice_id'] : null,
                !empty($input['po_id'])      ? (int)$input['po_id']      : null,
                (int)$input['supplier_id'],
                $amount,
                $methodCode,
                $sourceId,
                $redirect,
                $input['customer_name']  ?? null,
                $input['customer_email'] ?? null,
                $input['customer_phone'] ?? null,
                $uid,
                $input['notes'] ?? null,
            ]);

            respond(200, [
                'success'      => true,
                'id'           => (int) $conn->lastInsertId(),
                'payment_ref'  => $ref,
                'source_id'    => $sourceId,
                'checkout_url' => $redirect,
                'status'       => $src['attributes']['status'] ?? null,
            ]);
        }

        // ---------- kind=create_link ----------
        if ($kind === 'create_link') {
            require_fields($input, ['supplier_id','amount']);

            $amount = (float) $input['amount'];
            $ref    = make_payment_ref('LNK');
            $desc   = (string) ($input['description'] ?? ('Payment link ' . $ref));

            $res = $pm->createPaymentLink([
                'amount'      => (int) round($amount * 100),
                'currency'    => 'PHP',
                'description' => $desc,
                'remarks'     => $input['notes'] ?? null,
                'reference_number' => $ref,
                'metadata'    => ['payment_ref' => $ref, 'supplier_id' => (string)$input['supplier_id']],
            ]);

            if (!$res['ok']) {
                respond(400, ['success' => false,
                    'message' => 'PayMongo: ' . PayMongoClient::extractError($res),
                    'debug'   => $res['body']]);
            }

            $link       = $res['body']['data'] ?? [];
            $linkId     = $link['id'] ?? null;
            $linkUrl    = $link['attributes']['checkout_url'] ?? null;
            $uid        = current_user_id($input);

            $stmt = $conn->prepare("
                INSERT INTO payments
                    (payment_ref, invoice_id, po_id, supplier_id, amount, currency,
                     method, status, paymongo_link_id, paymongo_status, checkout_url,
                     idempotency_key, paid_by, notes, expires_at)
                VALUES (?, ?, ?, ?, ?, 'PHP',
                        'paymongo_link', 'pending', ?, 'pending', ?,
                        ?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))
            ");
            $stmt->execute([
                $ref,
                !empty($input['invoice_id']) ? (int)$input['invoice_id'] : null,
                !empty($input['po_id'])      ? (int)$input['po_id']      : null,
                (int)$input['supplier_id'],
                $amount,
                $linkId,
                $linkUrl,
                make_idempotency_key($ref, 'link'),
                $uid,
                $input['notes'] ?? null,
            ]);

            respond(200, [
                'success'   => true,
                'id'        => (int) $conn->lastInsertId(),
                'payment_ref'=> $ref,
                'link_id'   => $linkId,
                'link_url'  => $linkUrl,
                'status'    => $link['attributes']['status'] ?? null,
            ]);
        }

        // ---------- kind=refund ----------
        if ($kind === 'refund') {
            require_fields($input, ['payment_id']);

            $stmt = $conn->prepare("SELECT * FROM payments WHERE id = ? LIMIT 1");
            $stmt->execute([(int)$input['payment_id']]);
            $pay = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$pay) respond(404, ['success' => false, 'message' => 'Payment not found']);
            if ($pay['status'] !== 'succeeded') {
                respond(400, ['success' => false, 'message' => 'Only succeeded payments can be refunded']);
            }
            if (empty($pay['paymongo_payment_id']) && empty($pay['paymongo_intent_id'])) {
                respond(400, ['success' => false, 'message' => 'No PayMongo payment reference to refund']);
            }

            $refundAmount = isset($input['amount'])
                ? (float) $input['amount']
                : (float) $pay['amount'] - (float) $pay['refunded_amount'];

            if ($refundAmount <= 0) respond(400, ['success' => false, 'message' => 'Invalid refund amount']);
            if ($refundAmount > ((float)$pay['amount'] - (float)$pay['refunded_amount'])) {
                respond(400, ['success' => false, 'message' => 'Refund exceeds remaining balance']);
            }

            $refundPayload = [
                'amount'   => (int) round($refundAmount * 100),
                'currency' => 'PHP',
                'notes'    => (string) ($input['reason'] ?? 'Refund from Smart POS'),
                'reason'   => 'others',
            ];
            // PayMongo refund needs payment_id (from a paid payment) or payment_intent_id
            if (!empty($pay['paymongo_payment_id'])) {
                $refundPayload['payment_id'] = $pay['paymongo_payment_id'];
            } else {
                $refundPayload['payment_intent_id'] = $pay['paymongo_intent_id'];
            }

            $idem = 'refund:' . $pay['payment_ref'] . ':' . substr(hash('sha256', $refundAmount . microtime()), 0, 16);
            $res  = $pm->createRefund($refundPayload, $idem);

            if (!$res['ok']) {
                respond(400, ['success' => false,
                    'message' => 'PayMongo: ' . PayMongoClient::extractError($res),
                    'debug'   => $res['body']]);
            }

            $refundData = $res['body']['data'] ?? [];
            $refundId   = $refundData['id'] ?? null;
            $newTotal   = (float) $pay['refunded_amount'] + $refundAmount;
            $newStatus  = $newTotal >= (float) $pay['amount'] ? 'refunded' : 'succeeded';

            $conn->prepare("
                UPDATE payments
                SET refunded_amount = ?, refund_ref = ?, status = ?,
                    paymongo_status = ?, paymongo_payload = ?
                WHERE id = ?
            ")->execute([
                $newTotal, $refundId, $newStatus,
                $refundData['attributes']['status'] ?? 'pending',
                $res['raw'], $pay['id'],
            ]);

            // Reverse expense entry
            $conn->prepare("
                INSERT INTO transactions
                    (description, amount, type, category, date, status, reference, notes)
                VALUES (?, ?, 'income', 'refund', CURDATE(), 'completed', ?, ?)
            ")->execute([
                'Refund ' . $refundId . ' for ' . $pay['payment_ref'],
                $refundAmount,
                $pay['payment_ref'],
                $input['reason'] ?? null,
            ]);

            notifyRole($conn, ['finance','super_admin','admin'],
                '↩️ Refund issued — ' . $pay['payment_ref'],
                'Refund of ₱' . number_format($refundAmount, 2) . ' (' . $refundId . ')',
                'warning', 'warning');

            respond(200, [
                'success'   => true,
                'refund_id' => $refundId,
                'status'    => $refundData['attributes']['status'] ?? 'pending',
                'total_refunded' => $newTotal,
            ]);
        }

        respond(400, ['success' => false, 'message' => "Unknown kind: {$kind}"]);
    }

    // ============================================================
    // PUT — manual override (admin only)
    // ============================================================
    if ($method === 'PUT') {
        $id = $_GET['id'] ?? ($input['id'] ?? null);
        if (!$id) respond(400, ['success' => false, 'message' => 'ID required']);

        $allowed = ['status','paymongo_status','paid_at','notes','failure_reason'];
        $fields = []; $params = [];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $input)) { $fields[] = "$f = ?"; $params[] = $input[$f]; }
        }
        if (!$fields) respond(400, ['success' => false, 'message' => 'No valid fields']);

        $params[] = (int)$id;
        $stmt = $conn->prepare("UPDATE payments SET " . implode(', ', $fields) . " WHERE id = ?");
        $stmt->execute($params);
        respond(200, ['success' => true]);
    }

    respond(405, ['success' => false, 'message' => 'Method not allowed']);

} catch (InvalidArgumentException $e) {
    respond(400, ['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    paymongo_log('error', 'payments.php fatal: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
    respond(500, ['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}