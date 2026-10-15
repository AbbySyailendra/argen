<?php
// Endpoint preview: buat paragraf 2 & 3 untuk satu kata kunci (tanpa menyimpan apa pun)
header('Content-Type: application/json; charset=utf-8');

function out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    out(['error' => 'Method not allowed'], 405);
}

$keyword = trim($_POST['keyword'] ?? '');
$title   = trim($_POST['title'] ?? '');
$lokasi  = trim($_POST['lokasi'] ?? '');
$before  = trim($_POST['before'] ?? '');
$after   = trim($_POST['after'] ?? '');
$model   = $_POST['model'] ?? 'qwen2.5:3b';
$mode    = $_POST['mode'] ?? 'informatif';

if ($keyword === '') {
    out(['error' => 'Kata kunci kosong'], 400);
}

// Referensi dari Wikipedia
$raw_text = '';
$ctx = stream_context_create(["http" => ["header" => "User-Agent: AutoArticleBot/1.0\r\n", "timeout" => 10]]);
$wiki = @file_get_contents("https://id.wikipedia.org/api/rest_v1/page/summary/" . rawurlencode($keyword), false, $ctx);
if ($wiki !== false) {
    $d = json_decode($wiki, true);
    $raw_text = $d['extract'] ?? '';
}
if ($raw_text === '') {
    $raw_text = "Topik mengenai $keyword.";
}

$gaya = $mode === 'promosi'
    ? 'promosi lokal yang persuasif namun tetap natural (cocok untuk landing page penjualan)'
    : 'informatif dan edukatif';

$prompt = "Lanjutkan sebuah artikel blog berbahasa Indonesia.\n";
if ($title !== '')  $prompt .= "Judul artikel: $title\n";
$prompt .= "Kata kunci utama: $keyword\n";
if ($lokasi !== '') $prompt .= "Wilayah target: $lokasi\n";
$prompt .= "Gaya penulisan: $gaya\n\n";
if ($before !== '') $prompt .= "Teks artikel sebelumnya (sudah ada, JANGAN diulang):\n$before\n\n";
if ($after !== '')  $prompt .= "Teks setelahnya (sudah ada, hanya untuk menjaga alur, JANGAN ditulis ulang; \"[paragraf AI]\" adalah paragraf lain yang ditulis terpisah):\n$after\n\n";
$prompt .= "Referensi:\n$raw_text\n\n";
$prompt .= "Tulis TEPAT SATU paragraf baru (3-5 kalimat) yang menjadi sambungan alami di antara teks sebelum dan sesudahnya. "
        .  "Bahas sudut pandang yang berbeda dari paragraf yang sudah ada. "
        .  "Jangan beri judul, nomor, bullet, tanda kutip, atau kalimat pengantar. Keluarkan hanya isi paragrafnya.";

$ch = curl_init("http://127.0.0.1:11434/api/generate");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode(["model" => $model, "prompt" => $prompt, "stream" => false]),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT        => 120,
]);
$response = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

if ($response === false) {
    out(['error' => 'Gagal terhubung ke Ollama: ' . $err], 502);
}

$res  = json_decode($response, true);
$text = trim($res['response'] ?? '');
if ($text === '') {
    out(['error' => ($res['error'] ?? 'Ollama tidak mengembalikan teks') . " (model: $model)"], 502);
}

// Model kadang mengembalikan lebih dari satu paragraf: gabungkan jadi satu
$paragraph = trim(preg_replace('/\s*\R\s*/', ' ', $text), " \t\"");
out(['paragraph' => $paragraph]);
