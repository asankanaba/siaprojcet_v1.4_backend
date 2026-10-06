<?php
// ============================================================
// 📁 File: api/paymongo_return.php
// 🔁 Called by the frontend after the user returns from
//    PayMongo checkout. Polls PayMongo to sync the payment
//    in case the webhook is delayed (or, in dev on LAN, has
//    no way to reach us at all).
// ============================================================
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit(); }

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/paymongo.php';
require_once __DIR__ . '/_paymongo_client.php';

// ------------------------------------------------------------
// Read ref from GET
// ------------------------------------------------------------
$ref = $_GET['ref'] ?? $_GET['reference'] ?? null;

if (!$ref) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing ref']);
    exit();
}

try {
    $db   = new Database();
    $conn = $db->getConnection();

    if (!$conn) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'DB unavailable']);
        exit();
    }

    // ------------------------------------------------------------
    // Find the payment row
    // ------------------------------------------------------------
    $stmt = $conn->prepare("SELECT * FROM payments WHERE payment_ref = ? LIMIT 1");
    $stmt->execute([$ref]);
    $pay = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pay) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Payment not found']);
        exit();
    }

    // ------------------------------------------------------------
    // Already terminal? Return immediately.
    // ------------------------------------------------------------
    if (in_array($pay['status'], ['succeeded', 'failed', 'cancelled', 'refunded'], true)) {
        echo json_encode(['success' => true, 'data' => $pay, 'synced' => false]);
        exit();
    }

    // ------------------------------------------------------------
    // Not terminal — ask PayMongo for the latest state
    // ------------------------------------------------------------
    $pm     = new PayMongoClient();
    $synced = false;

    // ---- Checkout Session path ----
    if (!empty($pay['paymongo_checkout_id'])) {
        $res  = $pm->retrieveCheckoutSession($pay['paymongo_checkout_id']);
        $attr = $res['body']['data']['attributes'] ?? [];
        $remoteStatus = $attr['status'] ?? null;
        $hasPayments  = !empty($attr['payments']);

        // PayMongo marks checkout as 'paid' when complete
        if ($remoteStatus === 'paid' || $hasPayments) {
            $conn->beginTransaction();
            try {
                $conn->prepare("
                    UPDATE payments
                    SET status = 'succeeded',
                        paymongo_status = ?,
                        paymongo_intent_id = COALESCE(paymongo_intent_id, ?),
                        paid_at = COALESCE(paid_at, NOW()),
                        paymongo_payload = ?
                    WHERE id = ?
                ")->execute([
                    $remoteStatus,
                    $attr['payment_intent_id'] ?? null,
                    json_encode($attr, JSON_UNESCAPED_SLASHES),
                    $pay['id'],
                ]);

                // Update the PO if this payment is tied to one
                if (!empty($pay['po_id'])) {
                    $conn->prepare("
                        UPDATE purchase_orders
                        SET amount_paid = amount_paid + ?,
                            payment_status = IF(amount_paid + ? >= total_cost, 'paid', 'partial'),
                            lifecycle_status = IF(amount_paid + ? >= total_cost, 'paid', lifecycle_status)
                        WHERE id = ?
                    ")->execute([
                        (float)$pay['amount'],
                        (float)$pay['amount'],
                        (float)$pay['amount'],
                        (int)$pay['po_id'],
                    ]);
                }

                // Mark the supplier invoice paid, if any
                if (!empty($pay['invoice_id'])) {
                    $conn->prepare("UPDATE supplier_invoices SET status = 'paid' WHERE id = ?")
                         ->execute([(int)$pay['invoice_id']]);
                }

                // Record the expense once
                $conn->prepare("
                    INSERT INTO transactions
                        (description, amount, type, category, date, status, reference)
                    VALUES (?, ?, 'expense', 'procurement', CURDATE(), 'completed', ?)
                ")->execute([
                    'PayMongo payment ' . $pay['payment_ref'],
                    (float)$pay['amount'],
                    $pay['payment_ref'],
                ]);

                // Notify finance + supply chain
                if (function_exists('notifyRole')) {
                    notifyRole(
                        $conn,
                        ['finance','supply_chain','super_admin','admin'],
                        '💰 Payment Succeeded — ' . $pay['payment_ref'],
                        'Supplier payment of ₱' . number_format((float)$pay['amount'], 2) . ' completed via PayMongo.',
                        'success',
                        'success'
                    );
                }

                $conn->commit();
                $synced = true;
            } catch (Throwable $e) {
                $conn->rollBack();
                paymongo_log('error', 'return.php sync tx failed: ' . $e->getMessage());
            }
        }
    }
    // ---- Payment Intent path ----
    elseif (!empty($pay['paymongo_intent_id'])) {
        $res  = $pm->retrievePaymentIntent($pay['paymongo_intent_id']);
        $attr = $res['body']['data']['attributes'] ?? [];
        $remoteStatus = $attr['status'] ?? null;

        if ($remoteStatus === 'succeeded') {
            $conn->prepare("
                UPDATE payments
                SET status = 'succeeded',
                    paymongo_status = ?,
                    paid_at = COALESCE(paid_at, NOW())
                WHERE id = ?
            ")->execute([$remoteStatus, $pay['id']]);
            $synced = true;
        } elseif ($remoteStatus === 'failed' || $remoteStatus === 'cancelled') {
            $conn->prepare("
                UPDATE payments
                SET status = ?,
                    paymongo_status = ?,
                    failure_reason = ?
                WHERE id = ?
            ")->execute([
                $remoteStatus,
                $remoteStatus,
                $attr['last_payment_error']['message'] ?? null,
                $pay['id'],
            ]);
            $synced = true;
        }
    }
    // ---- Source path (GCash/Maya/GrabPay legacy) ----
    elseif (!empty($pay['paymongo_source_id'])) {
        $res  = $pm->retrieveSource($pay['paymongo_source_id']);
        $attr = $res['body']['data']['attributes'] ?? [];
        $remoteStatus = $attr['status'] ?? null;

        // source.status can be: pending, chargeable, cancelled, expired, consumed
        if ($remoteStatus === 'chargeable') {
            // Source is authorized; we need to attach it to a new payment intent
            // (see webhook's source.chargeable handler — this is the same logic as a fallback)
            try {
                $intentRes = $pm->createPaymentIntent([
                    'amount'                 => (int) round((float)$pay['amount'] * 100),
                    'currency'               => 'PHP',
                    'description'            => 'Payment ' . $pay['payment_ref'],
                    'payment_method_allowed' => [$attr['type'] ?? 'gcash'],
                    'metadata'               => [
                        'payment_ref' => $pay['payment_ref'],
                        'source_id'   => $pay['paymongo_source_id'],
                    ],
                ]);
                if (!empty($intentRes['body']['data']['id'])) {
                    $intentId = $intentRes['body']['data']['id'];
                    $attachRes = $pm->post("/payment_intents/{$intentId}/attach", [
                        'data' => ['attributes' => ['source' => $pay['paymongo_source_id']]],
                    ]);
                    $conn->prepare("
                        UPDATE payments
                        SET paymongo_intent_id = ?,
                            paymongo_status = ?,
                            status = 'processing',
                            paymongo_payload = ?
                        WHERE id = ?
                    ")->execute([
                        $intentId,
                        $attachRes['body']['data']['attributes']['status'] ?? 'awaiting_next_action',
                        json_encode($attachRes['body'], JSON_UNESCAPED_SLASHES),
                        $pay['id'],
                    ]);
                    $synced = true;
                }
            } catch (Throwable $e) {
                paymongo_log('error', 'return.php source charge failed: ' . $e->getMessage());
            }
        } elseif ($remoteStatus === 'cancelled' || $remoteStatus === 'expired') {
            $conn->prepare("
                UPDATE payments
                SET status = 'failed',
                    paymongo_status = ?,
                    failure_reason = ?
                WHERE id = ?
            ")->execute([
                $remoteStatus,
                'Source ' . $remoteStatus,
                $pay['id'],
            ]);
            $synced = true;
        } else {
            // Just store the latest PayMongo status
            $conn->prepare("UPDATE payments SET paymongo_status = ? WHERE id = ?")
                 ->execute([$remoteStatus, $pay['id']]);
        }
    }

    // ------------------------------------------------------------
    // Reload the fresh row and return
    // ------------------------------------------------------------
    $stmt = $conn->prepare("SELECT * FROM payments WHERE id = ? LIMIT 1");
    $stmt->execute([$pay['id']]);
    $fresh = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data'    => $fresh,
        'synced'  => $synced,
    ]);

} catch (Throwable $e) {
    paymongo_log('error', 'paymongo_return.php fatal: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}