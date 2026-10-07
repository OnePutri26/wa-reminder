<?php
session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../database.php';

/*
|--------------------------------------------------------------------------
| Helper
|--------------------------------------------------------------------------
*/

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function bindParams(mysqli_stmt $stmt, string $types, array &$params): void
{
    if ($types === '' || empty($params)) {
        return;
    }

    $bind = [$types];

    foreach ($params as $key => $value) {
        $bind[] = &$params[$key];
    }

    call_user_func_array([$stmt, 'bind_param'], $bind);
}

function fmtDate(?string $date, string $format = 'd/m/Y H:i'): string
{
    if (!$date) {
        return '-';
    }

    $timestamp = strtotime($date);

    if ($timestamp === false) {
        return '-';
    }

    return date($format, $timestamp);
}

function paginationUrl(int $page): string
{
    $params = $_GET;
    $params['page'] = $page;

    return '?' . http_build_query($params);
}

function statusBadge(string $status): string
{
    switch ($status) {
        case 'pending':
            return '<span class="badge badge-warning">Pending</span>';

        case 'terkirim':
            return '<span class="badge badge-success">Terkirim</span>';

        case 'gagal':
            return '<span class="badge badge-danger">Gagal</span>';

        default:
            return '<span class="badge badge-secondary">' . e($status ?: '-') . '</span>';
    }
}

function paymentBadge(string $status): string
{
    switch ($status) {
        case 'belum_bayar':
            return '<span class="badge badge-danger">Belum Bayar</span>';

        case 'sudah_bayar':
            return '<span class="badge badge-success">Sudah Bayar</span>';

        default:
            return '<span class="badge badge-secondary">-</span>';
    }
}

function timeBadge(?string $date): string
{
    if (!$date) {
        return '<span class="muted">-</span>';
    }

    return '<span class="time">' . e(fmtDate($date)) . '</span>';
}

/*
|--------------------------------------------------------------------------
| Filter
|--------------------------------------------------------------------------
*/

$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');
$paymentStatus = trim($_GET['payment_status'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 20);

$allowedPerPage = [10, 20, 50, 100];

if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 20;
}

$offset = ($page - 1) * $perPage;

/*
|--------------------------------------------------------------------------
| Statistik
|--------------------------------------------------------------------------
|
| Statistik dibuat dari seluruh reminder_logs.
| Tidak dipengaruhi filter customer.
|
*/

$stats = [
    'total' => 0,
    'pending' => 0,
    'terkirim' => 0,
    'gagal' => 0
];

$statsQuery = $conn->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN status = 'terkirim' THEN 1 ELSE 0 END) AS terkirim,
        SUM(CASE WHEN status = 'gagal' THEN 1 ELSE 0 END) AS gagal
    FROM reminder_logs
");

if ($statsQuery) {
    $row = $statsQuery->fetch_assoc();

    if ($row) {
        $stats['total'] = (int)($row['total'] ?? 0);
        $stats['pending'] = (int)($row['pending'] ?? 0);
        $stats['terkirim'] = (int)($row['terkirim'] ?? 0);
        $stats['gagal'] = (int)($row['gagal'] ?? 0);
    }
}

$successRate = 0;

if ($stats['total'] > 0) {
    $successRate = round(
        ($stats['terkirim'] / $stats['total']) * 100,
        1
    );
}

/*
|--------------------------------------------------------------------------
| Build WHERE
|--------------------------------------------------------------------------
*/

$where = [];
$params = [];
$types = '';

/*
|--------------------------------------------------------------------------
| Search
|--------------------------------------------------------------------------
*/

if ($search !== '') {
    $where[] = "(
        c.cid LIKE ?
        OR c.nama LIKE ?
        OR c.no_telepon LIKE ?
        OR rl.template_name LIKE ?
        OR rl.message_id LIKE ?
    )";

    $searchValue = '%' . $search . '%';

    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;

    $types .= 'sssss';
}

/*
|--------------------------------------------------------------------------
| Status pengiriman
|--------------------------------------------------------------------------
*/

if (in_array($status, ['pending', 'terkirim', 'gagal'], true)) {
    $where[] = "rl.status = ?";
    $params[] = $status;
    $types .= 's';
}

