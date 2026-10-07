<?php
require_once __DIR__ . '/../helpers.php';
requireLogin();
require_once __DIR__ . '/../database.php';

$search = trim($_GET['search'] ?? '');
$cycle  = trim($_GET['cycle'] ?? '');
$from   = trim($_GET['date_from'] ?? '');
$to     = trim($_GET['date_to'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$where = ["t.status_pembayaran = 'sudah_bayar'"]; $params = []; $types = '';
if ($search !== '') {
    $where[] = '(c.cid LIKE ? OR c.nama LIKE ?)';
    $k = '%' . $search . '%';
    array_push($params, $k, $k); $types .= 'ss';
}
if ($cycle !== '') { $where[] = 'c.penagihan_cycle = ?'; $params[] = $cycle; $types .= 's'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 'DATE(t.paid_at) >= ?'; $params[] = $from; $types .= 's'; } else { $from = ''; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $where[] = 'DATE(t.paid_at) <= ?'; $params[] = $to;   $types .= 's'; } else { $to = ''; }
$whereSql = 'WHERE ' . implode(' AND ', $where);
$fromSql  = 'FROM tagihan t JOIN customers c ON c.id = t.customer_id';

$st = $conn->prepare("SELECT COUNT(*), COALESCE(SUM(t.jumlah_tagihan),0) $fromSql $whereSql");
bindParams($st, $types, $params);
$st->execute();
[$total, $sum] = $st->get_result()->fetch_row();
$total = (int)$total;
$st->close();

$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$st = $conn->prepare("
    SELECT t.id, t.jumlah_tagihan, t.tanggal_jatuh_tempo, t.paid_at, c.cid, c.nama, c.no_telepon, c.penagihan_cycle
    $fromSql $whereSql
    ORDER BY t.paid_at DESC, t.id DESC
    LIMIT ? OFFSET ?
");
$p2 = array_merge($params, [$perPage, $offset]);
bindParams($st, $types . 'ii', $p2);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$cycles = $conn->query("SELECT DISTINCT penagihan_cycle FROM customers WHERE penagihan_cycle IS NOT NULL AND penagihan_cycle <> '' ORDER BY penagihan_cycle")->fetch_all(MYSQLI_NUM);
$hasFilter = $search !== '' || $cycle !== '' || $from !== '' || $to !== '';
$back = basename($_SERVER['REQUEST_URI']);

layout_start('Riwayat Pembayaran', 'payments', 'TAGIHAN', 'Riwayat Pembayaran', 'Tagihan yang sudah ditandai lunas.');
?>
<section class="reminder-card">
    <div class="reminder-filter">
        <form method="GET" action="payments.php" class="reminder-filter-form">
            <div class="reminder-field"><label for="search">CID / Nama</label>
                <input type="search" id="search" name="search" value="<?= e($search) ?>" placeholder="CID atau nama customer..."></div>
            <div class="reminder-field"><label for="cycle">Penagihan Cycle</label>
                <select id="cycle" name="cycle"><option value="">Semua Cycle</option>
                    <?php foreach ($cycles as [$cy]): ?><option value="<?= e($cy) ?>" <?= $cycle === $cy ? 'selected' : '' ?>><?= e($cy) ?></option><?php endforeach; ?>
                </select></div>
            <div class="reminder-field"><label for="date_from">Dibayar dari</label>
                <input type="date" id="date_from" name="date_from" value="<?= e($from) ?>"></div>
            <div class="reminder-field"><label for="date_to">Sampai</label>
                <input type="date" id="date_to" name="date_to" value="<?= e($to) ?>"></div>
            <div class="reminder-filter-actions">
                <button type="submit" class="reminder-filter-button">Cari Data</button>
                <?php if ($hasFilter): ?><a href="payments.php" class="reminder-reset">Reset</a><?php endif; ?>
            </div>
        </form>
    </div>

    <div class="reminder-card-header">
        <div><h2>Pembayaran</h2>
            <p><strong><?= number_format($total) ?></strong> tagihan lunas · total <strong><?= e(rupiah($sum)) ?></strong></p></div>
        <div class="table-info">Halaman <?= $page ?> / <?= $totalPages ?></div>
    </div>

    <?php if (!$rows): ?>
        <div class="reminder-empty"><div class="reminder-empty-icon">◌</div><h3>Belum ada pembayaran</h3>
            <p>Tandai tagihan lunas dari halaman <a href="unpaid.php">Customer Belum Bayar</a>.</p></div>
    <?php else: ?>
        <div class="reminder-table-wrapper">
            <table class="reminder-table">
                <thead><tr><th>Customer</th><th>No Telepon</th><th>Tagihan</th><th>Jatuh Tempo</th><th>Dibayar</th><th>Aksi</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><div class="customer-name"><?= e($r['nama']) ?></div><div class="customer-cid"># <?= e($r['cid']) ?><?= $r['penagihan_cycle'] ? ' · Cycle ' . e($r['penagihan_cycle']) : '' ?></div></td>
                        <td><div class="customer-phone"><?= e($r['no_telepon'] ?: '-') ?></div></td>
                        <td><div class="customer-amount"><?= e(rupiah($r['jumlah_tagihan'])) ?></div></td>
                        <td><div class="customer-date"><?= e(tanggal($r['tanggal_jatuh_tempo'])) ?></div></td>
                        <td><span class="reminder-status status-sent"><?= e(tanggalWaktu($r['paid_at'])) ?></span></td>
                        <td>
                            <form method="POST" action="mark-paid.php" onsubmit="return confirm('Kembalikan ke Belum Bayar?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="ids[]" value="<?= (int)$r['id'] ?>">
                                <input type="hidden" name="status" value="belum_bayar">
                                <input type="hidden" name="back" value="<?= e($back) ?>">
                                <button type="submit" class="reminder-send small btn-gray">Batal Lunas</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php render_pagination($page, $totalPages); ?>
    <?php endif; ?>
</section>
<?php layout_end();
