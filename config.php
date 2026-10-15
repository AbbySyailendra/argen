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
    return $m;
}

// Nama yang dipakai sistem dan tidak boleh dijadikan shortkey
const RESERVED_KEYS = ['titles', 'title', 'keyword', 'lokasi', 'paragraf', 'template'];

function h($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

// Hasil query -> array asosiatif
function rows(string $sql): array {
    $res = db()->query($sql);
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}
