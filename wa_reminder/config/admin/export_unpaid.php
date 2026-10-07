<?php
/** Export Data Belum Bayar. Pakai filter yang sama dengan halaman Customer Belum Bayar. */
require_once __DIR__ . '/../helpers.php';
requireLogin();
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/export_helper.php';

$search  = trim($_GET['search'] ?? '');
$cycle   = trim($_GET['cycle'] ?? '');
$from    = trim($_GET['date_from'] ?? '');
$to      = trim($_GET['date_to'] ?? '');
$payment = $_GET['payment'] ?? 'belum_bayar';
if (!in_array($payment, ['belum_bayar', 'sudah_bayar', 'semua'], true)) { $payment = 'belum_bayar'; }

$where = []; $params = []; $types = '';
if ($search !== '') { $where[] = '(c.cid LIKE ? OR c.nama LIKE ?)'; $k = '%' . $search . '%'; array_push($params, $k, $k); $types .= 'ss'; }
if ($cycle !== '')  { $where[] = 'c.penagihan_cycle = ?'; $params[] = $cycle; $types .= 's'; }
if ($payment !== 'semua') { $where[] = 't.status_pembayaran = ?'; $params[] = $payment; $types .= 's'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 't.tanggal_jatuh_tempo >= ?'; $params[] = $from; $types .= 's'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $where[] = 't.tanggal_jatuh_tempo <= ?'; $params[] = $to;   $types .= 's'; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$st = $conn->prepare("
    SELECT c.cid, c.nama, c.no_telepon, c.penagihan_cycle, t.jumlah_tagihan, t.tanggal_jatuh_tempo, t.status_pembayaran, t.paid_at
    FROM tagihan t JOIN customers c ON c.id = t.customer_id
    $whereSql
    ORDER BY t.tanggal_jatuh_tempo IS NULL, t.tanggal_jatuh_tempo ASC, c.nama ASC
");
bindParams($st, $types, $params);
$st->execute();
$res = $st->get_result();

$rows = (function () use ($res) {
    while ($r = $res->fetch_assoc()) {
        yield [$r['cid'], $r['nama'], (string)$r['no_telepon'], (string)$r['penagihan_cycle'],
               (float)$r['jumlah_tagihan'], (string)$r['tanggal_jatuh_tempo'],
               paymentLabel($r['status_pembayaran']), (string)$r['paid_at']];
    }
})();

export_xlsx(
    'data_belum_bayar_' . date('Ymd_His') . '.xlsx', 'Tagihan',
    ['CID', 'Nama', 'No Telepon', 'Penagihan Cycle', 'Tagihan', 'Jatuh Tempo', 'Status', 'Tanggal Bayar'],
    $rows, ['A', 'C', 'D', 'F'],
    ['A' => 14, 'B' => 28, 'C' => 18, 'D' => 18, 'E' => 16, 'F' => 14, 'G' => 14, 'H' => 20]
);
