<?php
/** Export Data Customer. Kolom A-D sama dengan format Import Data Customer. */
require_once __DIR__ . '/../helpers.php';
requireLogin();
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/export_helper.php';

$res = $conn->query("
    SELECT cid, nama, no_telepon, penagihan_cycle, alamat, created_at, updated_at
    FROM customers ORDER BY cid ASC
");

$rows = (function () use ($res) {
    while ($r = $res->fetch_assoc()) {
        yield [$r['cid'], $r['nama'], (string)$r['no_telepon'], (string)$r['penagihan_cycle'],
               (string)$r['alamat'], (string)$r['created_at'], (string)$r['updated_at']];
    }
})();

export_xlsx(
    'data_customer_' . date('Ymd_His') . '.xlsx', 'Customers',
    ['CID', 'Nama', 'No Telepon', 'Penagihan Cycle', 'Alamat', 'Dibuat', 'Diperbarui'],
    $rows, ['A', 'C', 'D'],
    ['A' => 14, 'B' => 28, 'C' => 18, 'D' => 18, 'E' => 36, 'F' => 20, 'G' => 20]
);
