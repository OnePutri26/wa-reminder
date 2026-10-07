<?php
/**
 * Helper bersama untuk semua halaman admin.
 *
 * customers = master data customer (CID, nama, alamat, no telepon, cycle)
 * tagihan   = kewajiban bayar per customer (nomor telepon diambil dari customers)
 */

/* ------------------------------------------------------------------ */
/* Umum                                                               */
/* ------------------------------------------------------------------ */

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function rupiah($amount): string
{
    return 'Rp ' . number_format((float)$amount, 0, ',', '.');
}

function tanggal($date): string
{
    $time = $date ? strtotime($date) : false;
    return $time ? date('d-m-Y', $time) : '-';
}

function tanggalWaktu($date): string
{
    $time = $date ? strtotime($date) : false;
    return $time ? date('d-m-Y H:i', $time) : '-';
}

function getInitials($name): string
{
    $parts = preg_split('/\s+/', trim((string)$name));
    if (!$parts || $parts[0] === '') {
        return '?';
    }
    $r = strtoupper(substr($parts[0], 0, 1));
    if (count($parts) > 1) {
        $r .= strtoupper(substr($parts[1], 0, 1));
    }
    return $r;
}

function requireLogin(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['admin_id'])) {
        header('Location: login.php');
        exit;
    }
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_valid(): bool
{
    return isset($_POST['csrf'], $_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], (string)$_POST['csrf']);
}

function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = ['msg' => $message, 'type' => $type];
}

function bindParams(mysqli_stmt $stmt, string $types, array &$params): void
{
    if ($types === '' || empty($params)) {
        return;
    }
    $bind = [$types];
    foreach ($params as $i => $v) {
        $bind[] = &$params[$i];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
}

function pageUrl(int $page): string
{
    $q = $_GET;
    $q['page'] = $page;
    return basename($_SERVER['PHP_SELF']) . '?' . http_build_query($q);
}

/** Kembalikan array id (int > 0, unik) dari input POST berupa array/skalar. */
function postIds(string $key): array
{
    $ids = [];
    foreach ((array)($_POST[$key] ?? []) as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    return array_values($ids);
}

/* ------------------------------------------------------------------ */
/* Normalisasi data import                                            */
/* ------------------------------------------------------------------ */

function normalizeHeader($value): string
{
    $value = strtolower(trim((string)$value));
    return str_replace([' ', '_', '-', '.', '/', '\\', '(', ')'], '', $value);
}

/** Cari index kolom dari daftar alias header. */
function findColumn(array $headers, array $aliases): ?int
{
    foreach ($aliases as $alias) {
        $target = normalizeHeader($alias);
        foreach ($headers as $index => $header) {
            if (normalizeHeader($header) === $target) {
                return $index;
            }
        }
    }
    return null;
}

/**
 * Nomor telepon -> format lokal 08xxxxxxxxxx.
 *   628123456789     -> 08123456789
 *   +62 812-3456-789 -> 08123456789
 *   8123456789       -> 08123456789
 * Return '' kalau tidak ada angka.
 */
function normalizePhone($phone): string
{
    if (is_string($phone) && preg_match('~[,;/\n]~', $phone)) {
        foreach (preg_split('~[,;/\n]+~', $phone) as $part) {
            $c = normalizeSinglePhone($part);
            if (strlen($c) >= 9 && strlen($c) <= 14) {
                return $c;
            }
        }
    }
    return normalizeSinglePhone($phone);
}

function normalizeSinglePhone($phone): string
{
    if (is_float($phone) || is_int($phone)) {
        $phone = sprintf('%.0f', $phone);
    }
    $phone = preg_replace('/\D+/', '', trim((string)$phone));

    if ($phone === '') {
        return '';
    }
    if (str_starts_with($phone, '62')) {
        return '0' . substr($phone, 2);
    }
    if (str_starts_with($phone, '0')) {
        return $phone;
    }
    return '0' . $phone;
}

/** Nomor lokal (08xxx) -> format API WhatsApp (628xxx). */
function toWhatsappNumber(string $phone): string
{
    $phone = preg_replace('/\D+/', '', $phone);

    if (str_starts_with($phone, '0')) {
        return '62' . substr($phone, 1);
    }
    if (str_starts_with($phone, '62')) {
        return $phone;
    }
    if (str_starts_with($phone, '8')) {
        return '62' . $phone;
    }
    return $phone;
}

/**
 * Penagihan Cycle: teks dirapikan. "Cycle 1", "cycle1", "C1", "siklus 1" -> "Cycle 1".
 * Angka biasa (10, 15, 20) disimpan apa adanya. Kosong -> null.
 */
function normalizeCycle($value): ?string
{
    if (is_float($value) && floor($value) == $value) {
        $value = (int)$value;
    }
    $v = trim(preg_replace('/\s+/', ' ', (string)$value));

    if ($v === '' || strlen($v) > 30) {
        return null;
    }
    if (preg_match('/^(?:cycle|siklus|c)\s*[-:]?\s*(\d+)$/i', $v, $m)) {
        return 'Cycle ' . (int)$m[1];
    }
    return $v;
}

/** "150.000", "Rp 150.000,50", 150000 -> float. Return null kalau bukan angka. */
function parseAmount($value): ?float
{
    if (is_int($value) || is_float($value)) {
        return (float)$value;
    }
    $v = trim((string)$value);
    if ($v === '') {
        return null;
    }
    $v = preg_replace('/[^0-9.,-]/', '', $v);

    if (strpos($v, ',') !== false) {
        $v = str_replace('.', '', $v);
        $v = str_replace(',', '.', $v);
    } elseif (!preg_match('/^-?\d+\.\d{1,2}$/', $v)) {
        $v = str_replace('.', '', $v);
    }
    return is_numeric($v) ? (float)$v : null;
}

/** Tanggal dari Excel (serial/teks) -> 'Y-m-d' atau null. */
function parseDateValue($value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d');
    }
    if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
        $n = (float)$value;
        if ($n > 20000 && $n < 80000) { // serial date Excel
            return gmdate('Y-m-d', (int)round(($n - 25569) * 86400));
        }
        return null;
    }
    $v = trim((string)$value);
    foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'Y/m/d', 'd.m.Y'] as $fmt) {
        $d = DateTime::createFromFormat('!' . $fmt, $v);
        $err = DateTime::getLastErrors();
        if ($d && (!$err || ($err['warning_count'] === 0 && $err['error_count'] === 0))) {
            return $d->format('Y-m-d');
        }
    }
    return null;
}