/*
|--------------------------------------------------------------------------
| Status pembayaran customer
|--------------------------------------------------------------------------
|
| Ini adalah STATUS PEMBAYARAN SAAT INI.
|
| Contoh:
| Customer mengirim reminder tanggal 1.
| Tanggal 5 customer bayar.
| History tetap ada.
| Jika difilter "Sudah Bayar", reminder tanggal 1 tersebut
| tetap bisa muncul karena customer sekarang sudah bayar.
|
*/

if (in_array($paymentStatus, ['belum_bayar', 'sudah_bayar'], true)) {
    $where[] = "c.status_payment = ?";
    $params[] = $paymentStatus;
    $types .= 's';
}

/*
|--------------------------------------------------------------------------
| Date From
|--------------------------------------------------------------------------
*/

if ($dateFrom !== '') {
    $dateFromValid = DateTime::createFromFormat('Y-m-d', $dateFrom);

    if ($dateFromValid && $dateFromValid->format('Y-m-d') === $dateFrom) {
        $where[] = "rl.created_at >= ?";
        $params[] = $dateFrom . ' 00:00:00';
        $types .= 's';
    }
}

/*
|--------------------------------------------------------------------------
| Date To
|--------------------------------------------------------------------------
*/

if ($dateTo !== '') {
    $dateToValid = DateTime::createFromFormat('Y-m-d', $dateTo);

    if ($dateToValid && $dateToValid->format('Y-m-d') === $dateTo) {
        $where[] = "rl.created_at <= ?";
        $params[] = $dateTo . ' 23:59:59';
        $types .= 's';
    }
}

$whereSql = '';

if (!empty($where)) {
    $whereSql = 'WHERE ' . implode(' AND ', $where);
}

/*
|--------------------------------------------------------------------------
| Count Data
|--------------------------------------------------------------------------
*/

$countSql = "
    SELECT COUNT(*) AS total
    FROM reminder_logs rl
    INNER JOIN customers c
        ON c.id = rl.customer_id
    $whereSql
";

$countStmt = $conn->prepare($countSql);

if (!$countStmt) {
    die('Gagal menyiapkan query count: ' . $conn->error);
}

$countParams = $params;

if ($types !== '') {
    bindParams($countStmt, $types, $countParams);
}

$countStmt->execute();

$countResult = $countStmt->get_result();
$countRow = $countResult->fetch_assoc();

$totalRows = (int)($countRow['total'] ?? 0);

$countStmt->close();

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

