<?php
require_once __DIR__ . '/wp.php';
$db = db();

// Simpan tanggal publish & status WordPress sebuah artikel
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'schedule') {
    $id = (int) ($_POST['id'] ?? 0);
    $pa = trim($_POST['publish_at'] ?? '');
    $pa = preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $pa) ? str_replace('T', ' ', substr($pa, 0, 16)) . ':00' : null;
    $ws = array_key_exists($_POST['wp_target_status'] ?? '', WP_STATUSES) ? $_POST['wp_target_status'] : '';
    $st = $db->prepare('UPDATE articles SET publish_at = ?, wp_target_status = ? WHERE id = ?');
    $st->bind_param('ssi', $pa, $ws, $id);
    $st->execute();
    header('Location: articles.php?id=' . $id . '&msg=' . urlencode('Jadwal & status disimpan.'));
    exit;
}

// Label status WordPress sebuah artikel
function wp_badge(array $a): string {
    $pill = 'text-xs px-2 py-0.5 rounded-full font-medium border border-current/20 ';
    $map = ['publish' => ['Terbit', 'bg-emerald-500/15 text-emerald-300'], 'future' => ['Terjadwal', 'bg-amber-500/15 text-amber-300'],
            'draft' => ['Draft', 'bg-white/10 text-gray-300'], 'pending' => ['Review', 'bg-cyan-500/15 text-cyan-300'], 'private' => ['Privat', 'bg-white/10 text-gray-300']];
    if (!empty($a['wp_error'])) return '<span class="' . $pill . 'bg-rose-500/15 text-rose-300" title="' . h($a['wp_error']) . '">WP gagal</span>';
    if (empty($a['wp_post_id'])) return '<span class="' . $pill . 'bg-white/5 text-[#ADADAD]">belum dikirim</span>';
    [$l, $c] = $map[$a['wp_state']] ?? [$a['wp_state'], 'bg-white/10 text-gray-300'];
    return '<span class="' . $pill . $c . '">WP: ' . h($l) . '</span>';
}

// Hapus
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $st = $db->prepare('DELETE FROM articles WHERE id = ?');
    $id = (int) ($_POST['id'] ?? 0);
    $st->bind_param('i', $id);
    $st->execute();
    header('Location: articles.php?msg=' . urlencode('Artikel dihapus.'));
    exit;
}

$viewId = (int) ($_GET['id'] ?? 0);
$article = $viewId ? (rows('SELECT * FROM articles WHERE id = ' . $viewId)[0] ?? null) : null;

// Daftar
$q = trim($_GET['q'] ?? '');
$per = 20;
$page = max(1, (int) ($_GET['p'] ?? 1));
$where = '';
if ($q !== '') {
    $e = $db->real_escape_string($q);
    $where = "WHERE keyword LIKE '%$e%' OR lokasi LIKE '%$e%' OR title LIKE '%$e%' OR content LIKE '%$e%'";
}
$total = (int) rows("SELECT COUNT(*) c FROM articles $where")[0]['c'];
$pages = max(1, (int) ceil($total / $per));
$page = min($page, $pages);
$unsent = (int) rows("SELECT COUNT(*) c FROM articles WHERE wp_post_id IS NULL")[0]['c'];
$list = rows("SELECT id, title, keyword, lokasi, model, status, created_at, CHAR_LENGTH(content) len, publish_at, wp_post_id, wp_state, wp_error, wp_url
              FROM articles $where ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per));

$btn = 'bg-[#0078D4] hover:bg-[#106EBE] text-white text-sm font-medium py-2 px-4 rounded-lg';
?>
<?php require_once __DIR__ . '/ui.php'; ui_head(($article ? $article['keyword'] . ' - ' : '') . 'Artikel - Argen'); ui_nav('articles'); ?>
<div class="max-w-6xl mx-auto px-4 sm:px-6 py-8">

    <div class="mb-6">
        <h1 class="text-3xl font-semibold tracking-tight text-white">Artikel Tersimpan</h1>
        <p class="text-[#ADADAD] text-sm mt-1"><?= $total ?> artikel tersimpan.</p>
    </div>
    <?php if (!empty($_GET['msg'])): ?><div class="mb-4 p-3 rounded-lg bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 text-sm"><?= h($_GET['msg']) ?></div><?php endif; ?>

