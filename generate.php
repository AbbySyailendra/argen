<?php
// Endpoint penulisan artikel oleh Ollama. Dua aksi:
//   action=outline   : rancang kerangka artikel (tema + pokok bahasan tiap {paragraf}) supaya paragraf saling terhubung
//   action=paragraph : tulis SATU paragraf untuk posisi {paragraf} / {paragraf:instruksi}, mengikuti kerangka
// Bahan tulisan = referensi dari sumber web yang diatur (Wikipedia dll) untuk kata kunci dan wilayah, agar AI tidak mengarang.
header('Content-Type: application/json; charset=utf-8');

function out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/sources.php';

// ---------- Input ----------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    out(['error' => 'Method not allowed'], 405);
}

$action      = in_array($_POST['action'] ?? '', ['outline', 'title'], true) ? $_POST['action'] : 'paragraph';
$title_before = trim($_POST['title_before'] ?? '');   // teks tetap di depan {judul}
$title_after  = trim($_POST['title_after'] ?? '');    // teks tetap di belakang {judul}
$keyword     = trim($_POST['keyword'] ?? '');
$title       = trim($_POST['title'] ?? '');
$lokasi      = trim($_POST['lokasi'] ?? '');
$kota        = trim($_POST['kota'] ?? '');
$instruction = trim($_POST['instruction'] ?? '');
$before      = trim($_POST['before'] ?? '');
$after       = trim($_POST['after'] ?? '');
$model       = $_POST['model'] ?? '';
$mode        = $_POST['mode'] ?? 'informatif';
$skeleton    = trim($_POST['skeleton'] ?? '');
$slots       = json_decode($_POST['slots'] ?? '[]', true) ?: [];          // instruksi pengguna per slot ('' = bebas)
$outline     = json_decode($_POST['outline'] ?? 'null', true);            // {tema, pokok[]} dari aksi outline
$index       = max(1, (int) ($_POST['index'] ?? 1));
$total       = max(1, (int) ($_POST['total'] ?? 1));

if ($keyword === '') out(['error' => 'Kata kunci kosong'], 400);
if ($model === '')   out(['error' => 'Model Ollama belum dipilih / Ollama tidak terdeteksi'], 400);

// ---------- Bahan dari sumber web (Pengaturan > Sumber Web) ----------
$sources = [];
$refs = '';
$seen = [];
$budget = 12000;   // batas total karakter referensi agar muat di konteks model

$addRefs = function (string $label, array $pages) use (&$refs, &$sources, &$seen, &$budget) {
    foreach ($pages as $pg) {
        if ($budget < 500 || isset($seen[$pg['url']])) continue;
        $seen[$pg['url']] = true;
        $text = clip($pg['text'], $budget);
        $budget -= mb_strlen($text);
        $refs .= "[$label: {$pg['title']}]\n$text\n\n";
        $sources[] = ['title' => $pg['title'], 'url' => $pg['url']];
    }
};

foreach (active_sources('keyword') as $src) {
    $addRefs('REFERENSI TOPIK', fetch_source($src, $keyword, true));
}

if ($lokasi !== '') {
    $kotaShort = trim(preg_replace('/^(Kota|Kabupaten|Kab\.)\s+/iu', '', $kota));
    foreach (active_sources('lokasi') as $src) {
        $pages = fetch_source($src, $lokasi . ($kotaShort !== '' ? ' ' . $kotaShort : ''), false, src_words($lokasi));
        if (!$pages && $kotaShort !== '') $pages = fetch_source($src, $kotaShort, true);
        $addRefs('REFERENSI WILAYAH', $pages);
    }
}
if ($refs === '') {
    $refs = "(Tidak ada referensi ditemukan. Tulis hanya hal umum yang pasti benar dan hindari angka, tahun, atau klaim spesifik.)\n";
}

$gaya = $mode === 'promosi'
    ? 'Promosi lokal yang persuasif tetapi tetap jujur dan natural, seperti landing page penjualan.'
    : 'Informatif dan edukatif, netral, seperti artikel blog yang rapi.';

$header = "GAYA TULISAN: $gaya\n";
if ($title !== '')  $header .= "JUDUL ARTIKEL: $title\n";
$header .= "KATA KUNCI: $keyword\n";
if ($lokasi !== '') {
    $header .= "WILAYAH TARGET: $lokasi" . ($kota !== '' && $kota !== $lokasi ? " (area/kelurahan di $kota; sebut sebagai wilayah, bukan sebagai kota)" : '') . "\n";
}

