<?php

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../helpers.php';


/* ======================================================
   HELPER
====================================================== */

function bindParams($stmt, $types, &$params)
{
    if ($types === '' || empty($params)) {
        return;
    }

    $bind = array($types);

    foreach ($params as $key => &$value) {
        $bind[] = &$value;
    }

    call_user_func_array(array($stmt, 'bind_param'), $bind);
}


function formatDateIndonesia($date)
{
    if (!$date) {
        return '-';
    }

    $time = strtotime($date);

    if (!$time) {
        return '-';
    }

    $bulan = array(
        1 => 'Jan',
        2 => 'Feb',
        3 => 'Mar',
        4 => 'Apr',
        5 => 'Mei',
        6 => 'Jun',
        7 => 'Jul',
        8 => 'Agu',
        9 => 'Sep',
        10 => 'Okt',
        11 => 'Nov',
        12 => 'Des'
    );

    return date('d', $time)
        . ' ' . $bulan[(int) date('n', $time)]
        . ' ' . date('Y', $time)
        . ' ' . date('H:i', $time);
}


function getInitials($name)
{
    $name = trim($name);

    if ($name === '') {
        return '?';
    }

    $parts = preg_split('/\s+/', $name);

    $result = strtoupper(substr($parts[0], 0, 1));

    if (count($parts) > 1) {
        $result .= strtoupper(substr($parts[1], 0, 1));
    }

    return $result;
}


function reminderStatusClass($status)
{
    if ($status === 'terkirim') {
        return 'status-sent';
    }

    if ($status === 'gagal') {
        return 'status-failed';
    }

    if ($status === 'pending') {
        return 'status-pending';
    }

    return 'status-none';
}


function reminderStatusText($status)
{
    if ($status === 'terkirim') {
        return 'Terkirim';
    }

    if ($status === 'gagal') {
        return 'Gagal';
    }

    if ($status === 'pending') {
        return 'Menunggu';
    }

    return 'Belum Dikirim';
}


function pageUrl($page)
{
    $query = $_GET;

    $query['page'] = $page;

    return 'reminder.php?' . http_build_query($query);
}


function countValue($conn, $sql)
{
    try {

        $result = $conn->query($sql);

        if ($result) {
            $row = $result->fetch_assoc();

            return (int) (
                isset($row['total'])
                    ? $row['total']
                    : 0
            );
        }

    } catch (Throwable $e) {
        // Abaikan error statistik
    }

    return 0;
}


/* ======================================================
   FILTER
====================================================== */

$search = isset($_GET['search'])
    ? trim($_GET['search'])
    : '';

$status = isset($_GET['status'])
    ? trim($_GET['status'])
    : '';

/*
 * DEFAULT:
 * Halaman reminder hanya menampilkan pelanggan
 * yang BELUM BAYAR.
 */
$payment = isset($_GET['payment'])
    ? trim($_GET['payment'])
    : 'belum_bayar';

$cycle = isset($_GET['cycle'])
    ? trim($_GET['cycle'])
    : '';

$date_from = isset($_GET['date_from'])
    ? trim($_GET['date_from'])
    : '';

$date_to = isset($_GET['date_to'])
    ? trim($_GET['date_to'])
    : '';

$page = isset($_GET['page'])
    ? max(1, (int) $_GET['page'])
    : 1;

$per_page = 15;


/* ======================================================
   VALIDASI PAYMENT FILTER
====================================================== */

if (
    $payment !== ''
    && $payment !== 'belum_bayar'
    && $payment !== 'sudah_bayar'
) {
    $payment = 'belum_bayar';
}


/* ======================================================
   CYCLE
====================================================== */

$cycleOptions = array(

    '15' => array(
        'label' => 'Cycle 15 – Awal Bulan (tgl 15)',
        'sql'   => 'DAY(c.due_date) = 15'
    ),

    '25' => array(
        'label' => 'Cycle 25 – Akhir Bulan (tgl 25)',
        'sql'   => 'DAY(c.due_date) = 25'
    ),

    'c1' => array(
        'label' => 'Cycle 1 – tgl 01–07',
        'sql'   => 'DAY(c.due_date) BETWEEN 1 AND 7'
    ),

    'c2' => array(
        'label' => 'Cycle 2 – tgl 08–15',
        'sql'   => 'DAY(c.due_date) BETWEEN 8 AND 15'
    ),

    'c3' => array(
        'label' => 'Cycle 3 – tgl 16–23',
        'sql'   => 'DAY(c.due_date) BETWEEN 16 AND 23'
    ),

    'c4' => array(
        'label' => 'Cycle 4 – tgl 24–akhir bulan',
        'sql'   => 'DAY(c.due_date) >= 24'
    )
);

