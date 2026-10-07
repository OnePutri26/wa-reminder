<?php
/** Edit satu customer. Ubah nomor WhatsApp cukup di sini; semua tagihan ikut otomatis. */
require_once __DIR__ . '/../helpers.php';
requireLogin();
require_once __DIR__ . '/../database.php';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

$st = $conn->prepare('SELECT * FROM customers WHERE id = ?');
$st->bind_param('i', $id);
$st->execute();
$c = $st->get_result()->fetch_assoc();
$st->close();

if (!$c) {
    flash('Customer tidak ditemukan.', 'error');
    header('Location: customers.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        $error = 'Token tidak valid. Muat ulang halaman.';
    } else {
        $nama   = trim($_POST['nama'] ?? '');
        $alamat = trim($_POST['alamat'] ?? '');
        $phone  = normalizePhone($_POST['no_telepon'] ?? '');
        $cycle  = normalizeCycle($_POST['penagihan_cycle'] ?? '');

        if ($nama === '') {
            $error = 'Nama tidak boleh kosong.';
        } elseif ($phone !== '' && (strlen($phone) < 9 || strlen($phone) > 14)) {
            $error = 'Nomor telepon tidak valid.';
        } else {
            $phoneVal  = $phone !== '' ? $phone : null;
            $alamatVal = $alamat !== '' ? $alamat : null;
            $st = $conn->prepare('UPDATE customers SET nama = ?, alamat = ?, no_telepon = ?, penagihan_cycle = ? WHERE id = ?');
            $st->bind_param('ssssi', $nama, $alamatVal, $phoneVal, $cycle, $id);
            $st->execute();
            $st->close();
            flash('Customer ' . $c['cid'] . ' diperbarui.');
            header('Location: customers.php');
            exit;
        }
        $c['nama'] = $nama; $c['alamat'] = $alamat; $c['no_telepon'] = $phone; $c['penagihan_cycle'] = $cycle;
    }
}

layout_start('Edit Customer', 'customers', 'CUSTOMER', 'Edit Customer', 'CID tidak dapat diubah.',
    '<a href="customers.php" class="reminder-btn">← Kembali</a>');
?>
<section class="reminder-card form-card">
    <?php if ($error): ?><div class="app-flash error"><?= e($error) ?></div><?php endif; ?>
    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">

        <div class="reminder-field"><label>CID</label>
            <input type="text" value="<?= e($c['cid']) ?>" readonly></div>
        <div class="reminder-field"><label for="nama">Nama</label>
            <input type="text" id="nama" name="nama" value="<?= e($c['nama']) ?>" required maxlength="150"></div>
        <div class="reminder-field"><label for="no_telepon">No Telepon / WhatsApp</label>
            <input type="text" id="no_telepon" name="no_telepon" value="<?= e($c['no_telepon']) ?>" placeholder="08123456789">
            <p class="hint">Format apa pun diterima (62812…, +62 812…), otomatis disimpan sebagai 08xxx.</p></div>
        <div class="reminder-field"><label for="penagihan_cycle">Penagihan Cycle</label>
            <input type="text" id="penagihan_cycle" name="penagihan_cycle" value="<?= e($c['penagihan_cycle']) ?>" maxlength="30"></div>
        <div class="reminder-field"><label for="alamat">Alamat</label>
            <textarea id="alamat" name="alamat" rows="3"><?= e($c['alamat']) ?></textarea></div>

        <button type="submit" class="reminder-filter-button">Simpan</button>
    </form>
</section>
<?php layout_end();
