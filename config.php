<?php
// Koneksi database + tabel master (template, shortkey). Tabel wilayah sudah ada.
mysqli_report(MYSQLI_REPORT_OFF);

// ---- Pengaturan koneksi ----
// Nilai bawaan cocok untuk Laragon. Untuk server/VPS, jangan ubah file ini: salin config.local.example.php
// menjadi config.local.php (tidak ikut git) atau isi environment variable ARGEN_DB_HOST, ARGEN_DB_USER, dst.
if (is_file(__DIR__ . '/config.local.php')) require __DIR__ . '/config.local.php';
function cfg_const(string $name, $default) {
    if (!defined($name)) {
        $env = getenv('ARGEN_' . $name);
        define($name, $env !== false && $env !== '' ? (is_int($default) ? (int) $env : $env) : $default);
    }
}
cfg_const('DB_HOST', '127.0.0.1');
cfg_const('DB_USER', 'root');
cfg_const('DB_PASS', '');
cfg_const('DB_NAME', 'argen');
cfg_const('DB_PORT', 3309);                          // Laragon di komputer ini 3309; server umumnya 3306
cfg_const('OLLAMA_URL', 'http://127.0.0.1:11434');   // alamat Ollama (jangan dibuka ke publik)
cfg_const('SETUP_KEY', '');                          // kunci membuat admin pertama dari web bila bukan akses lokal
cfg_const('DEBUG', '0');

error_reporting(E_ALL);
ini_set('display_errors', DEBUG === '1' ? '1' : '0');   // di server jangan tampilkan galat ke pengunjung
ini_set('log_errors', '1');