if (!isset($cycleOptions[$cycle])) {
    $cycle = '';
}


/* ======================================================
   STATISTIK
====================================================== */

$stats = array(

    'total' => countValue(
        $conn,
        "SELECT COUNT(*) AS total FROM customers"
    ),

    'pending' => countValue(
        $conn,
        "SELECT COUNT(*) AS total
         FROM reminder_logs
         WHERE status = 'pending'"
    ),

    'sent' => countValue(
        $conn,
        "SELECT COUNT(*) AS total
         FROM reminder_logs
         WHERE status = 'terkirim'"
    ),

    'failed' => countValue(
        $conn,
        "SELECT COUNT(*) AS total
         FROM reminder_logs
         WHERE status = 'gagal'"
    )
);


/* ======================================================
   WHERE
====================================================== */

$where  = array();
$params = array();
$types  = '';


/* ======================================================
   SEARCH
====================================================== */

if ($search !== '') {

    $where[] = "(
        c.cid LIKE ?
        OR c.nama LIKE ?
        OR c.no_telepon LIKE ?
    )";

    $keyword = '%' . $search . '%';

    $params[] = $keyword;
    $params[] = $keyword;
    $params[] = $keyword;

    $types .= 'sss';
}


/* ======================================================
   STATUS REMINDER
====================================================== */

if ($status === 'belum_dikirim') {

    $where[] = 'last_log.status IS NULL';

} elseif (
    $status === 'pending'
    || $status === 'terkirim'
    || $status === 'gagal'
) {

    $where[] = 'last_log.status = ?';

    $params[] = $status;

    $types .= 's';

} else {

    $status = '';
}


/* ======================================================
   STATUS PEMBAYARAN
====================================================== */

if (
    $payment === 'belum_bayar'
    || $payment === 'sudah_bayar'
) {

    $where[] = 'c.status_payment = ?';

    $params[] = $payment;

    $types .= 's';

}


/* ======================================================
   CYCLE
====================================================== */

if ($cycle !== '') {

    $where[] = $cycleOptions[$cycle]['sql'];

}


/* ======================================================
   TANGGAL REMINDER
   Menggunakan:
   1. reminder_logs.created_at
   2. fallback customers.last_sent_at
====================================================== */

if ($date_from !== '') {

    $where[] = '
        DATE(
            COALESCE(
                last_log.sent_at,
                c.last_sent_at
            )
        ) >= ?
    ';

    $params[] = $date_from;

    $types .= 's';
}


if ($date_to !== '') {

    $where[] = '
        DATE(
            COALESCE(
                last_log.sent_at,
                c.last_sent_at
            )
        ) <= ?
    ';

    $params[] = $date_to;

    $types .= 's';
}


/* ======================================================
   WHERE SQL
====================================================== */

$where_sql = '';

if (!empty($where)) {

    $where_sql = 'WHERE ' . implode(
        ' AND ',
        $where
    );

}


/* ======================================================
   LAST REMINDER LOG
   Ambil log terakhir setiap customer
====================================================== */

$latestLogJoin = "

    LEFT JOIN (

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
            rl.created_at

        FROM reminder_logs rl

        INNER JOIN (

            SELECT

                customer_id,
                MAX(id) AS max_id

            FROM reminder_logs

            GROUP BY customer_id

        ) latest

        ON latest.max_id = rl.id

    ) last_log

    ON last_log.customer_id = c.id

";


/* ======================================================
   COUNT DATA
====================================================== */

$countSql = "

    SELECT COUNT(*) AS total

    FROM customers c

    {$latestLogJoin}

    {$where_sql}

";


$count_stmt = $conn->prepare($countSql);

if (!$count_stmt) {

    die(
        'Gagal menyiapkan query: '
        . e($conn->error)
    );

}


bindParams(
    $count_stmt,
    $types,
    $params
);


$count_stmt->execute();


$count_result = $count_stmt->get_result();

$count_row = $count_result->fetch_assoc();


$filtered_total = (int) (
    isset($count_row['total'])
        ? $count_row['total']
        : 0
);


