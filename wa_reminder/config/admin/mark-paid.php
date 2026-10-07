<?php
require_once __DIR__ . '/../helpers.php';
requireLogin();
require_once __DIR__ . '/../database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: unpaid.php');
    exit;
}
if (!csrf_valid()) {
    http_response_code(403);
    die('Token tidak valid. Kembali ke dashboard lalu coba lagi.');
}

$ids    = postIds('ids');
$status = (string)($_POST['status'] ?? '');

// kembali ke halaman asal (hanya halaman internal yang diizinkan)
$back = (string)($_POST['back'] ?? '');
if (!preg_match('#^(unpaid|payments)\.php(\?[A-Za-z0-9_=&%.\-\[\]]*)?$#', $back)) {
    $back = 'unpaid.php';
}

if (!$ids || !in_array($status, ['belum_bayar', 'sudah_bayar'], true)) {
    flash('Permintaan tidak valid.', 'error');
    header('Location: ' . $back);
    exit;
}

[$changed, $cancelled] = setTagihanStatus($conn, $ids, $status);

if ($status === 'sudah_bayar') {
    $msg = $changed . ' tagihan ditandai sudah bayar.';
    if ($cancelled > 0) {
        $msg .= ' ' . $cancelled . ' antrean reminder dibatalkan.';
    }
} else {
    $msg = $changed . ' tagihan dikembalikan ke belum bayar.';
}
flash($msg);

header('Location: ' . $back);
exit;
