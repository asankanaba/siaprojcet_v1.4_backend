<?php
// ============================================================
// 📁 File: api/paymongo_webhook.php
// 🌐 PUBLIC endpoint — PayMongo POSTs events here.
//    Register this URL in PayMongo dashboard → Developers → Webhooks:
//      Dev:  http://<your-lan-ip>/smart-pos-api/api/paymongo_webhook.php
//      Prod: https://your-domain.com/api/paymongo_webhook.php
//    Events to subscribe:
//      - payment.paid
//      - payment.failed
//      - checkout_session.payment.paid
//      - source.chargeable
//      - refund.updated
//      - refund.succeeded
//      - link.payment.paid
//    ✅ Updates linked supply_chain_requests when payment succeeds
//    ✅ Also updates supplier_invoices + logs transactions
// ============================================================
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/paymongo.php';
require_once __DIR__ . '/_paymongo_client.php';

$rawBody = file_get_contents('php://input');

if ($rawBody === false || $rawBody === '') {
    paymongo_log('error', 'Webhook: empty body');
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Empty body']);
    exit();
}

$payload = json_decode($rawBody, true);
if (!is_array($payload) || empty($payload['data'])) {
    paymongo_log('error', 'Webhook: invalid JSON', ['raw' => substr($rawBody, 0, 2000)]);
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Invalid payload']);
    exit();
}

$signatureHeader = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';
$sigOk = verify_paymongo_signature($rawBody, $signatureHeader);

if (!$sigOk && PAYMONGO_WEBHOOK_SECRET !== '') {
    paymongo_log('error', 'Webhook: signature mismatch', [
        'header' => $signatureHeader,
        'len'    => strlen($rawBody),
    ]);
    try {
        $db = new Database();
        $c = $db->getConnection();
        if ($c) {
            $c->prepare("
                INSERT INTO paymongo_webhook_events
                    (event_id, event_type, livemode, resource_id, payload, signature_ok, processed, process_error)
                VALUES (?, 'signature_invalid', 0, NULL, ?, 0, 0, ?)
            ")->execute([
                'sig-' . bin2hex(random_bytes(8)),
                substr($rawBody, 0, 65000),
                'Paymongo-Signature header did not verify'
            ]);
        }
    } catch (Throwable $e) { /* ignore */ }

    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Invalid signature']);
    exit();
}

$data       = $payload['data'];
$eventId    = $data['id'] ?? null;
$eventType  = $data['attributes']['type']       ?? 'unknown';
$livemode   = (bool)($data['attributes']['livemode'] ?? false);
$eventData  = $data['attributes']['data']       ?? [];
$resourceId = $eventData['id']                  ?? null;

if (!$eventId) {
    $eventId = 'evt-' . substr(hash('sha256', $rawBody), 0, 24);
}

paymongo_log('info', "Webhook received: {$eventType}", [
    'event_id' => $eventId,
    'resource' => $resourceId,
    'livemode' => $livemode,
]);

$db   = new Database();
$conn = $db->getConnection();

if (!$conn) {
    paymongo_log('error', 'Webhook: DB connection failed');
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'DB unavailable']);
    exit();
}

