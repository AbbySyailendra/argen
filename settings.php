<?php
require_once __DIR__ . '/config.php';
$db = db();

$tab = $_GET['tab'] ?? 'templates';
if (!in_array($tab, ['templates', 'shortkeys', 'wilayah', 'sources', 'wordpress'], true)) $tab = 'templates';

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
        $ttitle = trim(preg_replace('/\s+/', ' ', $_POST['title'] ?? ''));   // template judul (satu baris)
        if ($action === 'save') {
            if ($name === '' || $content === '') {
                $error = 'Nama dan isi template wajib diisi.';
            } elseif ($id) {
                $st = $db->prepare('UPDATE master_templates SET name=?, title=?, content=? WHERE id=?');
                $st->bind_param('sssi', $name, $ttitle, $content, $id);
                $ok = $st->execute() ? 'Template diperbarui.' : null;
            } else {
                $st = $db->prepare('INSERT INTO master_templates (name, title, content) VALUES (?, ?, ?)');
                $st->bind_param('sss', $name, $ttitle, $content);
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

    if ($tab === 'wordpress' && $action === 'save_wp') {
        require_once __DIR__ . '/wp.php';
        $url = rtrim(trim($_POST['wp_url'] ?? ''), '/');
        $url = preg_replace('#/wp-json.*$#i', '', $url);
        if ($url !== '' && !preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
        if ($url === '') {
            $error = 'Alamat situs WordPress wajib diisi.';
        } elseif (!in_array($_POST['wp_timezone'] ?? '', DateTimeZone::listIdentifiers(), true)) {
            $error = 'Zona waktu tidak valid.';
        } else {
            setting_set('wp_url', $url);
            setting_set('wp_user', trim($_POST['wp_user'] ?? ''));
            if (trim($_POST['wp_pass'] ?? '') !== '') setting_set('wp_pass', trim($_POST['wp_pass']));   // kosong = pertahankan yang lama
            setting_set('wp_status', array_key_exists($_POST['wp_status'] ?? '', WP_STATUSES) ? $_POST['wp_status'] : 'publish');
            setting_set('wp_category', trim($_POST['wp_category'] ?? ''));
            setting_set('wp_timezone', $_POST['wp_timezone']);
            setting_set('wp_tags', isset($_POST['wp_tags']) ? '1' : '0');
            setting_set('wp_append_sources', isset($_POST['wp_append_sources']) ? '1' : '0');
            $warn = wp_config_warnings(wp_cfg());
            $ok = 'Pengaturan WordPress disimpan.' . ($warn ? ' PERINGATAN: ' . implode(' | ', $warn) : '');
        }
    }

    if ($tab === 'sources') {
        if ($action === 'save') {
            $name = trim($_POST['name'] ?? '');
            $type = in_array($_POST['type'] ?? '', ['mediawiki', 'web', 'page'], true) ? $_POST['type'] : 'mediawiki';
            $url = trim($_POST['url'] ?? '');
            $applies = in_array($_POST['applies_to'] ?? '', ['both', 'keyword', 'lokasi'], true) ? $_POST['applies_to'] : 'both';
            $maxc = max(500, min(10000, (int) ($_POST['max_chars'] ?? 3000)));
            $pages = max(1, min(5, (int) ($_POST['pages'] ?? 2)));
            $prio = (int) ($_POST['priority'] ?? 10);
            $en = isset($_POST['enabled']) ? 1 : 0;
            if ($name === '') {
                $error = 'Nama sumber wajib diisi.';
            } elseif ($type !== 'web' && $url === '') {
                $error = 'Alamat wajib diisi untuk tipe ini.';
            } elseif ($type === 'page' && !str_contains($url, '{q')) {
                $error = 'Tipe "Halaman" harus memuat {q} di alamat, mis. https://contoh.com/cari?q={q}';
            } elseif ($id) {
                $st = $db->prepare('UPDATE master_sources SET name=?, type=?, url=?, applies_to=?, max_chars=?, pages=?, priority=?, enabled=? WHERE id=?');
                $st->bind_param('ssssiiiii', $name, $type, $url, $applies, $maxc, $pages, $prio, $en, $id);
                $ok = $st->execute() ? 'Sumber diperbarui.' : null;
            } else {
                $st = $db->prepare('INSERT INTO master_sources (name, type, url, applies_to, max_chars, pages, priority, enabled) VALUES (?,?,?,?,?,?,?,?)');
                $st->bind_param('ssssiiii', $name, $type, $url, $applies, $maxc, $pages, $prio, $en);
                $ok = $st->execute() ? 'Sumber ditambahkan.' : null;
            }
            if (!$ok && !$error) $error = 'Gagal menyimpan sumber.';
        } elseif ($action === 'delete') {
            $db->query('DELETE FROM master_sources WHERE id=' . $id);
            $ok = 'Sumber dihapus.';
        } elseif ($action === 'toggle') {
            $db->query('UPDATE master_sources SET enabled = 1 - enabled WHERE id=' . $id);
            $ok = 'Status sumber diubah.';
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
$tables = ['templates' => 'master_templates', 'shortkeys' => 'master_shortkeys', 'wilayah' => 'wilayah', 'sources' => 'master_sources'];
if ($editId) {
    $edit = rows('SELECT * FROM ' . $tables[$tab] . ' WHERE id=' . $editId)[0] ?? null;
}
// Jika gagal simpan, pertahankan isian
if ($error && $_SERVER['REQUEST_METHOD'] === 'POST') $edit = array_merge($edit ?? [], $_POST);

$srcs = rows('SELECT * FROM master_sources ORDER BY priority, id');

// Uji sumber: ambil referensi untuk satu kata dan tampilkan hasilnya
$testResult = null;
if ($tab === 'sources' && isset($_GET['test'])) {
    require_once __DIR__ . '/sources.php';
    $ts = rows('SELECT * FROM master_sources WHERE id=' . (int) $_GET['test'])[0] ?? null;
    $tq = trim($_GET['q'] ?? '');
    if ($ts && $tq !== '') {
        set_time_limit(120);
        $testResult = ['src' => $ts, 'q' => $tq, 'pages' => fetch_source($ts, $tq, true)];
    }
}

// Uji koneksi WordPress
$wpTest = null;
if ($tab === 'wordpress') {
    require_once __DIR__ . '/wp.php';
    $wp = wp_cfg();
    if (isset($_GET['test']) && $wp['url'] !== '') {
        set_time_limit(60);
        [$code, $json, $err, $raw] = wp_http($wp, 'GET', 'wp/v2/users/me?context=edit');
        if ($code === 200 && isset($json['id'])) {
            $caps = $json['capabilities'] ?? [];
            $wpTest = ['ok' => true, 'name' => $json['name'] ?? $json['slug'] ?? '?', 'roles' => implode(', ', $json['roles'] ?? []),
                       'publish' => !empty($caps['publish_posts']), 'cats' => !empty($caps['manage_categories'])];
        } else {
            $wpTest = ['ok' => false, 'msg' => wp_explain($code, $json, $err, $raw)];
        }
    }
}

$tpls = rows('SELECT * FROM master_templates ORDER BY name');
$keys = rows('SELECT * FROM master_shortkeys ORDER BY name');
$q = trim($_GET['q'] ?? '');
$like = '%' . $db->real_escape_string($q) . '%';
$wil = $tab === 'wilayah'
    ? rows("SELECT * FROM wilayah" . ($q !== '' ? " WHERE provinsi LIKE '$like' OR kota_kab LIKE '$like' OR kecamatan LIKE '$like' OR kelurahan LIKE '$like'" : '') . " ORDER BY provinsi, kota_kab, kecamatan, kelurahan LIMIT 300")
    : [];
$wilTotal = (int) rows('SELECT COUNT(*) c FROM wilayah')[0]['c'];

$input = 'w-full p-2.5 border border-white/15 rounded-lg focus:ring-2 focus:ring-[#0078D4] text-sm outline-none';
$btn = 'bg-[#0078D4] hover:bg-[#106EBE] text-white text-sm font-medium py-2 px-4 rounded-lg';
$tabCls = fn($t) => 'relative px-4 py-3 text-sm whitespace-nowrap -mb-px border-b-2 ' . ($tab === $t ? 'text-white font-medium border-[#0078D4]' : 'text-[#ADADAD] hover:text-white border-transparent');
?>
<?php require_once __DIR__ . '/ui.php'; ui_head('Pengaturan - Argen'); ui_nav('settings'); ?>
<div class="max-w-6xl mx-auto px-4 sm:px-6 py-8">

    <div class="mb-6">
        <h1 class="text-3xl font-semibold tracking-tight text-white">Pengaturan</h1>
        <p class="text-[#ADADAD] text-sm mt-1">Template, shortkey, wilayah, sumber web, dan koneksi WordPress yang dipakai global di generator.</p>
    </div>

    <div class="flex gap-1 border-b border-white/10 mb-6 overflow-x-auto">
        <a href="?tab=templates" class="<?= $tabCls('templates') ?>">Template (<?= count($tpls) ?>)</a>
        <a href="?tab=shortkeys" class="<?= $tabCls('shortkeys') ?>">Shortkey (<?= count($keys) ?>)</a>
        <a href="?tab=wilayah" class="<?= $tabCls('wilayah') ?>">Wilayah (<?= $wilTotal ?>)</a>
        <a href="?tab=sources" class="<?= $tabCls('sources') ?>">Sumber Web (<?= count($srcs) ?>)</a>
        <a href="?tab=wordpress" class="<?= $tabCls('wordpress') ?>">WordPress</a>
    </div>
    <?php if ($flash): ?><div class="mb-4 p-3 rounded-lg bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 text-sm"><?= h($flash) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="mb-4 p-3 rounded-lg bg-rose-500/10 border border-rose-500/30 text-rose-200 text-sm"><?= h($error) ?></div><?php endif; ?>

<?php if ($tab === 'templates'): ?>
    <div class="bg-[#1F1F1F] p-6 rounded-xl shadow-lg shadow-black/30 border border-white/10 mb-6">
        <h2 class="font-semibold mb-3"><?= $edit ? 'Edit Template' : 'Template Baru' ?></h2>
        <form method="POST" class="space-y-3">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
            <input name="name" value="<?= h($edit['name'] ?? '') ?>" class="<?= $input ?>" placeholder="Nama template, mis. Jasa Lokal" required>
            <label class="block text-xs text-[#ADADAD]">Judul artikel (template)
                <input name="title" value="<?= h($edit['title'] ?? '') ?>" class="<?= $input ?> font-mono mt-1" placeholder="{Jual|Pusat} {keyword} di {lokasi}: {judul}">
            </label>
            <p class="text-xs text-[#ADADAD] -mt-1">Judul memakai short key yang sama. <code class="bg-[#2D2D2D] px-1 rounded">{judul}</code> = bagian judul yang ditulis AI bersamaan dengan artikelnya (<code class="bg-[#2D2D2D] px-1 rounded">{judul: instruksi}</code> untuk mengarahkan). Isi <code class="bg-[#2D2D2D] px-1 rounded">{titles}</code> di artikel akan berisi judul final.</p>
            <label class="block text-xs text-[#ADADAD]">Isi artikel</label>
            <textarea name="content" rows="12" class="<?= $input ?> font-mono" placeholder="Kami {jual|menyediakan} {keyword} di {3 lokasi}.&#10;&#10;{paragraf}&#10;&#10;{cta}&#10;&#10;{paragraf}" required><?= h($edit['content'] ?? '') ?></textarea>
            <p class="text-xs text-[#ADADAD]">Bisa memakai semua short key, shortkey master Anda, dan <code class="bg-[#2D2D2D] px-1 rounded">{template:nama}</code> untuk menyisipkan template master lain.</p>
            <div class="flex gap-2">
                <button class="<?= $btn ?>">Simpan</button>
                <?php if ($edit): ?><a href="?tab=templates" class="px-4 py-2 text-sm text-gray-300">Batal</a><?php endif; ?>
            </div>
        </form>
    </div>
    <div class="bg-[#1F1F1F] rounded-xl shadow-lg shadow-black/30 border border-white/10 divide-y divide-white/5">
        <?php foreach ($tpls as $t): ?>
            <div class="p-4 flex justify-between gap-4">
                <div class="min-w-0">
                    <div class="font-medium"><?= h($t['name']) ?> <code class="text-xs bg-[#2D2D2D] px-1 rounded">{template:<?= h($t['name']) ?>}</code></div>
                    <?php if ($t['title'] !== ''): ?><div class="text-xs text-gray-300 mt-1 truncate">Judul: <?= h($t['title']) ?></div><?php endif; ?>
                    <div class="text-xs text-[#ADADAD] mt-1 truncate"><?= h(mb_substr(preg_replace('/\s+/', ' ', $t['content']), 0, 140)) ?></div>
                </div>
                <div class="flex gap-3 text-sm shrink-0">
                    <a href="?tab=templates&edit=<?= $t['id'] ?>" class="text-[#4CC2FF]">Edit</a>
                    <form method="POST" onsubmit="return confirm('Hapus template ini?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $t['id'] ?>"><button class="text-rose-400">Hapus</button></form>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (!$tpls): ?><div class="p-6 text-sm text-[#ADADAD]">Belum ada template.</div><?php endif; ?>
    </div>

<?php elseif ($tab === 'shortkeys'): ?>
    <div class="bg-[#1F1F1F] p-6 rounded-xl shadow-lg shadow-black/30 border border-white/10 mb-6">
        <h2 class="font-semibold mb-3"><?= $edit ? 'Edit Shortkey' : 'Shortkey Baru' ?></h2>
        <form method="POST" class="space-y-3">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
            <input name="name" value="<?= h($edit['name'] ?? '') ?>" class="<?= $input ?>" placeholder="Nama, mis. cta  (dipanggil dengan {cta})" required>
            <textarea name="value" rows="4" class="<?= $input ?> font-mono" placeholder="Hubungi kami {sekarang|hari ini} untuk {pesan|order} {keyword}." required><?= h($edit['value'] ?? '') ?></textarea>
            <p class="text-xs text-[#ADADAD]">Isi boleh memuat <code class="bg-[#2D2D2D] px-1 rounded">{a|b|c}</code> (acak), <code class="bg-[#2D2D2D] px-1 rounded">{keyword}</code>, <code class="bg-[#2D2D2D] px-1 rounded">{lokasi}</code>, <code class="bg-[#2D2D2D] px-1 rounded">{3 lokasi}</code>, <code class="bg-[#2D2D2D] px-1 rounded">{titles}</code>, bahkan shortkey master lain. Nama tidak boleh: <?= h(implode(', ', RESERVED_KEYS)) ?>.</p>
            <div class="flex gap-2">
                <button class="<?= $btn ?>">Simpan</button>
                <?php if ($edit): ?><a href="?tab=shortkeys" class="px-4 py-2 text-sm text-gray-300">Batal</a><?php endif; ?>
            </div>
        </form>
    </div>
    <div class="bg-[#1F1F1F] rounded-xl shadow-lg shadow-black/30 border border-white/10 divide-y divide-white/5">
        <?php foreach ($keys as $k): ?>
            <div class="p-4 flex justify-between gap-4">
                <div class="min-w-0">
                    <div class="font-medium"><code class="bg-[#2D2D2D] px-1.5 rounded">{<?= h($k['name']) ?>}</code></div>
                    <div class="text-xs text-[#ADADAD] mt-1 break-words"><?= h(mb_substr($k['value'], 0, 200)) ?></div>
                </div>
                <div class="flex gap-3 text-sm shrink-0">
                    <a href="?tab=shortkeys&edit=<?= $k['id'] ?>" class="text-[#4CC2FF]">Edit</a>
                    <form method="POST" onsubmit="return confirm('Hapus shortkey ini?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $k['id'] ?>"><button class="text-rose-400">Hapus</button></form>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (!$keys): ?><div class="p-6 text-sm text-[#ADADAD]">Belum ada shortkey.</div><?php endif; ?>
    </div>

<?php elseif ($tab === 'wordpress'):
    $v = fn($k, $d = '') => h($_POST[$k] ?? setting_get($k, $d));
?>
    <div class="bg-[#1F1F1F] p-6 rounded-xl shadow-lg shadow-black/30 border border-white/10 mb-6">
        <h2 class="font-semibold mb-1">Koneksi WordPress</h2>
        <p class="text-xs text-[#ADADAD] mb-4">Artikel dikirim lewat REST API bawaan WordPress memakai <strong>Application Password</strong>. Cocok untuk shared hosting; tidak perlu plugin atau akses FTP.</p>
        <form method="POST" class="space-y-3">
            <input type="hidden" name="action" value="save_wp">
            <label class="block text-xs text-gray-300">Alamat situs
                <input name="wp_url" value="<?= $v('wp_url') ?>" class="<?= $input ?> mt-1" placeholder="https://situsanda.com" required>
            </label>
            <div class="grid md:grid-cols-2 gap-3">
                <label class="block text-xs text-gray-300">Username WordPress
                    <input name="wp_user" value="<?= $v('wp_user') ?>" class="<?= $input ?> mt-1" autocomplete="off" required>
                </label>
                <label class="block text-xs text-gray-300">Application Password <?= setting_get('wp_pass') !== '' ? '(tersimpan; isi hanya bila ingin mengganti)' : '' ?>
                    <input type="password" name="wp_pass" class="<?= $input ?> mt-1" autocomplete="new-password" placeholder="xxxx xxxx xxxx xxxx xxxx xxxx">
                </label>
            </div>
            <div class="grid md:grid-cols-3 gap-3">
                <label class="block text-xs text-gray-300">Status posting bawaan
                    <select name="wp_status" class="<?= $input ?> bg-[#1F1F1F] mt-1">
                        <?php $cur = $_POST['wp_status'] ?? setting_get('wp_status', 'publish'); foreach (WP_STATUSES as $k => $l): ?>
                            <option value="<?= $k ?>" <?= $cur === $k ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="block text-xs text-gray-300">Kategori (nama; dibuat otomatis bila belum ada)
                    <input name="wp_category" value="<?= $v('wp_category') ?>" class="<?= $input ?> mt-1" placeholder="mis. Artikel">
                </label>
                <label class="block text-xs text-gray-300">Zona waktu tanggal publish
                    <select name="wp_timezone" class="<?= $input ?> bg-[#1F1F1F] mt-1">
                        <?php $tzCur = $_POST['wp_timezone'] ?? setting_get('wp_timezone', 'Asia/Jakarta');
                        foreach (['Asia/Jakarta' => 'WIB (Asia/Jakarta)', 'Asia/Makassar' => 'WITA (Asia/Makassar)', 'Asia/Jayapura' => 'WIT (Asia/Jayapura)', 'UTC' => 'UTC'] as $k => $l): ?>
                            <option value="<?= $k ?>" <?= $tzCur === $k ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="wp_tags" <?= setting_get('wp_tags', '1') === '1' ? 'checked' : '' ?>> Pasang kata kunci dan wilayah sebagai tag</label>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="wp_append_sources" <?= setting_get('wp_append_sources', '0') === '1' ? 'checked' : '' ?>> Tambahkan daftar sumber referensi di akhir artikel</label>
            <div class="flex gap-3 items-center">
                <button class="<?= $btn ?>">Simpan</button>
                <?php if (setting_get('wp_url') !== ''): ?><a href="?tab=wordpress&test=1" class="text-sm text-[#4CC2FF] underline">Uji koneksi</a><?php endif; ?>
            </div>
        </form>
    </div>

    <?php if ($wpTest): ?>
        <?php if ($wpTest['ok']): ?>
            <div class="mb-6 p-4 rounded-lg bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 text-sm">
                <strong>Terhubung</strong> sebagai <?= h($wpTest['name']) ?> (<?= h($wpTest['roles']) ?>).
                <?= $wpTest['publish'] ? 'Akun boleh menerbitkan post.' : '<strong class="text-rose-300">Akun TIDAK boleh menerbitkan post</strong>; hasilnya hanya bisa draft/pending.' ?>
                <?= $wpTest['cats'] ? '' : '<br>Akun tidak bisa membuat kategori baru; pastikan kategori yang dipakai sudah ada.' ?>
            </div>
        <?php else: ?>
            <div class="mb-6 p-4 rounded-lg bg-rose-500/10 border border-rose-500/30 text-rose-200 text-sm"><strong>Gagal terhubung.</strong> <?= h($wpTest['msg']) ?></div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="bg-[#1F1F1F] p-5 rounded-xl border border-white/10 text-sm text-gray-300 space-y-3">
        <div class="font-semibold text-gray-100">Cara membuat Application Password</div>
        <ol class="list-decimal pl-5 space-y-1">
            <li>Login ke WordPress, buka <strong>Pengguna &gt; Profil Anda</strong>.</li>
            <li>Gulir ke <strong>Application Passwords</strong>, isi nama (mis. <em>Argen</em>), klik <strong>Add New Application Password</strong>.</li>
            <li>Salin password yang muncul (spasi boleh ikut) lalu tempel di atas. Password ini bukan password login Anda dan bisa dicabut kapan saja.</li>
        </ol>
        <div class="font-semibold text-gray-100 pt-2">Aturan tanggal publish</div>
        <ul class="list-disc pl-5 space-y-1">
            <li>Status <strong>Terbit</strong> + tanggal <strong>masa depan</strong> → artikel <strong>terjadwal</strong> (status <em>future</em>) dan terbit otomatis pada waktunya.</li>
            <li>Status <strong>Terbit</strong> + tanggal <strong>lampau</strong> → langsung terbit dengan tanggal mundur (<em>backdate</em>).</li>
            <li>Tanpa tanggal → terbit sekarang. Status Draft/Review/Privat menyimpan tanggal itu tanpa menerbitkan.</li>
        </ul>
        <div class="text-xs text-[#ADADAD]">Catatan: artikel terjadwal diterbitkan oleh WP-Cron, yang berjalan saat situs ada pengunjung. Di situs sepi, terbitnya bisa terlambat beberapa menit; solusinya pasang cron job asli di hosting (memanggil <code class="bg-[#2D2D2D] px-1 rounded">wp-cron.php</code>). Aplikasi ini harus bisa mengakses internet; situs WordPress wajib HTTPS agar Application Password aktif.</div>
    </div>

<?php elseif ($tab === 'sources'):
    $typeLabel = ['mediawiki' => 'Situs Wiki (MediaWiki)', 'web' => 'Pencarian Web', 'page' => 'Halaman / URL'];
    $applyLabel = ['both' => 'Kata kunci & wilayah', 'keyword' => 'Kata kunci saja', 'lokasi' => 'Wilayah saja'];
    $e = $edit ?? [];
?>
    <div class="bg-[#1F1F1F] p-6 rounded-xl shadow-lg shadow-black/30 border border-white/10 mb-6">
        <h2 class="font-semibold mb-1"><?= $edit ? 'Edit Sumber' : 'Sumber Baru' ?></h2>
        <p class="text-xs text-[#ADADAD] mb-3">Referensi dari semua sumber aktif digabung dan diberikan ke AI sebelum menulis setiap paragraf. Makin banyak sumber yang relevan, makin kaya isinya.</p>
        <form method="POST" class="space-y-3">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int) ($e['id'] ?? 0) ?>">
            <div class="grid md:grid-cols-2 gap-3">
                <input name="name" value="<?= h($e['name'] ?? '') ?>" class="<?= $input ?>" placeholder="Nama, mis. Wikipedia Inggris" required>
                <select name="type" id="src_type" class="<?= $input ?> bg-[#1F1F1F]">
                    <?php foreach ($typeLabel as $k => $v): ?><option value="<?= $k ?>" <?= ($e['type'] ?? 'mediawiki') === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
                </select>
            </div>
            <input name="url" id="src_url" value="<?= h($e['url'] ?? '') ?>" class="<?= $input ?> font-mono" placeholder="">
            <p id="src_hint" class="text-xs text-[#ADADAD]"></p>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <label class="text-xs text-gray-300">Dipakai untuk
                    <select name="applies_to" class="<?= $input ?> bg-[#1F1F1F] mt-1">
                        <?php foreach ($applyLabel as $k => $v): ?><option value="<?= $k ?>" <?= ($e['applies_to'] ?? 'both') === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label class="text-xs text-gray-300">Maks. karakter / halaman
                    <input type="number" name="max_chars" min="500" max="10000" value="<?= (int) ($e['max_chars'] ?? 3000) ?>" class="<?= $input ?> mt-1">
                </label>
                <label class="text-xs text-gray-300">Jumlah halaman (pencarian web)
                    <input type="number" name="pages" min="1" max="5" value="<?= (int) ($e['pages'] ?? 2) ?>" class="<?= $input ?> mt-1">
                </label>
                <label class="text-xs text-gray-300">Urutan (kecil = dulu)
                    <input type="number" name="priority" value="<?= (int) ($e['priority'] ?? 10) ?>" class="<?= $input ?> mt-1">
                </label>
            </div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="enabled" <?= ($e['enabled'] ?? 1) ? 'checked' : '' ?>> Aktif</label>
            <div class="flex gap-2">
                <button class="<?= $btn ?>">Simpan</button>
                <?php if ($edit): ?><a href="?tab=sources" class="px-4 py-2 text-sm text-gray-300">Batal</a><?php endif; ?>
            </div>
        </form>
    </div>

    <?php if ($testResult): ?>
        <div class="bg-[#1F1F1F] p-5 rounded-xl shadow-lg shadow-black/30 border border-white/10 mb-6">
            <div class="font-semibold mb-2">Hasil uji: <?= h($testResult['src']['name']) ?> &middot; kata "<?= h($testResult['q']) ?>"</div>
            <?php foreach ($testResult['pages'] as $pg): ?>
                <div class="mb-3">
                    <a href="<?= h($pg['url']) ?>" target="_blank" rel="noopener" class="text-[#4CC2FF] underline text-sm"><?= h($pg['title']) ?></a>
                    <span class="text-xs text-[#ADADAD]">&middot; <?= number_format(mb_strlen($pg['text'])) ?> karakter terambil</span>
                    <div class="text-xs text-gray-300 bg-[#181818] rounded p-2 mt-1 whitespace-pre-line"><?= h(mb_substr($pg['text'], 0, 500)) ?>...</div>
                </div>
            <?php endforeach; ?>
            <?php if (!$testResult['pages']): ?><div class="text-sm text-rose-300">Tidak ada konten yang berhasil diambil. Cek alamat/koneksi internet, atau situs memblokir akses otomatis.</div><?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="bg-[#1F1F1F] rounded-xl shadow-lg shadow-black/30 border border-white/10 divide-y divide-white/5">
        <?php foreach ($srcs as $s): ?>
            <div class="p-4 flex justify-between gap-4 <?= $s['enabled'] ? '' : 'opacity-50' ?>">
                <div class="min-w-0">
                    <div class="font-medium"><?= h($s['name']) ?>
                        <span class="text-xs bg-[#2D2D2D] px-1.5 py-0.5 rounded ml-1"><?= h($typeLabel[$s['type']] ?? $s['type']) ?></span>
                        <span class="text-xs bg-[#0078D4]/15 text-[#4CC2FF] px-1.5 py-0.5 rounded"><?= h($applyLabel[$s['applies_to']] ?? '') ?></span>
                    </div>
                    <div class="text-xs text-[#ADADAD] mt-1 break-all font-mono"><?= h($s['url'] !== '' ? $s['url'] : '(seluruh web via Bing)') ?></div>
                    <form method="GET" class="mt-2 flex gap-2">
                        <input type="hidden" name="tab" value="sources"><input type="hidden" name="test" value="<?= $s['id'] ?>">
                        <input name="q" placeholder="Uji dengan kata, mis. kacang goreng" class="p-1.5 border border-white/15 rounded text-xs w-56">
                        <button class="text-xs text-[#4CC2FF] underline">Uji</button>
                    </form>
                </div>
                <div class="flex gap-3 text-sm shrink-0 items-start">
                    <form method="POST"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $s['id'] ?>"><button class="text-gray-300"><?= $s['enabled'] ? 'Matikan' : 'Aktifkan' ?></button></form>
                    <a href="?tab=sources&edit=<?= $s['id'] ?>" class="text-[#4CC2FF]">Edit</a>
                    <form method="POST" onsubmit="return confirm('Hapus sumber ini?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $s['id'] ?>"><button class="text-rose-400">Hapus</button></form>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (!$srcs): ?><div class="p-6 text-sm text-[#ADADAD]">Belum ada sumber. Tanpa sumber, AI menulis hanya dari pengetahuannya sendiri (lebih mudah keliru).</div><?php endif; ?>
    </div>

    <div class="bg-[#1F1F1F] p-5 rounded-xl border border-white/10 mt-6 text-sm text-gray-300 space-y-2">
        <div class="font-semibold text-gray-100">Contoh pengisian</div>
        <div><strong>Situs Wiki</strong>: <code class="bg-[#2D2D2D] px-1 rounded">en.wikipedia.org</code>, <code class="bg-[#2D2D2D] px-1 rounded">id.wikibooks.org</code>, <code class="bg-[#2D2D2D] px-1 rounded">id.wikihow.com</code>, <code class="bg-[#2D2D2D] px-1 rounded">namawiki.fandom.com</code> (hanya domain).</div>
        <div><strong>Pencarian Web</strong>: kosongkan alamat untuk mencari di seluruh web, atau isi domain (mis. <code class="bg-[#2D2D2D] px-1 rounded">detik.com</code>) untuk membatasi ke satu situs. Aplikasi membaca beberapa hasil teratas.</div>
        <div><strong>Halaman / URL</strong>: alamat dengan <code class="bg-[#2D2D2D] px-1 rounded">{q}</code> sebagai kata kunci, mis. <code class="bg-[#2D2D2D] px-1 rounded">https://id.wikipedia.org/wiki/{q_}</code> ({q_} = spasi jadi garis bawah).</div>
        <div class="text-xs text-[#ADADAD]">Hormati hak cipta dan ketentuan tiap situs. Konten ditulis ulang oleh AI dan sumbernya disimpan di setiap artikel. Sebagian situs memblokir akses otomatis; gunakan tombol Uji untuk memastikan.</div>
    </div>
    <script>
        (function () {
            var t = document.getElementById('src_type'), u = document.getElementById('src_url'), h = document.getElementById('src_hint');
            var info = {
                mediawiki: ['en.wikipedia.org', 'Domain situs MediaWiki, tanpa https://'],
                web: ['(kosong = seluruh web) atau domain, mis. detik.com', 'Opsional: batasi pencarian ke satu situs.'],
                page: ['https://contoh.com/cari?q={q}', 'Alamat halaman; {q} diganti kata kunci, {q_} = spasi jadi garis bawah.']
            };
            function sync() { u.placeholder = info[t.value][0]; h.textContent = info[t.value][1]; }
            t.addEventListener('change', sync); sync();
        })();
    </script>

<?php else: ?>
    <div class="grid md:grid-cols-2 gap-6 mb-6">
        <div class="bg-[#1F1F1F] p-6 rounded-xl shadow-lg shadow-black/30 border border-white/10">
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
                    <?php if ($edit): ?><a href="?tab=wilayah" class="px-4 py-2 text-sm text-gray-300">Batal</a><?php endif; ?>
                </div>
            </form>
        </div>
        <div class="bg-[#1F1F1F] p-6 rounded-xl shadow-lg shadow-black/30 border border-white/10">
            <h2 class="font-semibold mb-3">Import Massal</h2>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="action" value="import">
                <textarea name="bulk" rows="5" class="<?= $input ?> font-mono" placeholder="Jawa Timur|Kota Surabaya|Gubeng|Airlangga&#10;Jawa Timur|Kota Surabaya|Gubeng|Kertajaya"></textarea>
                <p class="text-xs text-[#ADADAD]">Satu baris satu kelurahan: Provinsi|Kota/Kab|Kecamatan|Kelurahan (pemisah <code class="bg-[#2D2D2D] px-1 rounded">|</code> atau tab, bisa tempel dari Excel).</p>
                <button class="<?= $btn ?>">Import</button>
            </form>
        </div>
    </div>

    <form method="GET" class="mb-3 flex gap-2">
        <input type="hidden" name="tab" value="wilayah">
        <input name="q" value="<?= h($q) ?>" class="<?= $input ?>" placeholder="Cari provinsi / kota / kecamatan / kelurahan...">
        <button class="<?= $btn ?>">Cari</button>
    </form>
    <div class="bg-[#1F1F1F] rounded-xl shadow-lg shadow-black/30 border border-white/10 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-[#181818] text-left text-gray-300"><tr><th class="p-3">Provinsi</th><th class="p-3">Kota/Kab</th><th class="p-3">Kecamatan</th><th class="p-3">Kelurahan</th><th class="p-3"></th></tr></thead>
            <tbody class="divide-y divide-white/5">
            <?php foreach ($wil as $w): ?>
                <tr>
                    <td class="p-3"><?= h($w['provinsi']) ?></td><td class="p-3"><?= h($w['kota_kab']) ?></td>
                    <td class="p-3"><?= h($w['kecamatan']) ?></td><td class="p-3"><?= h($w['kelurahan']) ?></td>
                    <td class="p-3 whitespace-nowrap">
                        <a href="?tab=wilayah&edit=<?= $w['id'] ?>" class="text-[#4CC2FF] mr-3">Edit</a>
                        <form method="POST" class="inline" onsubmit="return confirm('Hapus wilayah ini?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $w['id'] ?>"><button class="text-rose-400">Hapus</button></form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$wil): ?><tr><td colspan="5" class="p-6 text-[#ADADAD]">Tidak ada data.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if (count($wil) === 300): ?><p class="text-xs text-[#ADADAD] mt-2">Menampilkan 300 baris pertama. Gunakan pencarian untuk menyaring.</p><?php endif; ?>
<?php endif; ?>

</div>
</body>
</html>
