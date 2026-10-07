<?php

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

session_start();

if (empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../database.php';

$autoload = __DIR__ . '/../../vendor/autoload.php';

$successCount = 0;
$updateCount  = 0;
$errorCount   = 0;
$errors       = [];
$imported     = false;
$warning      = '';

/*
|--------------------------------------------------------------------------
| Helper
|--------------------------------------------------------------------------
*/

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function normalizeHeader($value): string
{
    $value = trim((string)$value);
    $value = strtolower($value);

    return str_replace(
        [' ', '_', '-', '.', '/', '\\', '(', ')'],
        '',
        $value
    );
}

/*
|--------------------------------------------------------------------------
| Phone
|--------------------------------------------------------------------------
*/

function normalizePhone($phone): string
{
    if (is_string($phone) && preg_match('~[,;/\n]~', $phone)) {

        foreach (preg_split('~[,;/\n]+~', $phone) as $part) {

            $candidate = normalizeSinglePhone($part);

            if (strlen($candidate) >= 10 && strlen($candidate) <= 15) {
                return $candidate;
            }
        }
    }

    return normalizeSinglePhone($phone);
}

function normalizeSinglePhone($phone): string
{
    if (is_float($phone) || is_int($phone)) {
        $phone = sprintf('%.0f', $phone);
    }

    $phone = preg_replace('/\D+/', '', trim((string)$phone));

    if ($phone === '') {
        return '';
    }

    if (str_starts_with($phone, '0')) {
        return '62' . substr($phone, 1);
    }

    if (str_starts_with($phone, '62')) {
        return $phone;
    }

    if (str_starts_with($phone, '8')) {
        return '62' . $phone;
    }

    return $phone;
}

/*
|--------------------------------------------------------------------------
| Cycle
|--------------------------------------------------------------------------
|
| Format yang diterima:
|
| 15
| Cycle 15
| 25
| Cycle 25
| 1
| Cycle 1
| C1
| c1
| 2
| C2
| 3
| C3
| 4
| C4
|
| Penyimpanan:
|
| 15
| 25
| c1
| c2
| c3
| c4
|
*/

function normalizeCycle($value): ?string
{
    $original = trim((string)$value);

    if ($original === '') {
        return null;
    }

    $value = strtolower($original);

    // Normalisasi variasi "Cycle 1", "cycle-1", "C1", dll.
    $value = str_replace([' ', '-', '_'], '', $value);

    // Hilangkan prefix cycle / siklus / c
    $value = preg_replace('/^(cycle|siklus|c)/', '', $value);

    if ($value === '') {
        return null;
    }

    if (!is_numeric($value)) {
        return null;
    }

    $number = (int)$value;

    if ($number === 15) {
        return '15';
    }

    if ($number === 25) {
        return '25';
    }

    if ($number >= 1 && $number <= 4) {
        return 'c' . $number;
    }

    return null;
}

/*
|--------------------------------------------------------------------------
| Due Date berdasarkan Cycle
|--------------------------------------------------------------------------
|
| 15      -> tanggal 15
| 25      -> tanggal 25
| c1      -> tanggal 07
| c2      -> tanggal 15
| c3      -> tanggal 23
| c4      -> hari terakhir bulan
|
*/

function calculateDueDate(string $cycle): string
{
    $year  = (int)date('Y');
    $month = (int)date('m');

    switch ($cycle) {

        case '15':
            return sprintf(
                '%04d-%02d-15',
                $year,
                $month
            );

        case '25':
            return sprintf(
                '%04d-%02d-25',
                $year,
                $month
            );

        case 'c1':
            return sprintf(
                '%04d-%02d-07',
                $year,
                $month
            );

        case 'c2':
            return sprintf(
                '%04d-%02d-15',
                $year,
                $month
            );

        case 'c3':
            return sprintf(
                '%04d-%02d-23',
                $year,
                $month
            );

        case 'c4':
            return date(
                'Y-m-d',
                strtotime('last day of this month')
            );

        default:
            throw new InvalidArgumentException(
                'Cycle tidak valid: ' . $cycle
            );
    }
}

/*
|--------------------------------------------------------------------------
| Amount
|--------------------------------------------------------------------------
*/

function parseAmount($value): float
{
    if ($value === null || $value === '') {
        return 0;
    }

    $value = trim((string)$value);

    if (strpos($value, ',') !== false) {

        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);

    } else {

        if (!preg_match('/^\d+\.\d{1,2}$/', $value)) {
            $value = str_replace('.', '', $value);
        }
    }

    $value = preg_replace('/[^0-9.-]/', '', $value);

    return is_numeric($value) ? (float)$value : 0;
}

