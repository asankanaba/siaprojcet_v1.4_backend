<?php
// ============================================
// 📁 File: api/_paymongo_client.php
// 🔌 Shared PayMongo HTTP client
// ============================================

require_once __DIR__ . '/../config/paymongo.php';

if (!class_exists('PayMongoClient')) {

class PayMongoClient
{
    private string $secretKey;
    private string $baseUrl;
    private int    $timeout;
    private int    $maxRetries;

    public function __construct(?string $secretKey = null, ?string $baseUrl = null, int $timeout = 30, int $maxRetries = 2)
    {
        $this->secretKey  = $secretKey ?? PAYMONGO_SECRET_KEY;
        $this->baseUrl    = rtrim($baseUrl  ?? PAYMONGO_API_BASE, '/');
        $this->timeout    = $timeout;
        $this->maxRetries = $maxRetries;

        if (strpos($this->secretKey, 'REPLACE_ME') !== false) {
            paymongo_log('warning', 'PayMongo secret key is still the placeholder value.');
        }
    }

    public function get(string $endpoint, array $query = []): array
    {
        $url = $this->baseUrl . $endpoint;
        if ($query) $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
        return $this->request('GET', $url);
    }

    public function post(string $endpoint, array $body = [], ?string $idempotencyKey = null): array
    {
        return $this->request('POST', $this->baseUrl . $endpoint, $body, $idempotencyKey);
    }

    public function put(string $endpoint, array $body = [], ?string $idempotencyKey = null): array
    {
        return $this->request('PUT', $this->baseUrl . $endpoint, $body, $idempotencyKey);
    }

    public function createCheckoutSession(array $attributes, ?string $idempotencyKey = null): array
    {
        return $this->post('/checkout_sessions', ['data' => ['attributes' => $attributes]], $idempotencyKey);
    }

    public function createPaymentIntent(array $attributes, ?string $idempotencyKey = null): array
    {
        return $this->post('/payment_intents', ['data' => ['attributes' => $attributes]], $idempotencyKey);
    }

    public function attachPaymentIntent(string $intentId, string $paymentMethodId, ?string $returnUrl = null): array
    {
        $attrs = ['payment_method' => $paymentMethodId];
        if ($returnUrl) $attrs['return_url'] = $returnUrl;
        return $this->post("/payment_intents/{$intentId}/attach", ['data' => ['attributes' => $attrs]]);
    }

    public function createPaymentMethod(array $attrs): array
    {
        return $this->post('/payment_methods', ['data' => ['attributes' => $attrs]]);
    }

    public function createSource(array $attrs): array
    {
        return $this->post('/sources', ['data' => ['attributes' => $attrs]]);
    }

    public function createPaymentLink(array $attrs): array
    {
        return $this->post('/links', ['data' => ['attributes' => $attrs]]);
    }

    public function createRefund(array $attrs, ?string $idempotencyKey = null): array
    {
        return $this->post('/refunds', ['data' => ['attributes' => $attrs]], $idempotencyKey);
    }

    public function retrievePaymentIntent(string $id): array
    {
        return $this->get("/payment_intents/{$id}");
    }

    public function retrieveCheckoutSession(string $id): array
    {
        return $this->get("/checkout_sessions/{$id}");
    }

    public function retrieveSource(string $id): array
    {
        return $this->get("/sources/{$id}");
    }

    public function createWebhook(array $attrs): array
    {
        return $this->post('/webhooks', ['data' => ['attributes' => $attrs]]);
    }

    public function listWebhooks(): array
    {
        return $this->get('/webhooks');
    }

    private function request(string $method, string $url, ?array $body = null, ?string $idempotencyKey = null): array
    {
        $attempt = 0;
        $lastResult = null;

        while ($attempt <= $this->maxRetries) {
            $attempt++;
            $result = $this->doCurl($method, $url, $body, $idempotencyKey);
            $lastResult = $result;

            $isRetryable = $result['error'] !== null
                        || ($result['code'] >= 500 && $result['code'] < 600)
                        || $result['code'] === 429;

            if (!$isRetryable || $attempt > $this->maxRetries) break;

            $sleep = (int) pow(2, $attempt - 1) * 300;
            usleep($sleep * 1000);
            paymongo_log('warning', "Retrying PayMongo {$method} {$url} (attempt {$attempt})", [
                'code' => $result['code'], 'error' => $result['error']
            ]);
        }

        paymongo_log(
            ($lastResult['code'] >= 400 || $lastResult['error']) ? 'error' : 'info',
            "PayMongo {$method} {$url}",
            [
                'status'   => $lastResult['code'],
                'error'    => $lastResult['error'],
                'request'  => $body,
                'response' => $lastResult['body'],
            ]
        );

        return $lastResult;
    }

    private function doCurl(string $method, string $url, ?array $body, ?string $idempotencyKey): array
    {
        $ch = curl_init();
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode($this->secretKey . ':'),
        ];
        if ($idempotencyKey) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
        }

        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch) ?: null;
        curl_close($ch);

        $decoded = $raw ? json_decode($raw, true) : null;

        return [
            'ok'   => $err === null && $code >= 200 && $code < 300,
            'code' => $code,
            'body' => $decoded,
            'raw'  => $raw,
            'error'=> $err,
        ];
    }

    public static function extractError(array $result): string
    {
        if (!empty($result['error'])) return (string) $result['error'];
        $body = $result['body'] ?? null;
        if (is_array($body)) {
            if (!empty($body['errors'][0]['detail'])) return (string) $body['errors'][0]['detail'];
            if (!empty($body['errors'][0]['code']))   return (string) $body['errors'][0]['code'];
            if (!empty($body['message']))             return (string) $body['message'];
        }
        return 'Unknown PayMongo error (HTTP ' . ($result['code'] ?? '?') . ')';
    }
}

}