$eventRowId = 0;
try {
    $stmt = $conn->prepare("
        INSERT INTO paymongo_webhook_events
            (event_id, event_type, livemode, resource_id, payload, signature_ok, processed)
        VALUES (?, ?, ?, ?, ?, 1, 0)
    ");
    $stmt->execute([
        $eventId,
        $eventType,
        $livemode ? 1 : 0,
        $resourceId,
        substr($rawBody, 0, 65000),
    ]);
    $eventRowId = (int) $conn->lastInsertId();
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate') !== false) {
        paymongo_log('warning', "Webhook: duplicate event {$eventId} — ignoring");
        echo json_encode(['ok' => true, 'duplicate' => true]);
        exit();
    }
    paymongo_log('error', 'Webhook: DB insert failed', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Insert failed']);
    exit();
}

$result = ['handled' => false, 'message' => null, 'payment_id' => null];

try {
    switch ($eventType) {
        case 'payment.paid':
        case 'checkout_session.payment.paid':
        case 'link.payment.paid':
            $result = handle_payment_paid($conn, $eventType, $eventData);
            break;

        case 'payment.failed':
            $result = handle_payment_failed($conn, $eventData);
            break;

        case 'source.chargeable':
            $result = handle_source_chargeable($conn, $eventData);
            break;

        case 'refund.updated':
        case 'refund.succeeded':
        case 'refund.failed':
            $result = handle_refund_event($conn, $eventType, $eventData);
            break;

        default:
            $result = ['handled' => false, 'message' => "Unhandled event type: {$eventType}"];
            paymongo_log('info', "Webhook: no handler for {$eventType}");
    }
} catch (Throwable $e) {
    $result = ['handled' => false, 'message' => 'Handler exception: ' . $e->getMessage()];
    paymongo_log('error', "Webhook handler exception for {$eventType}", [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
}

try {
    $conn->prepare("
        UPDATE paymongo_webhook_events
        SET processed = ?, process_error = ?, payment_id = ?
        WHERE id = ?
    ")->execute([
        $result['handled'] ? 1 : 0,
        $result['handled'] ? null : ($result['message'] ?? 'Not handled'),
        $result['payment_id'],
        $eventRowId,
    ]);
} catch (Throwable $e) {
    paymongo_log('warning', 'Webhook: could not update event row: ' . $e->getMessage());
}

echo json_encode([
    'ok'      => true,
    'event'   => $eventType,
    'handled' => $result['handled'],
    'message' => $result['message'],
]);
exit();


// ============================================================
// HANDLERS
// ============================================================

function handle_payment_paid(PDO $conn, string $eventType, array $eventData): array
{
    $resourceId = $eventData['id'] ?? null;
    $attrs      = $eventData['attributes'] ?? [];

    $pay = null;

    if (!$pay && $resourceId && strpos($resourceId, 'cs_') === 0) {
        $stmt = $conn->prepare("SELECT * FROM payments WHERE paymongo_checkout_id = ? LIMIT 1");
        $stmt->execute([$resourceId]);
        $pay = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$pay) {
        $intentId = $attrs['payment_intent_id'] ?? null;
        if ($intentId) {
            $stmt = $conn->prepare("SELECT * FROM payments WHERE paymongo_intent_id = ? LIMIT 1");
            $stmt->execute([$intentId]);
            $pay = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }

    if (!$pay && $resourceId && strpos($resourceId, 'link_') === 0) {
        $stmt = $conn->prepare("SELECT * FROM payments WHERE paymongo_link_id = ? LIMIT 1");
        $stmt->execute([$resourceId]);
        $pay = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$pay) {
        $ref = $attrs['reference_number'] ?? null;
        if ($ref) {
            $stmt = $conn->prepare("SELECT * FROM payments WHERE payment_ref = ? LIMIT 1");
            $stmt->execute([$ref]);
            $pay = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }

    if (!$pay) {
        return ['handled' => false, 'message' => "No matching payment for resource {$resourceId}", 'payment_id' => null];
    }

    if ($pay['status'] === 'succeeded') {
        return ['handled' => true, 'message' => 'Already succeeded (idempotent skip)', 'payment_id' => (int)$pay['id']];
    }

    $paymongoPaymentId = null;
    if (!empty($attrs['payments'][0]['id'])) {
        $paymongoPaymentId = $attrs['payments'][0]['id'];
    } elseif (!empty($attrs['payment_id'])) {
        $paymongoPaymentId = $attrs['payment_id'];
    }

    $conn->beginTransaction();
    try {
        // 1. Mark payment as succeeded
        $conn->prepare("
            UPDATE payments
            SET status = 'succeeded',
                paymongo_status = ?,
                paymongo_intent_id = COALESCE(paymongo_intent_id, ?),
                paymongo_payment_id = COALESCE(paymongo_payment_id, ?),
                payment_method_type = COALESCE(payment_method_type, ?),
                paid_at = COALESCE(paid_at, NOW()),
                paymongo_payload = ?
            WHERE id = ?
        ")->execute([
            $attrs['status']              ?? 'paid',
            $attrs['payment_intent_id']   ?? null,
            $paymongoPaymentId,
            $attrs['payment_method_used'] ?? $attrs['source']['type'] ?? null,
            json_encode($eventData, JSON_UNESCAPED_SLASHES),
            $pay['id'],
        ]);

        // 2. Update purchase_orders + linked supply_chain_requests
        if (!empty($pay['po_id'])) {
            $conn->prepare("
                UPDATE purchase_orders
                SET amount_paid = amount_paid + ?,
                    payment_status = IF(amount_paid + ? >= total_cost, 'paid', 'partial'),
                    lifecycle_status = IF(amount_paid + ? >= total_cost, 'paid', lifecycle_status),
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([
                (float)$pay['amount'],
                (float)$pay['amount'],
                (float)$pay['amount'],
                (int)$pay['po_id'],
            ]);

            // ✅ Update linked supply_chain_requests — broader status filter
            $updReq = $conn->prepare("
                UPDATE supply_chain_requests r
                JOIN purchase_orders po ON po.requisition_id = r.id
                SET r.status     = 'paid',
                    r.paid_by    = COALESCE(r.paid_by, po.ordered_by),
                    r.paid_at    = COALESCE(r.paid_at, NOW()),
                    r.updated_at = NOW()
                WHERE po.id = ?
                  AND r.status NOT IN ('completed', 'rejected', 'cancelled', 'unavailable')
            ");
            $updReq->execute([(int)$pay['po_id']]);

            paymongo_log('info', "Webhook: updated supply_chain_requests for PO", [
                'po_id'        => (int)$pay['po_id'],
                'rows_changed' => $updReq->rowCount(),
            ]);
        }

        // 3. Update supplier invoice if present
        if (!empty($pay['invoice_id'])) {
            $conn->prepare("UPDATE supplier_invoices SET status = 'paid' WHERE id = ?")
                 ->execute([(int)$pay['invoice_id']]);
        }

        // 4. Log expense transaction
        $conn->prepare("
            INSERT INTO transactions
                (description, amount, type, category, date, status, reference)
            VALUES (?, ?, 'expense', 'procurement', CURDATE(), 'completed', ?)
        ")->execute([
            'PayMongo payment ' . $pay['payment_ref'],
            (float)$pay['amount'],
            $pay['payment_ref'],
        ]);

        // 5. Notify roles
        notifyRole(
            $conn,
            ['finance','supply_chain','super_admin','admin'],
            '💰 Payment Succeeded — ' . $pay['payment_ref'],
            'Supplier payment of ₱' . number_format((float)$pay['amount'], 2) . ' completed via PayMongo.',
            'success',
            'success'
        );

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollBack();
        throw $e;
    }

    return ['handled' => true, 'message' => 'Payment marked succeeded', 'payment_id' => (int)$pay['id']];
}

function handle_payment_failed(PDO $conn, array $eventData): array
{
    $resourceId = $eventData['id']     ?? null;
    $attrs      = $eventData['attributes'] ?? [];
    $intentId   = $attrs['payment_intent_id'] ?? ($resourceId && strpos($resourceId, 'pi_') === 0 ? $resourceId : null);

    if (!$intentId) {
        return ['handled' => false, 'message' => 'No intent id on failed payment', 'payment_id' => null];
    }

    $stmt = $conn->prepare("SELECT id FROM payments WHERE paymongo_intent_id = ? LIMIT 1");
    $stmt->execute([$intentId]);
    $pay = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$pay) return ['handled' => false, 'message' => "No payment for intent {$intentId}", 'payment_id' => null];

    $reason = $attrs['last_payment_error']['message']
           ?? $attrs['failure_reason']
           ?? 'Payment failed';

    $conn->prepare("
        UPDATE payments
        SET status = 'failed',
            paymongo_status = 'failed',
            failure_reason = ?,
            paymongo_payload = ?
        WHERE id = ?
    ")->execute([$reason, json_encode($eventData, JSON_UNESCAPED_SLASHES), $pay['id']]);

    notifyRole(
        $conn,
        ['finance','supply_chain'],
        '⚠️ Payment Failed',
        'A PayMongo payment failed: ' . $reason,
        'warning',
        'warning'
    );

    return ['handled' => true, 'message' => 'Marked failed', 'payment_id' => (int)$pay['id']];
}

function handle_source_chargeable(PDO $conn, array $eventData): array
{
    $sourceId = $eventData['id'] ?? null;
    $attrs    = $eventData['attributes'] ?? [];

    if (!$sourceId) return ['handled' => false, 'message' => 'No source id', 'payment_id' => null];

    $stmt = $conn->prepare("SELECT * FROM payments WHERE paymongo_source_id = ? LIMIT 1");
    $stmt->execute([$sourceId]);
    $pay = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$pay) return ['handled' => false, 'message' => "No payment for source {$sourceId}", 'payment_id' => null];

    if (in_array($pay['status'], ['succeeded','processing'], true)) {
        return ['handled' => true, 'message' => 'Already processing/succeeded', 'payment_id' => (int)$pay['id']];
    }

    $conn->prepare("UPDATE payments SET status = 'processing', paymongo_status = 'source_chargeable' WHERE id = ?")
         ->execute([$pay['id']]);

    try {
        $pm = new PayMongoClient();
        $intentRes = $pm->createPaymentIntent([
            'amount'                 => (int) round((float)$pay['amount'] * 100),
            'currency'               => 'PHP',
            'description'            => 'Payment ' . $pay['payment_ref'],
            'payment_method_allowed' => [$attrs['type'] ?? 'card'],
            'metadata'               => [
                'payment_ref' => $pay['payment_ref'],
                'source_id'   => $sourceId,
            ],
        ]);
        if (!$intentRes['ok']) {
            throw new RuntimeException('Intent creation failed: ' . PayMongoClient::extractError($intentRes));
        }
        $intentId = $intentRes['body']['data']['id'] ?? null;

        $attachRes = $pm->post("/payment_intents/{$intentId}/attach", [
            'data' => ['attributes' => ['source' => $sourceId]],
        ]);
        if (!$attachRes['ok']) {
            throw new RuntimeException('Attach failed: ' . PayMongoClient::extractError($attachRes));
        }

        $conn->prepare("
            UPDATE payments
            SET paymongo_intent_id = ?,
                paymongo_status = ?,
                paymongo_payload = ?
            WHERE id = ?
        ")->execute([
            $intentId,
            $attachRes['body']['data']['attributes']['status'] ?? 'awaiting_next_action',
            json_encode($attachRes['body'], JSON_UNESCAPED_SLASHES),
            $pay['id'],
        ]);

        return ['handled' => true, 'message' => 'Source charged via new intent', 'payment_id' => (int)$pay['id']];
    } catch (Throwable $e) {
        paymongo_log('error', 'source.chargeable handler failed: ' . $e->getMessage());
        $conn->prepare("UPDATE payments SET failure_reason = ? WHERE id = ?")
             ->execute(['source.chargeable error: ' . $e->getMessage(), $pay['id']]);
        return ['handled' => false, 'message' => $e->getMessage(), 'payment_id' => (int)$pay['id']];
    }
}

function handle_refund_event(PDO $conn, string $eventType, array $eventData): array
{
    $refundId = $eventData['id'] ?? null;
    $attrs    = $eventData['attributes'] ?? [];
    $status   = $attrs['status'] ?? null;
    $pmtId    = $attrs['payment_id'] ?? null;
    $amount   = isset($attrs['amount']) ? ((int)$attrs['amount']) / 100 : null;

    $stmt = $conn->prepare("
        SELECT * FROM payments
        WHERE refund_ref = ?
           OR paymongo_payment_id = ?
        LIMIT 1
    ");
    $stmt->execute([$refundId, $pmtId]);
    $pay = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$pay) return ['handled' => false, 'message' => "No payment for refund {$refundId}", 'payment_id' => null];

    $newRefunded = (float)$pay['refunded_amount'];
    if ($status === 'succeeded' && $amount !== null) {
        if ($newRefunded < $amount) {
            $newRefunded = (float)$amount;
        }
    }
    $fullyRefunded = $newRefunded >= (float)$pay['amount'];

    $conn->prepare("
        UPDATE payments
        SET refunded_amount = ?,
            refund_ref = COALESCE(refund_ref, ?),
            status = ?,
            paymongo_status = ?
        WHERE id = ?
    ")->execute([
        $newRefunded,
        $refundId,
        $fullyRefunded ? 'refunded' : $pay['status'],
        $status,
        $pay['id'],
    ]);

    return ['handled' => true, 'message' => "Refund {$status}", 'payment_id' => (int)$pay['id']];
}

function verify_paymongo_signature(string $rawBody, string $header): bool
{
    if ($header === '') return false;

    $parts = [];
    foreach (explode(',', $header) as $piece) {
        $kv = explode('=', trim($piece), 2);
        if (count($kv) === 2) $parts[$kv[0]] = $kv[1];
    }
    if (empty($parts['t'])) return false;

    $timestamp = $parts['t'];
    $signedPayload = $timestamp . '.' . $rawBody;

    if (abs(time() - (int)$timestamp) > 300) {
        paymongo_log('warning', 'Webhook: timestamp too old', ['t' => $timestamp]);
        return false;
    }

    $secret = PAYMONGO_WEBHOOK_SECRET;
    if ($secret === '') return false;

    $expected = hash_hmac('sha256', $signedPayload, $secret);
    $candidates = array_filter([
        $parts['te'] ?? null,
        $parts['li'] ?? null,
    ]);

    foreach ($candidates as $sig) {
        if (hash_equals($expected, $sig)) return true;
    }
    return false;
}