<?php
require_once __DIR__ . '/../helpers.php';
requireLogin();
require_once __DIR__ . '/export_helper.php';

// Subset kolom export invoice sistem akuntansi. Satu baris = satu invoice.
export_xlsx(
    'template_import_belum_bayar.xlsx', 'Template',
    ['NO_INV', 'NO_FAKTUR', 'TGL', 'TGL_JATUH_TEMPO', 'REF_NO_CUSTOMER', 'NAMA_CUSTOMER', 'VAT', 'CANCELED',
     'KODE_ITEM_1', 'QTY_1', 'VAT_1', 'HARGA_PER_QTY_1', 'DISKON_PER_QTY_1'],
    [
        ['026055-SAA-INV-10-26', '26055', '2026-10-01', '2026-10-07', 'CG000010626', 'NICHOLAS THAN', '12', '0', 'P0076', '1.00', '1', '200000.00', '0.00'],
        ['026062-SAA-INV-10-26', '26062', '2026-10-01', '2026-10-07', 'CG000030826', 'Apriyani Sri lestari', '12', '0', 'P0076', '1.00', '1', '300000.00', '0.00'],
    ],
    ['A', 'B', 'C', 'D', 'E', 'G', 'H', 'I', 'J', 'K', 'L', 'M'],
    ['A' => 24, 'B' => 12, 'C' => 12, 'D' => 16, 'E' => 16, 'F' => 26, 'G' => 6, 'H' => 10, 'I' => 12, 'J' => 8, 'K' => 8, 'L' => 18, 'M' => 18]
);