/*
|--------------------------------------------------------------------------
| Payment Status
|--------------------------------------------------------------------------
*/

function normalizePaymentStatus($value): ?string
{
    $value = strtolower(trim((string)$value));

    $value = str_replace(
        [' ', '-', '_'],
        '',
        $value
    );

    if (
        $value === '' ||
        $value === 'belumbayar' ||
        $value === 'belumlunas' ||
        $value === 'unpaid'
    ) {
        return 'belum_bayar';
    }

    if (
        $value === 'sudahbayar' ||
        $value === 'sudahdibayar' ||
        $value === 'paid' ||
        $value === 'lunas'
    ) {
        return 'sudah_bayar';
    }

    return null;
}

/*
|--------------------------------------------------------------------------
| Find Column
|--------------------------------------------------------------------------
*/

function findColumn(array $headers, array $aliases): ?int
{
    foreach ($aliases as $alias) {

        $target = normalizeHeader($alias);

        foreach ($headers as $index => $header) {

            if (normalizeHeader($header) === $target) {
                return $index;
            }
        }
    }

    return null;
}

/*
|--------------------------------------------------------------------------
| Process Import
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!isset($_FILES['import_file'])) {

        $warning = 'File import belum dipilih.';

    } elseif ($_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {

        $warning =
            'Upload file gagal. Kode error: ' .
            $_FILES['import_file']['error'];

    } else {

        $file = $_FILES['import_file'];

        $extension = strtolower(
            pathinfo($file['name'], PATHINFO_EXTENSION)
        );

        $allowed = ['xlsx', 'xls', 'csv'];

        if (!in_array($extension, $allowed, true)) {

            $warning =
                'Format file tidak didukung. ' .
                'Gunakan XLSX, XLS, atau CSV.';

        } elseif (!file_exists($autoload)) {

            $warning =
                'PhpSpreadsheet belum terinstall. ' .
                'File vendor/autoload.php tidak ditemukan.';

        } else {

            require_once $autoload;

            try {

                /*
                 * Reader
                 */

                $reader = IOFactory::createReaderForFile(
                    $file['tmp_name']
                );

                $reader->setReadDataOnly(true);

                $spreadsheet = $reader->load(
                    $file['tmp_name']
                );

                $sheet = $spreadsheet->getActiveSheet();

                $rows = $sheet->toArray(
                    null,
                    true,
                    true,
                    false
                );

                if (empty($rows)) {
                    throw new RuntimeException(
                        'File tidak berisi data.'
                    );
                }

                /*
                 * Header
                 */

                $headers = array_shift($rows);

                /*
                 * CID
                 */

                $cidColumn = findColumn($headers, [
                    'CID',
                    'No Ref',
                    'Ref No',
                    'Customer ID',
                    'ID Customer',
                    'Kode Customer',
                    'Kode Pelanggan'
                ]);

                /*
                 * Nama
                 */

                $nameColumn = findColumn($headers, [
                    'Nama',
                    'Name',
                    'Nama Customer',
                    'Nama Pelanggan',
                    'Full Name'
                ]);

                /*
                 * Cycle
                 */

                $cycleColumn = findColumn($headers, [
                    'Penagihan Cycle',
                    'Cycle Penagihan',
                    'Cycle Tagihan',
                    'Billing Cycle',
                    'Cycle'
                ]);

                /*
                 * Phone
                 */

                $phoneColumn = findColumn($headers, [
                    'No Telepon',
                    'No Telfon',
                    'No Tlfn',
                    'No Telp',
                    'Nomor Telepon',
                    'No HP',
                    'Nomor HP',
                    'Phone',
                    'Telephone',
                    'WhatsApp',
                    'No WhatsApp'
                ]);

                /*
                 * Address
                 */

                $addressColumn = findColumn($headers, [
                    'Alamat',
                    'Address',
                    'Alamat Customer',
                    'Alamat Pelanggan'
                ]);

                /*
                 * Payment
                 */

                $paymentColumn = findColumn($headers, [
                    'Status Payment',
                    'Status Pembayaran',
                    'Status Bayar',
                    'Payment Status'
                ]);

                /*
                 * Amount
                 */

                $amountColumn = findColumn($headers, [
                    'Amount',
                    'Nominal',
                    'Tagihan',
                    'Jumlah',
                    'Total'
                ]);

                /*
                 * Validasi kolom
                 *
                 * Sesuai kebutuhan sekarang:
                 * CID, Nama, Cycle wajib.
                 *
                 * No Telepon tidak diwajibkan di file,
                 * tetapi jika tidak ada maka disimpan kosong.
                 *
                 * Alamat juga opsional.
                 */

                $missing = [];

                if ($cidColumn === null) {
                    $missing[] = 'CID / No Ref';
                }

                if ($nameColumn === null) {
                    $missing[] = 'Nama';
                }

                if ($cycleColumn === null) {
                    $missing[] = 'Cycle';
                }

                if (!empty($missing)) {

                    throw new RuntimeException(
                        'Kolom wajib tidak ditemukan: ' .
                        implode(', ', $missing) .
                        '.'
                    );
                }

                /*
                 * Pastikan struktur database
                 */

                $requiredColumns = [
                    'cid',
                    'nama',
                    'no_telepon',
                    'alamat',
                    'penagihan_cycle',
                    'status_payment',
                    'amount',
                    'due_date',
                    'last_sent_at'
                ];

                $existingColumns = [];

                $columnResult = $conn->query(
                    "SHOW COLUMNS FROM customers"
                );

                if (!$columnResult) {

                    throw new RuntimeException(
                        'Tidak dapat membaca struktur tabel customers: ' .
                        $conn->error
                    );
                }

                while ($column = $columnResult->fetch_assoc()) {
                    $existingColumns[] = $column['Field'];
                }

                foreach ($requiredColumns as $column) {

                    if (!in_array($column, $existingColumns, true)) {

                        throw new RuntimeException(
                            "Kolom '{$column}' tidak ditemukan " .
                            "di tabel customers."
                        );
                    }
                }

                /*
                 * Prepare SQL
                 *
                 * cid UNIQUE.
                 *
                 * Jika CID baru:
                 * INSERT.
                 *
                 * Jika CID sudah ada:
                 * UPDATE data customer.
                 *
                 * last_sent_at TIDAK disentuh.
                 *
                 * Ini penting agar riwayat WA tidak hilang.
                 */

                $sql = "
                    INSERT INTO customers
                    (
                        cid,
                        nama,
                        no_telepon,
                        alamat,
                        penagihan_cycle,
                        status_payment,
                        amount,
                        due_date
                    )
                    VALUES
                    (
                        ?, ?, ?, ?, ?, ?, ?, ?
                    )
                    ON DUPLICATE KEY UPDATE
                        nama = VALUES(nama),
                        no_telepon = VALUES(no_telepon),
                        alamat = VALUES(alamat),
                        penagihan_cycle = VALUES(penagihan_cycle),
                        status_payment = VALUES(status_payment),
                        amount = VALUES(amount),
                        due_date = VALUES(due_date)
                ";

                $stmt = $conn->prepare($sql);

                if (!$stmt) {

                    throw new RuntimeException(
                        'Query import gagal disiapkan: ' .
                        $conn->error
                    );
                }

                /*
                 * Loop rows
                 */

                foreach ($rows as $rowIndex => $row) {

                    $excelRow = $rowIndex + 2;

                    /*
                     * Data dasar
                     */

                    $cid = trim(
                        (string)($row[$cidColumn] ?? '')
                    );

                    $name = trim(
                        (string)($row[$nameColumn] ?? '')
                    );

                    $rawCycle = trim(
                        (string)($row[$cycleColumn] ?? '')
                    );

                    /*
                     * Phone
                     */

                    $phone = '';

                    if ($phoneColumn !== null) {

                        $phone = normalizePhone(
                            $row[$phoneColumn] ?? ''
                        );
                    }

                    /*
                     * Address
                     */

                    $address = '';

                    if ($addressColumn !== null) {

                        $address = trim(
                            (string)($row[$addressColumn] ?? '')
                        );
                    }

                    /*
                     * Lewati baris kosong
                     */

                    if (
                        $cid === '' &&
                        $name === '' &&
                        $rawCycle === '' &&
                        $phone === '' &&
                        $address === ''
                    ) {
                        continue;
                    }

                    /*
                     * Validasi CID
                     */

                    if ($cid === '') {

                        $errors[] =
                            "Baris {$excelRow}: CID / No Ref kosong.";

                        $errorCount++;
                        continue;
                    }

                    /*
                     * Validasi Nama
                     */

                    if ($name === '') {

                        $errors[] =
                            "Baris {$excelRow}: Nama kosong.";

                        $errorCount++;
                        continue;
                    }

                    /*
                     * Validasi Cycle
                     */

                    if ($rawCycle === '') {

                        $errors[] =
                            "Baris {$excelRow}: Cycle kosong.";

                        $errorCount++;
                        continue;
                    }

                    $cycle = normalizeCycle($rawCycle);

                    if ($cycle === null) {

                        $errors[] =
                            "Baris {$excelRow}: Cycle '{$rawCycle}' " .
                            "tidak valid. Gunakan 15, 25, 1, 2, 3, 4, " .
                            "C1, C2, C3, C4, atau Cycle 1-4.";

                        $errorCount++;
                        continue;
                    }

                    /*
                     * Nomor telepon
                     *
                     * Karena database no_telepon NOT NULL,
                     * kalau Excel tidak punya kolom telepon,
                     * kita simpan string kosong.
                     *
                     * Namun jika kolom telepon tersedia dan diisi,
                     * validasi nomor tetap dilakukan.
                     */

                    if ($phoneColumn !== null && $phone !== '') {

                        if (
                            strlen($phone) < 10 ||
                            strlen($phone) > 15
                        ) {

                            $errors[] =
                                "Baris {$excelRow}: Nomor telepon " .
                                "tidak valid ({$phone}).";

                            $errorCount++;
                            continue;
                        }
                    }

                    /*
                     * Due Date
                     *
                     * SELALU dihitung berdasarkan Cycle.
                     *
                     * Excel tidak perlu memiliki kolom Due Date.
                     */

                    $dueDate = calculateDueDate($cycle);

                    /*
                     * Payment
                     */

                    $payment = 'belum_bayar';

                    if ($paymentColumn !== null) {

                        $parsedPayment =
                            normalizePaymentStatus(
                                $row[$paymentColumn] ?? ''
                            );

                        if ($parsedPayment !== null) {
                            $payment = $parsedPayment;
                        }
                    }

                    /*
                     * Amount
                     */

                    $amount = 0;

                    if ($amountColumn !== null) {

                        $amount = parseAmount(
                            $row[$amountColumn] ?? ''
                        );
                    }

                    /*
                     * Cek apakah CID sudah ada
                     *
                     * Hanya untuk menghitung statistik
                     * baru vs update.
                     */

                    $checkStmt = $conn->prepare(
                        "SELECT id
                         FROM customers
                         WHERE cid = ?
                         LIMIT 1"
                    );

                    if (!$checkStmt) {

                        throw new RuntimeException(
                            'Query pengecekan CID gagal: ' .
                            $conn->error
                        );
                    }

                    $checkStmt->bind_param(
                        's',
                        $cid
                    );

                    $checkStmt->execute();

                    $result = $checkStmt->get_result();

                    $exists = $result->num_rows > 0;

                    $checkStmt->close();

                    /*
                     * Execute INSERT / UPDATE
                     */

                    $stmt->bind_param(
                        'ssssssds',
                        $cid,
                        $name,
                        $phone,
                        $address,
                        $cycle,
                        $payment,
                        $amount,
                        $dueDate
                    );

                    if (!$stmt->execute()) {

                        $errors[] =
                            "Baris {$excelRow}: gagal menyimpan " .
                            "CID {$cid}. " .
                            $stmt->error;

                        $errorCount++;
                        continue;
                    }

                    /*
                     * Statistik
                     */

                    if ($exists) {
                        $updateCount++;
                    } else {
                        $successCount++;
                    }
                }

                $stmt->close();

                $imported = true;

            } catch (Throwable $e) {

                $warning =
                    'Import gagal: ' .
                    $e->getMessage();
            }
        }
    }
}

