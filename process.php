<?php
// Koneksi database memakai pengaturan di config.php
require_once __DIR__ . '/config.php';
$mysqli = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw_keywords = $_POST['keywords'] ?? '';
    $target_wilayah = $_POST['target_wilayah'] ?? 'ALL';
    $model = $_POST['model'] ?? 'qwen2.5:3b';
    $mode = $_POST['mode'] ?? 'informatif';

    // 1. Pecah Input Textarea Berdasarkan Newline (Baris per Baris)
    $keyword_list = array_filter(array_map('trim', explode("\n", $raw_keywords)));

    if (empty($keyword_list)) {
        die("Silakan masukkan minimal satu kata kunci.");
    }

    // 2. Resolver Lokasi (FB Ads Style Logic)
    $list_kelurahan = [];
    if ($target_wilayah !== 'ALL') {
        list($level, $value) = explode(':', $target_wilayah, 2);

        if ($level === 'PROV') {
            $stmt = $mysqli->prepare("SELECT kelurahan, kecamatan, kota_kab FROM wilayah WHERE provinsi = ?");
            $stmt->bind_param("s", $value);
        } elseif ($level === 'KOTA') {
            $stmt = $mysqli->prepare("SELECT kelurahan, kecamatan, kota_kab FROM wilayah WHERE kota_kab = ?");
            $stmt->bind_param("s", $value);
        } elseif ($level === 'KEC') {
            list($kec, $kota) = explode('|', $value);
            $stmt = $mysqli->prepare("SELECT kelurahan, kecamatan, kota_kab FROM wilayah WHERE kecamatan = ? AND kota_kab = ?");
            $stmt->bind_param("ss", $kec, $kota);
        } elseif ($level === 'KEL') {
            list($kel, $kec, $kota) = explode('|', $value);
            $stmt = $mysqli->prepare("SELECT kelurahan, kecamatan, kota_kab FROM wilayah WHERE kelurahan = ? AND kecamatan = ? AND kota_kab = ?");
            $stmt->bind_param("sss", $kel, $kec, $kota);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $list_kelurahan[] = $row['kelurahan'] . ", " . $row['kecamatan'] . ", " . $row['kota_kab'];
        }
        $stmt->close();
    }

    // Array Hasil Penulisan
    $processed_results = [];

    // 3. Looping Proses Per Kata Kunci (Baris per Baris)
    foreach ($keyword_list as $index => $keyword) {
        
        // A. Ambil Data Wikipedia
        $wiki_url = "https://id.wikipedia.org/api/rest_v1/page/summary/" . urlencode($keyword);
        $options = ["http" => ["header" => "User-Agent: AutoArticleBot/1.0\r\n"]];
        $context = stream_context_create($options);
        $wiki_response = @file_get_contents($wiki_url, false, $context);
        
        $raw_text = "";
        if ($wiki_response !== FALSE) {
            $wiki_data = json_decode($wiki_response, true);
            $raw_text = $wiki_data['extract'] ?? '';
        }

        // Jika topik wikipedia tidak ketemu, gunakan fallback info umum
        if (empty($raw_text)) {
            $raw_text = "Topik mengenai $keyword.";
        }

        // B. Buat Prompt Ollama
        $prompt = "Tulis artikel blog berkualitas tentang '$keyword'.\n";
        if (!empty($list_kelurahan)) {
            $prompt .= "Targetkan konteks lokal untuk wilayah berikut: " . implode(", ", array_slice($list_kelurahan, 0, 10)) . ".\n";
        }
        $prompt .= "Gunakan gaya bahasa $mode dan bahasa Indonesia yang baik.\n\nReferensi Teks:\n" . $raw_text;

        // C. Kirim ke Ollama
        $payload = json_encode([
            "model" => $model,
            "prompt" => $prompt,
            "stream" => false
        ]);

        $ch = curl_init("http://127.0.0.1:11434/api/generate");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);

        $response = curl_exec($ch);
        curl_close($ch);

        $res_data = json_decode($response, true);
        $article_content = $res_data['response']
            ?? ('Gagal memproses dengan AI: ' . ($res_data['error'] ?? 'Ollama tidak merespons') . " (model: $model)");

        $processed_results[] = [
            'keyword' => $keyword,
            'content' => $article_content
        ];
    }
}
$mysqli->close();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <script src="https://cdn.tailwindcss.com"></script>
    <title>Hasil Generate Artikel</title>
</head>
<body class="bg-slate-100 min-h-screen py-8 text-slate-800">
    <div class="max-w-4xl mx-auto px-4">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold">Hasil Penulisan Artikel (<?php echo count($processed_results); ?> Artikel)</h1>
            <a href="index.php" class="bg-slate-200 hover:bg-slate-300 text-slate-700 px-4 py-2 rounded-lg text-sm font-medium">← Kembali</a>
        </div>

        <div class="space-y-6">
            <?php foreach ($processed_results as $i => $item): ?>
                <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200">
                    <span class="inline-block bg-blue-100 text-blue-800 text-xs font-semibold px-2.5 py-0.5 rounded mb-2">
                        Artikel #<?php echo $i + 1; ?>
                    </span>
                    <h2 class="text-xl font-bold text-slate-900 mb-3"><?php echo htmlspecialchars($item['keyword']); ?></h2>
                    <div class="prose max-w-none text-slate-700 leading-relaxed whitespace-pre-line text-sm border-t border-slate-100 pt-3">
                        <?php echo htmlspecialchars($item['content']); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>