<?php
require_once __DIR__ . '/config.php';
$db = db();

$tab = $_GET['tab'] ?? 'templates';
if (!in_array($tab, ['templates', 'shortkeys', 'wilayah'], true)) $tab = 'templates';

$flash = $_GET['msg'] ?? '';
$error = '';

// ---------- Aksi (POST) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int) ($_POST['id'] ?? 0);
    $ok     = null;

    if ($tab === 'templates') {
        $name = trim($_POST['name'] ?? '');
        $content = trim($_POST['content'] ?? '');
        if ($action === 'save') {
            if ($name === '' || $content === '') {
                $error = 'Nama dan isi template wajib diisi.';
            } elseif ($id) {
                $st = $db->prepare('UPDATE master_templates SET name=?, content=? WHERE id=?');
                $st->bind_param('ssi', $name, $content, $id);
                $ok = $st->execute() ? 'Template diperbarui.' : null;
            } else {
                $st = $db->prepare('INSERT INTO master_templates (name, content) VALUES (?, ?)');
                $st->bind_param('ss', $name, $content);
                $ok = $st->execute() ? 'Template ditambahkan.' : null;
            }
            if (!$ok && !$error) $error = 'Gagal menyimpan (nama template mungkin sudah dipakai).';
        } elseif ($action === 'delete') {
            $db->query('DELETE FROM master_templates WHERE id=' . $id);
            $ok = 'Template dihapus.';
        }
    }

    if ($tab === 'shortkeys') {
        $name = trim($_POST['name'] ?? '', " \t\n\r{}");
        $value = trim($_POST['value'] ?? '');
        if ($action === 'save') {
            if (!preg_match('/^[a-z][a-z0-9_]*$/i', $name)) {
                $error = 'Nama shortkey hanya huruf, angka, dan underscore (diawali huruf).';
            } elseif (in_array(strtolower($name), RESERVED_KEYS, true)) {
                $error = '"' . $name . '" sudah dipakai sistem. Pilih nama lain.';
            } elseif ($value === '') {
                $error = 'Isi shortkey wajib diisi.';
            } elseif ($id) {
                $st = $db->prepare('UPDATE master_shortkeys SET name=?, value=? WHERE id=?');
                $st->bind_param('ssi', $name, $value, $id);
                $ok = $st->execute() ? 'Shortkey diperbarui.' : null;
            } else {
                $st = $db->prepare('INSERT INTO master_shortkeys (name, value) VALUES (?, ?)');
                $st->bind_param('ss', $name, $value);
                $ok = $st->execute() ? 'Shortkey ditambahkan.' : null;
            }
            if (!$ok && !$error) $error = 'Gagal menyimpan (nama shortkey mungkin sudah dipakai).';
        } elseif ($action === 'delete') {
            $db->query('DELETE FROM master_shortkeys WHERE id=' . $id);
            $ok = 'Shortkey dihapus.';
        }
    }

    if ($tab === 'wilayah') {
        if ($action === 'save') {
            $f = array_map(fn($k) => trim($_POST[$k] ?? ''), ['provinsi', 'kota_kab', 'kecamatan', 'kelurahan']);
            if (in_array('', $f, true)) {
                $error = 'Provinsi, kota/kab, kecamatan, dan kelurahan wajib diisi.';
            } elseif ($id) {
                $st = $db->prepare('UPDATE wilayah SET provinsi=?, kota_kab=?, kecamatan=?, kelurahan=? WHERE id=?');
                $st->bind_param('ssssi', $f[0], $f[1], $f[2], $f[3], $id);
                $ok = $st->execute() ? 'Wilayah diperbarui.' : null;
            } else {
                $st = $db->prepare('INSERT INTO wilayah (provinsi, kota_kab, kecamatan, kelurahan) VALUES (?, ?, ?, ?)');
                $st->bind_param('ssss', $f[0], $f[1], $f[2], $f[3]);
                $ok = $st->execute() ? 'Wilayah ditambahkan.' : null;
            }
            if (!$ok && !$error) $error = 'Gagal menyimpan wilayah.';
        } elseif ($action === 'delete') {
            $db->query('DELETE FROM wilayah WHERE id=' . $id);
            $ok = 'Wilayah dihapus.';
        } elseif ($action === 'import') {
            // Format per baris: Provinsi|Kota/Kab|Kecamatan|Kelurahan (pemisah | atau tab)
            $st = $db->prepare('INSERT INTO wilayah (provinsi, kota_kab, kecamatan, kelurahan) VALUES (?, ?, ?, ?)');
            $n = 0; $skip = 0;
            foreach (preg_split('/\R/', $_POST['bulk'] ?? '') as $line) {
                if (trim($line) === '') continue;
                $p = array_map('trim', preg_split('/[|\t]/', $line));
                if (count($p) < 4 || in_array('', array_slice($p, 0, 4), true)) { $skip++; continue; }
                $st->bind_param('ssss', $p[0], $p[1], $p[2], $p[3]);
                $st->execute() ? $n++ : $skip++;
            }
            $ok = "Import selesai: $n baris masuk" . ($skip ? ", $skip baris dilewati." : '.');
        }
    }

    if ($ok) {
        header('Location: settings.php?tab=' . $tab . '&msg=' . urlencode($ok));
        exit;
    }
}

