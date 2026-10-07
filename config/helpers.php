<?php
/**
 * Helper bersama untuk halaman admin.
 */

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function rupiah($amount): string
{
    return 'Rp ' . number_format((float)$amount, 0, ',', '.');
}

function tanggal($date): string
{
    $time = $date ? strtotime($date) : false;
    return $time ? date('d-m-Y', $time) : '-';
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_valid(): bool
{
    return isset($_POST['csrf'], $_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], (string)$_POST['csrf']);
}

/**
 * Masukkan customer ke antrean reminder (reminder_logs, status pending).
 * Hanya customer 'belum_bayar' dan belum punya antrean pending.
 * Return: [queued, skipped]
 */
function queueReminders(mysqli $conn, array $ids, string $template = 'payment_reminder'): array
{
    $queued = 0;
    $skipped = 0;

    $check = $conn->prepare("
        SELECT c.id,
               EXISTS(
                   SELECT 1 FROM reminder_logs rl
                   WHERE rl.customer_id = c.id AND rl.status = 'pending'
               ) AS has_pending
        FROM customers c
        WHERE c.id = ? AND c.status_payment = 'belum_bayar'
        LIMIT 1
    ");
    $insert = $conn->prepare("
        INSERT INTO reminder_logs (customer_id, template_name, status)
        VALUES (?, ?, 'pending')
    ");

    foreach ($ids as $id) {
        $check->bind_param('i', $id);
        $check->execute();
        $row = $check->get_result()->fetch_assoc();

        if (!$row || (int)$row['has_pending'] === 1) {
            $skipped++;
            continue;
        }

        $insert->bind_param('is', $id, $template);
        $insert->execute();
        $queued++;
    }

    $check->close();
    $insert->close();

    return [$queued, $skipped];
}

/**
 * Ubah nomor format lokal (08xxx) ke format internasional tanpa plus (628xxx),
 * yang dibutuhkan oleh API WhatsApp.
 */
function toWhatsappNumber(string $phone): string
{
    $phone = preg_replace('/\D+/', '', $phone);

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

/**
 * Ubah status pembayaran customer.
 * Kalau jadi 'sudah_bayar', semua antrean reminder yang masih 'pending'
 * milik customer itu otomatis dibatalkan (status 'gagal' + keterangan),
 * supaya tidak ada WhatsApp tagihan terkirim ke orang yang sudah bayar.
 * Return: [berhasil_ubah, antrean_dibatalkan]
 */
function setPaymentStatus(mysqli $conn, array $ids, string $newStatus): array
{
    if (!in_array($newStatus, ['belum_bayar', 'sudah_bayar'], true)) {
        return [0, 0];
    }

    $changed   = 0;
    $cancelled = 0;

    $conn->begin_transaction();

    try {
        $upd = $conn->prepare(
            "UPDATE customers SET status_payment = ?
             WHERE id = ? AND status_payment <> ?"
        );
        $cancel = $conn->prepare(
            "UPDATE reminder_logs
             SET status = 'gagal',
                 error_message = 'Dibatalkan: customer sudah bayar'
             WHERE customer_id = ? AND status = 'pending'"
        );

        foreach ($ids as $id) {
            $id = (int)$id;
            $upd->bind_param('sis', $newStatus, $id, $newStatus);
            $upd->execute();

            if ($upd->affected_rows > 0) {
                $changed++;
            }

            if ($newStatus === 'sudah_bayar') {
                $cancel->bind_param('i', $id);
                $cancel->execute();
                $cancelled += $cancel->affected_rows;
            }
        }

        $upd->close();
        $cancel->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    return [$changed, $cancelled];
}

/**
 * Dipakai worker sebelum mengirim WhatsApp.
 * 1) Batalkan antrean pending milik customer yang SUDAH bayar.
 * 2) Kembalikan antrean pending yang masih valid (customer belum bayar).
 */
function getSendableReminders(mysqli $conn, int $limit = 50): array
{
    $conn->query(
        "UPDATE reminder_logs rl
         JOIN customers c ON c.id = rl.customer_id
         SET rl.status = 'gagal',
             rl.error_message = 'Dibatalkan: customer sudah bayar'
         WHERE rl.status = 'pending'
           AND c.status_payment = 'sudah_bayar'"
    );

    $stmt = $conn->prepare(
        "SELECT rl.id AS log_id, rl.template_name,
                c.id AS customer_id, c.cid, c.nama, c.no_telepon,
                c.amount, c.due_date
         FROM reminder_logs rl
         JOIN customers c ON c.id = rl.customer_id
         WHERE rl.status = 'pending'
           AND c.status_payment = 'belum_bayar'
         ORDER BY rl.id ASC
         LIMIT ?"
    );
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}
