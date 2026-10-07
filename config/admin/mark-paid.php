<?php

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: reminder.php');
    exit;
}

if (!csrf_valid()) {
    http_response_code(403);
    die('Token tidak valid. Kembali ke dashboard lalu coba lagi.');
}

$ids = [];
foreach ((array)($_POST['id'] ?? []) as $id) {
    $id = (int)$id;
    if ($id > 0) {
        $ids[] = $id;
    }
}
$ids = array_values(array_unique($ids));

$status = (string)($_POST['status'] ?? '');

if (!$ids || !in_array($status, ['belum_bayar', 'sudah_bayar'], true)) {
    $_SESSION['flash'] = 'Permintaan tidak valid.';
    header('Location: reminder.php');
    exit;
}

[$changed, $cancelled] = setPaymentStatus($conn, $ids, $status);

if ($status === 'sudah_bayar') {
    $msg = $changed . ' customer ditandai sudah bayar.';
    if ($cancelled > 0) {
        $msg .= ' ' . $cancelled . ' antrean reminder dibatalkan.';
    }
} else {
    $msg = $changed . ' customer dikembalikan ke belum bayar.';
}

$_SESSION['flash'] = $msg;

// Kembali ke halaman reminder dengan filter yang sama (hanya path internal).
$back = (string)($_POST['back'] ?? '');
if (!preg_match('#^reminder\.php(\?[A-Za-z0-9_=&%.\-]*)?$#', $back)) {
    $back = 'reminder.php';
}

header('Location: ' . $back);
exit;