// ---------- Data tampilan ----------
$editId = (int) ($_GET['edit'] ?? 0);
$edit = null;
$tables = ['templates' => 'master_templates', 'shortkeys' => 'master_shortkeys', 'wilayah' => 'wilayah'];
if ($editId) {
    $edit = rows('SELECT * FROM ' . $tables[$tab] . ' WHERE id=' . $editId)[0] ?? null;
}
// Jika gagal simpan, pertahankan isian
if ($error && $_SERVER['REQUEST_METHOD'] === 'POST') $edit = array_merge($edit ?? [], $_POST);

$tpls = rows('SELECT * FROM master_templates ORDER BY name');
$keys = rows('SELECT * FROM master_shortkeys ORDER BY name');
$q = trim($_GET['q'] ?? '');
$like = '%' . $db->real_escape_string($q) . '%';
$wil = $tab === 'wilayah'
    ? rows("SELECT * FROM wilayah" . ($q !== '' ? " WHERE provinsi LIKE '$like' OR kota_kab LIKE '$like' OR kecamatan LIKE '$like' OR kelurahan LIKE '$like'" : '') . " ORDER BY provinsi, kota_kab, kecamatan, kelurahan LIMIT 300")
    : [];
$wilTotal = (int) rows('SELECT COUNT(*) c FROM wilayah')[0]['c'];

$input = 'w-full p-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 text-sm outline-none';
$btn = 'bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium py-2 px-4 rounded-lg';
$tabCls = fn($t) => 'px-4 py-2 rounded-lg text-sm font-medium ' . ($tab === $t ? 'bg-blue-600 text-white' : 'bg-white text-slate-700 border border-slate-200 hover:bg-slate-50');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengaturan Master</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 min-h-screen py-8 text-slate-800">
<div class="max-w-4xl mx-auto px-4">

    <div class="flex justify-between items-start mb-6">
        <div>
            <h1 class="text-3xl font-bold text-slate-900">Pengaturan Master</h1>
            <p class="text-slate-600 text-sm mt-1">Template, shortkey, dan wilayah yang bisa dipakai global di generator.</p>
        </div>
        <a href="index.php" class="bg-slate-200 hover:bg-slate-300 text-slate-700 px-4 py-2 rounded-lg text-sm font-medium">← Generator</a>
    </div>

    <div class="flex gap-2 mb-4">
        <a href="?tab=templates" class="<?= $tabCls('templates') ?>">Template (<?= count($tpls) ?>)</a>
        <a href="?tab=shortkeys" class="<?= $tabCls('shortkeys') ?>">Shortkey (<?= count($keys) ?>)</a>
        <a href="?tab=wilayah" class="<?= $tabCls('wilayah') ?>">Wilayah (<?= $wilTotal ?>)</a>
    </div>

    <?php if ($flash): ?><div class="mb-4 p-3 rounded-lg bg-green-50 border border-green-200 text-green-800 text-sm"><?= h($flash) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="mb-4 p-3 rounded-lg bg-red-50 border border-red-200 text-red-800 text-sm"><?= h($error) ?></div><?php endif; ?>

