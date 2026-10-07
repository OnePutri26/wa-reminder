<?php
require_once __DIR__ . '/../helpers.php';
requireLogin();
require_once __DIR__ . '/export_helper.php';

// Wajib: REF_NO, NAMA, NO_TELP. Sisanya opsional. Nama kolom sama dengan export sistem akuntansi.
export_xlsx(
    'template_import_customer.xlsx', 'Template',
    ['REF_NO', 'NAMA', 'NO_TELP', 'PENAGIHAN_CYCLE', 'ALAMAT', 'EMAIL', 'PAKET'],
    [
        ['CG000010626', 'NICHOLAS THAN', '081188095623', 'Cycle 1', 'Apt City Garden Tower U Lt 3 Unit 30', 'nama@email.com', 'P0291'],
        ['CG000030826', 'Apriyani Sri lestari', '+62 819-0600-9261', 'Cycle 1', 'Apt City Garden Tower U Lt 2 Unit 57', '', 'P0390'],
    ],
    ['A', 'C', 'D', 'G'],
    ['A' => 16, 'B' => 28, 'C' => 20, 'D' => 18, 'E' => 40, 'F' => 26, 'G' => 10]
);
