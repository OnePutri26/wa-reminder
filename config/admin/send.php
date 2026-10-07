<?php

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../helpers.php';

// Aksi ini mengubah data, jadi hanya boleh lewat POST + token CSRF.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: reminder.php');
    exit;
}

if (!csrf_valid()) {
    http_response_code(403);
    die('Token tidak valid. Kembali ke dashboard lalu coba lagi.');
}

$ids = [];

if (isset($_POST['customer_ids'])) {
    foreach ((array)$_POST['customer_ids'] as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $ids[] = $id;
        }
    }
} elseif (isset($_POST['id'])) {
    $id = (int)$_POST['id'];
    if ($id > 0) {
        $ids[] = $id;
    }
}

$ids = array_values(array_unique($ids));

[$success, $skipped] = queueReminders($conn, $ids);

?>
<!DOCTYPE html>

<html lang="id">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>

<title>
    Proses Reminder
</title>

<link
    rel="stylesheet"
    href="assets/bulk.css?v=1"
>

</head>

<body>

<div class="result-page">

<div class="result-card">

<div class="result-icon">
    ✓
</div>

<span class="eyebrow">
    REQUEST QUEUED
</span>

<h1>
    Reminder siap diproses
</h1>

<p>

    <?= number_format($success) ?>

    customer masuk antrean
    <strong>pending</strong>.

</p>


<div class="notice">

<strong>
    Status pengiriman
</strong>

<span>

    Saat ini sistem baru membuat
    antrean reminder.

    WhatsApp belum benar-benar dikirim
    karena API provider belum dipasang.

</span>

</div>


<?php if ($skipped > 0): ?>

<p class="muted">

    <?= number_format($skipped) ?>

    data dilewati karena customer
    sudah bayar, sudah punya antrean pending, atau tidak ditemukan.

</p>

<?php endif; ?>


<div class="result-actions">

<a
    href="reminder.php"
    class="primary"
>
    Kembali ke Dashboard
</a>

<a
    href="send-bulk.php"
    class="secondary"
>
    Reminder Massal
</a>

</div>

</div>

</div>

</body>

</html>