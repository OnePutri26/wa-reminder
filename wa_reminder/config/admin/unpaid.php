<?php
require_once __DIR__ . '/../helpers.php';
requireLogin();
require_once __DIR__ . '/../database.php';

$search  = trim($_GET['search'] ?? '');
$cycle   = trim($_GET['cycle'] ?? '');
$from    = trim($_GET['date_from'] ?? '');
$to      = trim($_GET['date_to'] ?? '');
$payment = $_GET['payment'] ?? 'belum_bayar';          // default: belum bayar saja
if (!in_array($payment, ['belum_bayar', 'sudah_bayar', 'semua'], true)) {
    $payment = 'belum_bayar';
}
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$where = []; $params = []; $types = '';
if ($search !== '') {
    $where[] = '(c.cid LIKE ? OR c.nama LIKE ? OR t.no_invoice LIKE ?)';
    $k = '%' . $search . '%';
    array_push($params, $k, $k, $k);
    $types .= 'sss';
}
if ($cycle !== '') {
    $where[] = 'c.penagihan_cycle = ?';
    $params[] = $cycle; $types .= 's';
}
if ($payment !== 'semua') {
    $where[] = 't.status_pembayaran = ?';
    $params[] = $payment; $types .= 's';
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $where[] = 't.tanggal_jatuh_tempo >= ?'; $params[] = $from; $types .= 's';
} else { $from = ''; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $where[] = 't.tanggal_jatuh_tempo <= ?'; $params[] = $to; $types .= 's';
} else { $to = ''; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$from_sql = 'FROM tagihan t JOIN customers c ON c.id = t.customer_id';

$st = $conn->prepare("SELECT COUNT(*), COALESCE(SUM(t.jumlah_tagihan),0) $from_sql $whereSql");
bindParams($st, $types, $params);
$st->execute();
[$total, $sum] = $st->get_result()->fetch_row();
$total = (int)$total;
$st->close();

$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$st = $conn->prepare("
    SELECT t.id, t.no_invoice, t.jumlah_tagihan, t.ppn, t.tanggal_jatuh_tempo, t.status_pembayaran,
           c.cid, c.nama, c.no_telepon, c.penagihan_cycle,
           (SELECT rl.status FROM reminder_logs rl WHERE rl.tagihan_id = t.id ORDER BY rl.id DESC LIMIT 1) AS last_status,
           (SELECT MAX(rl.sent_at) FROM reminder_logs rl WHERE rl.tagihan_id = t.id AND rl.status = 'terkirim') AS last_sent
    $from_sql $whereSql
    ORDER BY t.tanggal_jatuh_tempo IS NULL, t.tanggal_jatuh_tempo ASC, c.nama ASC
    LIMIT ? OFFSET ?
");
$p2 = array_merge($params, [$perPage, $offset]);
bindParams($st, $types . 'ii', $p2);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$cycles = $conn->query("SELECT DISTINCT penagihan_cycle FROM customers WHERE penagihan_cycle IS NOT NULL AND penagihan_cycle <> '' ORDER BY penagihan_cycle")->fetch_all(MYSQLI_NUM);
$hasFilter = $search !== '' || $cycle !== '' || $from !== '' || $to !== '' || $payment !== 'belum_bayar';
$back = basename($_SERVER['REQUEST_URI']);
$today = date('Y-m-d');

layout_start(
    'Customer Belum Bayar', 'unpaid', 'TAGIHAN', 'Customer Belum Bayar',
    'Nomor WhatsApp diambil otomatis dari Data Customer.',
    '<a href="import_unpaid.php" class="reminder-btn">↑ Import</a>'
    . '<a href="export_unpaid.php?' . e(http_build_query($_GET)) . '" class="reminder-btn reminder-btn-primary">↓ Export</a>'
);
?>
<section class="reminder-card">
    <div class="reminder-filter">
        <form method="GET" action="unpaid.php" class="reminder-filter-form">
            <div class="reminder-field">
                <label for="search">CID / Nama / No Invoice</label>
                <input type="search" id="search" name="search" value="<?= e($search) ?>" placeholder="CID, nama, atau no invoice...">
            </div>
            <div class="reminder-field">
                <label for="cycle">Penagihan Cycle</label>
                <select id="cycle" name="cycle">
                    <option value="">Semua Cycle</option>
                    <?php foreach ($cycles as [$cy]): ?>
                        <option value="<?= e($cy) ?>" <?= $cycle === $cy ? 'selected' : '' ?>><?= e($cy) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="reminder-field">
                <label for="payment">Status Pembayaran</label>
                <select id="payment" name="payment">
                    <option value="belum_bayar" <?= $payment === 'belum_bayar' ? 'selected' : '' ?>>Belum Bayar</option>
                    <option value="sudah_bayar" <?= $payment === 'sudah_bayar' ? 'selected' : '' ?>>Sudah Bayar</option>
                    <option value="semua" <?= $payment === 'semua' ? 'selected' : '' ?>>Semua</option>
                </select>
            </div>
            <div class="reminder-field">
                <label for="date_from">Jatuh tempo dari</label>
                <input type="date" id="date_from" name="date_from" value="<?= e($from) ?>">
            </div>
            <div class="reminder-field">
                <label for="date_to">Sampai</label>
                <input type="date" id="date_to" name="date_to" value="<?= e($to) ?>">
            </div>
            <div class="reminder-filter-actions">
                <button type="submit" class="reminder-filter-button">Cari Data</button>
                <?php if ($hasFilter): ?><a href="unpaid.php" class="reminder-reset">Reset</a><?php endif; ?>
            </div>
        </form>
    </div>

    <div class="reminder-card-header">
        <div>
            <h2>Daftar Tagihan</h2>
            <p>
                <strong><?= number_format($total) ?></strong> tagihan · total <strong><?= e(rupiah($sum)) ?></strong>
                <?= $payment === 'belum_bayar' ? '· Belum Bayar' : ($payment === 'sudah_bayar' ? '· Sudah Bayar' : '· Semua status') ?>
            </p>
        </div>
        <div class="table-info">Halaman <?= $page ?> / <?= $totalPages ?></div>
    </div>

    <?php if (!$rows): ?>
        <div class="reminder-empty">
            <div class="reminder-empty-icon">◌</div>
            <h3>Data tidak ditemukan</h3>
            <p>Tidak ada tagihan yang sesuai filter. <a href="import_unpaid.php">Import Customer Belum Bayar</a>.</p>
        </div>
    <?php else: ?>
        <form method="POST" action="send.php" id="bulkForm" class="bulk-bar"
              onsubmit="return confirm('Masukkan tagihan terpilih ke antrean reminder?');">
            <?= csrf_field() ?>
            <label><input type="checkbox" class="chk" id="selectAll"> Pilih semua di halaman ini</label>
            <button type="submit" class="reminder-send" id="bulkBtn" disabled>Kirim Reminder Terpilih (<span id="selCount">0</span>)</button>
        </form>

        <div class="reminder-table-wrapper">
            <table class="reminder-table">
                <thead><tr>
                    <th></th><th>Customer</th><th>No Telepon</th><th>Tagihan</th><th>Jatuh Tempo</th><th>Reminder</th><th>Aksi</th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $paid  = $r['status_pembayaran'] === 'sudah_bayar';
                    $late  = !$paid && $r['tanggal_jatuh_tempo'] && $r['tanggal_jatuh_tempo'] < $today;
                    $noTel = trim((string)$r['no_telepon']) === '';
                    $canSend = !$paid && !$noTel && $r['last_status'] !== 'pending';
                    $why = $paid ? 'Sudah bayar' : ($noTel ? 'Customer belum punya nomor telepon' : 'Sudah ada antrean menunggu');
                ?>
                    <tr>
                        <td><input type="checkbox" class="chk row-chk" name="tagihan_ids[]" value="<?= (int)$r['id'] ?>" form="bulkForm" <?= $canSend ? '' : 'disabled' ?>></td>
                        <td><div class="customer-name"><?= e($r['nama']) ?></div><div class="customer-cid"># <?= e($r['cid']) ?><?= $r['penagihan_cycle'] ? ' · ' . e($r['penagihan_cycle']) : '' ?></div><div class="customer-cid">INV <?= e($r['no_invoice']) ?></div></td>
                        <td><div class="customer-phone"><?= $noTel ? '<span class="muted-text">belum ada</span>' : e($r['no_telepon']) ?></div></td>
                        <td><div class="customer-amount"><?= e(rupiah($r['jumlah_tagihan'])) ?></div><?php if ((float)$r['ppn'] > 0): ?><div class="customer-date">incl. PPN <?= e(rupiah($r['ppn'])) ?></div><?php endif; ?></td>
                        <td>
                            <div class="customer-date" <?= $late ? 'style="color:#dc2626;font-weight:600"' : '' ?>><?= e(tanggal($r['tanggal_jatuh_tempo'])) ?></div>
                            <span class="reminder-status <?= $paid ? 'status-sent' : 'status-none' ?>" style="margin-top:4px"><?= e(paymentLabel($r['status_pembayaran'])) ?></span>
                        </td>
                        <td>
                            <span class="reminder-status <?= e(reminderStatusClass($r['last_status'])) ?>"><?= e(reminderStatusText($r['last_status'])) ?></span>
                            <?php if ($r['last_sent']): ?><div class="customer-date" style="margin-top:4px"><?= e(tanggalWaktu($r['last_sent'])) ?></div><?php endif; ?>
                        </td>
                        <td>
                            <div class="row-actions">
                                <form method="POST" action="send.php">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="tagihan_ids[]" value="<?= (int)$r['id'] ?>">
                                    <button type="submit" class="reminder-send small" <?= $canSend ? '' : 'disabled title="' . e($why) . '"' ?>>Kirim Reminder</button>
                                </form>
                                <form method="POST" action="mark-paid.php"
                                      onsubmit="return confirm('<?= $paid ? 'Kembalikan ke Belum Bayar?' : 'Tandai sudah bayar? Antrean reminder yang menunggu akan dibatalkan.' ?>');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="ids[]" value="<?= (int)$r['id'] ?>">
                                    <input type="hidden" name="status" value="<?= $paid ? 'belum_bayar' : 'sudah_bayar' ?>">
                                    <input type="hidden" name="back" value="<?= e($back) ?>">
                                    <button type="submit" class="reminder-send small <?= $paid ? 'btn-gray' : 'btn-green' ?>"><?= $paid ? 'Batal Lunas' : 'Tandai Lunas' ?></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php render_pagination($page, $totalPages); ?>
    <?php endif; ?>
</section>

<script>
(function () {
    var all = document.getElementById('selectAll');
    if (!all) { return; }
    var boxes = Array.prototype.slice.call(document.querySelectorAll('.row-chk:not(:disabled)'));
    var btn = document.getElementById('bulkBtn');
    var cnt = document.getElementById('selCount');
    function sync() {
        var n = boxes.filter(function (b) { return b.checked; }).length;
        cnt.textContent = n;
        btn.disabled = n === 0;
        all.checked = n > 0 && n === boxes.length;
    }
    all.addEventListener('change', function () { boxes.forEach(function (b) { b.checked = all.checked; }); sync(); });
    boxes.forEach(function (b) { b.addEventListener('change', sync); });
    sync();
})();
</script>
<?php layout_end();