function db(): mysqli {
    static $m = null;
    if ($m) return $m;

    $m = new mysqli(DB_HOST, DB_USER, DB_PASS, '', DB_PORT);
    if ($m->connect_error) {
        error_log('Argen: koneksi database gagal (' . DB_HOST . ':' . DB_PORT . '): ' . $m->connect_error);
        http_response_code(500);
        die("<div style='font-family:sans-serif;padding:20px;color:red;'>Koneksi database gagal." . (DEBUG === '1' ? ' (' . DB_HOST . ':' . DB_PORT . '): ' . htmlspecialchars($m->connect_error) : '') . "<br>Periksa MySQL sudah berjalan dan pengaturan di config.local.php (atau config.php) sesuai.</div>");
    }
    $m->set_charset('utf8mb4');

    // Database & tabel dibuat otomatis pada pemakaian pertama
    $m->query('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4');
    $m->select_db(DB_NAME);
    $m->query("CREATE TABLE IF NOT EXISTS wilayah (
        id INT AUTO_INCREMENT PRIMARY KEY,
        provinsi VARCHAR(100) NOT NULL,
        kota_kab VARCHAR(100) NOT NULL,
        kecamatan VARCHAR(100) NOT NULL,
        kelurahan VARCHAR(100) NOT NULL
    ) CHARACTER SET utf8mb4");

    $m->query("CREATE TABLE IF NOT EXISTS master_templates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE,
        content MEDIUMTEXT NOT NULL
    ) CHARACTER SET utf8mb4");
    if ($m->query("SHOW COLUMNS FROM master_templates LIKE 'title'")->num_rows === 0) {
        $m->query("ALTER TABLE master_templates ADD COLUMN title VARCHAR(255) NOT NULL DEFAULT ''");   // template judul
    }
    $m->query("CREATE TABLE IF NOT EXISTS master_shortkeys (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(50) NOT NULL UNIQUE,
        value MEDIUMTEXT NOT NULL
    ) CHARACTER SET utf8mb4");
    $m->query("CREATE TABLE IF NOT EXISTS master_sources (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        type VARCHAR(20) NOT NULL DEFAULT 'mediawiki',
        url VARCHAR(500) NOT NULL DEFAULT '',
        applies_to VARCHAR(10) NOT NULL DEFAULT 'both',
        max_chars INT NOT NULL DEFAULT 3000,
        pages INT NOT NULL DEFAULT 2,
        priority INT NOT NULL DEFAULT 10,
        enabled TINYINT NOT NULL DEFAULT 1
    ) CHARACTER SET utf8mb4");
    // Isi awal: Wikipedia Indonesia (sumber lain bisa ditambah di Pengaturan > Sumber Web)
    if ((int) $m->query('SELECT COUNT(*) c FROM master_sources')->fetch_assoc()['c'] === 0) {
        $m->query("INSERT INTO master_sources (name, type, url, applies_to, max_chars, pages, priority)
                   VALUES ('Wikipedia Indonesia', 'mediawiki', 'id.wikipedia.org', 'both', 3000, 1, 1)");
        $m->query("INSERT INTO master_sources (name, type, url, applies_to, max_chars, pages, priority, enabled)
                   VALUES ('Pencarian Web (Bing)', 'web', '', 'keyword', 2500, 2, 5, 0)");   // nonaktif; aktifkan bila perlu
    }
    $m->query("CREATE TABLE IF NOT EXISTS articles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL DEFAULT '',
        keyword VARCHAR(255) NOT NULL,
        lokasi VARCHAR(255) NOT NULL DEFAULT '',
        wilayah_target VARCHAR(255) NOT NULL DEFAULT '',
        model VARCHAR(100) NOT NULL DEFAULT '',
        mode VARCHAR(30) NOT NULL DEFAULT '',
        template MEDIUMTEXT,
        content MEDIUMTEXT NOT NULL,
        sources TEXT,
        status VARCHAR(20) NOT NULL DEFAULT 'ok',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (keyword), INDEX (created_at)
    ) CHARACTER SET utf8mb4");

    // Kolom publikasi WordPress (ditambahkan bila belum ada, agar database lama otomatis ter-upgrade)
    if ($m->query("SHOW COLUMNS FROM articles LIKE 'wp_post_id'")->num_rows === 0) {
        $m->query("ALTER TABLE articles
            ADD COLUMN publish_at DATETIME NULL,
            ADD COLUMN wp_target_status VARCHAR(20) NOT NULL DEFAULT '',
            ADD COLUMN wp_post_id INT NULL,
            ADD COLUMN wp_url VARCHAR(500) NULL,
            ADD COLUMN wp_state VARCHAR(20) NULL,
            ADD COLUMN wp_error TEXT NULL,
            ADD COLUMN wp_sent_at DATETIME NULL");
    }

    $m->query("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        pass_hash VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) CHARACTER SET utf8mb4");
    $m->query("CREATE TABLE IF NOT EXISTS login_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ip VARCHAR(45) NOT NULL,
        username VARCHAR(50) NOT NULL DEFAULT '',
        at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (ip, at)
    ) CHARACTER SET utf8mb4");

    $m->query("CREATE TABLE IF NOT EXISTS master_settings (
        `key` VARCHAR(60) PRIMARY KEY,
        `value` TEXT NOT NULL
    ) CHARACTER SET utf8mb4");
    return $m;
}

function setting_get(string $key, string $default = ''): string {
    $st = db()->prepare('SELECT `value` FROM master_settings WHERE `key` = ?');
    $st->bind_param('s', $key);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    return $r ? $r['value'] : $default;
}

function setting_set(string $key, string $value): void {
    $st = db()->prepare('INSERT INTO master_settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
    $st->bind_param('ss', $key, $value);
    $st->execute();
}

// Pengaturan Ollama yang bisa diubah di Pengaturan > AI (konteks lebih kecil = lebih ringan di server RAM kecil)
function ollama_cfg(): array {
    return [
        'num_ctx'    => max(1024, min(32768, (int) setting_get('ai_num_ctx', '8192'))),
        'keep_alive' => setting_get('ai_keep_alive', '30m'),
        'timeout'    => max(30, min(1800, (int) setting_get('ai_timeout', '300'))),
        'model'      => setting_get('ai_default_model', ''),
    ];
}

// Nama yang dipakai sistem dan tidak boleh dijadikan shortkey
const RESERVED_KEYS = ['titles', 'title', 'keyword', 'lokasi', 'paragraf', 'judul', 'template'];

function h($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

// Hasil query -> array asosiatif
function rows(string $sql): array {
    $res = db()->query($sql);
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

require_once __DIR__ . '/auth.php';
auth_boot();
if (!defined('ARGEN_PUBLIC')) auth_guard();
