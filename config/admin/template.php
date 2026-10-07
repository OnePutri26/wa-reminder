<?php

session_start();

if (empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$autoload = __DIR__ . '/../../vendor/autoload.php';

if (!file_exists($autoload)) {
    die('PhpSpreadsheet belum terinstall. Jalankan: composer install');
}

require_once $autoload;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Template');

$sheet->fromArray(
    ['CID', 'Nama', 'No Telepon', 'Status Payment', 'Amount', 'Due Date'],
    null,
    'A1'
);
$sheet->getStyle('A1:F1')->getFont()->setBold(true);

// Contoh baris (nomor telepon wajib disimpan sebagai teks)
$sheet->setCellValueExplicit('A2', 'CID-0001', DataType::TYPE_STRING);
$sheet->setCellValueExplicit('B2', 'Budi Santoso', DataType::TYPE_STRING);
$sheet->setCellValueExplicit('C2', '081234567890', DataType::TYPE_STRING);
$sheet->setCellValueExplicit('D2', 'belum_bayar', DataType::TYPE_STRING);
$sheet->setCellValue('E2', 150000);
$sheet->setCellValueExplicit('F2', date('Y-m-d', strtotime('+7 days')), DataType::TYPE_STRING);

$sheet->getStyle('C2:C1000')->getNumberFormat()->setFormatCode('@');

foreach (['A' => 14, 'B' => 28, 'C' => 18, 'D' => 16, 'E' => 14, 'F' => 14] as $col => $w) {
    $sheet->getColumnDimension($col)->setWidth($w);
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="template_import_customer.xlsx"');
header('Cache-Control: max-age=0');

(new Xlsx($spreadsheet))->save('php://output');
exit;