<?php if ($tab === 'templates'): ?>
    <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200 mb-6">
        <h2 class="font-semibold mb-3"><?= $edit ? 'Edit Template' : 'Template Baru' ?></h2>
        <form method="POST" class="space-y-3">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
            <input name="name" value="<?= h($edit['name'] ?? '') ?>" class="<?= $input ?>" placeholder="Nama template, mis. Jasa Lokal" required>
            <textarea name="content" rows="12" class="<?= $input ?> font-mono" placeholder="Kami {jual|menyediakan} {keyword} di {3 lokasi}.&#10;&#10;{paragraf}&#10;&#10;{cta}&#10;&#10;{paragraf}" required><?= h($edit['content'] ?? '') ?></textarea>
            <p class="text-xs text-slate-500">Bisa memakai semua short key, shortkey master Anda, dan <code class="bg-slate-100 px-1 rounded">{template:nama}</code> untuk menyisipkan template master lain.</p>
            <div class="flex gap-2">
                <button class="<?= $btn ?>">Simpan</button>
                <?php if ($edit): ?><a href="?tab=templates" class="px-4 py-2 text-sm text-slate-600">Batal</a><?php endif; ?>
            </div>
        </form>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 divide-y divide-slate-100">
        <?php foreach ($tpls as $t): ?>
            <div class="p-4 flex justify-between gap-4">
                <div class="min-w-0">
                    <div class="font-medium"><?= h($t['name']) ?> <code class="text-xs bg-slate-100 px-1 rounded">{template:<?= h($t['name']) ?>}</code></div>
                    <div class="text-xs text-slate-500 mt-1 truncate"><?= h(mb_substr(preg_replace('/\s+/', ' ', $t['content']), 0, 140)) ?></div>
                </div>
                <div class="flex gap-3 text-sm shrink-0">
                    <a href="?tab=templates&edit=<?= $t['id'] ?>" class="text-blue-600">Edit</a>
                    <form method="POST" onsubmit="return confirm('Hapus template ini?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $t['id'] ?>"><button class="text-red-600">Hapus</button></form>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (!$tpls): ?><div class="p-6 text-sm text-slate-500">Belum ada template.</div><?php endif; ?>
    </div>

<?php elseif ($tab === 'shortkeys'): ?>
    <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200 mb-6">
        <h2 class="font-semibold mb-3"><?= $edit ? 'Edit Shortkey' : 'Shortkey Baru' ?></h2>
        <form method="POST" class="space-y-3">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
            <input name="name" value="<?= h($edit['name'] ?? '') ?>" class="<?= $input ?>" placeholder="Nama, mis. cta  (dipanggil dengan {cta})" required>
            <textarea name="value" rows="4" class="<?= $input ?> font-mono" placeholder="Hubungi kami {sekarang|hari ini} untuk {pesan|order} {keyword}." required><?= h($edit['value'] ?? '') ?></textarea>
            <p class="text-xs text-slate-500">Isi boleh memuat <code class="bg-slate-100 px-1 rounded">{a|b|c}</code> (acak), <code class="bg-slate-100 px-1 rounded">{keyword}</code>, <code class="bg-slate-100 px-1 rounded">{lokasi}</code>, <code class="bg-slate-100 px-1 rounded">{3 lokasi}</code>, <code class="bg-slate-100 px-1 rounded">{titles}</code>, bahkan shortkey master lain. Nama tidak boleh: <?= h(implode(', ', RESERVED_KEYS)) ?>.</p>
            <div class="flex gap-2">
                <button class="<?= $btn ?>">Simpan</button>
                <?php if ($edit): ?><a href="?tab=shortkeys" class="px-4 py-2 text-sm text-slate-600">Batal</a><?php endif; ?>
            </div>
        </form>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 divide-y divide-slate-100">
        <?php foreach ($keys as $k): ?>
            <div class="p-4 flex justify-between gap-4">
                <div class="min-w-0">
                    <div class="font-medium"><code class="bg-slate-100 px-1.5 rounded">{<?= h($k['name']) ?>}</code></div>
                    <div class="text-xs text-slate-500 mt-1 break-words"><?= h(mb_substr($k['value'], 0, 200)) ?></div>
                </div>
                <div class="flex gap-3 text-sm shrink-0">
                    <a href="?tab=shortkeys&edit=<?= $k['id'] ?>" class="text-blue-600">Edit</a>
                    <form method="POST" onsubmit="return confirm('Hapus shortkey ini?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $k['id'] ?>"><button class="text-red-600">Hapus</button></form>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (!$keys): ?><div class="p-6 text-sm text-slate-500">Belum ada shortkey.</div><?php endif; ?>
    </div>

