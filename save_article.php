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

$st = db()->prepare('INSERT INTO articles (title, keyword, lokasi, wilayah_target, model, mode, template, content, sources, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$title = $f('title'); $lokasi = $f('lokasi'); $target = $f('wilayah_target');
$model = $f('model'); $mode = $f('mode'); $template = $f('template'); $sources = $f('sources');
$st->bind_param('ssssssssss', $title, $keyword, $lokasi, $target, $model, $mode, $template, $content, $sources, $status);

if (!$st->execute()) {
    http_response_code(500);
    echo json_encode(['error' => 'Gagal menyimpan: ' . $st->error]);
    exit;
}
echo json_encode(['id' => $st->insert_id]);