/**
 * Baca file upload (xlsx/xls/csv) -> [headers, rows]. Lempar RuntimeException kalau gagal.
 */
function readUploadedSheet(array $file): array
{
    if (empty($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload file gagal atau file belum dipilih.');
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
        throw new RuntimeException('Format file tidak didukung. Gunakan XLSX, XLS, atau CSV.');
    }

    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoload)) {
        throw new RuntimeException('PhpSpreadsheet belum terinstall. Jalankan: composer install');
    }
    require_once $autoload;

    $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($file['tmp_name']);
    $reader->setReadDataOnly(true);
    $rows = $reader->load($file['tmp_name'])->getActiveSheet()->toArray(null, true, true, false);

    // buang baris yang benar-benar kosong
    $rows = array_values(array_filter($rows, function ($r) {
        foreach ($r as $cell) {
            if (trim((string)$cell) !== '') {
                return true;
            }
        }
        return false;
    }));

    if (count($rows) < 2) {
        throw new RuntimeException('File tidak berisi data.');
    }

    return [array_shift($rows), $rows];
}

/* ------------------------------------------------------------------ */
/* Tagihan & reminder                                                 */
/* ------------------------------------------------------------------ */

function paymentLabel(string $s): string
{
    return $s === 'sudah_bayar' ? 'Sudah Bayar' : 'Belum Bayar';
}

function reminderStatusClass($status): string
{
    return [
        'terkirim' => 'status-sent',
        'gagal'    => 'status-failed',
        'pending'  => 'status-pending',
    ][$status] ?? 'status-none';
}

function reminderStatusText($status): string
{
    return [
        'terkirim' => 'Terkirim',
        'gagal'    => 'Gagal',
        'pending'  => 'Menunggu',
    ][$status] ?? 'Belum Dikirim';
}

/**
 * Masukkan tagihan ke antrean reminder (reminder_logs, status pending).
 * Hanya tagihan 'belum_bayar', customer punya nomor telepon, dan belum
 * ada antrean pending untuk tagihan itu.
 * Return: [diantrekan, dilewati, tanpa_nomor]
 */
