<?php
/**
 * Tampilan bersama untuk halaman import (tema gelap import.css).
 * Bukan halaman mandiri: di-include oleh import_customer.php & import_unpaid.php.
 */
const IMPORT_ERR_LIMIT = 200; // maks. baris detail error yang ditampilkan

function render_import_page(array $c): void
{
    $r = $c['result'] ?? null;
    $warning = $c['warning'] ?? '';
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($c['title']) ?> | WA Reminder</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/import.css?v=1.2">
    <style>
        .import-result{margin-top:20px}
        .result-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px;margin-bottom:15px}
        .result-card{padding:15px;border:1px solid var(--border);border-radius:12px;background:rgba(255,255,255,.025)}
        .result-card small{display:block;color:var(--muted);font-size:10px;margin-bottom:7px}
        .result-card strong{font-size:22px}
        .result-card.success strong{color:#4ade80}
        .result-card.update strong{color:#60a5fa}
        .result-card.error strong{color:#f87171}
        .result-card.warn strong{color:#fbbf24}
        .error-list{max-height:250px;overflow-y:auto;padding:14px 16px;margin-bottom:12px;border:1px solid rgba(239,68,68,.15);border-radius:12px;background:rgba(239,68,68,.05)}
        .error-list.warn{border-color:rgba(251,191,36,.2);background:rgba(251,191,36,.05)}
        .error-list strong{display:block;margin-bottom:9px;color:#fca5a5;font-size:12px}
        .error-list.warn strong{color:#fcd34d}
        .error-list div{padding:6px 0;color:#cbd5e1;font-size:11px;line-height:1.5;border-bottom:1px solid rgba(255,255,255,.04)}
        .error-list div:last-child{border-bottom:0}
        .requirements{flex-wrap:wrap}
        .top-actions{flex-wrap:wrap}
    </style>
</head>
<body>
<div class="background-glow glow-1"></div>
<div class="background-glow glow-2"></div>

<div class="page">
    <header class="topbar">
        <div class="brand">
            <div class="brand-icon">WA</div>
            <div>
                <strong>WA Reminder</strong>
                <span><?= e($c['brandSub']) ?></span>
            </div>
        </div>
        <div class="top-actions">
            <a href="dashboard.php" class="btn secondary">Dashboard</a>
            <a href="customers.php" class="btn secondary">Data Customer</a>
            <a href="unpaid.php" class="btn secondary">Belum Bayar</a>
            <a href="logout.php" class="btn danger">Logout</a>
        </div>
    </header>

    <section class="hero">
        <div>
            <span class="eyebrow"><?= e($c['eyebrow']) ?></span>
            <h1><?= e($c['h1a']) ?> <span><?= e($c['h1b']) ?></span></h1>
            <p><?= e($c['intro']) ?></p>
        </div>
        <div class="hero-stat">
            <div class="stat-card">
                <span class="stat-label">STATUS</span>
                <strong><?= $r ? 'Import selesai' : 'Siap menerima file' ?></strong>
                <small><?= $r ? number_format($r['total']) . ' baris diproses' : 'XLSX · XLS · CSV' ?></small>
            </div>
        </div>
    </section>

    <?php if ($r): ?>
        <div class="alert success">
            <div class="alert-symbol">✓</div>
            <div>
                <strong>Import selesai</strong>
                <span><?= e($r['summary']) ?></span>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($warning !== ''): ?>
        <div class="alert error">
            <div class="alert-symbol">!</div>
            <div>
                <strong>Import gagal</strong>
                <span><?= e($warning) ?></span>
            </div>
        </div>
    <?php endif; ?>

    <div class="content-grid">
        <section class="panel upload-panel">
            <div class="panel-heading">
                <div>
                    <span class="section-label">STEP 01</span>
                    <h2><?= e($c['panelTitle']) ?></h2>
                    <p><?= e($c['panelDesc']) ?></p>
                </div>
                <div class="file-types">XLSX <i>•</i> XLS <i>•</i> CSV</div>
            </div>

            <form method="POST" enctype="multipart/form-data" id="importForm">
                <?= csrf_field() ?>
                <label class="dropzone" id="dropzone" tabindex="0">
                    <div class="upload-circle">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 16V4" /><path d="M7 9l5-5 5 5" /><path d="M5 20h14" />
                        </svg>
                    </div>
                    <h3><?= e($c['dropTitle']) ?></h3>
                    <p>Drag &amp; drop file di sini atau gunakan tombol di bawah.</p>
                    <span class="browse">Pilih File</span>
                    <input type="file" name="import_file" id="importFile" accept=".xlsx,.xls,.csv" required>
                    <div class="file-name" id="fileName">Belum ada file dipilih</div>
                </label>

                <div class="requirements">
                    <?php foreach ($c['requirements'] as $req): ?>
                        <div><span>✓</span> <?= e($req) ?></div>
                    <?php endforeach; ?>
                </div>

                <button type="submit" class="submit-button" id="submitButton">
                    <span class="submit-icon" id="submitIcon">↑</span>
                    <span id="submitText"><?= e($c['submitText']) ?></span>
                </button>

                <a href="<?= e($c['template']) ?>" class="template-link">↓ Download Template Excel</a>
            </form>

            <?php if ($r): ?>
                <div class="import-result">
                    <span class="section-label">HASIL IMPORT</span>

                    <div class="result-grid">
                        <?php foreach ($r['cards'] as [$label, $value, $class]): ?>
                            <div class="result-card <?= e($class) ?>">
                                <small><?= e($label) ?></small>
                                <strong><?= number_format($value) ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if (!empty($r['notFound'])): ?>
                        <div class="error-list warn">
                            <strong>CID tidak ditemukan di Data Customer (<?= number_format($r['notFoundTotal']) ?>)</strong>
                            <?php foreach ($r['notFound'] as $line): ?><div><?= e($line) ?></div><?php endforeach; ?>
                            <?php if ($r['notFoundTotal'] > count($r['notFound'])): ?>
                                <div>… dan <?= number_format($r['notFoundTotal'] - count($r['notFound'])) ?> lainnya</div>
                            <?php endif; ?>
                            <div>Masukkan customer tersebut lebih dulu lewat <a href="import_customer.php" style="color:#93c5fd">Import Data Customer</a>.</div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($r['duplicates'])): ?>
                        <div class="error-list warn">
                            <strong>Data duplikat (dilewati)</strong>
                            <?php foreach ($r['duplicates'] as $line): ?><div><?= e($line) ?></div><?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($r['errors'])): ?>
                        <div class="error-list">
                            <strong>Detail baris yang gagal</strong>
                            <?php foreach ($r['errors'] as $line): ?><div><?= e($line) ?></div><?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>

        <aside class="panel guide-panel">
            <div class="panel-heading">
                <div>
                    <span class="section-label">FORMAT</span>
                    <h2>Struktur file</h2>
                    <p>Nama header dibaca secara fleksibel (huruf besar/kecil dan spasi tidak masalah).</p>
                </div>
            </div>

            <div class="format-card">
                <span>KOLOM</span>
                <code><?= e($c['formatCode']) ?></code>
            </div>
            <div class="example-card">
                <span>CONTOH</span>
                <code><?= e($c['exampleCode']) ?></code>
            </div>

            <div class="rules">
                <h3>Aturan</h3>
                <?php foreach ($c['rules'] as [$b, $text]): ?>
                    <div class="rule"><b><?= e($b) ?></b><span><?= e($text) ?></span></div>
                <?php endforeach; ?>
            </div>
        </aside>
    </div>

    <footer>
        <span>WA Reminder</span><i></i><span><?= e($c['eyebrow']) ?></span><i></i><span><?= date('Y') ?></span>
    </footer>
</div>

<script>
const fileInput = document.getElementById('importFile');
const fileName = document.getElementById('fileName');
const dropzone = document.getElementById('dropzone');
const form = document.getElementById('importForm');
const submitButton = document.getElementById('submitButton');
const submitText = document.getElementById('submitText');
const submitIcon = document.getElementById('submitIcon');

function showFile(file) {
    if (!file) { fileName.textContent = 'Belum ada file dipilih'; fileName.className = 'file-name'; return; }
    const ext = file.name.split('.').pop().toLowerCase();
    if (!['xlsx', 'xls', 'csv'].includes(ext)) {
        fileName.textContent = 'Format file tidak didukung';
        fileName.className = 'file-name invalid';
        fileInput.value = '';
        return;
    }
    fileName.textContent = file.name;
    fileName.className = 'file-name selected';
}
fileInput.addEventListener('change', function () { showFile(this.files[0]); });
['dragenter', 'dragover'].forEach(function (n) {
    dropzone.addEventListener(n, function (ev) { ev.preventDefault(); ev.stopPropagation(); dropzone.classList.add('dragging'); });
});
['dragleave', 'drop'].forEach(function (n) {
    dropzone.addEventListener(n, function (ev) { ev.preventDefault(); ev.stopPropagation(); dropzone.classList.remove('dragging'); });
});
dropzone.addEventListener('drop', function (ev) {
    const files = ev.dataTransfer.files;
    if (!files.length) { return; }
    fileInput.files = files;
    showFile(files[0]);
});
form.addEventListener('submit', function () {
    if (!fileInput.files.length) { return; }
    submitButton.disabled = true;
    submitText.textContent = 'Sedang mengimport...';
    submitIcon.className = 'spinner';
    submitIcon.textContent = '';
});
</script>
</body>
</html>
<?php
}
