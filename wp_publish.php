<?php
// Endpoint: kirim satu artikel tersimpan ke WordPress
require_once __DIR__ . '/wp.php';
header('Content-Type: application/json; charset=utf-8');
set_time_limit(120);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}
echo json_encode(wp_publish_article((int) ($_POST['id'] ?? 0)), JSON_UNESCAPED_UNICODE);
