<?php
require_once __DIR__ . '/_paymongo_client.php';
header('Content-Type: application/json');
$client = new PayMongoClient();
// Attempt a harmless GET — should return 401 with placeholder key, which proves wiring is OK.
$res = $client->listWebhooks();
echo json_encode(['code' => $res['code'], 'error' => $res['error'], 'body' => $res['body']], JSON_PRETTY_PRINT);