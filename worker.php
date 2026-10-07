<?php
/**
 * Worker pengirim reminder. Jalankan dari CLI / cron:
 *   php worker.php
 *
 * Alur: cek ulang status bayar -> kirim -> update reminder_logs.
 * BAGIAN KIRIM KE PROVIDER WHATSAPP MASIH KOSONG (lihat TODO).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/helpers.php';

// Antrean milik customer yang sudah bayar otomatis dibatalkan di sini.
$rows = getSendableReminders($conn, 50);

$ok = $conn->prepare(
    "UPDATE reminder_logs
     SET status = 'terkirim', message_id = ?, sent_at = NOW()
     WHERE id = ? AND status = 'pending'"
);
$fail = $conn->prepare(
    "UPDATE reminder_logs
     SET status = 'gagal', error_message = ?
     WHERE id = ? AND status = 'pending'"
);

foreach ($rows as $r) {
    $to = toWhatsappNumber($r['no_telepon']);

    // TODO: panggil API provider WhatsApp di sini.
    //   $res = kirimWhatsapp($to, $r['template_name'], [...]);
    //   if ($res['ok']) { $messageId = $res['id']; ... } else { $err = $res['error']; ... }
    $sent = false;
    $err  = 'Provider WhatsApp belum dipasang';
    $messageId = null;

    if ($sent) {
        $ok->bind_param('si', $messageId, $r['log_id']);
        $ok->execute();
    } else {
        $fail->bind_param('si', $err, $r['log_id']);
        $fail->execute();
    }

    echo "[{$r['cid']}] {$r['nama']} -> " . ($sent ? 'terkirim' : "gagal ($err)") . PHP_EOL;
}

echo count($rows) . " antrean diproses." . PHP_EOL;