$count_stmt->close();


/* ======================================================
   PAGINATION
====================================================== */

$total_pages = max(
    1,
    (int) ceil(
        $filtered_total / $per_page
    )
);


if ($page > $total_pages) {
    $page = $total_pages;
}


$offset = (
    $page - 1
) * $per_page;


/* ======================================================
   DATA CUSTOMER
====================================================== */

$sql = "

    SELECT

        c.id,
        c.cid,
        c.nama,
        c.no_telepon,
        c.due_date,
        c.status_payment,
        c.amount,
        c.last_sent_at,
        c.created_at AS customer_created_at,

        last_log.id AS reminder_id,
        last_log.template_name,
        last_log.message_id,
        last_log.status AS reminder_status,
        last_log.error_message,
        last_log.sent_at,
        last_log.delivered_at,
        last_log.read_at,
        last_log.created_at AS reminder_created_at

    FROM customers c

    {$latestLogJoin}

    {$where_sql}

    ORDER BY

        CASE

            WHEN last_log.status IS NULL THEN 0

            WHEN last_log.status = 'gagal' THEN 1

            WHEN last_log.status = 'pending' THEN 2

            ELSE 3

        END,

        c.nama ASC

    LIMIT ?

    OFFSET ?

";


$data_params = $params;

$data_params[] = $per_page;
$data_params[] = $offset;


$stmt = $conn->prepare($sql);

if (!$stmt) {

    die(
        'Gagal menyiapkan query customer: '
        . e($conn->error)
    );

}


bindParams(
    $stmt,
    $types . 'ii',
    $data_params
);


$stmt->execute();


$result = $stmt->get_result();


$customers = array();


while ($row = $result->fetch_assoc()) {

    $customers[] = $row;

}


$stmt->close();


/* ======================================================
   ADMIN
====================================================== */

$admin_name = 'Administrator';


if (isset($_SESSION['admin_name'])) {

    $admin_name = $_SESSION['admin_name'];

} elseif (isset($_SESSION['username'])) {

    $admin_name = $_SESSION['username'];

}


/* ======================================================
   FILTER STATUS
====================================================== */

$hasFilter = (
    $search !== ''
    || $status !== ''
    || $payment !== 'belum_bayar'
    || $cycle !== ''
    || $date_from !== ''
    || $date_to !== ''
);

?>
<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>WA Reminder | Dashboard</title>

    <meta
        name="description"
        content="Dashboard WhatsApp Reminder"
    >

    <link
        rel="stylesheet"
        href="assets/reminder.css?v=9"
    >

</head>


<body>

