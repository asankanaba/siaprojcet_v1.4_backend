<?php
// ============================================
// 📁 File: api/_cloudinary_client.php
// ☁️ Shared Cloudinary HTTP client
// Uploads images, returns secure URLs
// ============================================

require_once __DIR__ . '/../config/cloudinary.php';

if (!class_exists('CloudinaryClient')) {

class CloudinaryClient
{
    private string $cloudName;
    private string $apiKey;
    private string $apiSecret;
    private int    $timeout;

    public function __construct(?string $cloudName = null, ?string $apiKey = null, ?string $apiSecret = null, int $timeout = 60)
    {
        $this->cloudName = $cloudName ?: CLOUDINARY_CLOUD_NAME;
        $this->apiKey    = $apiKey    ?: CLOUDINARY_API_KEY;
        $this->apiSecret = $apiSecret ?: CLOUDINARY_API_SECRET;
        $this->timeout   = $timeout;

        if (empty($this->apiKey) || empty($this->apiSecret)) {
            cloudinary_log('warning', 'Cloudinary credentials missing or empty. Uploads will fail.');
        }
    }

    /**
     * Upload a local file (from $_FILES) to Cloudinary.
     * Returns ['ok' => bool, 'url' => string|null, 'public_id' => string|null, 'raw' => array, 'error' => string|null]
     */
    public function uploadFile(string $tmpPath, string $originalName = '', ?string $folder = null): array
    {
        if (!is_file($tmpPath)) {
            return ['ok' => false, 'url' => null, 'public_id' => null, 'raw' => [], 'error' => 'Temp file not found'];
        }

        $folder = $folder ?: CLOUDINARY_UPLOAD_FOLDER;
        $timestamp = time();
        $params = [
            'folder'    => $folder,
            'timestamp' => $timestamp,
        ];

        // Generate signature (Cloudinary requires this for authenticated uploads)
        $signature = $this->signParams($params);

        $postFields = [
            'file'       => new CURLFile($tmpPath, mime_content_type($tmpPath) ?: 'application/octet-stream', $originalName ?: basename($tmpPath)),
            'api_key'    => $this->apiKey,
            'timestamp'  => $timestamp,
            'folder'     => $folder,
            'signature'  => $signature,
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => CLOUDINARY_UPLOAD_URL,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch) ?: null;
        curl_close($ch);

        $body = $raw ? json_decode($raw, true) : null;

        cloudinary_log(
            ($err || $code >= 400) ? 'error' : 'info',
            "Cloudinary upload to {$folder}",
            ['status' => $code, 'error' => $err, 'response' => is_array($body) ? array_intersect_key($body, ['secure_url' => 1, 'public_id' => 1, 'error' => 1]) : null]
        );

        if ($err || $code < 200 || $code >= 300) {
            $msg = $err
                ?: ($body['error']['message'] ?? null)
                ?: ('Cloudinary HTTP ' . $code);
            return ['ok' => false, 'url' => null, 'public_id' => null, 'raw' => is_array($body) ? $body : [], 'error' => $msg];
        }

        return [
            'ok'        => true,
            'url'       => $body['secure_url']     ?? null,
            'public_id' => $body['public_id']      ?? null,
            'raw'       => $body,
            'error'     => null,
        ];
    }

    /**
     * Get an optimized delivery URL from a public_id.
     * Adds f_auto,q_auto,w_1200,c_limit transformation.
     */
    public function getOptimizedUrl(string $publicId): string
    {
        $trans = CLOUDINARY_UPLOAD_TRANSFORMATION;
        return "https://res.cloudinary.com/{$this->cloudName}/image/upload/{$trans}/{$publicId}";
    }

    /**
     * Extract public_id from a Cloudinary secure_url.
     * e.g. https://res.cloudinary.com/mg2yferi/image/upload/v1234/smart-pos/products/abc.jpg
     *   → smart-pos/products/abc
     */
    public static function publicIdFromUrl(string $url): ?string
    {
        if (!preg_match('#/upload/(?:v\d+/)?(.+?)(?:\.\w+)?$#', $url, $m)) {
            return null;
        }
        return $m[1];
    }

    /**
     * Sign params per Cloudinary spec:
     * sort alphabetically, join with &, append api_secret, sha1.
     */
    private function signParams(array $params): string
    {
        ksort($params);
        $parts = [];
        foreach ($params as $k => $v) {
            if ($v === null || $v === '') continue;
            $parts[] = $k . '=' . (is_bool($v) ? ($v ? 'true' : 'false') : $v);
        }
        $toSign = implode('&', $parts) . $this->apiSecret;
        return sha1($toSign);
    }
}

}
?>