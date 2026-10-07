<?php
require_once __DIR__ . '/../helpers.php';
requireLogin();
require_once __DIR__ . '/../database.php';

$search = trim($_GET['search'] ?? '');
$cycle  = trim($_GET['cycle'] ?? '');
$phone  = ($_GET['phone'] ?? '') === 'kosong' ? 'kosong' : '';
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$where = []; $params = []; $types = '';
if ($search !== '') {
    $where[] = '(c.cid LIKE ? OR c.nama LIKE ? OR c.no_telepon LIKE ?)';
    $k = '%' . $search . '%';
    array_push($params, $k, $k, $k);
    $types .= 'sss';
}
if ($cycle !== '') {
    $where[] = 'c.penagihan_cycle = ?';
    $params[] = $cycle;
    $types .= 's';
}
if ($phone === 'kosong') {
    $where[] = "(c.no_telepon IS NULL OR c.no_telepon = '')";
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$st = $conn->prepare("SELECT COUNT(*) FROM customers c $whereSql");
bindParams($st, $types, $params);
$st->execute();
$total = (int)$st->get_result()->fetch_row()[0];
$st->close();

$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$st = $conn->prepare("
    SELECT c.id, c.cid, c.nama, c.alamat, c.no_telepon, c.penagihan_cycle, c.updated_at,
           (SELECT COUNT(*) FROM tagihan t WHERE t.customer_id = c.id AND t.status_pembayaran = 'belum_bayar') AS belum_bayar
    FROM customers c $whereSql
    ORDER BY c.nama ASC
    LIMIT ? OFFSET ?
");
$p2 = array_merge($params, [$perPage, $offset]);
bindParams($st, $types . 'ii', $p2);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$cycles = $conn->query("SELECT DISTINCT penagihan_cycle FROM customers WHERE penagihan_cycle IS NOT NULL AND penagihan_cycle <> '' ORDER BY penagihan_cycle")->fetch_all(MYSQLI_NUM);
$hasFilter = $search !== '' || $cycle !== '' || $phone !== '';

layout_start(
    'Data Customer', 'customers', 'CUSTOMER', 'Data Customer',
    'Master data customer. Nomor telepon di sini dipakai oleh semua tagihan dan reminder.',
    '<a href="import_customer.php" class="reminder-btn">↑ Import</a>'
    . '<a href="export_customer.php" class="reminder-btn reminder-btn-primary">↓ Export</a>'
);
?>
<section class="reminder-card">
    <div class="reminder-filter">
        <form method="GET" action="customers.php" class="reminder-filter-form">
            <div class="reminder-field">
                <label for="search">Cari customer</label>
                <input type="search" id="search" name="search" value="<?= e($search) ?>" placeholder="CID, nama, atau nomor telepon...">
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
                <label for="phone">Nomor Telepon</label>
                <select id="phone" name="phone">
                    <option value="">Semua</option>
                    <option value="kosong" <?= $phone === 'kosong' ? 'selected' : '' ?>>Belum ada nomor</option>
                </select>
            </div>
            <div class="reminder-filter-actions">
                <button type="submit" class="reminder-filter-button">Cari Data</button>
                <?php if ($hasFilter): ?><a href="customers.php" class="reminder-reset">Reset</a><?php endif; ?>
            </div>
        </form>
    </div>

    <div class="reminder-card-header">
        <div>
            <h2>Daftar Customer</h2>
            <p>Menampilkan <strong><?= number_format($total) ?></strong> customer</p>
        </div>
        <div class="table-info">Halaman <?= $page ?> / <?= $totalPages ?></div>
    </div>

    <?php if (!$rows): ?>
        <div class="reminder-empty">
            <div class="reminder-empty-icon">◌</div>
            <h3>Data tidak ditemukan</h3>
            <p>Belum ada customer atau tidak ada yang sesuai filter. <a href="import_customer.php">Import Data Customer</a>.</p>
        </div>
    <?php else: ?>
        <div class="reminder-table-wrapper">
            <table class="reminder-table">
                <thead><tr><th>Customer</th><th>No Telepon</th><th>Cycle</th><th>Alamat</th><th>Tagihan Belum Bayar</th><th>Aksi</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><div class="customer-name"><?= e($r['nama']) ?></div><div class="customer-cid"># <?= e($r['cid']) ?></div></td>
                        <td><div class="customer-phone"><?= $r['no_telepon'] ? e($r['no_telepon']) : '<span class="muted-text">belum ada</span>' ?></div></td>
                        <td><div class="customer-date"><?= e($r['penagihan_cycle'] ?: '-') ?></div></td>
                        <td><div class="customer-date"><?= e($r['alamat'] ?: '-') ?></div></td>
                        <td>
                            <?php if ((int)$r['belum_bayar'] > 0): ?>
                                <a href="unpaid.php?search=<?= urlencode($r['cid']) ?>"><span class="reminder-status status-unpaid"><?= (int)$r['belum_bayar'] ?> tagihan</span></a>
                            <?php else: ?>
                                <span class="muted-text">-</span>
                            <?php endif; ?>
                        </td>
                        <td><a class="reminder-send small" href="customer_edit.php?id=<?= (int)$r['id'] ?>">Edit</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php render_pagination($page, $totalPages); ?>
    <?php endif; ?>
</section>
<?php layout_end();
