<?php
// Pengambil bahan referensi dari berbagai sumber web (diatur di Pengaturan > Sumber Web).
//   mediawiki : situs berbasis MediaWiki (Wikipedia, Wikibooks, WikiHow, Fandom, dll) lewat API-nya
//   web       : pencarian web (DuckDuckGo) lalu membaca halaman hasil teratas
//   page      : satu alamat halaman dengan {q} sebagai kata kunci, mis. https://contoh.com/cari?q={q}
require_once __DIR__ . '/config.php';

function http_get(string $url, int $timeout = 12): ?string {
    if (!preg_match('#^https?://#i', $url)) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 4,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_ENCODING       => '',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => ['Accept-Language: id,en;q=0.8'],
    ]);
    $r = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($r === false || $code >= 400) return null;
    return substr($r, 0, 1500000);
}

function src_words(string $s): array {
    return array_values(array_filter(preg_split('/[\s,.\-()]+/u', mb_strtolower($s)), fn($w) => mb_strlen($w) >= 3));
}

function src_title_ok(string $title, array $must): bool {
    $t = mb_strtolower($title);
    foreach ($must as $w) if (mb_strpos($t, $w) === false) return false;
    return true;
}

// Potong rapi di akhir kalimat
function clip(string $text, int $max): string {
    if (mb_strlen($text) <= $max) return $text;
    $cut = mb_substr($text, 0, $max);
    $pos = max((int) mb_strrpos($cut, '. '), (int) mb_strrpos($cut, ".\n"));
    return $pos > $max * 0.5 ? mb_substr($cut, 0, $pos + 1) : $cut;
}

// ---------- MediaWiki ----------
function mw_api(string $host, array $params): ?array {
    $r = http_get("https://$host/w/api.php?" . http_build_query($params + ['format' => 'json']));
    return $r ? json_decode($r, true) : null;
}

function mw_page(string $host, string $title): ?array {
    $d = mw_api($host, [
        'action' => 'query', 'prop' => 'extracts', 'explaintext' => 1, 'exsectionformat' => 'plain',
        'redirects' => 1, 'titles' => $title,
    ]);
    foreach ($d['query']['pages'] ?? [] as $p) {
        if (isset($p['missing']) || empty($p['extract'])) continue;
        $text = $p['extract'];
        if (mb_strlen($text) < 400 && preg_match('/dapat merujuk|mengacu pada|dapat berarti|may refer to|disambiguation/iu', $text)) continue;
        $text = preg_split('/^\s*(Referensi|Pranala luar|Catatan kaki|Daftar pustaka|Pustaka|Lihat pula|Bacaan lanjutan|Catatan|References|External links|See also|Notes|Further reading|Footnotes)\s*$/mu', $text)[0];
        return [
            'title' => $p['title'] . " ($host)",
            'url'   => "https://$host/wiki/" . str_replace('%2F', '/', rawurlencode(str_replace(' ', '_', $p['title']))),
            'text'  => trim($text),
        ];
    }
    return null;
}

function mw_lookup(string $host, string $query, bool $isKeyword, ?array $must): ?array {
    $must = $must ?? src_words($query);
    $try = function (string $q, array $mustWords, bool $exact) use ($host): ?array {
        if ($exact) {
            $p = mw_page($host, mb_strtoupper(mb_substr($q, 0, 1)) . mb_substr($q, 1));
            if ($p) return $p;
        }
        $d = mw_api($host, ['action' => 'query', 'list' => 'search', 'srsearch' => $q, 'srlimit' => 5, 'srnamespace' => 0]);
        foreach ($d['query']['search'] ?? [] as $hit) {
            if (!src_title_ok($hit['title'], $mustWords)) continue;   // hindari artikel yang tidak nyambung
            $p = mw_page($host, $hit['title']);
            if ($p) return $p;
        }
        return null;
    };
    $found = $try($query, $must, $isKeyword);
    if (!$found && $isKeyword) {              // longgarkan: coba tiap kata, dari yang paling depan
        foreach (src_words($query) as $w) {
            if (mb_strlen($w) < 4) continue;
            if ($found = $try($w, [$w], true)) break;
        }
    }
    return $found;
}

// ---------- HTML -> teks ----------
function html_to_text(string $html): array {
    $enc = mb_detect_encoding($html, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true) ?: 'UTF-8';
    if ($enc !== 'UTF-8') $html = mb_convert_encoding($html, 'UTF-8', $enc);

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();
    $xp = new DOMXPath($dom);

    foreach ($xp->query('//script|//style|//noscript|//nav|//header|//footer|//aside|//form|//iframe|//svg|//figure|//*[contains(@class,"comment")]|//*[contains(@class,"sidebar")]') as $n) {
        $n->parentNode?->removeChild($n);
    }
    $title = trim($xp->query('//title')->item(0)?->textContent ?? '');
    $h1 = trim($xp->query('//h1')->item(0)?->textContent ?? '');

    $parts = [];
    foreach ($xp->query('//p|//h2|//h3|//li') as $n) {
        $t = trim(preg_replace('/\s+/u', ' ', $n->textContent));
        $isP = $n->nodeName === 'p';
        if (($isP && mb_strlen($t) >= 60) || (!$isP && $n->nodeName !== 'li' && mb_strlen($t) >= 4) || ($n->nodeName === 'li' && mb_strlen($t) >= 80)) {
            $parts[] = $t;
        }
    }
    return ['title' => $h1 !== '' ? $h1 : $title, 'text' => implode("\n", array_unique($parts))];
}