<?php else: ?>
    <div class="grid md:grid-cols-2 gap-6 mb-6">
        <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200">
            <h2 class="font-semibold mb-3"><?= $edit ? 'Edit Wilayah' : 'Tambah Wilayah' ?></h2>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
                <input name="provinsi" value="<?= h($edit['provinsi'] ?? '') ?>" class="<?= $input ?>" placeholder="Provinsi" required>
                <input name="kota_kab" value="<?= h($edit['kota_kab'] ?? '') ?>" class="<?= $input ?>" placeholder="Kota / Kabupaten" required>
                <input name="kecamatan" value="<?= h($edit['kecamatan'] ?? '') ?>" class="<?= $input ?>" placeholder="Kecamatan" required>
                <input name="kelurahan" value="<?= h($edit['kelurahan'] ?? '') ?>" class="<?= $input ?>" placeholder="Kelurahan" required>
                <div class="flex gap-2">
                    <button class="<?= $btn ?>">Simpan</button>
                    <?php if ($edit): ?><a href="?tab=wilayah" class="px-4 py-2 text-sm text-slate-600">Batal</a><?php endif; ?>
                </div>
            </form>
        </div>
        <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200">
            <h2 class="font-semibold mb-3">Import Massal</h2>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="action" value="import">
                <textarea name="bulk" rows="5" class="<?= $input ?> font-mono" placeholder="Jawa Timur|Kota Surabaya|Gubeng|Airlangga&#10;Jawa Timur|Kota Surabaya|Gubeng|Kertajaya"></textarea>
                <p class="text-xs text-slate-500">Satu baris satu kelurahan: Provinsi|Kota/Kab|Kecamatan|Kelurahan (pemisah <code class="bg-slate-100 px-1 rounded">|</code> atau tab, bisa tempel dari Excel).</p>
                <button class="<?= $btn ?>">Import</button>
            </form>
        </div>
    </div>

    <form method="GET" class="mb-3 flex gap-2">
        <input type="hidden" name="tab" value="wilayah">
        <input name="q" value="<?= h($q) ?>" class="<?= $input ?>" placeholder="Cari provinsi / kota / kecamatan / kelurahan...">
        <button class="<?= $btn ?>">Cari</button>
    </form>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left text-slate-600"><tr><th class="p-3">Provinsi</th><th class="p-3">Kota/Kab</th><th class="p-3">Kecamatan</th><th class="p-3">Kelurahan</th><th class="p-3"></th></tr></thead>
            <tbody class="divide-y divide-slate-100">
            <?php foreach ($wil as $w): ?>
                <tr>
                    <td class="p-3"><?= h($w['provinsi']) ?></td><td class="p-3"><?= h($w['kota_kab']) ?></td>
                    <td class="p-3"><?= h($w['kecamatan']) ?></td><td class="p-3"><?= h($w['kelurahan']) ?></td>
                    <td class="p-3 whitespace-nowrap">
                        <a href="?tab=wilayah&edit=<?= $w['id'] ?>" class="text-blue-600 mr-3">Edit</a>
                        <form method="POST" class="inline" onsubmit="return confirm('Hapus wilayah ini?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $w['id'] ?>"><button class="text-red-600">Hapus</button></form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$wil): ?><tr><td colspan="5" class="p-6 text-slate-500">Tidak ada data.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if (count($wil) === 300): ?><p class="text-xs text-slate-500 mt-2">Menampilkan 300 baris pertama. Gunakan pencarian untuk menyaring.</p><?php endif; ?>
<?php endif; ?>

</div>
</body>
</html>