// ---------- Panggilan Ollama ----------
function ollama_chat(string $model, string $system, string $user, array $opts = [], ?string $format = null): string {
    $ai = ollama_cfg();
    set_time_limit($ai['timeout'] + 60);
    $body = [
        'keep_alive' => $ai['keep_alive'],
        'model'    => $model,
        'stream'   => false,
        'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
        'options'  => $opts + ['temperature' => 0.7, 'top_p' => 0.9, 'repeat_penalty' => 1.15, 'num_ctx' => $ai['num_ctx']],
    ];
    if ($format) $body['format'] = $format;

    $ch = curl_init(OLLAMA_URL . '/api/chat');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => $ai['timeout'],
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);
    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($response === false) out(['error' => 'Gagal terhubung ke Ollama: ' . $err], 502);
    $res  = json_decode($response, true);
    $text = trim($res['message']['content'] ?? '');
    if ($text === '') {
        out(['error' => ($res['error'] ?? 'Ollama tidak mengembalikan teks') . " (model: $model)"], 502);
    }
    return $text;
}

// =====================================================================
// AKSI 1: KERANGKA ARTIKEL
// =====================================================================
if ($action === 'outline') {
    $n = max(1, count($slots));
    $slotList = '';
    foreach (array_values($slots) as $i => $ins) {
        $slotList .= ($i + 1) . '. ' . (trim($ins) !== '' ? 'WAJIB mengikuti instruksi pengguna: ' . trim($ins) : '(bebas, tentukan sendiri)') . "\n";
    }

    $system = <<<TXT
Kamu adalah editor yang merancang kerangka artikel blog berbahasa Indonesia agar setiap paragrafnya saling terhubung dan tidak berulang.
Balas HANYA dengan JSON valid, tanpa penjelasan lain.
TXT;

    $user = $header . "\n" . $refs;
    if ($skeleton !== '') $user .= "[KERANGKA ARTIKEL SAAT INI - teks tetap dari pengguna; (BAGIAN n) adalah bagian yang akan kamu rancang]\n$skeleton\n\n";
    $user .= <<<TXT
TUGAS: Rancang kerangka untuk $n paragraf AI.

ATURAN
- "tema": satu kalimat yang menyatakan MAKSUD KESELURUHAN artikel ini (untuk apa dan untuk siapa artikel ditulis), selaras dengan judul, kata kunci, dan wilayah.
- "pokok": tepat $n kalimat pendek, satu per paragraf AI, berurutan.
- Setiap pokok bahasan HARUS BERBEDA, tidak tumpang tindih, dan berkembang logis dari pengenalan, ke pembahasan inti (misalnya ciri khas, manfaat, kaitannya dengan wilayah, tips), lalu penutup bila slot terakhir memungkinkan.
- Semua pokok harus bisa dijawab dari REFERENSI dan tetap mendukung tema utama. Jangan merancang pokok yang menuntut klaim usaha karangan (asal bahan, harga, sertifikasi, lama berdiri, pengiriman).
- Slot yang punya instruksi pengguna: pakai instruksi itu sebagai pokok bahasannya (boleh dirapikan, jangan diubah maknanya).

SLOT
$slotList
FORMAT BALASAN
{"tema": "...", "pokok": ["...", "..."]}
TXT;

    $raw = ollama_chat($model, $system, $user, ['temperature' => 0.4], 'json');
    $j = json_decode($raw, true);
    $pokok = array_values(array_filter(array_map(fn($x) => is_string($x) ? trim($x) : '', $j['pokok'] ?? [])));

    // Lengkapi / pangkas agar jumlahnya pas, dan hormati instruksi pengguna
    $fallback = ['pengenalan umum tentang topik', 'ciri khas dan keunggulan', 'kaitannya dengan wilayah', 'tips dan pertimbangan', 'penutup dan ajakan'];
    $final = [];
    foreach (array_values($slots) as $i => $ins) {
        $final[] = trim($ins) !== '' ? trim($ins) : ($pokok[$i] ?? $fallback[$i % count($fallback)]);
    }
    out([
        'tema'    => trim($j['tema'] ?? '') ?: "Artikel tentang $keyword" . ($lokasi !== '' ? " di $lokasi" : ''),
        'pokok'   => $final,
        'sources' => $sources,
    ]);
}

