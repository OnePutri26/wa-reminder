<?php
require_once __DIR__ . '/../helpers.php';
requireLogin();
require_once __DIR__ . '/export_helper.php';

export_xlsx(
    'template_import_belum_bayar.xlsx', 'Template',
    ['CID', 'Nama', 'Tagihan', 'Jatuh Tempo'],
    [
        ['CUST001', 'Budi Santoso', 150000, date('Y-m-d', strtotime('+7 days'))],
        ['CUST003', 'Siti Aminah',  200000, date('Y-m-d', strtotime('+14 days'))],
    ],
    ['A', 'D'],
    ['A' => 14, 'B' => 28, 'C' => 14, 'D' => 14]
);