// Relevan jika minimal ~60% kata penting pencarian muncul di teks halaman
function text_relevant(string $text, string $query): bool {
    $ws = array_values(array_filter(src_words($query), fn($w) => mb_strlen($w) >= 4));
    if (!$ws) return true;
    $t = mb_strtolower($text);
    $hit = 0;
    foreach ($ws as $w) if (mb_strpos($t, $w) !== false) $hit++;
    return $hit >= max(1, (int) ceil(count($ws) * 0.6));
}

function page_fetch(string $url): ?array {
    $html = http_get($url);
    if (!$html) return null;
    $r = html_to_text($html);
    if (mb_strlen($r['text']) < 300) return null;      // halaman kosong / diblokir
    $host = parse_url($url, PHP_URL_HOST) ?: $url;
    return ['title' => ($r['title'] ?: $host) . " ($host)", 'url' => $url, 'text' => $r['text']];
}

// ---------- Pencarian web (Bing, cadangan DuckDuckGo) ----------
function parse_dom(string $html): DOMXPath {
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();
    return new DOMXPath($dom);
}

// Bing membungkus tautan hasil: https://www.bing.com/ck/a?...&u=a1<base64url dari URL asli>
function unwrap_bing(string $href): string {
    if (preg_match('#bing\.com/ck/a#i', $href) && preg_match('/[?&]u=a1([^&]+)/', html_entity_decode($href), $m)) {
        $b64 = strtr($m[1], '-_', '+/');
        $u = base64_decode($b64 . str_repeat('=', (4 - strlen($b64) % 4) % 4), true);
        if ($u && preg_match('#^https?://#i', $u)) return $u;
    }
    return $href;
}

function web_search(string $query, int $n): array {
    $urls = [];
    $skip = '#(^https?://(www\.)?(bing|duckduckgo|google|youtube|facebook|instagram|tiktok|twitter|x)\.com)#i';

    if ($html = http_get('https://www.bing.com/search?setlang=id&cc=ID&q=' . urlencode($query))) {
        foreach (parse_dom($html)->query('//li[contains(@class,"b_algo")]//h2/a/@href') as $a) {
            $u = unwrap_bing($a->nodeValue);
            if (preg_match('#^https?://#i', $u) && !preg_match($skip, $u)) $urls[] = $u;
        }
    }
    if (!$urls && ($html = http_get('https://html.duckduckgo.com/html/?q=' . urlencode($query)))) {
        foreach (parse_dom($html)->query('//a[contains(@class,"result__a")]/@href') as $a) {
            $u = $a->nodeValue;
            if (preg_match('/[?&]uddg=([^&]+)/', $u, $m)) $u = urldecode($m[1]);
            if (str_starts_with($u, '//')) $u = 'https:' . $u;
            if (preg_match('#^https?://#i', $u) && !preg_match($skip, $u)) $urls[] = $u;
        }
    }
    return array_slice(array_values(array_unique($urls)), 0, $n * 2);   // sisakan cadangan bila ada yang gagal dibaca
}
// ---------- Satu sumber -> daftar halaman [title, url, text] ----------
// $isKeyword=true untuk topik (kata kunci), false untuk wilayah. $must = kata wajib di judul (MediaWiki)
function fetch_source(array $src, string $query, bool $isKeyword, ?array $must = null): array {
    $dir = sys_get_temp_dir() . '/argen_src';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $file = $dir . '/' . md5(implode('|', [$src['type'], $src['url'], $src['pages'], $query, (int) $isKeyword])) . '.json';
    if (is_file($file) && filemtime($file) > time() - 7 * 86400) {
        return json_decode(file_get_contents($file), true) ?: [];
    }

    $out = [];
    switch ($src['type']) {
        case 'mediawiki':
            $host = preg_replace('#^https?://|/.*$#i', '', trim($src['url']));
            if ($p = mw_lookup($host, $query, $isKeyword, $must)) $out[] = $p;
            break;

        case 'page':
            $url = str_replace(['{q_}', '{q}'], [rawurlencode(str_replace(' ', '_', $query)), rawurlencode($query)], $src['url']);
            if ($p = page_fetch($url)) $out[] = $p;
            break;

        case 'web':
            $site = trim($src['url']);
            $q = $query . ($site !== '' ? ' site:' . preg_replace('#^https?://|/.*$#i', '', $site) : '');
            foreach (web_search($q, max(1, (int) $src['pages'])) as $u) {
                $p = page_fetch($u);
                if ($p && text_relevant($p['text'], $query)) $out[] = $p;   // buang halaman yang tidak membahas topiknya
                if (count($out) >= max(1, (int) $src['pages'])) break;
            }
            break;
    }

    // Pangkas teks sesuai batas per sumber
    foreach ($out as &$o) $o['text'] = clip($o['text'], max(500, (int) $src['max_chars']));
    unset($o);

    if ($out) file_put_contents($file, json_encode($out, JSON_UNESCAPED_UNICODE));   // hasil kosong tidak di-cache
    return $out;
}

// Semua sumber aktif untuk satu cakupan ('keyword' | 'lokasi')
function active_sources(string $scope): array {
    return rows("SELECT * FROM master_sources WHERE enabled = 1 AND applies_to IN ('both', '" . ($scope === 'lokasi' ? 'lokasi' : 'keyword') . "') ORDER BY priority, id");
}