// =====================================================================
// AKSI 1b: JUDUL ARTIKEL (ditulis setelah kerangka, agar selaras dengan isi)
// =====================================================================
if ($action === 'title') {
    $system = <<<TXT
Kamu adalah editor judul artikel blog berbahasa Indonesia. Judulmu menarik, jelas, dan selaras dengan isi artikel.
Balas HANYA dengan teks judul (satu baris), tanpa tanda kutip, tanpa markdown, tanpa emoji, tanpa label seperti "Judul:", tanpa penjelasan.
TXT;

    $user = $header . "\n" . $refs;
    if (is_array($outline) && !empty($outline['pokok'])) {
        $user .= 'MAKSUD KESELURUHAN ARTIKEL: ' . ($outline['tema'] ?? '') . "\nKERANGKA ARTIKEL:\n";
        foreach ($outline['pokok'] as $i => $pk) $user .= ($i + 1) . '. ' . $pk . "\n";
        $user .= "\n";
    }
    $user .= "TUGAS: Tulis bagian judul yang mengisi posisi {judul}.\n";
    if ($title_before !== '' || $title_after !== '') {
        $user .= 'Bentuk judul lengkap: "' . $title_before . ' {judul} ' . $title_after . "\"\n"
               . "Tulis HANYA isi {judul}-nya; teks di depan dan belakangnya sudah tetap, jangan diulang, dan hasil gabungannya harus mengalir sebagai satu judul yang wajar.\n";
    }
    if ($instruction !== '') $user .= "Arahan pengguna untuk judul: $instruction\n";
    $user .= <<<TXT
ATURAN
- Bahasa Indonesia, panjang bagian yang kamu tulis sekitar 35 sampai 65 karakter (lebih pendek jika teks tetapnya sudah panjang).
- Sebut kata kunci atau topik secara natural; sebut wilayah bila relevan.
- DILARANG memakai kata klaim seperti "terbaik", "termurah", "terlengkap", "terpercaya", "nomor 1", serta angka, harga, promo, atau garansi karangan.
- Jangan diakhiri tanda titik.
TXT;

    $clean = function (string $t) use ($title_before, $title_after): string {
        if (preg_match('/[\x{3040}-\x{30FF}\x{3400}-\x{9FFF}\x{AC00}-\x{D7AF}\x{FF00}-\x{FFEF}]/u', $t, $m, PREG_OFFSET_CAPTURE)) $t = substr($t, 0, $m[0][1]);
        $t = trim(preg_split('/\R/u', trim($t))[0] ?? '');
        $t = preg_replace('/^\s*(judul( artikel)?|title)\s*[:\-–]\s*/iu', '', $t);
        $t = preg_replace('/[*_#`>]+/u', '', $t);
        $t = trim($t, " \t\"“”'‘’.");
        if (mb_strlen($t) > 90) {                       // terlalu panjang: potong di batas kata
            $t = mb_substr($t, 0, 90);
            $t = rtrim(mb_substr($t, 0, (int) mb_strrpos($t, ' ') ?: 90), " ,;:-");
        }
        // Model sering mengulang teks tetap di sekitar {judul}: buang dari hasil
        foreach (array_filter([$title_before]) as $b) $t = preg_replace('/^' . preg_quote($b, '/') . '\s*/iu', '', $t);
        foreach (array_filter([$title_after]) as $af) $t = preg_replace('/\s*' . preg_quote($af, '/') . '$/iu', '', $t);
        $t = trim($t, " \t:-–,");        return mb_strlen($t) >= 8 ? $t : '';
    };

    // Klaim superlatif karangan ("terbaik", "termurah", "nomor 1") ditolak; model diminta mencoba lagi
    $banned = '/\b(terbaik|terbagus|termurah|terlengkap|terpercaya|termahal|nomor\s*(1|satu)|no\.?\s*1|paling\s+(murah|bagus|baik|lengkap))\b/iu';
    $final = '';
    $last = '';
    for ($try = 0; $try < 3 && $final === ''; $try++) {
        $raw = ollama_chat($model, $system, $user, ['temperature' => 0.7 + 0.1 * $try]);
        $cand = $clean($raw);
        if ($cand !== '') $last = $cand;
        if ($cand !== '' && !preg_match($banned, $cand)) $final = $cand;
    }
    if ($final === '' && $last !== '') {            // semua percobaan mengandung klaim: buang kata klaimnya
        $final = trim(preg_replace('/\s{2,}/u', ' ', preg_replace($banned, '', $last)));
    }
    if ($final === '') out(['error' => 'Judul AI tidak layak setelah 3 percobaan: ' . mb_substr($raw, 0, 100)], 502);
    out(['title' => $final]);
}
// =====================================================================
// AKSI 2: SATU PARAGRAF
// =====================================================================
$system = <<<TXT
Kamu adalah penulis artikel blog berbahasa Indonesia yang berpengalaman. Kamu menulis satu artikel utuh paragraf demi paragraf, sehingga setiap paragraf harus terasa sebagai bagian dari satu cerita yang mengalir, bukan tulisan terpisah.