<div class="reminder-app">


    <!-- =========================================================
         SIDEBAR
    ========================================================== -->

    <aside class="reminder-sidebar">

        <div class="reminder-brand">

            <div class="reminder-brand-logo">
                WA
            </div>

            <div class="reminder-brand-text">

                <strong>WA Reminder</strong>

                <span>
                    Admin Dashboard
                </span>

            </div>

        </div>


        <nav class="reminder-nav">

            <div class="reminder-nav-title">
                MENU UTAMA
            </div>


            <a
                href="reminder.php"
                class="active"
            >

                <span class="reminder-nav-icon">
                    ◉
                </span>

                <span>
                    Reminder
                </span>

            </a>


            <a href="history.php">

                <span class="reminder-nav-icon">
                    ◷
                </span>

                <span>
                    Riwayat
                </span>

            </a>


            <div class="reminder-nav-title reminder-nav-title-space">
                DATA
            </div>


            <a href="import.php">

                <span class="reminder-nav-icon">
                    ↑
                </span>

                <span>
                    Import Excel
                </span>

            </a>


            <a href="export.php?type=all">

                <span class="reminder-nav-icon">
                    ↓
                </span>

                <span>
                    Export Excel
                </span>

            </a>


            <div class="reminder-nav-title reminder-nav-title-space">
                AKUN
            </div>


            <a href="logout.php">

                <span class="reminder-nav-icon">
                    ↪
                </span>

                <span>
                    Logout
                </span>

            </a>

        </nav>


        <div class="reminder-sidebar-bottom">

            <div class="admin-mini-avatar">

                <?= e(getInitials($admin_name)) ?>

            </div>


            <div class="admin-mini-info">

                <strong>
                    <?= e($admin_name) ?>
                </strong>

                <span>
                    Administrator
                </span>

            </div>

        </div>

    </aside>


    <!-- =========================================================
         MAIN
    ========================================================== -->

    <main class="reminder-main">

        <?php if (!empty($_SESSION['flash'])): ?>
            <div style="margin:0 0 16px;padding:12px 16px;border-radius:10px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;font-weight:600">
                <?= e($_SESSION['flash']) ?>
            </div>
            <?php unset($_SESSION['flash']); ?>
        <?php endif; ?>



        <!-- TOPBAR -->

        <header class="reminder-topbar">

            <div class="reminder-title">

                <div class="reminder-eyebrow">
                    WHATSAPP AUTOMATION
                </div>

                <h1>
                    Payment Reminder
                </h1>

                <p>
                    Kelola pelanggan dan kirim pengingat
                    melalui WhatsApp.
                </p>

            </div>


            <div class="reminder-actions">

                <a
                    href="import.php"
                    class="reminder-btn"
                >
                    ↑ Import
                </a>


                <a
                    href="export.php?type=all"
                    class="reminder-btn reminder-btn-primary"
                >
                    ↓ Export Customer
                </a>

            </div>

        </header>


        <!-- =====================================================
             STATISTICS
        ====================================================== -->

        <section class="reminder-stats">


            <div class="reminder-stat">

                <div class="reminder-stat-top">

                    <div class="reminder-stat-label-top">
                        TOTAL CUSTOMER
                    </div>

                    <div class="reminder-stat-icon">
                        ◎
                    </div>

                </div>


                <div class="reminder-stat-number">

                    <?= number_format($stats['total']) ?>

                </div>


                <div class="reminder-stat-label">
                    Seluruh data pelanggan
                </div>

            </div>


            <div class="reminder-stat stat-warning">

                <div class="reminder-stat-top">

                    <div class="reminder-stat-label-top">
                        PENDING
                    </div>

                    <div class="reminder-stat-icon">
                        !
                    </div>

                </div>


                <div class="reminder-stat-number">

                    <?= number_format($stats['pending']) ?>

                </div>


                <div class="reminder-stat-label">
                    Reminder menunggu
                </div>

            </div>


            <div class="reminder-stat stat-success">

                <div class="reminder-stat-top">

                    <div class="reminder-stat-label-top">
                        TERKIRIM
                    </div>

                    <div class="reminder-stat-icon">
                        ✓
                    </div>

                </div>


                <div class="reminder-stat-number">

                    <?= number_format($stats['sent']) ?>

                </div>


                <div class="reminder-stat-label">
                    Reminder berhasil dikirim
                </div>

            </div>


            <div class="reminder-stat stat-message">

                <div class="reminder-stat-top">

                    <div class="reminder-stat-label-top">
                        GAGAL
                    </div>

                    <div class="reminder-stat-icon">
                        ×
                    </div>

                </div>


                <div class="reminder-stat-number">

                    <?= number_format($stats['failed']) ?>

                </div>


                <div class="reminder-stat-label">
                    Reminder gagal dikirim
                </div>

            </div>


        </section>


        <!-- =====================================================
             CARD
        ====================================================== -->

        <section class="reminder-card">


            <!-- =================================================
                 FILTER
            ================================================== -->

            <div class="reminder-filter">

                <form
                    method="GET"
                    action="reminder.php"
                    class="reminder-filter-form"
                >


                    <!-- SEARCH -->

                    <div class="reminder-field">

                        <label for="search">
                            Cari pelanggan
                        </label>

                        <input
                            type="search"
                            id="search"
                            name="search"
                            value="<?= e($search) ?>"
                            placeholder="CID, nama, atau nomor WhatsApp..."
                        >

                    </div>


                    <!-- STATUS REMINDER -->

                    <div class="reminder-field">

                        <label for="status">
                            Status Reminder
                        </label>

                        <select
                            id="status"
                            name="status"
                        >

                            <option value="">
                                Semua Status
                            </option>


                            <option
                                value="belum_dikirim"
                                <?= $status === 'belum_dikirim'
                                    ? 'selected'
                                    : '' ?>
                            >
                                Belum Dikirim
                            </option>


                            <option
                                value="pending"
                                <?= $status === 'pending'
                                    ? 'selected'
                                    : '' ?>
                            >
                                Pending
                            </option>


                            <option
                                value="terkirim"
                                <?= $status === 'terkirim'
                                    ? 'selected'
                                    : '' ?>
                            >
                                Terkirim
                            </option>


                            <option
                                value="gagal"
                                <?= $status === 'gagal'
                                    ? 'selected'
                                    : '' ?>
                            >
                                Gagal
                            </option>

                        </select>

                    </div>


                    <!-- STATUS PEMBAYARAN -->

                    <div class="reminder-field">

                        <label for="payment">
                            Status Pembayaran
                        </label>

                        <select
                            id="payment"
                            name="payment"
                        >

                            <option
                                value="belum_bayar"
                                <?= $payment === 'belum_bayar'
                                    ? 'selected'
                                    : '' ?>
                            >
                                Belum Bayar
                            </option>


                            <option
                                value="sudah_bayar"
                                <?= $payment === 'sudah_bayar'
                                    ? 'selected'
                                    : '' ?>
                            >
                                Sudah Bayar
                            </option>


                            <option
                                value=""
                                <?= $payment === ''
                                    ? 'selected'
                                    : '' ?>
                            >
                                Semua Customer
                            </option>

                        </select>

                    </div>


                    <!-- CYCLE -->

                    <div class="reminder-field">

                        <label for="cycle">
                            Cycle
                        </label>

                        <select
                            id="cycle"
                            name="cycle"
                        >

                            <option value="">
                                Semua Cycle
                            </option>


                            <?php foreach (
                                $cycleOptions
                                as $key => $option
                            ): ?>

                                <option
                                    value="<?= e($key) ?>"
                                    <?= $cycle === (string) $key
                                        ? 'selected'
                                        : '' ?>
                                >
                                    <?= e($option['label']) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <!-- DATE FROM -->

                    <div class="reminder-field">

                        <label for="date_from">
                            Reminder dari
                        </label>

                        <input
                            type="date"
                            id="date_from"
                            name="date_from"
                            value="<?= e($date_from) ?>"
                        >

                    </div>


                    <!-- DATE TO -->

                    <div class="reminder-field">

                        <label for="date_to">
                            Sampai
                        </label>

                        <input
                            type="date"
                            id="date_to"
                            name="date_to"
                            value="<?= e($date_to) ?>"
                        >

                    </div>


                    <!-- FILTER BUTTON -->

                    <div class="reminder-filter-actions">

                        <button
                            type="submit"
                            class="reminder-filter-button"
                        >
                            Cari Data
                        </button>


                        <?php if ($hasFilter): ?>

                            <a
                                href="reminder.php"
                                class="reminder-reset"
                            >
                                Reset
                            </a>

                        <?php endif; ?>

                    </div>


                </form>

            </div>


            <!-- =================================================
                 TABLE HEADER
            ================================================== -->

            <div class="reminder-card-header">

                <div>

                    <h2>
                        Daftar Pelanggan
                    </h2>


                    <p>

                        Menampilkan

                        <strong>
                            <?= number_format($filtered_total) ?>
                        </strong>

                        data pelanggan


                        <?php if ($payment === 'belum_bayar'): ?>

                            · Belum Bayar

                        <?php elseif ($payment === 'sudah_bayar'): ?>

                            · Sudah Bayar

                        <?php elseif ($payment === ''): ?>

                            · Semua Customer

                        <?php endif; ?>


                        <?php if ($cycle !== ''): ?>

                            ·
                            <?= e(
                                $cycleOptions[$cycle]['label']
                            ) ?>

                        <?php endif; ?>

                    </p>

                </div>


                <div class="table-info">

                    Halaman
                    <?= (int) $page ?>
                    /
                    <?= (int) $total_pages ?>

                </div>

            </div>


            <!-- =================================================
                 TABLE
            ================================================== -->

            <?php if (empty($customers)): ?>


                <div class="reminder-empty">

                    <div class="reminder-empty-icon">
                        ◌
                    </div>


                    <h3>
                        Data tidak ditemukan
                    </h3>


                    <p>

                        Tidak ada pelanggan yang
                        sesuai dengan filter.

                    </p>

                </div>


            <?php else: ?>


                <div class="reminder-table-wrapper">

                    <table class="reminder-table">


                        <thead>

                            <tr>

                                <th>
                                    Pelanggan
                                </th>

                                <th>
                                    WhatsApp
                                </th>

                                <th>
                                    Jatuh Tempo
                                </th>

                                <th>
                                    Reminder
                                </th>

                                <th>
                                    Terakhir Dikirim
                                </th>

                                <th>
                                    Aksi
                                </th>

                            </tr>

                        </thead>


                        <tbody>


                        <?php foreach (
                            $customers
                            as $customer
                        ): ?>


                            <?php

                            /* ==================================
                               NAMA CUSTOMER
                            ================================== */

                            $customerName = 'Tanpa Nama';


                            if (
                                isset($customer['nama'])
                                && trim($customer['nama']) !== ''
                            ) {

                                $customerName =
                                    trim($customer['nama']);

                            }


                            /* ==================================
                               STATUS REMINDER
                            ================================== */

                            $reminderStatus =
                                isset(
                                    $customer['reminder_status']
                                )
                                ? $customer['reminder_status']
                                : null;


                            /* ==================================
                               TERAKHIR DIKIRIM

                               Prioritas:
                               1. reminder_logs.sent_at
                               2. customers.last_sent_at

                               BUKAN created_at
                            ================================== */

                            $lastReminder =
                                !empty(
                                    $customer['sent_at']
                                )
                                ? $customer['sent_at']
                                : (
                                    !empty(
                                        $customer['last_sent_at']
                                    )
                                    ? $customer['last_sent_at']
                                    : null
                                );


                            /* ==================================
                               STATUS PEMBAYARAN
                            ================================== */

                            $isPaid = (
                                isset(
                                    $customer['status_payment']
                                )
                                && $customer['status_payment']
                                    === 'sudah_bayar'
                            );


                            /* ==================================
                               JATUH TEMPO
                            ================================== */

                            $dueDate = '-';

                            $isLate = false;


                            if (
                                !empty(
                                    $customer['due_date']
                                )
                            ) {

                                $dueTime =
                                    strtotime(
                                        $customer['due_date']
                                    );


                                if ($dueTime) {

                                    $dueDate =
                                        date(
                                            'd/m/Y',
                                            $dueTime
                                        );


                                    $isLate =
                                        !$isPaid
                                        && $customer['due_date']
                                            < date('Y-m-d');

                                }

                            }

                            ?>


                            <tr>


                                <!-- CUSTOMER -->

                                <td>

                                    <div class="customer-cell">


                                        <div class="customer-avatar">

                                            <?= e(
                                                getInitials(
                                                    $customerName
                                                )
                                            ) ?>

                                        </div>


                                        <div>

                                            <div class="customer-name">

                                                <?= e(
                                                    $customerName
                                                ) ?>

                                            </div>


                                            <div class="customer-cid">

                                                #
                                                <?= e(
                                                    isset(
                                                        $customer['cid']
                                                    )
                                                    ? $customer['cid']
                                                    : '-'
                                                ) ?>

                                            </div>

                                        </div>


                                    </div>

                                </td>


                                <!-- WHATSAPP -->

                                <td>

                                    <div class="customer-phone">

                                        <?= e(
                                            isset(
                                                $customer['no_telepon']
                                            )
                                            ? $customer['no_telepon']
                                            : '-'
                                        ) ?>

                                    </div>

                                </td>


                                <!-- JATUH TEMPO -->

                                <td>

                                    <div
                                        class="customer-date"
                                        <?= $isLate
                                            ? 'style="color:#dc2626;font-weight:600"'
                                            : '' ?>
                                    >

                                        <?= e($dueDate) ?>

                                    </div>


                                    <span
                                        class="reminder-status <?= $isPaid
                                            ? 'status-sent'
                                            : 'status-none' ?>"
                                        style="margin-top:4px"
                                    >

                                        <?= $isPaid
                                            ? 'Sudah Bayar'
                                            : 'Belum Bayar' ?>

                                    </span>

                                </td>


                                <!-- STATUS REMINDER -->

                                <td>

                                    <span
                                        class="reminder-status <?= e(
                                            reminderStatusClass(
                                                $reminderStatus
                                            )
                                        ) ?>"
                                    >

                                        <?= e(
                                            reminderStatusText(
                                                $reminderStatus
                                            )
                                        ) ?>

                                    </span>

                                </td>


                                <!-- TERAKHIR DIKIRIM -->

                                <td>

                                    <div class="customer-date">

                                        <?= e(
                                            formatDateIndonesia(
                                                $lastReminder
                                            )
                                        ) ?>

                                    </div>

                                </td>


                                <!-- AKSI -->

                                <td>

                                    <form
                                        method="post"
                                        action="send.php"
                                        class="send-form"
                                    >


                                        <?= csrf_field() ?>


                                        <input
                                            type="hidden"
                                            name="id"
                                            value="<?= (int) $customer['id'] ?>"
                                        >


                                        <button
                                            type="submit"
                                            class="reminder-send"

                                            <?= $isPaid

                                                ? 'disabled title="Pelanggan sudah bayar"'

                                                : (
                                                    $reminderStatus
                                                    === 'pending'

                                                    ? 'disabled title="Sudah ada antrean menunggu"'

                                                    : ''
                                                )
                                            ?>
                                        >

                                            Kirim

                                        </button>


                                    </form>

                                    <form
                                        method="post"
                                        action="mark-paid.php"
                                        class="send-form"
                                        style="margin-top:6px"
                                        onsubmit="return confirm('<?= $isPaid
                                            ? 'Kembalikan ke Belum Bayar?'
                                            : 'Tandai customer ini sudah bayar? Antrean reminder yang pending akan dibatalkan.' ?>');"
                                    >

                                        <?= csrf_field() ?>

                                        <input type="hidden" name="id[]" value="<?= (int) $customer['id'] ?>">
                                        <input type="hidden" name="status" value="<?= $isPaid ? 'belum_bayar' : 'sudah_bayar' ?>">
                                        <input type="hidden" name="back" value="<?= e(ltrim(basename($_SERVER['REQUEST_URI']), '/')) ?>">

                                        <button type="submit" class="reminder-send" style="background:<?= $isPaid ? '#6b7280' : '#059669' ?>">
                                            <?= $isPaid ? 'Batal Lunas' : 'Tandai Lunas' ?>
                                        </button>

                                    </form>

                                </td>


                            </tr>


                        <?php endforeach; ?>


                        </tbody>

                    </table>

                </div>


                <!-- =================================================
                     PAGINATION
                ================================================== -->

                <?php if ($total_pages > 1): ?>


                    <?php

                    $startPage =
                        max(
                            1,
                            $page - 2
                        );

                    $endPage =
                        min(
                            $total_pages,
                            $page + 2
                        );

                    ?>


                    <div class="reminder-pagination">


                        <?php if ($page > 1): ?>

                            <a
                                href="<?= e(
                                    pageUrl(
                                        $page - 1
                                    )
                                ) ?>"
                            >
                                ‹
                            </a>

                        <?php endif; ?>


                        <?php if ($startPage > 1): ?>


                            <a
                                href="<?= e(
                                    pageUrl(1)
                                ) ?>"
                            >
                                1
                            </a>


                            <?php if ($startPage > 2): ?>

                                <span class="pagination-dots">
                                    …
                                </span>

                            <?php endif; ?>


                        <?php endif; ?>


                        <?php for (
                            $i = $startPage;
                            $i <= $endPage;
                            $i++
                        ): ?>


                            <?php if ($i === $page): ?>

                                <span class="active">
                                    <?= (int) $i ?>
                                </span>

                            <?php else: ?>

                                <a
                                    href="<?= e(
                                        pageUrl($i)
                                    ) ?>"
                                >
                                    <?= (int) $i ?>
                                </a>

                            <?php endif; ?>


                        <?php endfor; ?>


                        <?php if (
                            $endPage < $total_pages
                        ): ?>


                            <?php if (
                                $endPage
                                < $total_pages - 1
                            ): ?>

                                <span class="pagination-dots">
                                    …
                                </span>

                            <?php endif; ?>


                            <a
                                href="<?= e(
                                    pageUrl(
                                        $total_pages
                                    )
                                ) ?>"
                            >
                                <?= (int) $total_pages ?>
                            </a>


                        <?php endif; ?>


                        <?php if ($page < $total_pages): ?>

                            <a
                                href="<?= e(
                                    pageUrl(
                                        $page + 1
                                    )
                                ) ?>"
                            >
                                ›
                            </a>

                        <?php endif; ?>


                    </div>


                <?php endif; ?>


            <?php endif; ?>


        </section>


        <!-- FOOTER -->

        <footer class="reminder-footer">

            <span>
                WA Reminder
            </span>

            <span>
                &copy; <?= date('Y') ?> Admin System
            </span>

        </footer>


    </main>

</div>

</body>
</html>