$totalPages = max(1, (int)ceil($totalRows / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

/*
|--------------------------------------------------------------------------
| Ambil Data History
|--------------------------------------------------------------------------
*/

$dataSql = "
    SELECT
        rl.id,
        rl.customer_id,
        rl.template_name,
        rl.message_id,
        rl.status,
        rl.error_message,
        rl.sent_at,
        rl.delivered_at,
        rl.read_at,
        rl.created_at,

        c.cid,
        c.nama,
        c.no_telepon,
        c.status_payment,
        c.amount,
        c.due_date

    FROM reminder_logs rl

    INNER JOIN customers c
        ON c.id = rl.customer_id

    $whereSql

    ORDER BY rl.created_at DESC

    LIMIT ? OFFSET ?
";

$dataStmt = $conn->prepare($dataSql);

if (!$dataStmt) {
    die('Gagal menyiapkan query data: ' . $conn->error);
}

$dataParams = $params;
$dataTypes = $types . 'ii';

$dataParams[] = $perPage;
$dataParams[] = $offset;

bindParams($dataStmt, $dataTypes, $dataParams);

$dataStmt->execute();

$dataResult = $dataStmt->get_result();

$histories = [];

while ($row = $dataResult->fetch_assoc()) {
    $histories[] = $row;
}

$dataStmt->close();

/*
|--------------------------------------------------------------------------
| Pagination Range
|--------------------------------------------------------------------------
*/

$paginationStart = max(1, $page - 2);
$paginationEnd = min($totalPages, $page + 2);

?>
<!DOCTYPE html>
<html lang="id">
<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>History Reminder | WA Reminder</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family:
                Inter,
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                sans-serif;

            background: #f5f7fb;
            color: #172033;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .container {
            width: min(1500px, calc(100% - 40px));
            margin: 0 auto;
        }

        /*
        |--------------------------------------------------------------------------
        | Header
        |--------------------------------------------------------------------------
        */

        .topbar {
            background: #ffffff;
            border-bottom: 1px solid #e7eaf0;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .topbar-inner {
            min-height: 70px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .brand-icon {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: #2563eb;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 18px;
        }

        .brand-title {
            font-size: 17px;
            font-weight: 800;
        }

        .brand-subtitle {
            color: #7b8497;
            font-size: 12px;
            margin-top: 2px;
        }

        .nav {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .nav a {
            padding: 9px 13px;
            border-radius: 8px;
            color: #667085;
            font-size: 14px;
            font-weight: 600;
        }

        .nav a:hover {
            background: #f1f5ff;
            color: #2563eb;
        }

        .nav a.active {
            background: #eff6ff;
            color: #2563eb;
        }

        /*
        |--------------------------------------------------------------------------
        | Main
        |--------------------------------------------------------------------------
        */

        main {
            padding: 30px 0 50px;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 20px;
            margin-bottom: 25px;
        }

        .page-header h1 {
            margin: 0 0 7px;
            font-size: 27px;
            letter-spacing: -0.5px;
        }

        .page-header p {
            margin: 0;
            color: #737d91;
            font-size: 14px;
        }

        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #ffffff;
            border: 1px solid #e1e5ec;
            padding: 10px 15px;
            border-radius: 9px;
            font-size: 14px;
            font-weight: 600;
            color: #475467;
        }

        .back-button:hover {
            border-color: #2563eb;
            color: #2563eb;
        }

        /*
        |--------------------------------------------------------------------------
        | Stats
        |--------------------------------------------------------------------------
        */

        .stats-grid {
            display: grid;
            grid-template-columns:
                repeat(4, minmax(0, 1fr));

            gap: 16px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: #ffffff;
            border: 1px solid #e7eaf0;
            border-radius: 14px;
            padding: 20px;
        }

        .stat-label {
            color: #7b8497;
            font-size: 13px;
            margin-bottom: 10px;
        }

        .stat-value {
            font-size: 27px;
            font-weight: 800;
            letter-spacing: -0.5px;
        }

        .stat-footer {
            margin-top: 8px;
            font-size: 12px;
            color: #8b95a7;
        }

        /*
        |--------------------------------------------------------------------------
        | Filter
        |--------------------------------------------------------------------------
        */

        .filter-card {
            background: #ffffff;
            border: 1px solid #e7eaf0;
            border-radius: 14px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .filter-title {
            font-size: 15px;
            font-weight: 800;
            margin-bottom: 15px;
        }

        .filter-grid {
            display: grid;
            grid-template-columns:
                minmax(220px, 2fr)
                minmax(150px, 1fr)
                minmax(170px, 1fr)
                minmax(140px, 1fr)
                minmax(140px, 1fr)
                auto;

            gap: 10px;
            align-items: end;
        }

        .form-group label {
            display: block;
            margin-bottom: 7px;
            font-size: 12px;
            font-weight: 700;
            color: #667085;
        }

        .form-control {
            width: 100%;
            height: 40px;
            border: 1px solid #dfe3ea;
            border-radius: 8px;
            padding: 0 11px;
            background: #ffffff;
            color: #172033;
            outline: none;
            font-size: 13px;
        }

        .form-control:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, .08);
        }

        .button {
            height: 40px;
            border: 0;
            border-radius: 8px;
            padding: 0 16px;
            cursor: pointer;
            font-weight: 700;
            font-size: 13px;
        }

        .button-primary {
            background: #2563eb;
            color: #ffffff;
        }

        .button-primary:hover {
            background: #1d4ed8;
        }

        .button-secondary {
            background: #f1f3f7;
            color: #475467;
        }

        /*
        |--------------------------------------------------------------------------
        | Summary
        |--------------------------------------------------------------------------
        */

        .summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 12px;
            color: #667085;
            font-size: 13px;
        }

        .summary-row strong {
            color: #172033;
        }

        /*
        |--------------------------------------------------------------------------
        | Table
        |--------------------------------------------------------------------------
        */

        .table-card {
            background: #ffffff;
            border: 1px solid #e7eaf0;
            border-radius: 14px;
            overflow: hidden;
        }

        .table-wrap {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1150px;
        }

        th {
            background: #f8fafc;
            color: #667085;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .4px;
            text-align: left;
            padding: 13px 15px;
            border-bottom: 1px solid #e7eaf0;
            white-space: nowrap;
        }

        td {
            padding: 15px;
            border-bottom: 1px solid #edf0f4;
            vertical-align: middle;
            font-size: 13px;
        }

        tbody tr:hover {
            background: #fafcff;
        }

        tbody tr:last-child td {
            border-bottom: 0;
        }

        .customer {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .customer-name {
            font-weight: 750;
            color: #172033;
        }

        .customer-cid {
            color: #2563eb;
            font-size: 11px;
            font-weight: 700;
        }

        .phone {
            color: #667085;
            font-size: 12px;
        }

        .template {
            display: inline-block;
            background: #f1f5f9;
            color: #475467;
            padding: 5px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
        }

        .message-id {
            max-width: 180px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            color: #667085;
            font-family: monospace;
            font-size: 11px;
        }

        .time {
            white-space: nowrap;
            color: #667085;
            font-size: 12px;
        }

        .muted {
            color: #a0a7b4;
        }

        /*
        |--------------------------------------------------------------------------
        | Badge
        |--------------------------------------------------------------------------
        */

        .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 5px 8px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 800;
            white-space: nowrap;
        }

        .badge-warning {
            background: #fff7ed;
            color: #c2410c;
        }

        .badge-success {
            background: #ecfdf3;
            color: #15803d;
        }

        .badge-danger {
            background: #fef2f2;
            color: #dc2626;
        }

        .badge-secondary {
            background: #f1f3f5;
            color: #667085;
        }

        /*
        |--------------------------------------------------------------------------
        | Error
        |--------------------------------------------------------------------------
        */

        .error-text {
            color: #dc2626;
            font-size: 11px;
            max-width: 220px;
        }

        /*
        |--------------------------------------------------------------------------
        | Detail Button
        |--------------------------------------------------------------------------
        */

        .detail-button {
            border: 1px solid #dfe3ea;
            background: #ffffff;
            color: #475467;
            border-radius: 7px;
            padding: 7px 10px;
            cursor: pointer;
            font-size: 11px;
            font-weight: 700;
        }

        .detail-button:hover {
            color: #2563eb;
            border-color: #2563eb;
        }

        /*
        |--------------------------------------------------------------------------
        | Empty
        |--------------------------------------------------------------------------
        */

        .empty {
            padding: 70px 20px;
            text-align: center;
        }

        .empty-icon {
            width: 55px;
            height: 55px;
            margin: 0 auto 14px;
            border-radius: 14px;
            background: #eff6ff;
            color: #2563eb;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 23px;
        }

        .empty h3 {
            margin: 0 0 7px;
            font-size: 17px;
        }

        .empty p {
            margin: 0;
            color: #7b8497;
            font-size: 13px;
        }

        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 5px;
            padding: 18px;
            border-top: 1px solid #edf0f4;
        }

        .page-link {
            min-width: 34px;
            height: 34px;
            padding: 0 9px;
            display: inline-flex;
            justify-content: center;
            align-items: center;
            border: 1px solid #e1e5ec;
            border-radius: 7px;
            color: #667085;
            font-size: 12px;
            font-weight: 700;
            background: #ffffff;
        }

        .page-link:hover {
            border-color: #2563eb;
            color: #2563eb;
        }

        .page-link.active {
            background: #2563eb;
            color: #ffffff;
            border-color: #2563eb;
        }

        .page-link.disabled {
            opacity: .45;
            pointer-events: none;
        }

        /*
        |--------------------------------------------------------------------------
        | Modal
        |--------------------------------------------------------------------------
        */

        .modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, .55);
            z-index: 500;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal.show {
            display: flex;
        }

        .modal-box {
            width: min(650px, 100%);
            max-height: 90vh;
            overflow-y: auto;
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(15, 23, 42, .2);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px;
            border-bottom: 1px solid #edf0f4;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 17px;
        }

        .modal-close {
            border: 0;
            background: #f1f3f7;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 18px;
            color: #667085;
        }

        .modal-body {
            padding: 20px;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .detail-item {
            background: #f8fafc;
            border-radius: 9px;
            padding: 12px;
        }

        .detail-label {
            font-size: 10px;
            text-transform: uppercase;
            color: #8a94a6;
            font-weight: 800;
            letter-spacing: .4px;
            margin-bottom: 5px;
        }

        .detail-value {
            font-size: 13px;
            color: #172033;
            word-break: break-word;
        }

        .detail-full {
            grid-column: 1 / -1;
        }

        /*
        |--------------------------------------------------------------------------
        | Responsive
        |--------------------------------------------------------------------------
        */

        @media (max-width: 1100px) {

            .filter-grid {
                grid-template-columns:
                    repeat(2, minmax(0, 1fr));
            }

            .filter-grid .search-field {
                grid-column: 1 / -1;
            }

            .stats-grid {
                grid-template-columns:
                    repeat(2, minmax(0, 1fr));
            }

        }

        @media (max-width: 700px) {

            .container {
                width: min(100% - 24px, 1500px);
            }

            .topbar-inner {
                min-height: auto;
                padding: 12px 0;
                align-items: flex-start;
                flex-direction: column;
            }

            .nav {
                width: 100%;
                overflow-x: auto;
            }

            .page-header {
                flex-direction: column;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .filter-grid {
                grid-template-columns: 1fr;
            }

            .filter-grid .search-field {
                grid-column: auto;
            }

            .detail-grid {
                grid-template-columns: 1fr;
            }

            .detail-full {
                grid-column: auto;
            }

        }

    </style>

</head>

<body>

<header class="topbar">

    <div class="container topbar-inner">

        <div class="brand">

            <div class="brand-icon">
                WA
            </div>

            <div>
                <div class="brand-title">
                    WA Reminder
                </div>

                <div class="brand-subtitle">
                    Customer Billing Management
                </div>
            </div>

        </div>

        <nav class="nav">

            <a href="reminder.php">
                Reminder
            </a>

            <a href="send-bulk.php">
                Kirim Bulk
            </a>

            <a href="history.php" class="active">
                History
            </a>

        </nav>

    </div>

</header>


<main>

    <div class="container">

        <!-- HEADER -->

        <div class="page-header">

            <div>

                <h1>
                    History Reminder
                </h1>

                <p>
                    Riwayat seluruh pengiriman reminder WhatsApp.
                    Status pembayaran customer dapat berubah tanpa menghapus history.
                </p>

            </div>

            <a
                href="reminder.php"
                class="back-button"
            >
                ← Kembali ke Reminder
            </a>

        </div>


        <!-- STATISTICS -->

        <div class="stats-grid">

            <div class="stat-card">

                <div class="stat-label">
                    Total Reminder
                </div>

                <div class="stat-value">
                    <?= number_format($stats['total']) ?>
                </div>

                <div class="stat-footer">
                    Seluruh riwayat
                </div>

            </div>


            <div class="stat-card">

                <div class="stat-label">
                    Terkirim
                </div>

                <div class="stat-value">
                    <?= number_format($stats['terkirim']) ?>
                </div>

                <div class="stat-footer">
                    Success rate <?= e($successRate) ?>%
                </div>

            </div>


            <div class="stat-card">

                <div class="stat-label">
                    Pending
                </div>

                <div class="stat-value">
                    <?= number_format($stats['pending']) ?>
                </div>

                <div class="stat-footer">
                    Masih dalam antrean
                </div>

            </div>


            <div class="stat-card">

                <div class="stat-label">
                    Gagal
                </div>

                <div class="stat-value">
                    <?= number_format($stats['gagal']) ?>
                </div>

                <div class="stat-footer">
                    Perlu diperiksa
                </div>

            </div>

        </div>


        <!-- FILTER -->

        <div class="filter-card">

            <div class="filter-title">
                Filter History
            </div>

            <form method="GET">

                <div class="filter-grid">

                    <div class="form-group search-field">

                        <label>
                            Cari Customer
                        </label>

                        <input
                            type="text"
                            name="search"
                            class="form-control"
                            placeholder="CID, nama, nomor HP, template, message ID..."
                            value="<?= e($search) ?>"
                        >

                    </div>


                    <div class="form-group">

                        <label>
                            Status Pengiriman
                        </label>

                        <select
                            name="status"
                            class="form-control"
                        >

                            <option value="">
                                Semua Status
                            </option>

                            <option
                                value="pending"
                                <?= $status === 'pending' ? 'selected' : '' ?>
                            >
                                Pending
                            </option>

                            <option
                                value="terkirim"
                                <?= $status === 'terkirim' ? 'selected' : '' ?>
                            >
                                Terkirim
                            </option>

                            <option
                                value="gagal"
                                <?= $status === 'gagal' ? 'selected' : '' ?>
                            >
                                Gagal
                            </option>

                        </select>

                    </div>


                    <div class="form-group">

                        <label>
                            Status Pembayaran
                        </label>

                        <select
                            name="payment_status"
                            class="form-control"
                        >

                            <option value="">
                                Semua Customer
                            </option>

                            <option
                                value="belum_bayar"
                                <?= $paymentStatus === 'belum_bayar' ? 'selected' : '' ?>
                            >
                                Belum Bayar
                            </option>

                            <option
                                value="sudah_bayar"
                                <?= $paymentStatus === 'sudah_bayar' ? 'selected' : '' ?>
                            >
                                Sudah Bayar
                            </option>

                        </select>

                    </div>


                    <div class="form-group">

                        <label>
                            Dari Tanggal
                        </label>

                        <input
                            type="date"
                            name="date_from"
                            class="form-control"
                            value="<?= e($dateFrom) ?>"
                        >

                    </div>


                    <div class="form-group">

                        <label>
                            Sampai Tanggal
                        </label>

                        <input
                            type="date"
                            name="date_to"
                            class="form-control"
                            value="<?= e($dateTo) ?>"
                        >

                    </div>


                    <div class="form-group">

                        <button
                            type="submit"
                            class="button button-primary"
                        >
                            Filter
                        </button>

                    </div>

                </div>

            </form>

        </div>


        <!-- SUMMARY -->

        <div class="summary-row">

            <div>

                Menampilkan
                <strong>
                    <?= number_format(count($histories)) ?>
                </strong>
                dari
                <strong>
                    <?= number_format($totalRows) ?>
                </strong>
                history

            </div>


            <div>

                Per halaman:

                <select
                    onchange="changePerPage(this.value)"
                    class="form-control"
                    style="width:auto;display:inline-block;height:34px;margin-left:5px;"
                >

                    <?php foreach ($allowedPerPage as $option): ?>

                        <option
                            value="<?= $option ?>"
                            <?= $perPage === $option ? 'selected' : '' ?>
                        >
                            <?= $option ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

        </div>


        <!-- TABLE -->

        <div class="table-card">

            <?php if (empty($histories)): ?>

                <div class="empty">

                    <div class="empty-icon">
                        ✓
                    </div>

                    <h3>
                        Tidak ada history
                    </h3>

                    <p>
                        Belum ada reminder yang sesuai dengan filter yang dipilih.
                    </p>

                </div>

            <?php else: ?>

                <div class="table-wrap">

                    <table>

                        <thead>

                            <tr>

                                <th>
                                    Waktu
                                </th>

                                <th>
                                    Customer
                                </th>

                                <th>
                                    Telepon
                                </th>

                                <th>
                                    Pembayaran
                                </th>

                                <th>
                                    Template
                                </th>

                                <th>
                                    Status
                                </th>

                                <th>
                                    Terkirim
                                </th>

                                <th>
                                    Delivered
                                </th>

                                <th>
                                    Dibaca
                                </th>

                                <th>
                                    Aksi
                                </th>

                            </tr>

                        </thead>

                        <tbody>

                        <?php foreach ($histories as $history): ?>

                            <?php

                            $detailData = [
                                'id' => $history['id'],
                                'cid' => $history['cid'],
                                'nama' => $history['nama'],
                                'no_telepon' => $history['no_telepon'],
                                'status_payment' => $history['status_payment'],
                                'amount' => $history['amount'],
                                'due_date' => $history['due_date'],
                                'template_name' => $history['template_name'],
                                'message_id' => $history['message_id'],
                                'status' => $history['status'],
                                'error_message' => $history['error_message'],
                                'created_at' => $history['created_at'],
                                'sent_at' => $history['sent_at'],
                                'delivered_at' => $history['delivered_at'],
                                'read_at' => $history['read_at']
                            ];

                            ?>

                            <tr>

                                <td>
                                    <?= e(fmtDate($history['created_at'])) ?>
                                </td>


                                <td>

                                    <div class="customer">

                                        <div class="customer-name">
                                            <?= e($history['nama']) ?>
                                        </div>

                                        <div class="customer-cid">
                                            <?= e($history['cid']) ?>
                                        </div>

                                    </div>

                                </td>


                                <td>

                                    <div class="phone">
                                        <?= e($history['no_telepon'] ?: '-') ?>
                                    </div>

                                </td>


                                <td>

                                    <?= paymentBadge($history['status_payment']) ?>

                                </td>


                                <td>

                                    <span class="template">
                                        <?= e($history['template_name'] ?: '-') ?>
                                    </span>

                                </td>


                                <td>

                                    <?= statusBadge($history['status']) ?>

                                </td>


                                <td>

                                    <?= timeBadge($history['sent_at']) ?>

                                </td>


                                <td>

                                    <?= timeBadge($history['delivered_at']) ?>

                                </td>


                                <td>

                                    <?= timeBadge($history['read_at']) ?>

                                </td>


                                <td>

                                    <button
                                        type="button"
                                        class="detail-button"
                                        onclick='showDetail(<?= json_encode(
                                            $detailData,
                                            JSON_HEX_TAG |
                                            JSON_HEX_APOS |
                                            JSON_HEX_AMP |
                                            JSON_HEX_QUOT
                                        ) ?>)'
                                    >
                                        Detail
                                    </button>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>


                <!-- PAGINATION -->

                <?php if ($totalPages > 1): ?>

                    <div class="pagination">

                        <?php if ($page > 1): ?>

                            <a
                                href="<?= e(paginationUrl(1)) ?>"
                                class="page-link"
                            >
                                «
                            </a>

                            <a
                                href="<?= e(paginationUrl($page - 1)) ?>"
                                class="page-link"
                            >
                                ‹
                            </a>

                        <?php else: ?>

                            <span class="page-link disabled">
                                «
                            </span>

                            <span class="page-link disabled">
                                ‹
                            </span>

                        <?php endif; ?>


                        <?php for ($i = $paginationStart; $i <= $paginationEnd; $i++): ?>

                            <a
                                href="<?= e(paginationUrl($i)) ?>"
                                class="page-link <?= $i === $page ? 'active' : '' ?>"
                            >
                                <?= $i ?>
                            </a>

                        <?php endfor; ?>


                        <?php if ($page < $totalPages): ?>

                            <a
                                href="<?= e(paginationUrl($page + 1)) ?>"
                                class="page-link"
                            >
                                ›
                            </a>

                            <a
                                href="<?= e(paginationUrl($totalPages)) ?>"
                                class="page-link"
                            >
                                »
                            </a>

                        <?php else: ?>

                            <span class="page-link disabled">
                                ›
                            </span>

                            <span class="page-link disabled">
                                »
                            </span>

                        <?php endif; ?>

                    </div>

                <?php endif; ?>

            <?php endif; ?>

        </div>

    </div>