ATURAN MENULIS
1. Tulis dalam bahasa Indonesia yang baik, mudah dipahami orang awam. Pakai kalimat pendek sampai sedang, hindari istilah rumit tanpa penjelasan.
2. Gunakan HANYA fakta yang ada di bagian REFERENSI. Jangan mengarang angka, tahun, nama, atau klaim. Jika referensi tidak memuat suatu hal, jangan disebut.
   Ini berlaku juga untuk gaya promosi: JANGAN mengarang klaim usaha (asal bahan/petani, harga, sertifikasi, lama berdiri, jumlah pelanggan, pengiriman, promo). DILARANG KERAS menulis nomor telepon/WhatsApp, email, alamat, situs web, atau nama toko/merek; kontak ditulis sendiri oleh pengguna di tempat lain. Tonjolkan manfaat umum produk, ciri khasnya dari referensi, dan keterkaitannya dengan wilayah, lalu tutup dengan ajakan yang umum (misalnya menghubungi atau mengunjungi).
3. Tulis ulang dengan kata-katamu sendiri; jangan menyalin kalimat referensi mentah-mentah dan jangan menulis "menurut referensi" atau "berdasarkan Wikipedia/situs".
   Jika beberapa referensi saling melengkapi, gabungkan informasinya; jika bertentangan, pakai yang paling umum dan jangan sebut angka yang diragukan.
4. KETERHUBUNGAN: patuhi KERANGKA ARTIKEL. Bahas HANYA pokok bahasan paragraf ini; jangan membahas pokok bahasan paragraf lain.
5. ANTI PENGULANGAN: jangan mengulang fakta, kalimat, atau frasa yang sudah ada di teks sebelumnya. Jangan mengulang nama lengkap topik/wilayah di awal kalimat secara berturut-turut; gunakan kata ganti atau sebutan lain ("makanan ini", "kawasan tersebut").
6. Paragraf PEMBUKA: perkenalkan topik dan beri gambaran apa yang akan dibahas, tanpa membocorkan detail paragraf lain.
   Paragraf LANJUTAN: mulai dengan kalimat penghubung yang natural dari paragraf sebelumnya (variasikan, jangan selalu "Selanjutnya"/"Selain itu"), lalu kembangkan pokok bahasannya.
   Paragraf PENUTUP: rangkum maksud artikel dengan kata-kata BARU (jangan menyalin kalimat atau klaim dari paragraf sebelumnya) dan akhiri dengan kesimpulan atau ajakan yang selaras dengan gaya tulisan.
7. Keluarkan SATU paragraf utuh (3-5 kalimat) tanpa judul, tanpa nomor, tanpa bullet, tanpa tanda markdown, tanpa label atau awalan apa pun di depan paragraf, tanpa kalimat pembuka seperti "Berikut paragrafnya", dan tanpa komentar tentang tulisanmu sendiri. Langsung tulis isi paragrafnya.

CONTOH GAYA YANG BAIK
Pokok bahasan: pengertian tempe
Paragraf: Tempe adalah makanan tradisional Indonesia yang dibuat dari kedelai yang difermentasi dengan jamur. Proses ini membuat kedelai menyatu menjadi kue padat berwarna putih dengan tekstur yang khas. Tempe kaya protein sehingga banyak dipilih sebagai pengganti daging. Rasanya gurih dan mudah diolah menjadi berbagai masakan.
TXT;

$user = $header . "\n" . $refs;

// Kerangka & posisi paragraf ini
$topic = $instruction;
$role = '';
if (is_array($outline) && !empty($outline['pokok'])) {
    $user .= 'MAKSUD KESELURUHAN ARTIKEL: ' . ($outline['tema'] ?? '') . "\n\nKERANGKA ARTIKEL:\n";
    foreach ($outline['pokok'] as $i => $p) {
        $user .= ($i + 1) . '. ' . $p . ($i + 1 === $index ? '   <-- PARAGRAF YANG KAMU TULIS SEKARANG' : '') . "\n";
    }
    $user .= "\n";
    if ($topic === '') $topic = $outline['pokok'][$index - 1] ?? '';
}
$role = $total <= 1 ? 'paragraf tunggal' : ($index === 1 ? 'PEMBUKA' : ($index === $total ? 'PENUTUP' : 'LANJUTAN'));

