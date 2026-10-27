<?php
// Kerangka tampilan bersama: Microsoft Fluent Design 2 (Dark Mode). Lihat .claude/skills/argen-ui/SKILL.md

// Kepala dokumen + pembuka <body>. Panggil di awal halaman.
function ui_head(string $title): void { ?>
<!DOCTYPE html>
<html lang="id" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?></title>
    <script>
        // Tema: dark (bawaan) | light | auto (ikut sistem). Disimpan di localStorage; dijalankan sebelum render agar tidak berkedip.
        (function () {
            var t = 'dark';
            try { t = localStorage.getItem('argen-theme') || 'dark'; } catch (e) {}
            var q = /[?&]theme=(dark|light|auto)/.exec(location.search); if (q) t = q[1];   // pratinjau lewat URL, tidak disimpan
            var light = t === 'light' || (t === 'auto' && window.matchMedia && matchMedia('(prefers-color-scheme: light)').matches);
            var h = document.documentElement;
            h.classList.toggle('light', light); h.classList.toggle('dark', !light); h.dataset.theme = t;
        })();
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root { color-scheme: dark; }
        body { font-family: 'Segoe UI', Inter, system-ui, -apple-system, sans-serif; }
        /* Kolom isian gelap seragam (menimpa kelas bawaan) */
        input:not([type=checkbox]):not([type=radio]), textarea, select {
            background-color: #141414 !important; color: #fff; border-color: rgba(255,255,255,.15);
        }
        input::placeholder, textarea::placeholder { color: #7a7a7a; }
        textarea[readonly] { background-color: #181818 !important; }
        select option { background: #1F1F1F; color: #fff; }
        input:focus, textarea:focus, select:focus { outline: none; border-color: #0078D4 !important; box-shadow: 0 0 0 1px #0078D4; }
        input[type=checkbox] { accent-color: #0078D4; }
        a { transition: color .15s; }
        ::selection { background: #0078D4; color: #fff; }
        ::-webkit-scrollbar { width: 10px; height: 10px; }
        ::-webkit-scrollbar-thumb { background: #3a3a3a; border-radius: 6px; }
        ::-webkit-scrollbar-track { background: #141414; }
        code { background: #2D2D2D; color: #7ee7f0; }
        /* ===== Tema terang (Fluent 2 Light). Aktif bila <html class="light"> ===== */
        html.light { color-scheme: light; }
        .light [class~="bg-[#0F0F0F]"] { background-color: #F5F5F5; }
        .light [class~="bg-[#0F0F0F]/75"] { background-color: rgba(245,245,245,.80); }
        .light [class~="bg-[#1F1F1F]"] { background-color: #FFFFFF; }
        .light [class~="bg-[#181818]"] { background-color: #F7F7F7; }
        .light [class~="bg-[#2D2D2D]"] { background-color: #EBEBEB; }
        .light [class~="hover:bg-[#383838]"]:hover { background-color: #DEDEDE; }
        .light [class~="bg-white/10"] { background-color: rgba(0,0,0,.07); }
        .light [class~="bg-white/5"] { background-color: rgba(0,0,0,.04); }
        .light [class~="bg-[#0078D4]/15"] { background-color: rgba(15,108,189,.12); }
        .light [class~="bg-[#0078D4]/20"] { background-color: rgba(15,108,189,.16); }
        .light [class~="border-white/5"] { border-color: rgba(0,0,0,.06); }
        .light [class~="border-white/10"] { border-color: rgba(0,0,0,.10); }
        .light [class~="border-white/15"] { border-color: rgba(0,0,0,.16); }
        .light [class~="divide-white/5"] > :not([hidden]) ~ :not([hidden]) { border-color: rgba(0,0,0,.07); }
        .light [class~="shadow-black/30"] { box-shadow: 0 2px 10px rgba(0,0,0,.07); }
        .light .text-white { color: #242424; }
        .light [class~="hover:text-white"]:hover { color: #000; }
        .light .text-gray-100 { color: #242424; }
        .light .text-gray-200 { color: #303030; }
        .light .text-gray-300 { color: #424242; }
        .light [class~="text-[#ADADAD]"] { color: #5F5F5F; }
        .light [class~="text-[#4CC2FF]"], .light [class~="hover:text-[#4CC2FF]"]:hover { color: #0F6CBD; }
        .light .text-emerald-300 { color: #0B6A0B; }
        .light .text-amber-300 { color: #835B00; }
        .light .text-cyan-300 { color: #00666E; }
        .light .text-rose-200, .light .text-rose-300 { color: #B10E1C; }
        .light .text-rose-400 { color: #C50F1F; }
        .light [class~="bg-[#0078D4]"] { color: #fff; }   /* teks tombol utama tetap putih */
        .light input:not([type=checkbox]):not([type=radio]), .light textarea, .light select {
            background-color: #FFFFFF !important; color: #242424; border-color: rgba(0,0,0,.20);
        }
        .light textarea[readonly] { background-color: #F7F7F7 !important; }
        .light input::placeholder, .light textarea::placeholder { color: #8A8A8A; }
        .light select option { background: #FFFFFF; color: #242424; }
        .light code { background: #EBEBEB; color: #0F548C; }
        .light ::-webkit-scrollbar-thumb { background: #C4C4C4; }
        .light ::-webkit-scrollbar-track { background: #F0F0F0; }

        /* Pengalih tema */
        #theme-switch button { padding: 4px 10px; border-radius: 6px; color: inherit; opacity: .75; }
        #theme-switch button:hover { opacity: 1; }
        #theme-switch button[aria-pressed="true"] { background: #0078D4; color: #fff; opacity: 1; }
    </style>
</head>
<body class="bg-[#0F0F0F] text-white min-h-screen antialiased">
<?php }

// Bilah navigasi atas (efek acrylic). $active: index | articles | settings | panduan
function ui_nav(string $active): void {
    $items = [
        'index'    => ['index.php',    'Beranda'],
        'articles' => ['articles.php', 'Artikel'],
        'settings' => ['settings.php', 'Pengaturan'],
        'panduan'  => ['panduan.html', 'Panduan'],
    ]; ?>
<nav class="sticky top-0 z-50 border-b border-white/10 bg-[#0F0F0F]/75 backdrop-blur-xl">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 flex items-center gap-6 h-14 overflow-x-auto">
        <a href="index.php" class="flex items-center gap-2 font-semibold tracking-tight shrink-0">
            <span class="inline-flex h-6 w-6 items-center justify-center rounded-md bg-[#0078D4] text-xs font-bold">A</span>
            <span>Argen</span>
        </a>
        <div class="flex items-center gap-1 h-full">
            <?php foreach ($items as $key => [$href, $label]): $on = $key === $active; ?>
                <a href="<?= $href ?>"<?= $key === 'panduan' ? ' target="_blank"' : '' ?>
                   class="relative h-full flex items-center px-3 text-sm whitespace-nowrap <?= $on ? 'text-white font-medium' : 'text-[#ADADAD] hover:text-white' ?>">
                    <?= $label ?>
                    <?php if ($on): ?><span class="absolute left-3 right-3 bottom-0 h-0.5 rounded-full bg-[#0078D4]"></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
        <div id="theme-switch" role="group" aria-label="Tema tampilan" class="ml-auto shrink-0 flex items-center rounded-lg border border-white/10 bg-[#1F1F1F] p-0.5 text-xs">
            <button type="button" data-t="dark" aria-pressed="false">Gelap</button>
            <button type="button" data-t="light" aria-pressed="false">Terang</button>
            <button type="button" data-t="auto" aria-pressed="false">Auto</button>
        </div>
    </div>
</nav>
<script>
    (function () {
        var box = document.getElementById('theme-switch'); if (!box) return;
        function apply(t) {
            var light = t === 'light' || (t === 'auto' && matchMedia('(prefers-color-scheme: light)').matches);
            var h = document.documentElement;
            h.classList.toggle('light', light); h.classList.toggle('dark', !light); h.dataset.theme = t;
            box.querySelectorAll('button').forEach(function (b) { b.setAttribute('aria-pressed', b.dataset.t === t ? 'true' : 'false'); });
        }
        box.addEventListener('click', function (e) {
            var b = e.target.closest('button'); if (!b) return;
            try { localStorage.setItem('argen-theme', b.dataset.t); } catch (x) {}
            apply(b.dataset.t);
        });
        matchMedia('(prefers-color-scheme: light)').addEventListener && matchMedia('(prefers-color-scheme: light)').addEventListener('change', function () {
            if (document.documentElement.dataset.theme === 'auto') apply('auto');
        });
        apply(document.documentElement.dataset.theme || 'dark');
    })();
</script>
<?php }