</main>


<!-- DETAIL MODAL -->

<div
    id="detailModal"
    class="modal"
    onclick="closeModalOutside(event)"
>

    <div class="modal-box">

        <div class="modal-header">

            <h3>
                Detail Reminder
            </h3>

            <button
                type="button"
                class="modal-close"
                onclick="closeDetail()"
            >
                ×
            </button>

        </div>


        <div class="modal-body">

            <div class="detail-grid">

                <div class="detail-item">

                    <div class="detail-label">
                        ID History
                    </div>

                    <div
                        class="detail-value"
                        id="detail_id"
                    >
                        -
                    </div>

                </div>


                <div class="detail-item">

                    <div class="detail-label">
                        CID
                    </div>

                    <div
                        class="detail-value"
                        id="detail_cid"
                    >
                        -
                    </div>

                </div>


                <div class="detail-item">

                    <div class="detail-label">
                        Nama
                    </div>

                    <div
                        class="detail-value"
                        id="detail_nama"
                    >
                        -
                    </div>

                </div>


                <div class="detail-item">

                    <div class="detail-label">
                        Nomor Telepon
                    </div>

                    <div
                        class="detail-value"
                        id="detail_phone"
                    >
                        -
                    </div>

                </div>


                <div class="detail-item">

                    <div class="detail-label">
                        Status Pembayaran Saat Ini
                    </div>

                    <div
                        class="detail-value"
                        id="detail_payment"
                    >
                        -
                    </div>

                </div>


                <div class="detail-item">

                    <div class="detail-label">
                        Nominal
                    </div>

                    <div
                        class="detail-value"
                        id="detail_amount"
                    >
                        -
                    </div>

                </div>


                <div class="detail-item">

                    <div class="detail-label">
                        Jatuh Tempo
                    </div>

                    <div
                        class="detail-value"
                        id="detail_due"
                    >
                        -
                    </div>

                </div>


                <div class="detail-item">

                    <div class="detail-label">
                        Template
                    </div>

                    <div
                        class="detail-value"
                        id="detail_template"
                    >
                        -
                    </div>

                </div>


                <div class="detail-item">

                    <div class="detail-label">
                        Status Pengiriman
                    </div>

                    <div
                        class="detail-value"
                        id="detail_status"
                    >
                        -
                    </div>

                </div>


                <div class="detail-item">

                    <div class="detail-label">
                        Message ID
                    </div>

                    <div
                        class="detail-value"
                        id="detail_message_id"
                    >
                        -
                    </div>

                </div>


                <div class="detail-item">

                    <div class="detail-label">
                        Dibuat
                    </div>

                    <div
                        class="detail-value"
                        id="detail_created"
                    >
                        -
                    </div>

                </div>


                <div class="detail-item">

                    <div class="detail-label">
                        Terkirim
                    </div>

                    <div
                        class="detail-value"
                        id="detail_sent"
                    >
                        -
                    </div>

                </div>


                <div class="detail-item">

                    <div class="detail-label">
                        Delivered
                    </div>

                    <div
                        class="detail-value"
                        id="detail_delivered"
                    >
                        -
                    </div>

                </div>


                <div class="detail-item">

                    <div class="detail-label">
                        Dibaca
                    </div>

                    <div
                        class="detail-value"
                        id="detail_read"
                    >
                        -
                    </div>

                </div>


                <div class="detail-item detail-full">

                    <div class="detail-label">
                        Error
                    </div>

                    <div
                        class="detail-value"
                        id="detail_error"
                    >
                        -
                    </div>

                </div>

            </div>

        </div>

    </div>

