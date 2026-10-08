<?php
// ============================================
// 📁 File: config/cloudinary.php
// ☁️ Cloudinary configuration
// Reads credentials from environment variables
// (set in Vercel → Settings → Environment Variables)
// ============================================

// ---------- Credentials ----------
// 🔐 Set these in Vercel dashboard:
//    CLOUDINARY_CLOUD_NAME
//    CLOUDINARY_API_KEY
//    CLOUDINARY_API_SECRET
define('CLOUDINARY_CLOUD_NAME', getenv('CLOUDINARY_CLOUD_NAME') ?: 'mg2yferi');
define('CLOUDINARY_API_KEY',    getenv('CLOUDINARY_API_KEY')    ?: '');
define('CLOUDINARY_API_SECRET', getenv('CLOUDINARY_API_SECRET') ?: '');

// ---------- API ----------
define('CLOUDINARY_API_BASE',    'https://api.cloudinary.com/v1_1/' . CLOUDINARY_CLOUD_NAME);
define('CLOUDINARY_UPLOAD_URL',  CLOUDINARY_API_BASE . '/image/upload');
define('CLOUDINARY_TIMEOUT',     60);

// ---------- Upload defaults ----------
// Folder in Cloudinary where product images are stored
define('CLOUDINARY_UPLOAD_FOLDER', 'smart-pos/products');

// Transformation applied on upload (auto-optimized delivery URLs)
// f_auto → auto format (WebP/AVIF)
// q_auto → auto quality
// w_1200,c_limit → max 1200px wide, keep aspect
define('CLOUDINARY_UPLOAD_TRANSFORMATION', 'f_auto,q_auto,w_1200,c_limit');

// ---------- Logging ----------
define('CLOUDINARY_LOG_FILE', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cloudinary.log');

if (!function_exists('cloudinary_log')) {
    function cloudinary_log(string $level, string $message, array $context = []): void {
        $line = sprintf(
            "[%s] [%s] %s %s\n",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            $context ? json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : ''
        );
        @file_put_contents(CLOUDINARY_LOG_FILE, $line, FILE_APPEND | LOCK_EX);
    }
}
?>