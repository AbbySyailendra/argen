<?php
require_once __DIR__ . '/config.php';

// Data master dari halaman Pengaturan
$master_templates = rows('SELECT name, content FROM master_templates ORDER BY name');
$master_shortkeys = rows('SELECT name, value FROM master_shortkeys ORDER BY name');
$wilayah_rows     = rows('SELECT provinsi, kota_kab, kecamatan, kelurahan FROM wilayah ORDER BY provinsi, kota_kab, kecamatan, kelurahan');

$w_prov = []; $w_kota = []; $w_kec = []; $w_kel = [];
foreach ($wilayah_rows as $w) {
    $w_prov[$w['provinsi']] = true;
    $w_kota[$w['kota_kab']] = true;
    $w_kec[$w['kecamatan'] . '|' . $w['kota_kab']] = true;
    $w_kel[$w['kelurahan'] . '|' . $w['kecamatan'] . '|' . $w['kota_kab']] = true;
}

// Ambil daftar model yang benar-benar terpasang di Ollama
$ollama_models = [];
$ch = curl_init('http://127.0.0.1:11434/api/tags');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
$tags = curl_exec($ch);
curl_close($ch);
if ($tags !== false) {
    foreach (json_decode($tags, true)['models'] ?? [] as $m) {
        $ollama_models[] = $m['name'];
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auto Article Generator - Targeted Location</title>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 min-h-screen py-8 text-slate-800">

    <div class="max-w-4xl mx-auto px-4">
        <!-- Header -->
        <div class="mb-6 flex justify-between items-start">
            <div>
                <h1 class="text-3xl font-bold text-slate-900">Auto Article Generator</h1>
                <p class="text-slate-600 text-sm mt-1">Generate artikel otomatis per kata kunci & target wilayah ala FB Ads Targeting.</p>
            </div>
            <div class="flex gap-2">
                <a href="panduan.html" target="_blank" class="bg-slate-200 hover:bg-slate-300 text-slate-700 px-4 py-2 rounded-lg text-sm font-medium">📖 Panduan</a>
                <a href="settings.php" class="bg-slate-200 hover:bg-slate-300 text-slate-700 px-4 py-2 rounded-lg text-sm font-medium">⚙ Pengaturan</a>
            </div>
        </div>

        <form action="process.php" method="POST" class="space-y-6">
            
            <!-- Card 1: Input Keyword Massal -->
            <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200">
                <label>Judul Artikel</label>
                <input 
                    type="text" 
                    name="judul_artikel" 
                    class="w-full p-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm outline-none transition" 
                    placeholder="Contoh: Resep Masakan Nusantara" 
                    required>
                <label for="keywords" class="block font-semibold text-slate-700 mb-2">
                    Daftar Kata Kunci (1 Kata Kunci Per Baris)
                </label>
                <textarea 
                    id="keywords" 
                    name="keywords" 
                    rows="6" 
                    class="w-full p-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 font-mono text-sm outline-none transition" 
                    placeholder="Kacang goreng&#10;Resep nasi goreng&#10;Jasa renovasi rumah"
                    required></textarea>
                <p class="text-xs text-slate-500 mt-2">Sistem akan mengeksekusi setiap baris secara bergantian (satu per satu).</p>
            </div>

            <!-- Card 2: Pengaturan Target Lokasi & AI -->
            <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200 grid grid-cols-1 md:grid-cols-2 gap-6">
                
                <!-- Dropdown Target Lokasi (FB Ads Style) -->
                <div class="col-span-1 md:col-span-2">
                    <label for="target_wilayah" class="block font-semibold text-slate-700 mb-2">
                        Target Wilayah (Provinsi / Kota / Kecamatan / Kelurahan)
                    </label>
                    <select id="target_wilayah" name="target_wilayah" class="w-full p-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white text-sm outline-none">
                        <option value="ALL">-- Tanpa Target Lokasi (Nasional) --</option>

                        <!-- Wilayah dari master (Pengaturan > Wilayah) -->
                        <?php if ($w_prov): ?>
                        <optgroup label="── LEVEL PROVINSI (Semua Kelurahan di Provinsi) ──">
                            <?php foreach (array_keys($w_prov) as $v): ?>
                                <option value="PROV:<?= h($v) ?>">Seluruh <?= h($v) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <optgroup label="── LEVEL KOTA/KABUPATEN ──">
                            <?php foreach (array_keys($w_kota) as $v): ?>
                                <option value="KOTA:<?= h($v) ?>">Seluruh <?= h($v) ?> (Semua Kec & Kel)</option>
                            <?php endforeach; ?>
                        </optgroup>
                        <optgroup label="── LEVEL KECAMATAN ──">
                            <?php foreach (array_keys($w_kec) as $v): [$kec, $kota] = explode('|', $v); ?>
                                <option value="KEC:<?= h($v) ?>">Seluruh Kec. <?= h($kec) ?> (<?= h($kota) ?>)</option>
                            <?php endforeach; ?>
                        </optgroup>
                        <optgroup label="── SPESIFIK KELURAHAN ──">
                            <?php foreach (array_keys($w_kel) as $v): [$kel, $kec] = explode('|', $v); ?>
                                <option value="KEL:<?= h($v) ?>">Kel. <?= h($kel) ?> (Kec. <?= h($kec) ?>)</option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endif; ?>
                    </select>
                    <p class="text-xs text-slate-500 mt-1">
                        Daftar wilayah dikelola di <a href="settings.php?tab=wilayah" class="text-blue-600 underline">Pengaturan &gt; Wilayah</a>. Pilih kota untuk menyasar semua kelurahan di dalamnya, atau pilih spesifik kelurahan.
                    </p>
                </div>

                <!-- Engine Model AI -->
                <div>
                    <label for="model" class="block font-semibold text-slate-700 mb-2">Model Ollama</label>
                    <select id="model" name="model" class="w-full p-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white text-sm outline-none">
                        <?php foreach ($ollama_models as $m): ?>
                            <option value="<?php echo htmlspecialchars($m); ?>"><?php echo htmlspecialchars($m); ?></option>
                        <?php endforeach; ?>
                        <?php if (!$ollama_models): ?>
                            <option value="">(Ollama tidak terdeteksi / belum ada model)</option>
                        <?php endif; ?>
                    </select>
                    <p class="text-xs text-slate-500 mt-1">Daftar diambil otomatis dari Ollama yang terpasang.</p>
                </div>

                <!-- Mode Output -->
                <div>
                    <label for="mode" class="block font-semibold text-slate-700 mb-2">Gaya Penulisan</label>
                    <select id="mode" name="mode" class="w-full p-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white text-sm outline-none">
                        <option value="informatif">Informatif & Edukatif</option>
                        <option value="promosi">Promosi LOKAL (Landing Page/Penjualan)</option>
                    </select>
                </div>

            </div>

            <!-- Card 3: Template Paragraf Pertama + Preview -->
            <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200 space-y-4">
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <label for="template_p1" class="font-semibold text-slate-700">Template Artikel</label>
                        <div class="flex items-center gap-2">
                            <select id="tpl_select" class="p-1.5 border border-slate-300 rounded-lg bg-white text-sm outline-none">
                                <option value="">-- Pakai template master --</option>
                                <?php foreach ($master_templates as $t): ?>
                                    <option value="<?= h($t['name']) ?>"><?= h($t['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <a href="settings.php?tab=templates" class="text-xs text-blue-600 underline">Kelola</a>
                        </div>
                    </div>
                    <textarea
                        id="template_p1"
                        rows="12"
                        class="w-full p-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 font-mono text-sm outline-none transition"
                        placeholder="Kami {jual|dijual|menyediakan|} {keyword} berkualitas di {3 lokasi}.&#10;&#10;{paragraf}&#10;&#10;{paragraf}&#10;&#10;Paragraf penutup yang Anda tulis sendiri.&#10;&#10;{paragraf}"></textarea>
                    <p class="text-xs text-slate-500 mt-2 leading-relaxed">
                        Tulis paragraf sendiri dan sisipkan <code class="bg-slate-100 px-1 rounded">{paragraf}</code> di posisi yang ingin ditulis AI (Ollama). Satu <code class="bg-slate-100 px-1 rounded">{paragraf}</code> = satu paragraf AI; pisahkan antar paragraf dengan baris kosong.<br>
                        Short key:
                        <code class="bg-slate-100 px-1 rounded">{titles}</code> judul artikel,
                        <code class="bg-slate-100 px-1 rounded">{keyword}</code> kata kunci,
                        <code class="bg-slate-100 px-1 rounded">{lokasi}</code> satu nama wilayah,
                        <code class="bg-slate-100 px-1 rounded">{3 lokasi}</code> hingga 3 wilayah berbeda (jika wilayah kurang, tampil semuanya),
                        <code class="bg-slate-100 px-1 rounded">{kata1|kata2|kata3}</code> pilih salah satu secara acak (boleh kosong, mis. <code class="bg-slate-100 px-1 rounded">{jual|dijual|}</code>),
                        <code class="bg-slate-100 px-1 rounded">{template:nama}</code> sisipkan template master.
                        <?php if ($master_shortkeys): ?>
                            <br>Shortkey master:
                            <?php foreach ($master_shortkeys as $k): ?>
                                <code class="bg-blue-50 text-blue-800 px-1 rounded">{<?= h($k['name']) ?>}</code>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <br>Belum ada shortkey master. <a href="settings.php?tab=shortkeys" class="text-blue-600 underline">Buat di Pengaturan</a>.
                        <?php endif; ?>
                    </p>
                </div>

                <div class="flex items-center gap-3">
                    <button type="button" id="btnPreview" class="bg-slate-800 hover:bg-slate-900 text-white text-sm font-medium py-2 px-4 rounded-lg transition">
                        Generate Ulang (Acak)
                    </button>
                    <span class="text-xs text-slate-500">Preview saja, belum disimpan. Mengetik hanya menampilkan template; klik Proses & Generate untuk mengisi {paragraf} dengan AI.</span>
                </div>

                <div>
                    <label for="preview_p1" class="block font-semibold text-slate-700 mb-2">Hasil Preview (per kata kunci)</label>
                    <textarea
                        id="preview_p1"
                        rows="8"
                        readonly
                        class="w-full p-3 border border-slate-300 rounded-lg bg-slate-50 text-sm outline-none"
                        placeholder="Hasil akan muncul di sini..."></textarea>
                </div>
            </div>

            <!-- Submit Button -->
            <button type="submit" id="btnSubmit" class="w-full disabled:opacity-60 disabled:cursor-wait bg-blue-600 hover:bg-blue-700 text-white font-medium py-3.5 px-6 rounded-xl transition duration-200 shadow-md flex items-center justify-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                Proses & Generate Artikel
            </button>
        </form>
    </div>

    <script>
    (function () {
        const $ = (id) => document.getElementById(id);
        const pick = (arr) => arr[Math.floor(Math.random() * arr.length)];

        // Ambil daftar wilayah dari dropdown: {type, name, kec, kota}
        function readRegions() {
            return Array.from($('target_wilayah').options).map((o) => {
                const [type, rest = ''] = o.value.split(/:(.+)/);
                const p = rest.split('|');
                if (type === 'PROV') return { type, name: p[0] };
                if (type === 'KOTA') return { type, name: p[0], kota: p[0] };
                if (type === 'KEC')  return { type, name: p[0], kota: p[1] };
                if (type === 'KEL')  return { type, name: p[0], kec: p[1], kota: p[2] };
                return { type: 'ALL', name: 'Indonesia' };
            });
        }

        // Daftar nama wilayah (unik) yang masuk cakupan pilihan dropdown
        function locationPool() {
            const sel = $('target_wilayah').value;
            const regions = readRegions();
            const [type, rest = ''] = sel.split(/:(.+)/);
            const p = rest.split('|');
            let pool = [];

            if (type === 'KEL') return [p[0]];
            if (type === 'KEC') pool = regions.filter((r) => r.type === 'KEL' && r.kec === p[0]);
            else if (type === 'KOTA') pool = regions.filter((r) => (r.type === 'KEL' || r.type === 'KEC') && r.kota === p[0]);
            else if (type === 'PROV') pool = regions.filter((r) => ['KOTA', 'KEC', 'KEL'].includes(r.type));
            else pool = regions.filter((r) => r.type !== 'ALL');

            const names = [...new Set(pool.map((r) => r.name))];
            if (!names.length) return [type === 'ALL' ? 'Indonesia' : p[0]];
            return names;
        }

        // Satu wilayah acak
        function randomLocation() {
            return pick(locationPool());
        }

        // N wilayah berbeda (jika tersedia kurang dari N, tampilkan semuanya), mis. "A, B dan C"
        function randomLocations(n) {
            const pool = locationPool().slice();
            for (let i = pool.length - 1; i > 0; i--) {
                const j = Math.floor(Math.random() * (i + 1));
                [pool[i], pool[j]] = [pool[j], pool[i]];
            }
            const chosen = pool.slice(0, n);
            return chosen.length > 1
                ? chosen.slice(0, -1).join(', ') + ' dan ' + chosen[chosen.length - 1]
                : chosen[0];
        }

        // Ganti {a|b|c} dengan salah satu pilihan acak (pilihan kosong diperbolehkan)
        function spin(text) {
            const re = /\{([^{}]*\|[^{}]*)\}/;
            let guard = 0;
            while (re.test(text) && guard++ < 1000) {
                text = text.replace(re, (_, g) => pick(g.split('|')).trim());
            }
            return text;
        }

        // Data master dari halaman Pengaturan
        const MASTER_TEMPLATES = <?= json_encode(array_column($master_templates, 'content', 'name'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_FORCE_OBJECT) ?>;
        const MASTER_KEYS = <?= json_encode(array_column($master_shortkeys, 'value', 'name'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_FORCE_OBJECT) ?>;
        const lowerKeys = Object.fromEntries(Object.entries(MASTER_KEYS).map(([k, v]) => [k.toLowerCase(), v]));
        const lowerTpls = Object.fromEntries(Object.entries(MASTER_TEMPLATES).map(([k, v]) => [k.toLowerCase(), v]));

        // Perluas {template:nama} dan {shortkey master} (bertingkat, maks 6 lapis agar tidak looping)
        function expandMaster(text) {
            for (let i = 0; i < 6; i++) {
                const before = text;
                text = text
                    .replace(/\{template:([^{}]+)\}/gi, (m, n) => lowerTpls[n.trim().toLowerCase()] ?? m)
                    .replace(/\{([a-z][a-z0-9_]*)\}/gi, (m, n) => lowerKeys[n.toLowerCase()] ?? m);
                if (text === before) break;
            }
            return text;
        }

        function render(template, ctx) {
            let out = expandMaster(template)
                .replace(/\{titles?\}/gi, ctx.title)
                .replace(/\{keyword\}/gi, ctx.keyword)
                .replace(/\{(\d+)\s*lokasi\}/gi, (_, n) => randomLocations(parseInt(n, 10)))
                .replace(/\{lokasi\}/gi, ctx.lokasi);
            out = spin(out);
            return out.replace(/[ \t]{2,}/g, ' ').replace(/\s+([,.!?])/g, '$1').trim();
        }

        const getTitle = () => document.querySelector('[name="judul_artikel"]').value.trim();
        const getKeywords = () => $('keywords').value.split('\n').map((k) => k.trim()).filter(Boolean);

        // Preview template tanpa AI (live saat mengetik); {paragraf} tetap tampil sebagai penanda
        function generate() {
            const template = $('template_p1').value;
            if (!template.trim()) { $('preview_p1').value = ''; return; }

            const title = getTitle();
            const list = getKeywords();

            $('preview_p1').value = (list.length ? list : [''])
                .map((kw) => (kw ? '[' + kw + ']\n' : '') + render(template, { title, keyword: kw, lokasi: randomLocation() }))
                .join('\n\n');
        }

        // Minta Ollama menulis satu paragraf untuk posisi {paragraf}
        async function aiParagraph(params) {
            const res = await fetch('generate.php', { method: 'POST', body: new URLSearchParams(params) });
            const data = await res.json();
            if (!res.ok || data.error) throw new Error(data.error || 'HTTP ' + res.status);
            return data.paragraph;
        }

        // Submit: template dirender, setiap {paragraf} diisi Ollama. Hanya ditampilkan di preview.
        async function generateFull(e) {
            e.preventDefault();
            const template = $('template_p1').value;
            if (!/\{paragraf\}/i.test(template)) {
                alert('Template belum memiliki {paragraf}. Tambahkan {paragraf} di posisi yang ingin ditulis AI.');
                $('template_p1').focus();
                return;
            }

            const btn = $('btnSubmit');
            const label = btn.lastChild.textContent;
            const preview = $('preview_p1');
            const title = getTitle();
            const keywords = getKeywords();
            const blocks = [];
            btn.disabled = true;
            preview.scrollIntoView({ behavior: 'smooth', block: 'center' });

            for (let i = 0; i < keywords.length; i++) {
                const kw = keywords[i];
                const lokasi = randomLocation();
                // Bagian statis di antara penanda {paragraf}
                const parts = render(template, { title, keyword: kw, lokasi }).split(/\{paragraf\}/i);
                const total = parts.length - 1;
                let text = parts[0];
                const show = (tail) => {
                    preview.value = blocks.concat('[' + kw + ']\n' + text + (tail || '')).join('\n\n');
                };

                for (let n = 1; n <= total; n++) {
                    btn.lastChild.textContent = ' ' + (i + 1) + '/' + keywords.length + ' ' + kw + ' - paragraf AI ' + n + '/' + total + '...';
                    show('(menulis paragraf AI ' + n + '/' + total + '...)');

                    let para;
                    try {
                        para = await aiParagraph({
                            keyword: kw, title, lokasi,
                            before: text.trim(),
                            after: parts.slice(n).join('[paragraf AI]').trim(),
                            model: $('model').value,
                            mode: $('mode').value,
                        });
                    } catch (err) {
                        para = '(gagal: ' + err.message + ')';
                    }
                    text += para + parts[n];
                }

                show();
                blocks.push('[' + kw + ']\n' + text);
            }

            btn.lastChild.textContent = label;
            btn.disabled = false;
        }

        // Muat template master ke kolom template (tetap bisa diedit di sini)
        $('tpl_select').addEventListener('change', (e) => {
            if (e.target.value === '') return;
            $('template_p1').value = MASTER_TEMPLATES[e.target.value];
            generate();
        });

        $('btnPreview').addEventListener('click', generate);
        document.querySelector('form').addEventListener('submit', generateFull);
        ['template_p1', 'keywords', 'target_wilayah'].forEach((id) => $(id).addEventListener('input', generate));
        document.querySelector('[name="judul_artikel"]').addEventListener('input', generate);
    })();
    </script>

</body>
</html>