<?php if ($viewId): ?>
    <?php if (!$article): ?>
        <div class="bg-[#1F1F1F] p-6 rounded-xl border border-white/10">Artikel tidak ditemukan. <a href="articles.php" class="text-[#4CC2FF] underline">Kembali ke daftar</a></div>
    <?php else:
        $sources = json_decode($article['sources'] ?? '[]', true) ?: [];
        $paras = preg_split('/\R\s*\R/', trim($article['content']));
    ?>
        <div class="bg-[#1F1F1F] p-6 rounded-xl shadow-lg shadow-black/30 border border-white/10">
            <div class="flex flex-wrap gap-2 items-center text-xs mb-3">
                <span class="bg-[#0078D4]/20 text-[#4CC2FF] font-semibold px-2.5 py-0.5 rounded">#<?= $article['id'] ?></span>
                <?php if ($article['lokasi'] !== ''): ?><span class="bg-[#2D2D2D] px-2.5 py-0.5 rounded">📍 <?= h($article['lokasi']) ?></span><?php endif; ?>
                <span class="bg-[#2D2D2D] px-2.5 py-0.5 rounded"><?= h($article['model']) ?></span>
                <span class="bg-[#2D2D2D] px-2.5 py-0.5 rounded"><?= h($article['mode']) ?></span>
                <?php if ($article['status'] === 'partial'): ?><span class="bg-amber-500/15 text-amber-300 px-2.5 py-0.5 rounded">Sebagian gagal</span><?php endif; ?>
                <span class="text-[#ADADAD]"><?= h($article['created_at']) ?></span>
            </div>
            <h2 class="text-2xl font-bold text-white"><?= h($article['keyword']) ?></h2>
            <?php if ($article['title'] !== ''): ?><p class="text-[#ADADAD] text-sm"><?= h($article['title']) ?></p><?php endif; ?>

            <div id="body" class="mt-4 space-y-4 leading-relaxed text-[15px] border-t border-white/5 pt-4">
                <?php foreach ($paras as $p): ?><p><?= nl2br(h($p)) ?></p><?php endforeach; ?>
            </div>

            <?php if ($sources): ?>
                <div class="mt-6 pt-4 border-t border-white/5 text-xs text-[#ADADAD]">
                    <strong>Sumber (Wikipedia, CC BY-SA):</strong>
                    <?php foreach ($sources as $s): ?>
                        <a href="<?= h($s['url']) ?>" target="_blank" rel="noopener" class="text-[#4CC2FF] underline mr-2"><?= h($s['title']) ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="mt-6 pt-4 border-t border-white/5">
                <div class="font-semibold text-sm mb-2">WordPress <?= wp_badge($article) ?></div>
                <form method="POST" class="flex flex-wrap gap-3 items-end">
                    <input type="hidden" name="action" value="schedule"><input type="hidden" name="id" value="<?= $article['id'] ?>">
                    <label class="text-xs text-gray-300">Tanggal publish
                        <input type="datetime-local" name="publish_at" value="<?= $article['publish_at'] ? h(str_replace(' ', 'T', substr($article['publish_at'], 0, 16))) : '' ?>" class="block mt-1 p-2 border border-white/15 rounded-lg text-sm">
                    </label>
                    <label class="text-xs text-gray-300">Status
                        <select name="wp_target_status" class="block mt-1 p-2 border border-white/15 rounded-lg text-sm bg-[#1F1F1F]">
                            <option value="">(bawaan: <?= h(WP_STATUSES[wp_cfg()['status']] ?? 'Terbit') ?>)</option>
                            <?php foreach (WP_STATUSES as $k => $l): ?><option value="<?= $k ?>" <?= $article['wp_target_status'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <button class="bg-[#2D2D2D] hover:bg-[#383838] text-white text-sm py-2 px-3 rounded-lg">Simpan jadwal</button>
                    <button type="button" class="wp-send <?= $btn ?>" data-id="<?= $article['id'] ?>"><?= $article['wp_post_id'] ? 'Perbarui di WordPress' : 'Kirim ke WordPress' ?></button>
                </form>
                <div id="wp-result" class="mt-2 text-xs">
                    <?php if ($article['wp_error']): ?><span class="text-rose-300"><?= h($article['wp_error']) ?></span><?php endif; ?>
                    <?php if ($article['wp_url']): ?><a href="<?= h($article['wp_url']) ?>" target="_blank" rel="noopener" class="text-[#4CC2FF] underline"><?= h($article['wp_url']) ?></a><?php endif; ?>
                </div>
                <p class="text-xs text-[#ADADAD] mt-1">Terbit + tanggal masa depan = terjadwal; tanggal lampau = tanggal mundur (backdate); kosong = terbit sekarang.</p>
            </div>

            <div class="mt-6 flex gap-3 items-center">
                <button id="copy" class="<?= $btn ?>">Salin teks</button>
                <a href="articles.php" class="text-sm text-gray-300">← Kembali ke daftar</a>
                <form method="POST" class="ml-auto" onsubmit="return confirm('Hapus artikel ini?')">
                    <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $article['id'] ?>">
                    <button class="text-rose-400 text-sm">Hapus</button>
                </form>
            </div>
        </div>
        <script>
            document.getElementById('copy').addEventListener('click', function () {
                var t = Array.from(document.querySelectorAll('#body p')).map(function (p) { return p.innerText; }).join('\n\n');
                var b = this;
                navigator.clipboard.writeText(t).then(function () { b.textContent = 'Tersalin'; setTimeout(function () { b.textContent = 'Salin teks'; }, 1500); });
            });
        </script>
    <?php endif; ?>

<?php else: ?>
    <form method="GET" class="mb-4 flex gap-2">
        <input name="q" value="<?= h($q) ?>" placeholder="Cari kata kunci / wilayah / isi artikel..." class="w-full p-2.5 border border-white/15 rounded-lg text-sm outline-none focus:ring-2 focus:ring-[#0078D4]">
        <button class="<?= $btn ?>">Cari</button>
    </form>

    <?php if ($unsent > 0): ?>
        <div class="mb-3 flex items-center gap-3 text-sm">
            <button id="wp-bulk" class="<?= $btn ?>">Kirim semua yang belum terkirim ke WordPress (<?= $unsent ?>)</button>
            <span id="wp-bulk-status" class="text-xs text-[#ADADAD]"></span>
        </div>
    <?php endif; ?>

    <div class="bg-[#1F1F1F] rounded-xl shadow-lg shadow-black/30 border border-white/10 divide-y divide-white/5">
        <?php foreach ($list as $a): ?>
            <div class="p-4 flex justify-between gap-4 items-center">
                <a href="?id=<?= $a['id'] ?>" class="min-w-0 block">
                    <div class="font-medium text-white truncate hover:text-[#4CC2FF]"><?= h($a['keyword']) ?>
                        <?php if ($a['status'] === 'partial'): ?><span class="text-xs bg-amber-500/15 text-amber-300 px-1.5 rounded ml-1">sebagian gagal</span><?php endif; ?>
                    </div>
                    <div class="text-xs text-[#ADADAD] mt-0.5">
                        #<?= $a['id'] ?> · <?= $a['lokasi'] !== '' ? '📍 ' . h($a['lokasi']) . ' · ' : '' ?><?= h($a['model']) ?> · <?= number_format($a['len']) ?> karakter · <?= h($a['created_at']) ?>
                        <?= wp_badge($a) ?>
                        <?= $a['publish_at'] ? '· 🗓 ' . h(substr($a['publish_at'], 0, 16)) : '' ?>
                    </div>
                </a>
                <button type="button" class="wp-send text-[#4CC2FF] text-sm shrink-0" data-id="<?= $a['id'] ?>" data-sent="<?= $a['wp_post_id'] ? 1 : 0 ?>"><?= $a['wp_post_id'] ? 'Perbarui WP' : 'Kirim WP' ?></button>
                <form method="POST" onsubmit="return confirm('Hapus artikel ini?')" class="shrink-0">
                    <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $a['id'] ?>">
                    <button class="text-rose-400 text-sm">Hapus</button>
                </form>
            </div>
        <?php endforeach; ?>
        <?php if (!$list): ?><div class="p-8 text-center text-sm text-[#ADADAD]"><?= $q !== '' ? 'Tidak ada artikel yang cocok.' : 'Belum ada artikel. Buat dulu di halaman Generator.' ?></div><?php endif; ?>
    </div>

    <?php if ($pages > 1): ?>
        <div class="mt-4 flex justify-center gap-1 text-sm">
            <?php for ($i = 1; $i <= $pages; $i++): ?>
                <a href="?p=<?= $i ?>&q=<?= urlencode($q) ?>" class="px-3 py-1 rounded <?= $i === $page ? 'bg-[#0078D4] text-white' : 'bg-[#1F1F1F] border border-white/10' ?>"><?= $i ?></a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

</div>
<script>
    // Kirim satu artikel ke WordPress; mengembalikan {ok, message, url, state}
    async function sendWp(id) {
        const res = await fetch('wp_publish.php', { method: 'POST', body: new URLSearchParams({ id }) });
        try { return await res.json(); } catch (e) { return { ok: false, message: 'Respons server tidak valid (HTTP ' + res.status + ')' }; }
    }
    document.querySelectorAll('.wp-send').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            const old = btn.textContent;
            btn.disabled = true; btn.textContent = 'Mengirim...';
            const r = await sendWp(btn.dataset.id);
            btn.disabled = false;
            if (r.ok) { alert('Berhasil (' + r.message + ').' + (r.url ? '\n' + r.url : '')); location.reload(); }
            else { btn.textContent = old; alert('Gagal: ' + r.message); location.reload(); }
        });
    });
    const bulk = document.getElementById('wp-bulk');
    if (bulk) bulk.addEventListener('click', async function () {
        const ids = Array.from(document.querySelectorAll('.wp-send[data-sent="0"]')).map(function (b) { return b.dataset.id; });
        if (!ids.length) { alert('Tidak ada artikel belum terkirim pada halaman ini.'); return; }
        if (!confirm('Kirim ' + ids.length + ' artikel ke WordPress sesuai jadwal masing-masing?')) return;
        const st = document.getElementById('wp-bulk-status'); let ok = 0, fail = 0, lastErr = '';
        bulk.disabled = true;
        for (let i = 0; i < ids.length; i++) {
            st.textContent = 'Mengirim ' + (i + 1) + '/' + ids.length + '...';
            const r = await sendWp(ids[i]);
            if (r.ok) ok++; else { fail++; lastErr = r.message; if (/Login ditolak|Tidak bisa terhubung|belum lengkap/.test(r.message)) break; }
        }
        alert('Selesai: ' + ok + ' berhasil, ' + fail + ' gagal.' + (lastErr ? '\n' + lastErr : ''));
        location.reload();
    });
</script>
</body>
</html>
