<?php
// Publikasi artikel ke WordPress lewat REST API + Application Password (cocok untuk shared hosting).
require_once __DIR__ . '/config.php';

const WP_STATUSES = ['publish' => 'Terbit (publish)', 'draft' => 'Draft', 'pending' => 'Menunggu review', 'private' => 'Privat'];

function wp_cfg(): array {
    return [
        'url'            => rtrim(trim(setting_get('wp_url')), '/'),
        'user'           => setting_get('wp_user'),
        'pass'           => setting_get('wp_pass'),
        'status'         => setting_get('wp_status', 'publish'),
        'category'       => setting_get('wp_category'),
        'timezone'       => setting_get('wp_timezone', 'Asia/Jakarta'),
        'tags'           => setting_get('wp_tags', '1') === '1',
        'append_sources' => setting_get('wp_append_sources', '0') === '1',
    ];
}

// Panggil REST API. Mengembalikan [kode HTTP, array JSON|null, pesan error curl]
function wp_http(array $cfg, string $method, string $route, ?array $body = null): array {
    $once = function (string $url) use ($cfg, $method, $body) {
        $ch = curl_init($url);
        $headers = ['Accept: application/json', 'Authorization: Basic ' . base64_encode($cfg['user'] . ':' . $cfg['pass'])];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 40,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_USERAGENT      => 'ArgenPublisher/1.0',
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return [$code, $raw === false ? null : json_decode($raw, true), $err, $raw];
    };

    [$route, $query] = array_pad(explode('?', $route, 2), 2, '');
    $pretty = $cfg['url'] . '/wp-json/' . ltrim($route, '/') . ($query !== '' ? '?' . $query : '');
    [$code, $json, $err, $raw] = $once($pretty);

    // Permalink "polos" / wp-json tidak tersedia: coba bentuk ?rest_route=
    if (($code === 404 || ($json === null && $code < 500 && $err === '')) && !is_array($json)) {
        $alt = $cfg['url'] . '/?rest_route=/' . ltrim($route, '/') . ($query !== '' ? '&' . $query : '');
        [$code, $json, $err, $raw] = $once($alt);
    }
    return [$code, $json, $err, $raw];
}

// Terjemahkan kegagalan menjadi pesan yang bisa ditindaklanjuti
function wp_explain(int $code, $json, string $err, $raw): string {
    if ($code === 0) return 'Tidak bisa terhubung' . ($err !== '' ? ': ' . $err : '') . '. Periksa alamat situs dan koneksi internet.';
    if ($code === 401 || ($json['code'] ?? '') === 'rest_not_logged_in' || ($json['code'] ?? '') === 'incorrect_password') {
        return 'Login ditolak (401). Periksa username dan Application Password. Jika benar, hosting mungkin membuang header Authorization: tambahkan di .htaccess WordPress baris  RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]  (di bawah RewriteEngine On).';
    }
    if ($code === 403) return 'Ditolak (403)' . (isset($json['message']) ? ': ' . $json['message'] : '') . '. Plugin keamanan/WAF hosting mungkin memblokir REST API, atau akun tidak punya hak menulis post.';
    if ($code === 404) return 'REST API tidak ditemukan (404). Pastikan alamat situs benar dan REST API tidak dinonaktifkan oleh plugin.';
    if (isset($json['message'])) return "WordPress ($code): " . $json['message'];
    return "Respons tidak dikenal (HTTP $code): " . mb_substr(strip_tags((string) $raw), 0, 160);
}

// Cari (atau buat) kategori/tag berdasarkan nama; mengembalikan ID atau null
function wp_term_id(array $cfg, string $type, string $name): ?int {
    static $cache = [];
    $name = trim($name);
    if ($name === '') return null;
    $key = $type . '|' . mb_strtolower($name);
    if (array_key_exists($key, $cache)) return $cache[$key];

    [$code, $json] = wp_http($cfg, 'GET', "wp/v2/$type?per_page=100&search=" . urlencode($name));
    if ($code === 200 && is_array($json)) {
        foreach ($json as $t) {
            if (mb_strtolower(html_entity_decode($t['name'] ?? '')) === mb_strtolower($name)) return $cache[$key] = (int) $t['id'];
        }
    }
    [$code, $json] = wp_http($cfg, 'POST', "wp/v2/$type", ['name' => $name]);
    if (in_array($code, [200, 201], true) && isset($json['id'])) return $cache[$key] = (int) $json['id'];
    if (($json['code'] ?? '') === 'term_exists' && isset($json['data']['term_id'])) return $cache[$key] = (int) $json['data']['term_id'];
    return $cache[$key] = null;
}

