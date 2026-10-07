<?php
require_once __DIR__ . '/../helpers.php';
requireLogin();
require_once __DIR__ . '/../database.php';

// Aksi ini mengubah data: hanya POST + token CSRF.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: unpaid.php');
    exit;
}
if (!csrf_valid()) {
    http_response_code(403);
    die('Token tidak valid. Kembali ke halaman Customer Belum Bayar lalu coba lagi.');
}

$ids = postIds('tagihan_ids');
[$success, $skipped, $noPhone] = queueReminders($conn, $ids);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Proses Reminder</title>
<link rel="stylesheet" href="assets/bulk.css?v=1">
</head>
<body>
<div class="result-page">
<div class="result-card">
    <div class="result-icon">✓</div>
    <span class="eyebrow">REQUEST QUEUED</span>
    <h1>Reminder siap diproses</h1>
    <p><?= number_format($success) ?> tagihan masuk antrean <strong>pending</strong>.</p>

    <div class="notice">
        <strong>Status pengiriman</strong>
        <span>Saat ini sistem baru membuat antrean reminder. WhatsApp belum benar-benar dikirim karena API provider belum dipasang.</span>
    </div>

    <?php if ($skipped > 0): ?>
        <p class="muted"><?= number_format($skipped) ?> data dilewati karena sudah bayar, sudah punya antrean pending, atau tidak ditemukan.</p>
    <?php endif; ?>
    <?php if ($noPhone > 0): ?>
        <p class="muted"><?= number_format($noPhone) ?> data dilewati karena customer belum punya nomor telepon. Lengkapi di <a href="customers.php?phone=kosong">Data Customer</a>.</p>
    <?php endif; ?>

    <div class="result-actions">
        <a href="unpaid.php" class="primary">Kembali ke Belum Bayar</a>
        <a href="history.php" class="secondary">Riwayat Reminder</a>
    </div>
</div>
</div>
</body>
</html>