function queueReminders(mysqli $conn, array $tagihanIds, string $template = 'payment_reminder'): array
{
    $queued = $skipped = $noPhone = 0;

    $check = $conn->prepare("
        SELECT t.id, t.customer_id, c.no_telepon,
               EXISTS(SELECT 1 FROM reminder_logs rl
                      WHERE rl.tagihan_id = t.id AND rl.status = 'pending') AS has_pending
        FROM tagihan t
        JOIN customers c ON c.id = t.customer_id
        WHERE t.id = ? AND t.status_pembayaran = 'belum_bayar'
        LIMIT 1
    ");
    $insert = $conn->prepare("
        INSERT INTO reminder_logs (customer_id, tagihan_id, template_name, status)
        VALUES (?, ?, ?, 'pending')
    ");

    foreach ($tagihanIds as $id) {
        $check->bind_param('i', $id);
        $check->execute();
        $row = $check->get_result()->fetch_assoc();

        if (!$row || (int)$row['has_pending'] === 1) {
            $skipped++;
            continue;
        }
        if (trim((string)$row['no_telepon']) === '') {
            $noPhone++;
            continue;
        }

        $cid = (int)$row['customer_id'];
        $insert->bind_param('iis', $cid, $id, $template);
        $insert->execute();
        $queued++;
    }

    $check->close();
    $insert->close();

    return [$queued, $skipped, $noPhone];
}

/**
 * Ubah status pembayaran tagihan. Kalau jadi 'sudah_bayar', antrean reminder
 * pending untuk tagihan itu dibatalkan (status 'gagal' + keterangan).
 * Return: [tagihan_berubah, antrean_dibatalkan]
 */
function setTagihanStatus(mysqli $conn, array $ids, string $newStatus): array
{
    if (!in_array($newStatus, ['belum_bayar', 'sudah_bayar'], true)) {
        return [0, 0];
    }

    $changed = $cancelled = 0;
    $conn->begin_transaction();

    try {
        $upd = $conn->prepare("
            UPDATE tagihan
            SET status_pembayaran = ?,
                paid_at = IF(? = 'sudah_bayar', NOW(), NULL)
            WHERE id = ? AND status_pembayaran <> ?
        ");
        $cancel = $conn->prepare("
            UPDATE reminder_logs
            SET status = 'gagal', error_message = 'Dibatalkan: customer sudah bayar'
            WHERE tagihan_id = ? AND status = 'pending'
        ");

        foreach ($ids as $id) {
            $id = (int)$id;
            $upd->bind_param('ssis', $newStatus, $newStatus, $id, $newStatus);
            $upd->execute();
            $changed += $upd->affected_rows > 0 ? 1 : 0;

            if ($newStatus === 'sudah_bayar') {
                $cancel->bind_param('i', $id);
                $cancel->execute();
                $cancelled += $cancel->affected_rows;
            }
        }

        $upd->close();
        $cancel->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    return [$changed, $cancelled];
}

/**
 * Untuk worker pengirim: batalkan antrean tagihan yang sudah lunas,
 * lalu kembalikan antrean pending yang masih valid.
 * Nomor telepon SELALU diambil dari tabel customers.
 */
function getSendableReminders(mysqli $conn, int $limit = 50): array
{
    $conn->query("
        UPDATE reminder_logs rl
        JOIN tagihan t ON t.id = rl.tagihan_id
        SET rl.status = 'gagal', rl.error_message = 'Dibatalkan: customer sudah bayar'
        WHERE rl.status = 'pending' AND t.status_pembayaran = 'sudah_bayar'
    ");

    $stmt = $conn->prepare("
        SELECT rl.id AS log_id, rl.template_name,
               t.id AS tagihan_id, t.no_invoice, t.jumlah_tagihan, t.tanggal_jatuh_tempo,
               c.id AS customer_id, c.cid, c.nama, c.no_telepon, c.alamat
        FROM reminder_logs rl
        JOIN tagihan t   ON t.id = rl.tagihan_id
        JOIN customers c ON c.id = t.customer_id
        WHERE rl.status = 'pending'
          AND t.status_pembayaran = 'belum_bayar'
          AND c.no_telepon IS NOT NULL AND c.no_telepon <> ''
        ORDER BY rl.id ASC
        LIMIT ?
    ");
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

/* ------------------------------------------------------------------ */
/* Layout (sidebar + topbar) - tampilan sama dengan dashboard lama     */
/* ------------------------------------------------------------------ */

function layout_start(string $title, string $active, string $eyebrow, string $heading, string $subtitle = '', string $actionsHtml = ''): void
{
    $menu = [
        'DASHBOARD' => [
            ['dashboard', 'dashboard.php', '◉', 'Dashboard'],
        ],
        'CUSTOMER' => [
            ['customers',        'customers.php',        '◎', 'Data Customer'],
            ['import_customer',  'import_customer.php',  '↑', 'Import Data Customer'],
            ['export_customer',  'export_customer.php',  '↓', 'Export Data Customer'],
        ],
        'TAGIHAN' => [
            ['unpaid',           'unpaid.php',           '!', 'Customer Belum Bayar'],
            ['import_unpaid',    'import_unpaid.php',    '↑', 'Import Customer Belum Bayar'],
            ['payments',         'payments.php',         '✓', 'Riwayat Pembayaran'],
            ['export_unpaid',    'export_unpaid.php',    '↓', 'Export Data Belum Bayar'],
        ],
        'REMINDER' => [
            ['history',          'history.php',          '◷', 'Riwayat Reminder'],
        ],
        'AKUN' => [
            ['logout',           'logout.php',           '↪', 'Logout'],
        ],
    ];

    $adminName = $_SESSION['admin_name'] ?? ($_SESSION['username'] ?? 'Administrator');
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title) ?> | WA Reminder</title>
    <link rel="stylesheet" href="assets/reminder.css?v=9">
    <link rel="stylesheet" href="assets/app.css?v=1">
</head>
<body>
<div class="reminder-app">

    <aside class="reminder-sidebar">
        <div class="reminder-brand">
            <div class="reminder-brand-logo">WA</div>
            <div class="reminder-brand-text">
                <strong>WA Reminder</strong>
                <span>Admin Dashboard</span>
            </div>
        </div>

        <nav class="reminder-nav">
            <?php $first = true; foreach ($menu as $group => $items): ?>
                <div class="reminder-nav-title <?= $first ? '' : 'reminder-nav-title-space' ?>"><?= e($group) ?></div>
                <?php $first = false; foreach ($items as [$key, $href, $icon, $label]): ?>
                    <a href="<?= e($href) ?>" class="<?= $key === $active ? 'active' : '' ?>">
                        <span class="reminder-nav-icon"><?= $icon ?></span>
                        <span><?= e($label) ?></span>
                    </a>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </nav>

        <div class="reminder-sidebar-bottom">
            <div class="admin-mini-avatar"><?= e(getInitials($adminName)) ?></div>
            <div class="admin-mini-info">
                <strong><?= e($adminName) ?></strong>
                <span>Administrator</span>
            </div>
        </div>
    </aside>

    <main class="reminder-main">
        <header class="reminder-topbar">
            <div class="reminder-title">
                <div class="reminder-eyebrow"><?= e($eyebrow) ?></div>
                <h1><?= e($heading) ?></h1>
                <?php if ($subtitle !== ''): ?><p><?= e($subtitle) ?></p><?php endif; ?>
            </div>
            <?php if ($actionsHtml !== ''): ?>
                <div class="reminder-actions"><?= $actionsHtml ?></div>
            <?php endif; ?>
        </header>

        <?php if ($flash): ?>
            <div class="app-flash <?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div>
        <?php endif; ?>
<?php
}

function layout_end(): void
{
    ?>
        <footer class="reminder-footer">
            <span>WA Reminder</span>
            <span>&copy; <?= date('Y') ?> Admin System</span>
        </footer>
    </main>
</div>
</body>
</html>
<?php
}

/** Pagination dengan markup yang sama seperti dashboard lama. */
function render_pagination(int $page, int $totalPages): void
{
    if ($totalPages <= 1) {
        return;
    }
    $start = max(1, $page - 2);
    $end   = min($totalPages, $page + 2);
    echo '<div class="reminder-pagination">';
    if ($page > 1) {
        echo '<a href="' . e(pageUrl($page - 1)) . '">‹</a>';
    }
    if ($start > 1) {
        echo '<a href="' . e(pageUrl(1)) . '">1</a>';
        if ($start > 2) {
            echo '<span class="pagination-dots">…</span>';
        }
    }
    for ($i = $start; $i <= $end; $i++) {
        echo $i === $page
            ? '<span class="active">' . $i . '</span>'
            : '<a href="' . e(pageUrl($i)) . '">' . $i . '</a>';
    }
    if ($end < $totalPages) {
        if ($end < $totalPages - 1) {
            echo '<span class="pagination-dots">…</span>';
        }
        echo '<a href="' . e(pageUrl($totalPages)) . '">' . $totalPages . '</a>';
    }
    if ($page < $totalPages) {
        echo '<a href="' . e(pageUrl($page + 1)) . '">›</a>';
    }
    echo '</div>';
}
