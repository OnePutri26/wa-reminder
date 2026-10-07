<?php
/**
 * Bantu export XLSX. Bukan halaman mandiri.
 * $textCols = huruf kolom yang wajib teks (CID, nomor telepon, tanggal ISO).
 */
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

function export_xlsx(string $filename, string $sheetTitle, array $headers, iterable $rows, array $textCols, array $widths): void
{
    $autoload = __DIR__ . '/../../vendor/autoload.php';
    if (!file_exists($autoload)) {
        die('PhpSpreadsheet belum terinstall. Jalankan: composer install');
    }
    require_once $autoload;

    $ss = new Spreadsheet();
    $sh = $ss->getActiveSheet();
    $sh->setTitle($sheetTitle);
    $sh->fromArray($headers, null, 'A1');

    $lastCol = chr(ord('A') + count($headers) - 1);
    $sh->getStyle("A1:{$lastCol}1")->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1565C0']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D0D7DE']]],
    ]);
    $sh->getRowDimension(1)->setRowHeight(25);

    $r = 2;
    foreach ($rows as $row) {
        $c = 'A';
        foreach ($row as $value) {
            if (in_array($c, $textCols, true) || (is_string($value) && !is_numeric($value))) {
                $sh->setCellValueExplicit($c . $r, (string)$value, DataType::TYPE_STRING);
            } else {
                $sh->setCellValue($c . $r, $value);
            }
            $c++;
        }
        $r++;
    }

    foreach ($widths as $col => $w) {
        $sh->getColumnDimension($col)->setWidth($w);
    }
    $sh->freezePane('A2');
    if ($r > 2) {
        $sh->setAutoFilter("A1:{$lastCol}" . ($r - 1));
    }

    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    (new Xlsx($ss))->save('php://output');
    exit;
}