if ($before !== '') $user .= "[TEKS ARTIKEL SEBELUMNYA - sudah ada, JANGAN diulang; sambung dari sini]\n$before\n\n";
if ($after !== '')  $user .= "[TEKS SETELAHNYA - sudah ada, hanya untuk menjaga alur; \"(bagian lain)\" = bagian yang ditulis terpisah, jangan ditulis ulang di sini]\n$after\n\n";

$user .= "TUGAS: Tulis bagian ke-$index dari $total (peran: $role). Balas hanya dengan isi paragrafnya.\n";
$user .= $topic !== ''
    ? "Pokok bahasan paragraf ini: $topic\n"
    : "Buat satu paragraf yang menyambung alami dan membahas sudut pandang yang belum dibahas.\n";


// ---------- Bersihkan keluaran ----------
// Mengembalikan '' bila hasilnya tidak layak pakai (terlalu pendek setelah dibersihkan)
function clean_output(string $text, string $before, string $role): string {
    // Model kadang berpindah ke aksara Mandarin/Jepang/Korea di tengah tulisan: potong di situ, mundur ke akhir kalimat
    if (preg_match('/[\x{3040}-\x{30FF}\x{3400}-\x{9FFF}\x{AC00}-\x{D7AF}\x{FF00}-\x{FFEF}]/u', $text, $m, PREG_OFFSET_CAPTURE)) {
        $text = substr($text, 0, $m[0][1]);
        if (preg_match('/^(.*[.!?])/su', $text, $mm)) $text = $mm[1];
    }

    // Label yang kadang ditulis model: "Paragraf baru:", "Paragraf AI:", "[paragraf AI]", "(BAGIAN 2)", "Jawaban:", dll.
    $text = preg_replace('/[\[(]\s*(paragraf|bagian)[^\])\n]{0,25}[\])]\s*:?\s*/iu', '', $text);
    $text = preg_replace('/^\s*(paragraf|bagian|jawaban|hasil|teks)(\s+(baru|ai|ke-?\s*\d+|\d+|lanjutan|pembuka|penutup))*\s*[:\-–]\s*/iu', '', $text);
    $text = preg_replace('/[*_#`>]+/u', '', $text);                                    // sisa markdown
    $text = preg_replace('/^\s*(?:[-•]|\d+[.)])\s+/mu', '', $text);                    // bullet / nomor
    $text = trim(preg_replace('/\s*\R\s*/u', ' ', $text), " \t\"“”");

    // Buang kalimat meta ("Paragraf tersebut dapat disambungkan...") dan kalimat yang sudah ada di teks sebelumnya
    $norm = fn($s) => preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower(trim($s)));
    $prevSet = [];
    foreach (preg_split('/(?<=[.!?])\s+/u', $before, -1, PREG_SPLIT_NO_EMPTY) as $s) $prevSet[$norm($s)] = true;
    $kept = [];
    foreach (preg_split('/(?<=[.!?])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) as $s) {
        if (preg_match('/\bparagraf\b/iu', $s)) continue;
        // Data kontak karangan (nomor telepon, email, URL, alamat jalan) tidak boleh lolos
        if (preg_match('/@|https?:\/\/|\bwww\.|\b\.(com|co\.id|id|net)\b|\b0\d{2,4}[-\s.]?\d{3,4}[-\s.]?\d{3,5}\b|\+62|\b(nomor|no\.?)\s*(telepon|telp|hp|wa|whatsapp)\b|\bjalan\s+\p{Lu}\w+\s+no\.?\s*\d+/iu', $s)) continue;
        if (isset($prevSet[$norm($s)])) continue;
        $kept[] = $s;
    }

    // Batas 5 kalimat. Paragraf penutup mempertahankan kalimat terakhir (ajakan/kesimpulan).
    if (count($kept) > 5) {
        $kept = $role === 'PENUTUP' ? array_merge(array_slice($kept, 0, 4), [end($kept)]) : array_slice($kept, 0, 5);
    }
    $text = implode(' ', $kept);
    return mb_strlen($text) >= 60 ? $text : '';
}

// Coba maksimal 3 kali bila hasilnya rusak / terlalu pendek
$final = '';
for ($try = 0; $try < 3 && $final === ''; $try++) {
    $raw = ollama_chat($model, $system, $user, ['temperature' => 0.6 + 0.1 * $try]);
    $final = clean_output($raw, $before, $role);
}
if ($final === '') out(['error' => 'Keluaran AI tidak layak setelah 3 percobaan: ' . mb_substr($raw, 0, 120)], 502);

out(['paragraph' => $final, 'sources' => $sources]);
