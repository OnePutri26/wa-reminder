<?php
require_once __DIR__ . '/../helpers.php';
requireLogin();
require_once __DIR__ . '/export_helper.php';

export_xlsx(
    'template_import_customer.xlsx', 'Template',
    ['CID', 'Nama', 'No Telepon', 'Penagihan Cycle'],
    [
        ['CUST001', 'Budi Santoso', '081234567890', '10'],
        ['CUST002', 'Andi Wijaya',  '081298765432', '15'],
        ['CUST003', 'Siti Aminah',  '082112345678', '20'],
    ],
    ['A', 'C', 'D'],
    ['A' => 14, 'B' => 28, 'C' => 18, 'D' => 18]
);
