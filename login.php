<?php
define('ARGEN_PUBLIC', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ui.php';

// Tujuan setelah masuk: hanya file lokal (cegah open redirect)
$next = $_GET['next'] ?? $_POST['next'] ?? '';
$next = preg_match('#^[A-Za-z0-9_\-]+\.php(\?[^\s]*)?$#', $next) && !str_contains($next, '//') ? $next : 'index.php';

if (auth_user()) { header('Location: ' . $next); exit; }

$setup = auth_user_count() === 0;
$error = '';
if ($setup) {
    $canSetup = auth_setup_allowed($_POST['setup_key'] ?? '');
    $needKey = !auth_is_local();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = trim($_POST['username'] ?? '');
    $p = (string) ($_POST['password'] ?? '');
    if ($setup) {
        if (!$canSetup) {
            $error = $needKey ? 'Kunci setup salah atau belum diatur (SETUP_KEY di config.local.php).' : 'Pembuatan akun tidak diizinkan.';
        } elseif ($p !== (string) ($_POST['password2'] ?? '')) {
            $error = 'Konfirmasi kata sandi tidak sama.';
        } else {
            $error = auth_create_user($u, $p) ?? '';
            if ($error === '') $error = auth_login($u, $p) ?? '';
            if ($error === '') { header('Location: ' . $next); exit; }
        }
    } else {
        $error = auth_login($u, $p) ?? '';
        if ($error === '') { header('Location: ' . $next); exit; }
    }
}

$input = 'w-full p-2.5 border border-white/15 rounded-lg focus:ring-2 focus:ring-[#0078D4] text-sm outline-none';
ui_head($setup ? 'Buat Akun - Argen' : 'Masuk - Argen');
?>
<main class="min-h-screen flex items-center justify-center px-4 py-10">
    <div class="w-full max-w-sm">
        <div class="flex items-center gap-2 font-semibold tracking-tight mb-6 justify-center">
            <span class="inline-flex h-7 w-7 items-center justify-center rounded-md bg-[#0078D4] text-sm font-bold">A</span>
            <span class="text-lg">Argen</span>
        </div>
        <form method="POST" class="bg-[#1F1F1F] p-6 rounded-xl shadow-lg shadow-black/30 border border-white/10 space-y-4" autocomplete="on">
            <div>
                <h1 class="text-xl font-semibold"><?= $setup ? 'Buat akun admin' : 'Masuk' ?></h1>
                <p class="text-xs text-[#ADADAD] mt-1"><?= $setup ? 'Belum ada akun. Akun pertama ini akan memegang akses penuh.' : 'Masuk untuk memakai generator artikel.' ?></p>
            </div>
            <?php if ($error): ?>
                <div role="alert" class="rounded-lg border border-rose-400/30 bg-rose-500/10 text-rose-300 text-sm px-3 py-2"><?= h($error) ?></div>
            <?php endif; ?>
            <input type="hidden" name="next" value="<?= h($next) ?>">
            <label class="block text-xs text-gray-300">Username
                <input name="username" value="<?= h($_POST['username'] ?? '') ?>" class="<?= $input ?> mt-1" autocomplete="username" autofocus required>
            </label>
            <label class="block text-xs text-gray-300">Kata sandi<?= $setup ? ' (minimal 10 karakter)' : '' ?>
                <input type="password" name="password" class="<?= $input ?> mt-1" autocomplete="<?= $setup ? 'new-password' : 'current-password' ?>" required>
            </label>
            <?php if ($setup): ?>
                <label class="block text-xs text-gray-300">Ulangi kata sandi
                    <input type="password" name="password2" class="<?= $input ?> mt-1" autocomplete="new-password" required>
                </label>
                <?php if ($needKey): ?>
                    <label class="block text-xs text-gray-300">Kunci setup (dari config.local.php)
                        <input type="password" name="setup_key" class="<?= $input ?> mt-1" autocomplete="off" required>
                    </label>
                <?php endif; ?>
            <?php endif; ?>
            <button class="w-full bg-[#0078D4] hover:bg-[#106EBE] text-white text-sm font-medium py-2.5 rounded-lg"><?= $setup ? 'Buat akun & masuk' : 'Masuk' ?></button>
        </form>
        <div class="mt-4 flex justify-center"><div id="theme-switch" role="group" aria-label="Tema tampilan" class="flex items-center rounded-lg border border-white/10 bg-[#1F1F1F] p-0.5 text-xs">
            <button type="button" data-t="dark" aria-pressed="false">Gelap</button>
            <button type="button" data-t="light" aria-pressed="false">Terang</button>
            <button type="button" data-t="auto" aria-pressed="false">Auto</button>
        </div></div>
    </div>
</main>
<?php ui_theme_script(); ?>
</body></html>
