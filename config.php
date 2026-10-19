<?php
// Koneksi database + tabel master (template, shortkey). Tabel wilayah sudah ada.
mysqli_report(MYSQLI_REPORT_OFF);

// ---- Pengaturan koneksi (ubah di sini jika perlu) ----
const DB_HOST = '127.0.0.1';
const DB_USER = 'root';
const DB_PASS = '';
const DB_NAME = 'argen';
const DB_PORT = 3309;   // Laragon bawaan biasanya 3306

function db(): mysqli {
    static $m = null;
    if ($m) return $m;

    $m = new mysqli(DB_HOST, DB_USER, DB_PASS, '', DB_PORT);
    if ($m->connect_error) {
        die("<div style='font-family:sans-serif;padding:20px;color:red;'>Koneksi Database Gagal (" . DB_HOST . ':' . DB_PORT . '): ' . htmlspecialchars($m->connect_error) . "<br>Pastikan MySQL di Laragon sudah Start dan port di config.php sesuai.</div>");
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

// Nama yang dipakai sistem dan tidak boleh dijadikan shortkey
const RESERVED_KEYS = ['titles', 'title', 'keyword', 'lokasi', 'paragraf', 'template'];

function h($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

// Hasil query -> array asosiatif
function rows(string $sql): array {
    $res = db()->query($sql);
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}