$totalProcessed =
    $successCount +
    $updateCount +
    $errorCount;

?>
<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Import Customer | WA Reminder</title>

    <link
        rel="preconnect"
        href="https://fonts.googleapis.com"
    >

    <link
        rel="preconnect"
        href="https://fonts.gstatic.com"
        crossorigin
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="assets/import.css?v=1.2"
    >

    <style>

        .import-result {
            margin-top: 20px;
        }

        .result-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 15px;
        }

        .result-card {
            padding: 15px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: rgba(255,255,255,.025);
        }

        .result-card small {
            display: block;
            color: var(--muted);
            font-size: 10px;
            margin-bottom: 7px;
        }

        .result-card strong {
            font-size: 22px;
        }

        .result-card.success strong {
            color: #4ade80;
        }

        .result-card.update strong {
            color: #60a5fa;
        }

        .result-card.error strong {
            color: #f87171;
        }

        .error-list {
            max-height: 250px;
            overflow-y: auto;
            padding: 14px 16px;
            border: 1px solid rgba(239,68,68,.15);
            border-radius: 12px;
            background: rgba(239,68,68,.05);
        }

        .error-list strong {
            display: block;
            margin-bottom: 9px;
            color: #fca5a5;
            font-size: 12px;
        }

        .error-list div {
            padding: 6px 0;
            color: #cbd5e1;
            font-size: 11px;
            line-height: 1.5;
            border-bottom: 1px solid rgba(255,255,255,.04);
        }

        .error-list div:last-child {
            border-bottom: 0;
        }

        .requirements {
            flex-wrap: wrap;
        }

        @media (max-width: 620px) {

            .result-grid {
                grid-template-columns: 1fr;
            }

        }

    </style>

