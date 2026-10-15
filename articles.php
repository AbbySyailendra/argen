<?php
require_once __DIR__ . '/config.php';
$db = db();

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
$list = rows("SELECT id, title, keyword, lokasi, model, status, created_at, CHAR_LENGTH(content) len
              FROM articles $where ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per));

$btn = 'bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium py-2 px-4 rounded-lg';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $article ? h($article['keyword']) . ' - ' : '' ?>Daftar Artikel</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 min-h-screen py-8 text-slate-800">
<div class="max-w-4xl mx-auto px-4">

    <div class="flex justify-between items-start mb-6">
        <div>
            <h1 class="text-3xl font-bold text-slate-900">Daftar Artikel</h1>
            <p class="text-slate-600 text-sm mt-1"><?= $total ?> artikel tersimpan.</p>
        </div>
        <div class="flex gap-2">
            <a href="index.php" class="bg-slate-200 hover:bg-slate-300 text-slate-700 px-4 py-2 rounded-lg text-sm font-medium">← Generator</a>
            <a href="settings.php" class="bg-slate-200 hover:bg-slate-300 text-slate-700 px-4 py-2 rounded-lg text-sm font-medium">⚙ Pengaturan</a>
        </div>
    </div>

    <?php if (!empty($_GET['msg'])): ?><div class="mb-4 p-3 rounded-lg bg-green-50 border border-green-200 text-green-800 text-sm"><?= h($_GET['msg']) ?></div><?php endif; ?>

<?php if ($viewId): ?>
    <?php if (!$article): ?>
        <div class="bg-white p-6 rounded-xl border border-slate-200">Artikel tidak ditemukan. <a href="articles.php" class="text-blue-600 underline">Kembali ke daftar</a></div>
    <?php else:
        $sources = json_decode($article['sources'] ?? '[]', true) ?: [];
        $paras = preg_split('/\R\s*\R/', trim($article['content']));
    ?>
        <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200">
            <div class="flex flex-wrap gap-2 items-center text-xs mb-3">
                <span class="bg-blue-100 text-blue-800 font-semibold px-2.5 py-0.5 rounded">#<?= $article['id'] ?></span>
                <?php if ($article['lokasi'] !== ''): ?><span class="bg-slate-100 px-2.5 py-0.5 rounded">📍 <?= h($article['lokasi']) ?></span><?php endif; ?>
                <span class="bg-slate-100 px-2.5 py-0.5 rounded"><?= h($article['model']) ?></span>
                <span class="bg-slate-100 px-2.5 py-0.5 rounded"><?= h($article['mode']) ?></span>
                <?php if ($article['status'] === 'partial'): ?><span class="bg-amber-100 text-amber-800 px-2.5 py-0.5 rounded">Sebagian gagal</span><?php endif; ?>
                <span class="text-slate-500"><?= h($article['created_at']) ?></span>
            </div>
            <h2 class="text-2xl font-bold text-slate-900"><?= h($article['keyword']) ?></h2>
            <?php if ($article['title'] !== ''): ?><p class="text-slate-500 text-sm"><?= h($article['title']) ?></p><?php endif; ?>

            <div id="body" class="mt-4 space-y-4 leading-relaxed text-[15px] border-t border-slate-100 pt-4">
                <?php foreach ($paras as $p): ?><p><?= nl2br(h($p)) ?></p><?php endforeach; ?>
            </div>

            <?php if ($sources): ?>
                <div class="mt-6 pt-4 border-t border-slate-100 text-xs text-slate-500">
                    <strong>Sumber (Wikipedia, CC BY-SA):</strong>
                    <?php foreach ($sources as $s): ?>
                        <a href="<?= h($s['url']) ?>" target="_blank" rel="noopener" class="text-blue-600 underline mr-2"><?= h($s['title']) ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="mt-6 flex gap-3 items-center">
                <button id="copy" class="<?= $btn ?>">Salin teks</button>
                <a href="articles.php" class="text-sm text-slate-600">← Kembali ke daftar</a>
                <form method="POST" class="ml-auto" onsubmit="return confirm('Hapus artikel ini?')">
                    <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $article['id'] ?>">
                    <button class="text-red-600 text-sm">Hapus</button>
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
        <input name="q" value="<?= h($q) ?>" placeholder="Cari kata kunci / wilayah / isi artikel..." class="w-full p-2.5 border border-slate-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-blue-500">
        <button class="<?= $btn ?>">Cari</button>
    </form>

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 divide-y divide-slate-100">
        <?php foreach ($list as $a): ?>
            <div class="p-4 flex justify-between gap-4 items-center">
                <a href="?id=<?= $a['id'] ?>" class="min-w-0 block">
                    <div class="font-medium text-slate-900 truncate hover:text-blue-600"><?= h($a['keyword']) ?>
                        <?php if ($a['status'] === 'partial'): ?><span class="text-xs bg-amber-100 text-amber-800 px-1.5 rounded ml-1">sebagian gagal</span><?php endif; ?>
                    </div>
                    <div class="text-xs text-slate-500 mt-0.5">
                        #<?= $a['id'] ?> · <?= $a['lokasi'] !== '' ? '📍 ' . h($a['lokasi']) . ' · ' : '' ?><?= h($a['model']) ?> · <?= number_format($a['len']) ?> karakter · <?= h($a['created_at']) ?>
                    </div>
                </a>
                <form method="POST" onsubmit="return confirm('Hapus artikel ini?')" class="shrink-0">
                    <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $a['id'] ?>">
                    <button class="text-red-600 text-sm">Hapus</button>
                </form>
            </div>
        <?php endforeach; ?>
        <?php if (!$list): ?><div class="p-8 text-center text-sm text-slate-500"><?= $q !== '' ? 'Tidak ada artikel yang cocok.' : 'Belum ada artikel. Buat dulu di halaman Generator.' ?></div><?php endif; ?>
    </div>

    <?php if ($pages > 1): ?>
        <div class="mt-4 flex justify-center gap-1 text-sm">
            <?php for ($i = 1; $i <= $pages; $i++): ?>
                <a href="?p=<?= $i ?>&q=<?= urlencode($q) ?>" class="px-3 py-1 rounded <?= $i === $page ? 'bg-blue-600 text-white' : 'bg-white border border-slate-200' ?>"><?= $i ?></a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

</div>
</body>
</html>
