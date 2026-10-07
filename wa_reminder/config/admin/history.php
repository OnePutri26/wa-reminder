<?php
require_once __DIR__ . '/../helpers.php';
requireLogin();
require_once __DIR__ . '/../database.php';

$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';
if (!in_array($status, ['pending', 'terkirim', 'gagal'], true)) { $status = ''; }
$from = trim($_GET['date_from'] ?? '');
$to   = trim($_GET['date_to'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$where = []; $params = []; $types = '';
if ($search !== '') {
    $where[] = '(c.cid LIKE ? OR c.nama LIKE ? OR c.no_telepon LIKE ?)';
    $k = '%' . $search . '%';
    array_push($params, $k, $k, $k); $types .= 'sss';
}
if ($status !== '') { $where[] = 'rl.status = ?'; $params[] = $status; $types .= 's'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 'DATE(rl.created_at) >= ?'; $params[] = $from; $types .= 's'; } else { $from = ''; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $where[] = 'DATE(rl.created_at) <= ?'; $params[] = $to;   $types .= 's'; } else { $to = ''; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$fromSql  = 'FROM reminder_logs rl JOIN customers c ON c.id = rl.customer_id LEFT JOIN tagihan t ON t.id = rl.tagihan_id';

$st = $conn->prepare("SELECT COUNT(*) $fromSql $whereSql");
bindParams($st, $types, $params);
$st->execute();
$total = (int)$st->get_result()->fetch_row()[0];
$st->close();

$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$st = $conn->prepare("
    SELECT rl.id, rl.status, rl.error_message, rl.sent_at, rl.created_at, rl.template_name,
           c.cid, c.nama, c.no_telepon, t.jumlah_tagihan, t.tanggal_jatuh_tempo, t.status_pembayaran
    $fromSql $whereSql
    ORDER BY rl.id DESC
    LIMIT ? OFFSET ?
");
$p2 = array_merge($params, [$perPage, $offset]);
bindParams($st, $types . 'ii', $p2);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$q = $conn->query("SELECT COUNT(*), SUM(status='pending'), SUM(status='terkirim'), SUM(status='gagal') FROM reminder_logs")->fetch_row();
$stat = ['total' => (int)$q[0], 'pending' => (int)$q[1], 'terkirim' => (int)$q[2], 'gagal' => (int)$q[3]];
$hasFilter = $search !== '' || $status !== '' || $from !== '' || $to !== '';

layout_start('Riwayat Reminder', 'history', 'REMINDER', 'Riwayat Reminder', 'Antrean dan hasil pengiriman reminder WhatsApp.');
?>
<section class="reminder-stats">
    <?php foreach ([
        ['TOTAL', $stat['total'], 'Seluruh reminder', '◎', ''],
        ['PENDING', $stat['pending'], 'Menunggu dikirim', '!', 'stat-warning'],
        ['TERKIRIM', $stat['terkirim'], 'Berhasil dikirim', '✓', 'stat-success'],
        ['GAGAL', $stat['gagal'], 'Gagal / dibatalkan', '×', 'stat-message'],
    ] as [$l, $n, $d, $i, $cls]): ?>
        <div class="reminder-stat <?= $cls ?>">
            <div class="reminder-stat-top"><div class="reminder-stat-label-top"><?= $l ?></div><div class="reminder-stat-icon"><?= $i ?></div></div>
            <div class="reminder-stat-number"><?= number_format($n) ?></div>
            <div class="reminder-stat-label"><?= $d ?></div>
        </div>
    <?php endforeach; ?>
</section>

<section class="reminder-card">
    <div class="reminder-filter">
        <form method="GET" action="history.php" class="reminder-filter-form">
            <div class="reminder-field"><label for="search">Cari customer</label>
                <input type="search" id="search" name="search" value="<?= e($search) ?>" placeholder="CID, nama, atau nomor telepon..."></div>
            <div class="reminder-field"><label for="status">Status Reminder</label>
                <select id="status" name="status">
                    <option value="">Semua Status</option>
                    <?php foreach (['pending' => 'Menunggu', 'terkirim' => 'Terkirim', 'gagal' => 'Gagal'] as $v => $l): ?>
                        <option value="<?= $v ?>" <?= $status === $v ? 'selected' : '' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="reminder-field"><label for="date_from">Dari tanggal</label>
                <input type="date" id="date_from" name="date_from" value="<?= e($from) ?>"></div>
            <div class="reminder-field"><label for="date_to">Sampai</label>
                <input type="date" id="date_to" name="date_to" value="<?= e($to) ?>"></div>
            <div class="reminder-filter-actions">
                <button type="submit" class="reminder-filter-button">Cari Data</button>
                <?php if ($hasFilter): ?><a href="history.php" class="reminder-reset">Reset</a><?php endif; ?>
            </div>
        </form>
    </div>

    <div class="reminder-card-header">
        <div><h2>Daftar Reminder</h2><p>Menampilkan <strong><?= number_format($total) ?></strong> data</p></div>
        <div class="table-info">Halaman <?= $page ?> / <?= $totalPages ?></div>
    </div>

    <?php if (!$rows): ?>
        <div class="reminder-empty"><div class="reminder-empty-icon">◌</div><h3>Belum ada riwayat</h3>
            <p>Kirim reminder dari halaman <a href="unpaid.php">Customer Belum Bayar</a>.</p></div>
    <?php else: ?>
        <div class="reminder-table-wrapper">
            <table class="reminder-table">
                <thead><tr><th>Dibuat</th><th>Customer</th><th>No Telepon</th><th>Tagihan</th><th>Status</th><th>Terkirim</th><th>Keterangan</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><div class="customer-date"><?= e(tanggalWaktu($r['created_at'])) ?></div></td>
                        <td><div class="customer-name"><?= e($r['nama']) ?></div><div class="customer-cid"># <?= e($r['cid']) ?></div></td>
                        <td><div class="customer-phone"><?= e($r['no_telepon'] ?: '-') ?></div></td>
                        <td>
                            <?php if ($r['jumlah_tagihan'] !== null): ?>
                                <div class="customer-amount"><?= e(rupiah($r['jumlah_tagihan'])) ?></div>
                                <div class="customer-date">Tempo <?= e(tanggal($r['tanggal_jatuh_tempo'])) ?> · <?= e(paymentLabel($r['status_pembayaran'])) ?></div>
                            <?php else: ?><span class="muted-text">-</span><?php endif; ?>
                        </td>
                        <td><span class="reminder-status <?= e(reminderStatusClass($r['status'])) ?>"><?= e(reminderStatusText($r['status'])) ?></span></td>
                        <td><div class="customer-date"><?= e(tanggalWaktu($r['sent_at'])) ?></div></td>
                        <td><div class="customer-date"><?= e($r['error_message'] ?: '-') ?></div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php render_pagination($page, $totalPages); ?>
    <?php endif; ?>
</section>
<?php layout_end();
