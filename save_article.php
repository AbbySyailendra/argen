<?php
// Endpoint: simpan satu artikel hasil generate ke database
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$f = fn($k) => trim($_POST[$k] ?? '');
$content = $f('content');
$keyword = $f('keyword');
if ($content === '' || $keyword === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Keyword dan isi artikel wajib ada']);
    exit;
}
$status = $f('status') === 'partial' ? 'partial' : 'ok';

// Tanggal publish (waktu setempat, "Y-m-d H:i:s"); kosong = terbit saat dikirim
$publishAt = $f('publish_at');
$publishAt = preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $publishAt) ? str_replace('T', ' ', $publishAt) : null;
$wpStatus = array_key_exists($f('wp_target_status'), ['publish' => 1, 'draft' => 1, 'pending' => 1, 'private' => 1]) ? $f('wp_target_status') : '';

$st = db()->prepare('INSERT INTO articles (title, keyword, lokasi, wilayah_target, model, mode, template, content, sources, status, publish_at, wp_target_status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$title = $f('title'); $lokasi = $f('lokasi'); $target = $f('wilayah_target');
$model = $f('model'); $mode = $f('mode'); $template = $f('template'); $sources = $f('sources');
$st->bind_param('ssssssssssss', $title, $keyword, $lokasi, $target, $model, $mode, $template, $content, $sources, $status, $publishAt, $wpStatus);

if (!$st->execute()) {
    http_response_code(500);
    echo json_encode(['error' => 'Gagal menyimpan: ' . $st->error]);
    exit;
}
echo json_encode(['id' => $st->insert_id]);