// Tentukan status & tanggal yang dikirim.
//   Tanggal masa depan + status "publish" -> "future" (terjadwal). Tanggal lampau + "publish" -> terbit dengan tanggal mundur (backdate).
function wp_plan(?string $publishAt, string $target, string $tz): array {
    $target = array_key_exists($target, WP_STATUSES) ? $target : 'publish';
    if (!$publishAt) return ['status' => $target, 'date_gmt' => null, 'mode' => $target === 'publish' ? 'langsung terbit' : $target];

    $local = new DateTime($publishAt, new DateTimeZone($tz));
    $gmt = (clone $local)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s');
    if ($target !== 'publish') return ['status' => $target, 'date_gmt' => $gmt, 'mode' => $target];

    $future = $local->getTimestamp() > time();
    return ['status' => $future ? 'future' : 'publish', 'date_gmt' => $gmt, 'mode' => $future ? 'terjadwal' : 'tanggal mundur (backdate)'];
}

// Teks polos (paragraf dipisah baris kosong) -> HTML
function wp_content_html(string $text, array $sources, bool $appendSources): string {
    $html = '';
    foreach (preg_split('/\R\s*\R/', trim($text)) as $p) {
        $p = trim($p);
        if ($p !== '') $html .= '<p>' . nl2br(htmlspecialchars($p, ENT_QUOTES, 'UTF-8')) . "</p>\n";
    }
    if ($appendSources && $sources) {
        $html .= '<p><small>Sumber referensi: ';
        $html .= implode(', ', array_map(fn($s) => '<a href="' . htmlspecialchars($s['url'], ENT_QUOTES) . '" rel="noopener" target="_blank">' . htmlspecialchars($s['title']) . '</a>', $sources));
        $html .= '</small></p>';
    }
    return $html;
}

// Kirim satu artikel. Mengembalikan ['ok'=>bool, 'message'=>..., 'url'=>..., 'state'=>...]
function wp_publish_article(int $id): array {
    $cfg = wp_cfg();
    if ($cfg['url'] === '' || $cfg['user'] === '' || $cfg['pass'] === '') {
        return ['ok' => false, 'message' => 'Pengaturan WordPress belum lengkap (Pengaturan > WordPress).'];
    }
    $a = rows('SELECT * FROM articles WHERE id = ' . $id)[0] ?? null;
    if (!$a) return ['ok' => false, 'message' => 'Artikel tidak ditemukan.'];

    $target = $a['wp_target_status'] !== '' ? $a['wp_target_status'] : $cfg['status'];
    $plan = wp_plan($a['publish_at'] ?: null, $target, $cfg['timezone']);
    $title = $a['title'] !== '' ? $a['title'] : $a['keyword'];

    $body = [
        'title'   => $title,
        'content' => wp_content_html($a['content'], json_decode($a['sources'] ?? '[]', true) ?: [], $cfg['append_sources']),
        'status'  => $plan['status'],
    ];
    if ($plan['date_gmt']) $body['date_gmt'] = $plan['date_gmt'];

    $cat = wp_term_id($cfg, 'categories', $cfg['category']);
    if ($cat) $body['categories'] = [$cat];
    if ($cfg['tags']) {
        $tags = array_filter([wp_term_id($cfg, 'tags', $a['keyword']), $a['lokasi'] !== '' ? wp_term_id($cfg, 'tags', $a['lokasi']) : null]);
        if ($tags) $body['tags'] = array_values($tags);
    }

    $route = 'wp/v2/posts' . ($a['wp_post_id'] ? '/' . (int) $a['wp_post_id'] : '');
    [$code, $json, $err, $raw] = wp_http($cfg, 'POST', $route, $body);

    $db = db();
    if (in_array($code, [200, 201], true) && isset($json['id'])) {
        $postId = (int) $json['id'];
        $link = (string) ($json['link'] ?? '');
        $state = (string) ($json['status'] ?? $plan['status']);
        $st = $db->prepare('UPDATE articles SET wp_post_id=?, wp_url=?, wp_state=?, wp_error=NULL, wp_sent_at=NOW() WHERE id=?');
        $st->bind_param('issi', $postId, $link, $state, $id);
        $st->execute();
        return ['ok' => true, 'message' => $plan['mode'], 'url' => $link, 'state' => $state, 'post_id' => $postId];
    }

    $msg = wp_explain($code, $json, $err, $raw);
    $st = $db->prepare('UPDATE articles SET wp_error=? WHERE id=?');
    $st->bind_param('si', $msg, $id);
    $st->execute();
    return ['ok' => false, 'message' => $msg];
}
