<?php
require_once __DIR__ . '/../helpers.php';
requireLogin();
require_once __DIR__ . '/../database.php';

function scalar(mysqli $conn, string $sql): int
{
    $row = $conn->query($sql)->fetch_row();
    return (int)($row[0] ?? 0);
}

$s = [
    'customers' => scalar($conn, "SELECT COUNT(*) FROM customers"),
    'unpaid'    => scalar($conn, "SELECT COUNT(*) FROM tagihan WHERE status_pembayaran = 'belum_bayar'"),
    'overdue'   => scalar($conn, "SELECT COUNT(*) FROM tagihan WHERE status_pembayaran = 'belum_bayar' AND tanggal_jatuh_tempo < CURDATE()"),
    'paid'      => scalar($conn, "SELECT COUNT(*) FROM tagihan WHERE status_pembayaran = 'sudah_bayar'"),
    'pending'   => scalar($conn, "SELECT COUNT(*) FROM reminder_logs WHERE status = 'pending'"),
    'sent'      => scalar($conn, "SELECT COUNT(*) FROM reminder_logs WHERE status = 'terkirim'"),
    'failed'    => scalar($conn, "SELECT COUNT(*) FROM reminder_logs WHERE status = 'gagal'"),
];
$outstanding = (float)($conn->query("SELECT COALESCE(SUM(jumlah_tagihan),0) FROM tagihan WHERE status_pembayaran = 'belum_bayar'")->fetch_row()[0] ?? 0);

$noPhone = scalar($conn, "SELECT COUNT(*) FROM customers WHERE no_telepon IS NULL OR no_telepon = ''");

$soon = $conn->query("
    SELECT t.id, c.cid, c.nama, c.no_telepon, t.jumlah_tagihan, t.tanggal_jatuh_tempo
    FROM tagihan t JOIN customers c ON c.id = t.customer_id
    WHERE t.status_pembayaran = 'belum_bayar'
    ORDER BY t.tanggal_jatuh_tempo IS NULL, t.tanggal_jatuh_tempo ASC
    LIMIT 8
")->fetch_all(MYSQLI_ASSOC);

layout_start(
    'Dashboard', 'dashboard', 'WHATSAPP AUTOMATION', 'Dashboard',
    'Ringkasan customer, tagihan, dan reminder.',
    '<a href="import_customer.php" class="reminder-btn">↑ Import Customer</a>'
    . '<a href="import_unpaid.php" class="reminder-btn reminder-btn-primary">↑ Import Belum Bayar</a>'
);

$cards = [
    ['TOTAL CUSTOMER',  $s['customers'], 'Master data customer', '◎', ''],
    ['BELUM BAYAR',     $s['unpaid'],    rupiah($outstanding) . ' belum tertagih', '!', 'stat-warning'],
    ['LEWAT JATUH TEMPO', $s['overdue'], 'Tagihan belum bayar & lewat tempo', '×', 'stat-message'],
    ['SUDAH BAYAR',     $s['paid'],      'Tagihan lunas', '✓', 'stat-success'],
    ['REMINDER PENDING', $s['pending'],  'Menunggu dikirim', '◷', 'stat-warning'],
    ['REMINDER TERKIRIM', $s['sent'],    'Berhasil dikirim', '✓', 'stat-success'],
    ['REMINDER GAGAL',  $s['failed'],    'Gagal / dibatalkan', '×', 'stat-message'],
];
?>
<section class="reminder-stats">
    <?php foreach ($cards as [$label, $num, $desc, $icon, $cls]): ?>
        <div class="reminder-stat <?= e($cls) ?>">
            <div class="reminder-stat-top">
                <div class="reminder-stat-label-top"><?= e($label) ?></div>
                <div class="reminder-stat-icon"><?= $icon ?></div>
            </div>
            <div class="reminder-stat-number"><?= number_format($num) ?></div>
            <div class="reminder-stat-label"><?= e($desc) ?></div>
        </div>
    <?php endforeach; ?>
</section>

<?php if ($noPhone > 0): ?>
    <div class="app-flash warning">
        <?= number_format($noPhone) ?> customer belum punya nomor telepon dan tidak bisa menerima reminder.
        <a href="customers.php?phone=kosong">Lihat daftarnya</a>.
    </div>
<?php endif; ?>

<section class="reminder-card">
    <div class="reminder-card-header">
        <div>
            <h2>Tagihan Terdekat</h2>
            <p>8 tagihan belum bayar dengan jatuh tempo paling awal.</p>
        </div>
        <a href="unpaid.php" class="reminder-btn">Lihat semua</a>
    </div>

    <?php if (!$soon): ?>
        <div class="reminder-empty">
            <div class="reminder-empty-icon">◌</div>
            <h3>Belum ada tagihan belum bayar</h3>
            <p>Import Data Customer lebih dulu, lalu Import Customer Belum Bayar.</p>
        </div>
    <?php else: ?>
        <div class="reminder-table-wrapper">
            <table class="reminder-table">
                <thead><tr><th>Customer</th><th>No Telepon</th><th>Tagihan</th><th>Jatuh Tempo</th></tr></thead>
                <tbody>
                <?php foreach ($soon as $r): $late = $r['tanggal_jatuh_tempo'] && $r['tanggal_jatuh_tempo'] < date('Y-m-d'); ?>
                    <tr>
                        <td><div class="customer-name"><?= e($r['nama']) ?></div><div class="customer-cid"># <?= e($r['cid']) ?></div></td>
                        <td><div class="customer-phone"><?= e($r['no_telepon'] ?: '-') ?></div></td>
                        <td><div class="customer-amount"><?= e(rupiah($r['jumlah_tagihan'])) ?></div></td>
                        <td><div class="customer-date" <?= $late ? 'style="color:#dc2626;font-weight:600"' : '' ?>><?= e(tanggal($r['tanggal_jatuh_tempo'])) ?></div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php layout_end();