</div>


<script>

function setText(id, value) {

    const element = document.getElementById(id);

    if (!element) {
        return;
    }

    element.textContent =
        value === null ||
        value === undefined ||
        value === ''
            ? '-'
            : value;
}


function showDetail(data) {

    setText('detail_id', data.id);
    setText('detail_cid', data.cid);
    setText('detail_nama', data.nama);
    setText('detail_phone', data.no_telepon);

    setText(
        'detail_payment',
        data.status_payment === 'sudah_bayar'
            ? 'Sudah Bayar'
            : data.status_payment === 'belum_bayar'
                ? 'Belum Bayar'
                : '-'
    );

    if (data.amount !== null && data.amount !== '') {

        const amount =
            Number(data.amount);

        if (!isNaN(amount)) {

            setText(
                'detail_amount',
                'Rp ' +
                amount.toLocaleString('id-ID')
            );

        } else {

            setText(
                'detail_amount',
                data.amount
            );

        }

    } else {

        setText(
            'detail_amount',
            '-'
        );

    }

    setText(
        'detail_due',
        data.due_date
            ? formatDate(data.due_date)
            : '-'
    );

    setText(
        'detail_template',
        data.template_name
    );

    setText(
        'detail_status',
        data.status
    );

    setText(
        'detail_message_id',
        data.message_id
    );

    setText(
        'detail_created',
        formatDateTime(data.created_at)
    );

    setText(
        'detail_sent',
        formatDateTime(data.sent_at)
    );

    setText(
        'detail_delivered',
        formatDateTime(data.delivered_at)
    );

    setText(
        'detail_read',
        formatDateTime(data.read_at)
    );

    setText(
        'detail_error',
        data.error_message
    );

    document
        .getElementById('detailModal')
        .classList
        .add('show');
}


function closeDetail() {

    document
        .getElementById('detailModal')
        .classList
        .remove('show');
}


function closeModalOutside(event) {

    if (
        event.target ===
        document.getElementById('detailModal')
    ) {

        closeDetail();

    }

}


function formatDateTime(value) {

    if (!value) {
        return '-';
    }

    const date =
        new Date(
            value.replace(' ', 'T')
        );

    if (isNaN(date.getTime())) {
        return value;
    }

    return date.toLocaleString(
        'id-ID',
        {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        }
    );

}


function formatDate(value) {

    if (!value) {
        return '-';
    }

    const parts =
        value.split('-');

    if (parts.length !== 3) {
        return value;
    }

    return (
        parts[2] +
        '/' +
        parts[1] +
        '/' +
        parts[0]
    );

}


function changePerPage(value) {

    const url =
        new URL(
            window.location.href
        );

    url.searchParams.set(
        'per_page',
        value
    );

    url.searchParams.set(
        'page',
        '1'
    );

    window.location.href =
        url.toString();

}


document.addEventListener(
    'keydown',
    function(event) {

        if (event.key === 'Escape') {
            closeDetail();
        }

    }
);

</script>

</body>
</html>