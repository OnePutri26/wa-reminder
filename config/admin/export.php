<?php

session_start();

if (empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../database.php';

$autoload = __DIR__ . '/../../vendor/autoload.php';

if (!file_exists($autoload)) {
    die('PhpSpreadsheet belum terinstall. Jalankan: composer require phpoffice/phpspreadsheet');
}

require_once $autoload;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/*
|--------------------------------------------------------------------------
| Helper
|--------------------------------------------------------------------------
*/

function excelDate($value)
{
    if (empty($value) || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') {
        return '';
    }

    $time = strtotime($value);

    return $time !== false
        ? ExcelDate::PHPToExcel($time)
        : '';
}

function paymentLabel($status): string
{
    if ($status === 'sudah_bayar') {
        return 'Sudah Bayar';
    }

    return 'Belum Bayar';
}

function cycleLabel($cycle): string
{
    $map = [
        '15' => '15',
        '25' => '25',
        'c1' => 'Cycle 1',
        'c2' => 'Cycle 2',
        'c3' => 'Cycle 3',
        'c4' => 'Cycle 4',
    ];

    $cycle = (string) $cycle;

    return $map[$cycle] ?? $cycle;
}

/*
|--------------------------------------------------------------------------
| Ambil data customer
|--------------------------------------------------------------------------
|
| Catatan: nama kolom "last_sent_at" diasumsikan sebagai kolom
| "terakhir dikirim" di tabel customers. Ganti jika nama di
| database kamu berbeda.
|
*/

$colCheck = $conn->query("SHOW COLUMNS FROM customers LIKE 'last_sent_at'");

$hasLastSent = $colCheck && $colCheck->num_rows > 0;

// Jika kolom last_sent_at belum ada, kolom Terakhir Dikirim diexport kosong
$lastSentSelect = $hasLastSent
    ? 'last_sent_at'
    : 'NULL AS last_sent_at';

$result = $conn->query("
    SELECT
        id,
        cid,
        penagihan_cycle,
        nama,
        alamat,
        no_telepon,
        status_payment,
        amount,
        due_date,
        created_at,
        updated_at,
        {$lastSentSelect}
    FROM customers
    ORDER BY id ASC
");

if (!$result) {
    die('Gagal mengambil data customer: ' . $conn->error);
}

/*
|--------------------------------------------------------------------------
| Spreadsheet
|--------------------------------------------------------------------------
|
| Urutan & judul kolom sengaja sama dengan format import,
| sehingga file hasil export bisa langsung di-import kembali.
|
|  A  ID
|  B  CID
|  C  Penagihan Cycle
|  D  Nama
|  E  Alamat
|  F  No. Telepon
|  G  Status Payment
|  H  Amount
|  I  Jatuh Tempo
|  J  Dibuat
|  K  Diperbarui
|  L  Terakhir Dikirim
|
*/

$spreadsheet = new Spreadsheet();

$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Customers');

$headers = [
    'ID',
    'CID',
    'Penagihan Cycle',
    'Nama',
    'Alamat',
    'No. Telepon',
    'Status Payment',
    'Amount',
    'Jatuh Tempo',
    'Dibuat',
    'Diperbarui',
    'Terakhir Dikirim',
];

$sheet->fromArray($headers, null, 'A1');

$sheet->getStyle('A1:L1')->applyFromArray([
    'font' => [
        'bold'  => true,
        'color' => ['rgb' => 'FFFFFF'],
    ],
    'fill' => [
        'fillType'   => Fill::FILL_SOLID,
        'startColor' => ['rgb' => '1565C0'],
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical'   => Alignment::VERTICAL_CENTER,
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color'       => ['rgb' => 'D0D7DE'],
        ],
    ],
]);

$sheet->getRowDimension(1)->setRowHeight(25);

/*
|--------------------------------------------------------------------------
| Isi data
|--------------------------------------------------------------------------
*/

$row = 2;

while ($customer = $result->fetch_assoc()) {

    // A - ID
    $sheet->setCellValue('A' . $row, (int) $customer['id']);

    // B - CID (teks)
    $sheet->setCellValueExplicit(
        'B' . $row,
        (string) $customer['cid'],
        DataType::TYPE_STRING
    );

    // C - Penagihan Cycle (teks)
    $sheet->setCellValueExplicit(
        'C' . $row,
        cycleLabel($customer['penagihan_cycle']),
        DataType::TYPE_STRING
    );

    // D - Nama
    $sheet->setCellValueExplicit(
        'D' . $row,
        (string) $customer['nama'],
        DataType::TYPE_STRING
    );

    // E - Alamat
    $sheet->setCellValueExplicit(
        'E' . $row,
        (string) $customer['alamat'],
        DataType::TYPE_STRING
    );

    // F - No. Telepon (WAJIB teks supaya tidak menjadi 8.12E+11)
    $phone = preg_replace('/\D+/', '', trim((string) $customer['no_telepon']));

    $sheet->setCellValueExplicit(
        'F' . $row,
        $phone,
        DataType::TYPE_STRING
    );

    // G - Status Payment (Belum Bayar / Sudah Bayar)
    $sheet->setCellValueExplicit(
        'G' . $row,
        paymentLabel($customer['status_payment']),
        DataType::TYPE_STRING
    );

    // H - Amount (angka)
    $sheet->setCellValue('H' . $row, (float) $customer['amount']);

    // I - Jatuh Tempo (tanggal Excel)
    $sheet->setCellValue('I' . $row, excelDate($customer['due_date']));

    // J - Dibuat
    $sheet->setCellValue('J' . $row, excelDate($customer['created_at']));

    // K - Diperbarui
    $sheet->setCellValue('K' . $row, excelDate($customer['updated_at']));

    // L - Terakhir Dikirim
    $sheet->setCellValue('L' . $row, excelDate($customer['last_sent_at']));

    $row++;
}

$lastRow = max(2, $row - 1);

/*
|--------------------------------------------------------------------------
| Format kolom
|--------------------------------------------------------------------------
*/

$sheet->getStyle('F2:F' . $lastRow)
    ->getNumberFormat()
    ->setFormatCode('@');

$sheet->getStyle('H2:H' . $lastRow)
    ->getNumberFormat()
    ->setFormatCode('#,##0');

$sheet->getStyle('I2:I' . $lastRow)
    ->getNumberFormat()
    ->setFormatCode('dd-mm-yyyy');

$sheet->getStyle('J2:L' . $lastRow)
    ->getNumberFormat()
    ->setFormatCode('dd-mm-yyyy hh:mm:ss');

// Alignment
foreach (['A', 'B', 'C', 'G', 'I', 'J', 'K', 'L'] as $col) {
    $sheet->getStyle($col . '2:' . $col . $lastRow)
        ->getAlignment()
        ->setHorizontal(Alignment::HORIZONTAL_CENTER);
}

$sheet->getStyle('F2:F' . $lastRow)
    ->getAlignment()
    ->setHorizontal(Alignment::HORIZONTAL_LEFT);

$sheet->getStyle('H2:H' . $lastRow)
    ->getAlignment()
    ->setHorizontal(Alignment::HORIZONTAL_RIGHT);

// Border data
if ($row > 2) {

    $sheet->getStyle('A2:L' . $lastRow)
        ->getBorders()
        ->getAllBorders()
        ->setBorderStyle(Border::BORDER_THIN)
        ->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('D0D7DE'));
}

// Lebar kolom
$widths = [
    'A' => 10,
    'B' => 18,
    'C' => 18,
    'D' => 32,
    'E' => 50,
    'F' => 20,
    'G' => 16,
    'H' => 16,
    'I' => 16,
    'J' => 22,
    'K' => 22,
    'L' => 22,
];

foreach ($widths as $col => $width) {
    $sheet->getColumnDimension($col)->setWidth($width);
}

// Freeze header + filter
$sheet->freezePane('A2');
$sheet->setAutoFilter('A1:L' . $lastRow);

/*
|--------------------------------------------------------------------------
| Download
|--------------------------------------------------------------------------
*/

$filename = 'customer_' . date('Y-m-d_H-i-s') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Pragma: public');

$writer = new Xlsx($spreadsheet);

$writer->save('php://output');

exit;