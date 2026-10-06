<?php
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=2592000');

$file = isset($_GET['f']) ? basename($_GET['f']) : '';
$type = isset($_GET['t']) ? $_GET['t'] : 'products';

if (!$file || !preg_match('/^[A-Za-z0-9._-]+$/', $file)) {
    http_response_code(400); exit('Bad filename');
}

$allowed = ['products', 'profiles'];
if (!in_array($type, $allowed, true)) $type = 'products';

$path = __DIR__ . '/../uploads/' . $type . '/' . $file;

if (!is_file($path)) {
    http_response_code(404); exit('Not found');
}

$mime = mime_content_type($path) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
readfile($path);