</head>

<body>

<div class="background-glow glow-1"></div>
<div class="background-glow glow-2"></div>

<div class="page">

    <header class="topbar">

        <div class="brand">

            <div class="brand-icon">
                WA
            </div>

            <div>
                <strong>WA Reminder</strong>
                <span>Customer Management</span>
            </div>

        </div>

        <div class="top-actions">

            <a href="reminder.php" class="btn secondary">
                Reminder
            </a>

            <a href="history.php" class="btn secondary">
                History
            </a>

            <a href="logout.php" class="btn danger">
                Logout
            </a>

        </div>

    </header>


    <section class="hero">

        <div>

            <span class="eyebrow">
                CUSTOMER IMPORT
            </span>

            <h1>
                Import
                <span>Customer.</span>
            </h1>

            <p>
                Masukkan data customer dari file Excel atau CSV.
                Customer baru akan ditambahkan, sedangkan CID yang
                sudah ada akan diperbarui tanpa menghapus riwayat
                pengiriman WhatsApp.
            </p>

        </div>

        <div class="hero-stat">

            <div class="stat-card">

                <span class="stat-label">
                    STATUS
                </span>

                <strong>
                    <?= $imported
                        ? 'Import selesai'
                        : 'Siap menerima file'
                    ?>
                </strong>

                <small>
                    <?= $imported
                        ? number_format($totalProcessed) .
                          ' baris diproses'
                        : 'XLSX · XLS · CSV'
                    ?>
                </small>

            </div>

        </div>

    </section>


    <?php if ($imported): ?>

        <div class="alert success">

            <div class="alert-symbol">
                ✓
            </div>

            <div>

                <strong>
                    Import selesai
                </strong>

                <span>
                    <?= number_format($successCount) ?>
                    customer baru ditambahkan,
                    <?= number_format($updateCount) ?>
                    customer diperbarui,
                    <?= number_format($errorCount) ?>
                    baris gagal.
                </span>

            </div>

        </div>

    <?php endif; ?>


    <?php if ($warning !== ''): ?>

        <div class="alert error">

            <div class="alert-symbol">
                !
            </div>

            <div>

                <strong>
                    Import gagal
                </strong>

                <span>
                    <?= e($warning) ?>
                </span>

            </div>

        </div>

    <?php endif; ?>


    <div class="content-grid">


        <section class="panel upload-panel">

            <div class="panel-heading">

                <div>

                    <span class="section-label">
                        STEP 01
                    </span>

                    <h2>
                        Upload file customer
                    </h2>

                    <p>
                        CID, Nama, dan Cycle wajib tersedia.
                        No Telepon dan Alamat dapat digunakan
                        jika tersedia di file.
                    </p>

                </div>

                <div class="file-types">
                    XLSX <i>•</i> XLS <i>•</i> CSV
                </div>

            </div>


            <form
                method="POST"
                enctype="multipart/form-data"
                id="importForm"
            >

                <label
                    class="dropzone"
                    id="dropzone"
                    tabindex="0"
                >

                    <div class="upload-circle">

                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.7"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <path d="M12 16V4" />
                            <path d="M7 9l5-5 5 5" />
                            <path d="M5 20h14" />
                        </svg>

                    </div>

                    <h3>
                        Pilih file customer
                    </h3>

                    <p>
                        Drag & drop file di sini atau
                        gunakan tombol di bawah.
                    </p>

                    <span class="browse">
                        Pilih File
                    </span>

                    <input
                        type="file"
                        name="import_file"
                        id="importFile"
                        accept=".xlsx,.xls,.csv"
                        required
                    >

                    <div
                        class="file-name"
                        id="fileName"
                    >
                        Belum ada file dipilih
                    </div>

                </label>


                <div class="requirements">

                    <div>
                        <span>✓</span>
                        CID wajib
                    </div>

                    <div>
                        <span>✓</span>
                        Nama wajib
                    </div>

                    <div>
                        <span>✓</span>
                        Cycle wajib
                    </div>

                    <div>
                        <span>✓</span>
                        Due Date otomatis
                    </div>

                    <div>
                        <span>✓</span>
                        Last Sent tetap aman
                    </div>

                </div>


                <button
                    type="submit"
                    class="submit-button"
                    id="submitButton"
                >

                    <span
                        class="submit-icon"
                        id="submitIcon"
                    >
                        ↑
                    </span>

                    <span id="submitText">
                        Import Customer
                    </span>

                </button>


                <a
                    href="template.php"
                    class="template-link"
                >
                    ↓ Download Template Excel
                </a>

            </form>


            <?php if ($imported): ?>

                <div class="import-result">

                    <span class="section-label">
                        HASIL IMPORT
                    </span>

                    <div class="result-grid">

                        <div class="result-card success">
                            <small>CUSTOMER BARU</small>
                            <strong>
                                <?= number_format($successCount) ?>
                            </strong>
                        </div>

                        <div class="result-card update">
                            <small>DIPERBARUI</small>
                            <strong>
                                <?= number_format($updateCount) ?>
                            </strong>
                        </div>

                        <div class="result-card error">
                            <small>GAGAL</small>
                            <strong>
                                <?= number_format($errorCount) ?>
                            </strong>
                        </div>

                    </div>


                    <?php if (!empty($errors)): ?>

                        <div class="error-list">

                            <strong>
                                Detail baris yang gagal
                            </strong>

                            <?php foreach ($errors as $error): ?>

                                <div>
                                    <?= e($error) ?>
                                </div>

                            <?php endforeach; ?>

                        </div>

                    <?php endif; ?>

                </div>

            <?php endif; ?>

        </section>


        <aside class="panel guide-panel">

            <div class="panel-heading">

                <div>

                    <span class="section-label">
                        FORMAT
                    </span>

                    <h2>
                        Struktur file
                    </h2>

                    <p>
                        Sistem akan membaca nama header
                        secara fleksibel.
                    </p>

                </div>

            </div>


            <div class="format-card">

                <span>
                    KOLOM UTAMA
                </span>

                <code>
                    No Ref | Nama | Cycle
                </code>

            </div>


            <div class="example-card">

                <span>
                    CONTOH
                </span>

                <code>
                    S8002 | Dian Jaya Sari | 15
                </code>

            </div>


            <div class="rules">

                <h3>
                    Aturan Cycle
                </h3>

                <div class="rule">
                    <b>15</b>
                    <span>
                        Due Date tanggal <strong>15</strong>.
                    </span>
                </div>

                <div class="rule">
                    <b>25</b>
                    <span>
                        Due Date tanggal <strong>25</strong>.
                    </span>
                </div>

                <div class="rule">
                    <b>C1</b>
                    <span>
                        Tanggal <strong>01–07</strong>,
                        Due Date tanggal 07.
                    </span>
                </div>

                <div class="rule">
                    <b>C2</b>
                    <span>
                        Tanggal <strong>08–15</strong>,
                        Due Date tanggal 15.
                    </span>
                </div>

                <div class="rule">
                    <b>C3</b>
                    <span>
                        Tanggal <strong>16–23</strong>,
                        Due Date tanggal 23.
                    </span>
                </div>

                <div class="rule">
                    <b>C4</b>
                    <span>
                        Tanggal <strong>24–akhir bulan</strong>,
                        Due Date otomatis hari terakhir bulan.
                    </span>
                </div>

            </div>


            <div class="optional">

                <strong>
                    Kolom tambahan
                </strong>

                <code>
                    No Telepon · Alamat · Status Payment · Amount
                </code>

            </div>

        </aside>

    </div>


    <footer>

        <span>WA Reminder</span>

        <i></i>

        <span>Customer Import</span>

        <i></i>

        <span><?= date('Y') ?></span>

    </footer>

