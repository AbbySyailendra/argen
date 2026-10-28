<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ui.php';
$db = db();
$me = auth_user();
$flash = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'password') {
        $st = $db->prepare('SELECT pass_hash FROM users WHERE id = ?');
        $st->bind_param('i', $me['id']);
        $st->execute();
        $hash = $st->get_result()->fetch_assoc()['pass_hash'] ?? '';
        $new = (string) ($_POST['new'] ?? '');
        if (!password_verify((string) ($_POST['current'] ?? ''), $hash)) {
            $error = 'Kata sandi saat ini salah.';
        } elseif ($new !== (string) ($_POST['new2'] ?? '')) {
            $error = 'Konfirmasi kata sandi baru tidak sama.';
        } else {
            $error = auth_validate_new($me['username'], $new) ?? '';
            if ($error === '') {
                $nh = password_hash($new, PASSWORD_DEFAULT);
                $st = $db->prepare('UPDATE users SET pass_hash = ? WHERE id = ?');
                $st->bind_param('si', $nh, $me['id']);
                $st->execute();
                $flash = 'Kata sandi diganti.';
            }
        }
    } elseif ($action === 'add') {
        $error = auth_create_user(trim($_POST['username'] ?? ''), (string) ($_POST['password'] ?? '')) ?? '';
        if ($error === '') $flash = 'Pengguna ditambahkan.';
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id === (int) $me['id']) {
            $error = 'Akun yang sedang dipakai tidak bisa dihapus.';
        } else {
            $db->query('DELETE FROM users WHERE id = ' . $id);
            $flash = 'Pengguna dihapus.';
        }
    }
}

$users = rows('SELECT id, username, created_at FROM users ORDER BY id');
$input = 'w-full p-2.5 border border-white/15 rounded-lg focus:ring-2 focus:ring-[#0078D4] text-sm outline-none';
$btn = 'bg-[#0078D4] hover:bg-[#106EBE] text-white text-sm font-medium py-2 px-4 rounded-lg';
$card = 'bg-[#1F1F1F] p-6 rounded-xl shadow-lg shadow-black/30 border border-white/10 mb-6';
ui_head('Akun - Argen'); ui_nav('akun');
?>
<div class="max-w-3xl mx-auto px-4 sm:px-6 py-8">
    <h1 class="text-3xl font-semibold tracking-tight mb-1">Akun</h1>
    <p class="text-[#ADADAD] text-sm mb-6">Masuk sebagai <strong class="text-white"><?= h($me['username']) ?></strong>.</p>
    <?php if ($flash): ?><div role="status" class="mb-4 rounded-lg border border-emerald-400/30 bg-emerald-500/10 text-emerald-300 text-sm px-3 py-2"><?= h($flash) ?></div><?php endif; ?>
    <?php if ($error): ?><div role="alert" class="mb-4 rounded-lg border border-rose-400/30 bg-rose-500/10 text-rose-300 text-sm px-3 py-2"><?= h($error) ?></div><?php endif; ?>

    <div class="<?= $card ?>">
        <h2 class="font-semibold mb-3">Ganti kata sandi</h2>
        <form method="POST" class="space-y-3">
            <input type="hidden" name="action" value="password">
            <label class="block text-xs text-gray-300">Kata sandi saat ini
                <input type="password" name="current" class="<?= $input ?> mt-1" autocomplete="current-password" required></label>
            <div class="grid sm:grid-cols-2 gap-3">
                <label class="block text-xs text-gray-300">Kata sandi baru (min. 10 karakter)
                    <input type="password" name="new" class="<?= $input ?> mt-1" autocomplete="new-password" required></label>
                <label class="block text-xs text-gray-300">Ulangi kata sandi baru
                    <input type="password" name="new2" class="<?= $input ?> mt-1" autocomplete="new-password" required></label>
            </div>
            <button class="<?= $btn ?>">Simpan</button>
        </form>
    </div>

    <div class="<?= $card ?>">
        <h2 class="font-semibold mb-1">Pengguna</h2>
        <p class="text-xs text-[#ADADAD] mb-4">Semua pengguna punya akses penuh ke generator, artikel, dan pengaturan (termasuk Application Password WordPress). Tambahkan hanya orang yang dipercaya.</p>
        <div class="divide-y divide-white/5 border border-white/10 rounded-lg mb-4">
            <?php foreach ($users as $u): ?>
                <div class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                    <span><?= h($u['username']) ?> <span class="text-xs text-[#ADADAD]">· sejak <?= h(substr($u['created_at'], 0, 10)) ?></span></span>
                    <?php if ((int) $u['id'] !== (int) $me['id']): ?>
                        <form method="POST" onsubmit="return confirm('Hapus pengguna ini?')">
                            <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                            <button class="text-xs text-rose-400 hover:text-rose-300">Hapus</button>
                        </form>
                    <?php else: ?><span class="text-xs text-[#ADADAD]">Anda</span><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <form method="POST" class="grid sm:grid-cols-3 gap-3 items-end">
            <input type="hidden" name="action" value="add">
            <label class="block text-xs text-gray-300">Username baru
                <input name="username" class="<?= $input ?> mt-1" autocomplete="off" required></label>
            <label class="block text-xs text-gray-300">Kata sandi (min. 10 karakter)
                <input type="password" name="password" class="<?= $input ?> mt-1" autocomplete="new-password" required></label>
            <button class="<?= $btn ?>">Tambah pengguna</button>
        </form>
    </div>
</div>
</body></html>
