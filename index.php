<?php
require_once __DIR__ . '/wp.php';

// Data master dari halaman Pengaturan
$master_templates = rows('SELECT name, title, content FROM master_templates ORDER BY name');
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
$default_model = ollama_cfg()['model'];
$ollama_models = [];
$ch = curl_init(OLLAMA_URL . '/api/tags');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
$tags = curl_exec($ch);
curl_close($ch);
if ($tags !== false) {
    foreach (json_decode($tags, true)['models'] ?? [] as $m) {
        $ollama_models[] = $m['name'];
    }
    // Model umum dulu (jadi pilihan awal); model khusus kode ("coder") di urutan akhir
    usort($ollama_models, fn($a, $b) => (str_contains($a, 'coder') <=> str_contains($b, 'coder')) ?: strcmp($b, $a));
}
?>
<?php require_once __DIR__ . '/ui.php'; ui_head('Argen - Generator Artikel'); ui_nav('index'); ?>

    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-8">
        <!-- Header -->
        <div class="mb-6">
            <h1 class="text-3xl font-semibold tracking-tight text-white">Generator Artikel</h1>
            <p class="text-[#ADADAD] text-sm mt-1">Buat artikel otomatis per kata kunci dan wilayah, lalu simpan atau kirim ke WordPress.</p>
        </div>
        <form action="process.php" method="POST" class="space-y-6">
            
            <!-- Card 1: Input Keyword Massal -->
            <div class="bg-[#1F1F1F] p-6 rounded-xl shadow-lg shadow-black/30 border border-white/10">
                <label for="keywords" class="block font-semibold text-gray-200 mb-2">
                    Daftar Kata Kunci (1 Kata Kunci Per Baris)
                </label>
                <textarea 
                    id="keywords" 
                    name="keywords" 
                    rows="6" 
                    class="w-full p-3 border border-white/15 rounded-lg focus:ring-2 focus:ring-[#0078D4] focus:border-[#0078D4] font-mono text-sm outline-none transition" 
                    placeholder="Kacang goreng&#10;Resep nasi goreng&#10;Jasa renovasi rumah"
                    required></textarea>
                <p class="text-xs text-[#ADADAD] mt-2">Setiap kata kunci dikombinasikan dengan setiap kelurahan pada Target Wilayah. Contoh: 2 kata kunci × 3 kelurahan = 6 artikel, masing-masing disimpan terpisah.</p>
            </div>

            <!-- Card 2: Pengaturan Target Lokasi & AI -->
            <div class="bg-[#1F1F1F] p-6 rounded-xl shadow-lg shadow-black/30 border border-white/10 grid grid-cols-1 md:grid-cols-2 gap-6">
                
                <!-- Dropdown Target Lokasi (FB Ads Style) -->
                <div class="col-span-1 md:col-span-2">
                    <label for="target_wilayah" class="block font-semibold text-gray-200 mb-2">
                        Target Wilayah (Provinsi / Kota / Kecamatan / Kelurahan)
                    </label>
                    <select id="target_wilayah" name="target_wilayah" class="w-full p-3 border border-white/15 rounded-lg focus:ring-2 focus:ring-[#0078D4] bg-[#1F1F1F] text-sm outline-none">
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
                    <p class="text-xs text-[#ADADAD] mt-1">
                        Daftar wilayah dikelola di <a href="settings.php?tab=wilayah" class="text-[#4CC2FF] underline">Pengaturan &gt; Wilayah</a>. Memilih kecamatan/kota/provinsi = satu artikel untuk <strong>setiap kelurahan</strong> di dalamnya; pilih spesifik kelurahan untuk satu wilayah saja.
                    </p>
                </div>

                <!-- Batas jumlah artikel -->
                <div class="col-span-1 md:col-span-2">
                    <label for="max_articles" class="block font-semibold text-gray-200 mb-2">Batas Jumlah Artikel per Proses</label>
                    <input type="number" id="max_articles" min="1" value="50" class="w-full md:w-40 p-3 border border-white/15 rounded-lg text-sm outline-none focus:ring-2 focus:ring-[#0078D4]">
                    <p class="text-xs text-[#ADADAD] mt-1">Pengaman agar memilih satu kota tidak langsung membuat ratusan artikel. Jika kombinasi melebihi batas ini, hanya sebanyak batas yang diproses.</p>
                </div>

                <!-- Publikasi WordPress -->
                <div class="col-span-1 md:col-span-2 border-t border-white/5 pt-4">
                    <div class="font-semibold text-gray-200 mb-1">Publikasi WordPress <a href="settings.php?tab=wordpress" class="text-xs font-normal text-[#4CC2FF] underline ml-1">Pengaturan koneksi</a></div>
                    <p class="text-xs text-[#ADADAD] mb-3">Tanggal masa depan = <strong>terjadwal</strong>, tanggal lampau = <strong>tanggal mundur (backdate)</strong>, kosong = terbit saat dikirim. Dengan jarak antar artikel, tiap artikel mendapat tanggal berurutan.</p>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                        <label class="text-xs text-gray-300 col-span-2">Tanggal publish artikel pertama
                            <input type="datetime-local" id="pub_start" class="w-full mt-1 p-2.5 border border-white/15 rounded-lg text-sm outline-none focus:ring-2 focus:ring-[#0078D4]">
                        </label>
                        <label class="text-xs text-gray-300">Jarak antar artikel
                            <input type="number" id="pub_gap" min="0" value="0" class="w-full mt-1 p-2.5 border border-white/15 rounded-lg text-sm outline-none">
                        </label>
                        <label class="text-xs text-gray-300">Satuan
                            <select id="pub_unit" class="w-full mt-1 p-2.5 border border-white/15 rounded-lg text-sm bg-[#1F1F1F] outline-none">
                                <option value="1440">hari</option><option value="60">jam</option><option value="1">menit</option>
                            </select>
                        </label>
                        <label class="text-xs text-gray-300 col-span-2">Status posting
                            <select id="wp_status" class="w-full mt-1 p-2.5 border border-white/15 rounded-lg text-sm bg-[#1F1F1F] outline-none">
                                <option value="">(bawaan dari Pengaturan: <?= h(WP_STATUSES[wp_cfg()['status']] ?? 'Terbit') ?>)</option>
                                <?php foreach (WP_STATUSES as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?>
                            </select>
                        </label>
                        <label class="flex items-center gap-2 text-sm col-span-2 pt-5"><input type="checkbox" id="wp_auto"> Kirim otomatis ke WordPress setelah artikel dibuat</label>
                    </div>
                </div>

                <!-- Engine Model AI -->
                <div>
                    <label for="model" class="block font-semibold text-gray-200 mb-2">Model Ollama</label>
                    <select id="model" name="model" class="w-full p-3 border border-white/15 rounded-lg focus:ring-2 focus:ring-[#0078D4] bg-[#1F1F1F] text-sm outline-none">
                        <?php foreach ($ollama_models as $m): ?>
                            <option value="<?php echo htmlspecialchars($m); ?>"<?= $m === $default_model ? ' selected' : '' ?>><?php echo htmlspecialchars($m); ?></option>
                        <?php endforeach; ?>
                        <?php if (!$ollama_models): ?>
                            <option value="">(Ollama tidak terdeteksi / belum ada model)</option>
                        <?php endif; ?>
                    </select>
                    <p class="text-xs text-[#ADADAD] mt-1">Daftar diambil otomatis dari Ollama yang terpasang.</p>
                </div>

                <!-- Mode Output -->
                <div>
                    <label for="mode" class="block font-semibold text-gray-200 mb-2">Gaya Penulisan</label>
                    <select id="mode" name="mode" class="w-full p-3 border border-white/15 rounded-lg focus:ring-2 focus:ring-[#0078D4] bg-[#1F1F1F] text-sm outline-none">
                        <option value="informatif">Informatif & Edukatif</option>
                        <option value="promosi">Promosi LOKAL (Landing Page/Penjualan)</option>
                    </select>
                </div>

            </div>

            <!-- Card 3: Template Paragraf Pertama + Preview -->
            <div class="bg-[#1F1F1F] p-6 rounded-xl shadow-lg shadow-black/30 border border-white/10 space-y-4">
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <label for="template_p1" class="font-semibold text-gray-200">Template Artikel</label>
                        <div class="flex items-center gap-2">
                            <select id="tpl_select" class="p-1.5 border border-white/15 rounded-lg bg-[#1F1F1F] text-sm outline-none">
                                <option value="">-- Pakai template master --</option>
                                <?php foreach ($master_templates as $t): ?>
                                    <option value="<?= h($t['name']) ?>"><?= h($t['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <a href="settings.php?tab=templates" class="text-xs text-[#4CC2FF] underline">Kelola</a>
                        </div>
                    </div>
                    <label for="judul_artikel" class="block text-sm font-medium text-gray-200 mb-1">Judul Artikel <span class="text-xs font-normal text-[#ADADAD]">(template, dibuat bersama artikelnya)</span></label>
                    <input
                        type="text"
                        id="judul_artikel"
                        name="judul_artikel"
                        class="w-full p-3 mb-1 border border-white/15 rounded-lg focus:ring-2 focus:ring-[#0078D4] focus:border-[#0078D4] font-mono text-sm outline-none transition"
                        placeholder="{Jual|Pusat|Toko} {keyword} di {lokasi}: {judul}">
                    <p class="text-xs text-[#ADADAD] mb-4">Mendukung short key. <code class="bg-[#2D2D2D] px-1 rounded">{judul}</code> = bagian judul yang ditulis AI setelah kerangka artikel tersusun, supaya selaras dengan isinya (<code class="bg-[#2D2D2D] px-1 rounded">{judul: instruksi}</code> untuk mengarahkan). Tanpa <code class="bg-[#2D2D2D] px-1 rounded">{judul}</code>, judul memakai template apa adanya. Judul final bisa dipanggil di isi artikel dengan <code class="bg-[#2D2D2D] px-1 rounded">{titles}</code>.</p>
                    <label for="template_p1" class="block text-sm font-medium text-gray-200 mb-1">Isi Artikel</label>
                    <textarea
                        id="template_p1"
                        rows="12"
                        class="w-full p-3 border border-white/15 rounded-lg focus:ring-2 focus:ring-[#0078D4] focus:border-[#0078D4] font-mono text-sm outline-none transition"
                        placeholder="Kami {jual|dijual|menyediakan|} {keyword} berkualitas di {3 lokasi}.&#10;&#10;{paragraf}&#10;&#10;{paragraf}&#10;&#10;Paragraf penutup yang Anda tulis sendiri.&#10;&#10;{paragraf}"></textarea>
                    <p class="text-xs text-[#ADADAD] mt-2 leading-relaxed">
                        Tulis paragraf sendiri dan sisipkan <code class="bg-[#2D2D2D] px-1 rounded">{paragraf}</code> di posisi yang ingin ditulis AI (Ollama). Satu <code class="bg-[#2D2D2D] px-1 rounded">{paragraf}</code> = satu paragraf AI; pisahkan antar paragraf dengan baris kosong.<br>
                        Short key:
                        <code class="bg-[#2D2D2D] px-1 rounded">{titles}</code> judul artikel,
                        <code class="bg-[#2D2D2D] px-1 rounded">{keyword}</code> kata kunci,
                        <code class="bg-[#2D2D2D] px-1 rounded">{lokasi}</code> satu nama wilayah,
                        <code class="bg-[#2D2D2D] px-1 rounded">{3 lokasi}</code> hingga 3 wilayah berbeda (jika wilayah kurang, tampil semuanya),
                        <code class="bg-[#2D2D2D] px-1 rounded">{kata1|kata2|kata3}</code> pilih salah satu secara acak (boleh kosong, mis. <code class="bg-[#2D2D2D] px-1 rounded">{jual|dijual|}</code>),
                        <code class="bg-[#2D2D2D] px-1 rounded">{template:nama}</code> sisipkan template master,
                        <code class="bg-[#2D2D2D] px-1 rounded">{paragraf}</code> satu paragraf AI bebas,
                        <code class="bg-[#2D2D2D] px-1 rounded">{paragraf: jelaskan apa itu {keyword}}</code> paragraf AI sesuai instruksi (berbekal Wikipedia untuk kata kunci dan wilayah).
                        <?php if ($master_shortkeys): ?>
                            <br>Shortkey master:
                            <?php foreach ($master_shortkeys as $k): ?>
                                <code class="bg-[#0078D4]/15 text-[#4CC2FF] px-1 rounded">{<?= h($k['name']) ?>}</code>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <br>Belum ada shortkey master. <a href="settings.php?tab=shortkeys" class="text-[#4CC2FF] underline">Buat di Pengaturan</a>.
                        <?php endif; ?>
                    </p>
                </div>

                <div class="flex items-center gap-3">
                    <button type="button" id="btnPreview" class="bg-[#2D2D2D] hover:bg-[#383838] text-white text-sm font-medium py-2 px-4 rounded-lg transition">
                        Generate Ulang (Acak)
                    </button>
                    <span class="text-xs text-[#ADADAD]">Mengetik hanya menampilkan template. Klik Proses & Generate untuk mengisi {paragraf} dengan AI; hasilnya otomatis tersimpan ke <a href="articles.php" class="text-[#4CC2FF] underline">Daftar Artikel</a>.</span>
                </div>

                <div>
                    <label for="preview_p1" class="block font-semibold text-gray-200 mb-2">Hasil Preview (per kata kunci)</label>
                    <textarea
                        id="preview_p1"
                        rows="8"
                        readonly
                        class="w-full p-3 border border-white/15 rounded-lg bg-[#181818] text-sm outline-none"
                        placeholder="Hasil akan muncul di sini..."></textarea>
                </div>
            </div>

            <!-- Submit Button -->
            <button type="submit" id="btnSubmit" class="w-full disabled:opacity-60 disabled:cursor-wait bg-[#0078D4] hover:bg-[#106EBE] text-white font-medium py-3.5 px-6 rounded-xl transition duration-200 shadow-md flex items-center justify-center gap-2">
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
            const re = /\{(?!paragraf:|judul:)([^{}]*\|[^{}]*)\}/i;
            let guard = 0;
            while (re.test(text) && guard++ < 1000) {
                text = text.replace(re, (_, g) => pick(g.split('|')).trim());
            }
            return text;
        }

        // Data master dari halaman Pengaturan
        const MASTER_TEMPLATES = <?= json_encode(array_column($master_templates, 'content', 'name'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_FORCE_OBJECT) ?>;
        const MASTER_TITLES = <?= json_encode(array_column($master_templates, 'title', 'name'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_FORCE_OBJECT) ?>;
        const MASTER_KEYS =<?= json_encode(array_column($master_shortkeys, 'value', 'name'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_FORCE_OBJECT) ?>;
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

            const list = getKeywords();

            $('preview_p1').value = (list.length ? list : [''])
                .map((kw) => { const lokasi = randomLocation(); const title = renderTitle(kw, lokasi); return (kw ? '[' + kw + ']\n' : '') + (title ? 'Judul: ' + title + '\n' : '') + render(template, { title: title.replace(TITLE_RE, '(judul AI)'), keyword: kw, lokasi }); })
                .join('\n\n');
        }

        // Minta Ollama menulis satu paragraf untuk posisi {paragraf} / {paragraf:instruksi}
        async function aiParagraph(params) {
            const res = await fetch('generate.php', { method: 'POST', body: new URLSearchParams(params) });
            const data = await res.json();
            if (!res.ok || data.error) throw new Error(data.error || 'HTTP ' + res.status);
            return data;
        }

        async function saveArticle(fields) {
            const res = await fetch('save_article.php', { method: 'POST', body: new URLSearchParams(fields) });
            const data = await res.json();
            if (!res.ok || data.error) throw new Error(data.error || 'HTTP ' + res.status);
            return data.id;
        }

        const LEAVES = <?= json_encode(array_map(fn($w) => [$w['provinsi'], $w['kota_kab'], $w['kecamatan'], $w['kelurahan']], $wilayah_rows), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;

        // Daftar kelurahan (satuan artikel) pada cakupan wilayah yang dipilih. "Tanpa target" = satu satuan kosong.
        function scopeUnits() {
            const [type, rest = ''] = $('target_wilayah').value.split(/:(.+)/);
            const p = rest.split('|');
            const test = {
                PROV: (l) => l[0] === p[0],
                KOTA: (l) => l[1] === p[0],
                KEC:  (l) => l[2] === p[0] && l[1] === p[1],
                KEL:  (l) => l[3] === p[0] && l[2] === p[1] && l[1] === p[2],
            }[type];
            const units = test ? LEAVES.filter(test).map((l) => ({ name: l[3], kec: l[2], kota: l[1] })) : [];
            return units.length ? units : [{ name: '', kec: '', kota: '' }];
        }

        const PARA_RE = /\{paragraf(?::([^{}]*))?\}/gi;
        const TITLE_RE = /\{judul(?::([^{}]*))?\}/i;
        const SENT = '⟦JUDUL⟧';   // penanda {titles} di isi artikel; diganti judul final setelah judul AI jadi

        // Judul juga mendukung short key ({keyword}, {lokasi}, {a|b}, shortkey master). {judul} dibiarkan sebagai penanda untuk diisi AI.
        const renderTitle = (kw, lokasi) => render(getTitle(), { title: '', keyword: kw, lokasi });

        const tidyTitle = (t) => t.replace(/\s+/g, ' ').replace(/\s+([,.:!?])/g, '$1').replace(/^[\s:–-]+|[\s:–-]+$/g, '').trim();

        // Submit: setiap kombinasi kata kunci x wilayah = satu artikel. Tiap {paragraf} diisi Ollama (berbekal sumber web), lalu disimpan.
        async function generateFull(e) {
            e.preventDefault();
            const template = $('template_p1').value;
            if (!/\{paragraf(:[^{}]*)?\}/i.test(template)) {
                alert('Template belum memiliki {paragraf}. Tambahkan {paragraf} atau {paragraf: instruksi} di posisi yang ingin ditulis AI.');
                $('template_p1').focus();
                return;
            }
            if (!$('model').value) {
                alert('Model Ollama belum tersedia. Pastikan Ollama berjalan dan sudah ada model (ollama pull qwen2.5:3b).');
                return;
            }

            const keywords = getKeywords();
            const units = scopeUnits();
            let jobs = [];
            keywords.forEach((kw) => units.forEach((u) => jobs.push({ kw, u })));

            const max = Math.max(1, parseInt($('max_articles').value, 10) || 50);
            const all = jobs.length;
            let msg = 'Akan membuat ' + Math.min(all, max) + ' artikel (' + keywords.length + ' kata kunci × ' + units.length + ' wilayah).';
            if (all > max) { msg += '\n\nTotal kombinasi ' + all + ' melebihi batas ' + max + ', hanya ' + max + ' pertama yang diproses.'; jobs = jobs.slice(0, max); }
            if (jobs.length > 1 && !confirm(msg + '\n\nLanjutkan?')) return;

            // Tanggal publish artikel ke-i = awal + i x jarak (waktu setempat, format "YYYY-MM-DD HH:MM:00"); kosong bila tidak diisi
            const pad = (n) => String(n).padStart(2, '0');
            const publishAtFor = (idx) => {
                const v = $('pub_start').value;
                if (!v) return '';
                const d = new Date(v);
                d.setMinutes(d.getMinutes() + idx * (parseInt($('pub_gap').value, 10) || 0) * parseInt($('pub_unit').value, 10));
                return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':00';
            };

            const btn = $('btnSubmit');
            const label = btn.lastChild.textContent;
            const preview = $('preview_p1');
            const blocks = [];
            btn.disabled = true;
            preview.scrollIntoView({ behavior: 'smooth', block: 'center' });

            for (let i = 0; i < jobs.length; i++) {
                const kw = jobs[i].kw;
                const lokasi = jobs[i].u.name;
                const kota = jobs[i].u.kota;
                // Judul: bagian statis dari template judul; {judul} (bila ada) diisi AI setelah kerangka jadi
                const titleTpl = renderTitle(kw, lokasi);
                const hasAiTitle = TITLE_RE.test(titleTpl);
                const aiInstr = hasAiTitle ? ((titleTpl.match(TITLE_RE) || [])[1] || '').trim() : '';
                const fallbackFill = kw + (lokasi ? ' di ' + lokasi : '');
                let title = hasAiTitle ? tidyTitle(titleTpl.replace(TITLE_RE, fallbackFill)) : tidyTitle(titleTpl);   // sementara
                let titleNote = hasAiTitle ? ' (judul AI menyusul)' : '';
                const headOf = () => '[' + kw + (lokasi ? ' — ' + lokasi : '') + ']' + (title ? '\nJudul: ' + title + titleNote : '') + '\n';
                // split dengan grup tangkap: [teks0, instruksi1, teks1, instruksi2, teks2, ...]; {titles} masih berupa penanda SENT
                const parts = render(template, { title: SENT, keyword: kw, lokasi }).split(PARA_RE);
                const total = (parts.length - 1) / 2;
                let text = parts[0];
                let failed = 0;
                const sources = {};
                let outlineLine = '';
                const withTitle = (s) => s.split(SENT).join(title);
                const show2 = (tail) => { preview.value = blocks.concat(headOf() + outlineLine + withTitle(text) + (tail || '')).join('\n\n'); };

                // Langkah 1: rancang kerangka agar semua paragraf AI saling terhubung dan tidak berulang
                let outline = null;
                const slots = Array.from({ length: total }, (_, k) => (parts[2 * (k + 1) - 1] || '').trim());
                const skeleton = withTitle(parts.map((p, idx) => (idx % 2 ? '(BAGIAN ' + ((idx + 1) / 2) + ')' : p)).join('')).trim();
                btn.lastChild.textContent = ' Artikel ' + (i + 1) + '/' + jobs.length + ' (' + kw + (lokasi ? ', ' + lokasi : '') + ') - menyusun kerangka...';
                show2('(menyusun kerangka artikel...)');
                try {
                    outline = await aiParagraph({
                        action: 'outline', keyword: kw, title, lokasi, kota, skeleton,
                        slots: JSON.stringify(slots),
                        model: $('model').value, mode: $('mode').value,
                    });
                    (outline.sources || []).forEach((s) => { sources[s.url] = s; });
                } catch (err) {
                    outline = null;   // lanjut tanpa kerangka
                }
                if (outline) outlineLine = 'Tema: ' + outline.tema + '\nKerangka: ' + outline.pokok.map((p, k) => (k + 1) + ') ' + p).join(' | ') + '\n';

                // Langkah 2: judul AI, ditulis setelah kerangka agar selaras dengan isi
                if (hasAiTitle) {
                    btn.lastChild.textContent = ' Artikel ' + (i + 1) + '/' + jobs.length + ' (' + kw + (lokasi ? ', ' + lokasi : '') + ') - menulis judul...';
                    show2('(menulis judul...)');
                    const pos = titleTpl.search(TITLE_RE);
                    const tBefore = tidyTitle(titleTpl.slice(0, pos));
                    const tAfter = tidyTitle(titleTpl.slice(pos).replace(TITLE_RE, ''));
                    try {
                        const t = await aiParagraph({
                            action: 'title', keyword: kw, title: tidyTitle(titleTpl.replace(TITLE_RE, fallbackFill)), lokasi, kota,
                            title_before: tBefore, title_after: tAfter, instruction: aiInstr,
                            outline: outline ? JSON.stringify({ tema: outline.tema, pokok: outline.pokok }) : '',
                            model: $('model').value, mode: $('mode').value,
                        });
                        title = tidyTitle(titleTpl.replace(TITLE_RE, t.title));
                        titleNote = '';
                    } catch (err) {
                        titleNote = ' (judul AI gagal: ' + err.message + '; memakai kata kunci)';
                        failed++;
                    }
                }

                // Langkah 3: paragraf AI satu per satu
                for (let n = 1; n <= total; n++) {
                    btn.lastChild.textContent = ' Artikel ' + (i + 1) + '/' + jobs.length + ' (' + kw + (lokasi ? ', ' + lokasi : '') + ') - paragraf AI ' + n + '/' + total + '...';
                    show2('(menulis paragraf AI ' + n + '/' + total + '...)');

                    let para;
                    try {
                        const data = await aiParagraph({
                            keyword: kw, title, lokasi, kota,
                            instruction: (parts[2 * n - 1] || '').trim(),
                            outline: outline ? JSON.stringify({ tema: outline.tema, pokok: outline.pokok }) : '',
                            index: n, total,
                            before: withTitle(text).trim(),
                            after: withTitle(parts.slice(2 * n).map((p, idx) => (idx % 2 ? '(bagian lain)' : p)).join('')).trim(),
                            model: $('model').value,
                            mode: $('mode').value,
                        });
                        para = data.paragraph;
                        (data.sources || []).forEach((s) => { sources[s.url] = s; });
                    } catch (err) {
                        failed++;
                        para = '(gagal: ' + err.message + ')';
                    }
                    text += para + parts[2 * n];
                }
                text = withTitle(text);   // {titles} di isi artikel -> judul final

                // Simpan ke database
                let saved = '';
                try {
                    const id = await saveArticle({
                        title, keyword: kw, lokasi,
                        publish_at: publishAtFor(i), wp_target_status: $('wp_status').value,
                        wilayah_target: $('target_wilayah').value,
                        model: $('model').value, mode: $('mode').value,
                        template, content: text,
                        sources: JSON.stringify(Object.values(sources)),
                        status: failed ? 'partial' : 'ok',
                    });
                    saved = '\n\n✔ Tersimpan sebagai artikel #' + id + (publishAtFor(i) ? ' (publish: ' + publishAtFor(i) + ')' : '');
                    if ($('wp_auto').checked) {
                        btn.lastChild.textContent = ' Artikel ' + (i + 1) + '/' + jobs.length + ' - mengirim ke WordPress...';
                        try {
                            const res = await fetch('wp_publish.php', { method: 'POST', body: new URLSearchParams({ id }) });
                            const r = await res.json();
                            saved += r.ok ? '\n✔ WordPress: ' + r.message + (r.url ? ' → ' + r.url : '') : '\n✘ WordPress gagal: ' + r.message;
                        } catch (err) {
                            saved += '\n✘ WordPress gagal: ' + err.message;
                        }
                    }
                } catch (err) {
                    saved = '\n\n✘ Gagal menyimpan: ' + err.message;
                }

                text += saved;
                show2();
                blocks.push(headOf() + outlineLine + text);
            }

            btn.lastChild.textContent = label;
            btn.disabled = false;
        }
        // Muat template master ke kolom template (tetap bisa diedit di sini)
        $('tpl_select').addEventListener('change', (e) => {
            if (e.target.value === '') return;
            $('template_p1').value = MASTER_TEMPLATES[e.target.value];
            $('judul_artikel').value = MASTER_TITLES[e.target.value] || '';
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