</div>


<script>

const fileInput =
    document.getElementById('importFile');

const fileName =
    document.getElementById('fileName');

const dropzone =
    document.getElementById('dropzone');

const form =
    document.getElementById('importForm');

const submitButton =
    document.getElementById('submitButton');

const submitText =
    document.getElementById('submitText');

const submitIcon =
    document.getElementById('submitIcon');


function showFile(file) {

    if (!file) {

        fileName.textContent =
            'Belum ada file dipilih';

        fileName.className =
            'file-name';

        return;
    }

    const allowed = [
        'xlsx',
        'xls',
        'csv'
    ];

    const extension =
        file.name
            .split('.')
            .pop()
            .toLowerCase();

    if (!allowed.includes(extension)) {

        fileName.textContent =
            'Format file tidak didukung';

        fileName.className =
            'file-name invalid';

        fileInput.value = '';

        return;
    }

    fileName.textContent =
        file.name;

    fileName.className =
        'file-name selected';
}


fileInput.addEventListener(
    'change',
    function () {
        showFile(this.files[0]);
    }
);


['dragenter', 'dragover'].forEach(
    function (eventName) {

        dropzone.addEventListener(
            eventName,
            function (event) {

                event.preventDefault();
                event.stopPropagation();

                dropzone.classList.add(
                    'dragging'
                );

            }
        );

    }
);


['dragleave', 'drop'].forEach(
    function (eventName) {

        dropzone.addEventListener(
            eventName,
            function (event) {

                event.preventDefault();
                event.stopPropagation();

                dropzone.classList.remove(
                    'dragging'
                );

            }
        );

    }
);


dropzone.addEventListener(
    'drop',
    function (event) {

        const files =
            event.dataTransfer.files;

        if (!files.length) {
            return;
        }

        fileInput.files = files;

        showFile(files[0]);

    }
);


form.addEventListener(
    'submit',
    function () {

        if (!fileInput.files.length) {
            return;
        }

        submitButton.disabled = true;

        submitText.textContent =
            'Sedang mengimport...';

        submitIcon.className =
            'spinner';

        submitIcon.textContent = '';

    }
);

</script>

</body>
</html>
