<?php
// Login, sesi, dan pengamanan dasar. Dipanggil dari config.php sehingga melindungi semua halaman dan endpoint.
// Halaman yang boleh dibuka tanpa login (login.php) mendefinisikan ARGEN_PUBLIC sebelum memuat config.php.

const AUTH_IDLE_SECONDS = 8 * 3600;     // keluar otomatis bila 8 jam tidak aktif
const AUTH_MAX_FAILS_IP = 10;           // gagal login per IP dalam 15 menit
const AUTH_MAX_FAILS_USER = 5;          // gagal login per akun dalam 15 menit

function auth_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') === '443';
}

function auth_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? ''; }

function auth_is_local(): bool { return in_array(auth_ip(), ['127.0.0.1', '::1'], true); }

// Header keamanan, sesi, dan pemeriksaan asal permintaan POST. Aman dipanggil untuk halaman publik maupun tertutup.
function auth_boot(): void {
    if (PHP_SAPI === 'cli') return;

    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    if (auth_https()) header('Strict-Transport-Security: max-age=15552000');

    session_name('argen_sid');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => auth_https(), 'httponly' => true, 'samesite' => 'Strict']);
    ini_set('session.use_strict_mode', '1');
    session_start();

    // Perlindungan CSRF: permintaan yang mengubah data harus berasal dari situs ini sendiri
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $src = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
        if ($src !== '') {
            $from = strtolower((string) parse_url($src, PHP_URL_HOST));
            $mine = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
            if ($from !== $mine) auth_deny('Permintaan ditolak (asal tidak cocok).', 403);
        }
    }
}

function auth_wants_json(): bool {
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
        || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== '';
}

function auth_deny(string $msg, int $code): void {
    http_response_code($code);
    if (auth_wants_json()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $msg, 'ok' => false, 'message' => $msg, 'login' => $code === 401], JSON_UNESCAPED_UNICODE);
    } else {
        echo htmlspecialchars($msg);
    }
    exit;
}

// Pengguna yang sedang masuk (array id, username) atau null
function auth_user(): ?array {
    static $user = false;
    if ($user !== false) return $user;
    $user = null;
    $id = (int) ($_SESSION['uid'] ?? 0);
    if ($id && time() - (int) ($_SESSION['seen'] ?? 0) <= AUTH_IDLE_SECONDS) {
        $row = rows('SELECT id, username FROM users WHERE id = ' . $id)[0] ?? null;   // akun yang dihapus langsung kehilangan akses
        if ($row) {
            $_SESSION['seen'] = time();
            $user = $row;
        }
    }
    if (!$user) unset($_SESSION['uid']);
    return $user;
}

function auth_guard(): void {
    if (PHP_SAPI === 'cli') return;
    if (auth_user()) {
        session_write_close();   // lepas kunci sesi: proses generate bisa berjalan menit-menit tanpa menahan tab lain
        return;
    }
    if (auth_wants_json()) auth_deny('Sesi berakhir. Muat ulang halaman lalu masuk kembali.', 401);
    $path = basename((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
    $query = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY);
    $next = str_ends_with($path, '.php') ? $path . ($query !== '' ? '?' . $query : '') : '';
    header('Location: login.php' . ($next !== '' ? '?next=' . urlencode($next) : ''));
    exit;
}

function auth_user_count(): int { return (int) rows('SELECT COUNT(*) c FROM users')[0]['c']; }

// Akun pertama hanya boleh dibuat dari komputer server itu sendiri, atau dengan kunci setup (config.local.php)
function auth_setup_allowed(string $key = ''): bool {
    if (auth_user_count() > 0) return false;
    if (auth_is_local()) return true;
    return SETUP_KEY !== '' && hash_equals(SETUP_KEY, $key);
}

function auth_validate_new(string $username, string $pass): ?string {
    if (!preg_match('/^[a-z0-9_.-]{3,50}$/i', $username)) return 'Username 3-50 karakter: huruf, angka, titik, strip, underscore.';
    if (mb_strlen($pass) < 10) return 'Kata sandi minimal 10 karakter.';
    if (strcasecmp($pass, $username) === 0) return 'Kata sandi tidak boleh sama dengan username.';
    return null;
}

function auth_create_user(string $username, string $pass): ?string {
    if ($err = auth_validate_new($username, $pass)) return $err;
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $st = db()->prepare('INSERT INTO users (username, pass_hash) VALUES (?, ?)');
    $st->bind_param('ss', $username, $hash);
    return $st->execute() ? null : 'Username sudah dipakai.';
}

function auth_fail_count(string $col, string $val): int {
    $st = db()->prepare("SELECT COUNT(*) c FROM login_attempts WHERE $col = ? AND at > (NOW() - INTERVAL 15 MINUTE)");
    $st->bind_param('s', $val);
    $st->execute();
    return (int) $st->get_result()->fetch_assoc()['c'];
}

// Mengembalikan pesan galat, atau null bila berhasil masuk
function auth_login(string $username, string $pass): ?string {
    $db = db();
    $ip = auth_ip();
    $username = mb_substr($username, 0, 50);
    $db->query("DELETE FROM login_attempts WHERE at < (NOW() - INTERVAL 1 DAY)");
    if (auth_fail_count('ip', $ip) >= AUTH_MAX_FAILS_IP || auth_fail_count('username', $username) >= AUTH_MAX_FAILS_USER) {
        return 'Terlalu banyak percobaan gagal. Coba lagi dalam 15 menit.';
    }

    $st = $db->prepare('SELECT id, pass_hash FROM users WHERE username = ?');
    $st->bind_param('s', $username);
    $st->execute();
    $u = $st->get_result()->fetch_assoc();
    // Selalu menjalankan verifikasi agar waktu respons tidak membocorkan keberadaan akun
    $ok = password_verify($pass, $u['pass_hash'] ?? password_hash('tidak-ada', PASSWORD_DEFAULT));
    if (!$u || !$ok) {
        $st = $db->prepare('INSERT INTO login_attempts (ip, username) VALUES (?, ?)');
        $st->bind_param('ss', $ip, $username);
        $st->execute();
        return 'Username atau kata sandi salah.';
    }

    if (password_needs_rehash($u['pass_hash'], PASSWORD_DEFAULT)) {
        $new = password_hash($pass, PASSWORD_DEFAULT);
        $st = $db->prepare('UPDATE users SET pass_hash = ? WHERE id = ?');
        $st->bind_param('si', $new, $u['id']);
        $st->execute();
    }
    $st = $db->prepare('DELETE FROM login_attempts WHERE ip = ? OR username = ?');
    $st->bind_param('ss', $ip, $username);
    $st->execute();

    session_regenerate_id(true);   // cegah session fixation
    $_SESSION['uid'] = (int) $u['id'];
    $_SESSION['seen'] = time();
    return null;
}

function auth_logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $c = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $c['path'], 'secure' => $c['secure'], 'httponly' => true, 'samesite' => 'Strict']);
    }
    session_destroy();
}
