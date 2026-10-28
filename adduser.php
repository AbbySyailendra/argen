<?php
// Buat atau ganti kata sandi pengguna dari terminal server:
//   php adduser.php nama_pengguna
// Kata sandi diminta lewat terminal (tidak tampil di Linux), atau isi environment ARGEN_NEW_PASSWORD.
// Tidak bisa dijalankan lewat web.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/config.php';

$user = $argv[1] ?? '';
if ($user === '') { fwrite(STDERR, "Pemakaian: php adduser.php <username>\n"); exit(1); }

$pass = getenv('ARGEN_NEW_PASSWORD') ?: '';
if ($pass === '') {
    echo 'Kata sandi (min. 10 karakter): ';
    $posix = DIRECTORY_SEPARATOR === '/';
    if ($posix) system('stty -echo');
    $pass = rtrim((string) fgets(STDIN), "\r\n");
    if ($posix) { system('stty echo'); echo "\n"; }
}

$st = db()->prepare('SELECT COUNT(*) c FROM users WHERE username = ?');
$st->bind_param('s', $user);
$st->execute();
if ((int) $st->get_result()->fetch_assoc()['c'] > 0) {
    if ($err = auth_validate_new($user, $pass)) { fwrite(STDERR, "$err\n"); exit(1); }
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $st = db()->prepare('UPDATE users SET pass_hash = ? WHERE username = ?');
    $st->bind_param('ss', $hash, $user);
    $st->execute();
    echo "Kata sandi '$user' diganti.\n";
} else {
    if ($err = auth_create_user($user, $pass)) { fwrite(STDERR, "$err\n"); exit(1); }
    echo "Pengguna '$user' dibuat.\n";
}
