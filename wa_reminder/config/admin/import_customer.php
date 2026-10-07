<?php
/**
 * Import Data Customer -> tabel customers (master data).
 * Wajib: CID (REF_NO), Nama, No Telepon.
 * Opsional: Penagihan Cycle, Alamat, Email, Paket.
 * CID sudah ada -> UPDATE, belum ada -> INSERT.
 */
require_once __DIR__ . '/../helpers.php';
requireLogin();
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/import_page.php';

/** Nilai sel -> teks bersih (angka Excel tidak jadi 1.0E+5). */
function cellText($v): string
{
    if (is_float($v) || is_int($v)) {
        $v = sprintf('%.0f', $v);
    }
    return trim(preg_replace('/\s+/', ' ', (string)$v));
}

$result  = null;
$warning = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!csrf_valid()) {
            throw new RuntimeException('Token tidak valid. Muat ulang halaman lalu coba lagi.');
        }

        [$headers, $rows] = readUploadedSheet($_FILES['import_file'] ?? []);

        $col = [
            'cid'    => findColumn($headers, ['CID', 'REF_NO', 'Ref No', 'Ref No Customer', 'REF_NO_CUSTOMER', 'No Ref', 'Customer ID', 'ID Customer', 'Kode Customer', 'Kode Pelanggan']),
            'nama'   => findColumn($headers, ['Nama', 'Name', 'Nama Customer', 'Nama Pelanggan']),
            'phone'  => findColumn($headers, ['No Telepon', 'NO_TELP', 'No Telp', 'Telepon', 'No HP', 'HP', 'WhatsApp', 'Phone']),
            // opsional
            'cycle'  => findColumn($headers, ['Penagihan Cycle', 'PENAGIHAN_CYCLE', 'Cycle', 'Siklus']),
            'alamat' => findColumn($headers, ['Alamat', 'Alamat Lengkap', 'Address']),
            'email'  => findColumn($headers, ['Email', 'E-mail']),
            'paket'  => findColumn($headers, ['Paket', 'Package']),
        ];

        $missing = [];
        foreach (['cid' => 'CID / REF_NO', 'nama' => 'Nama', 'phone' => 'No Telepon / NO_TELP'] as $k => $label) {
            if ($col[$k] === null) {
                $missing[] = $label;
            }
        }
        if ($missing) {
            throw new RuntimeException('Kolom wajib tidak ditemukan: ' . implode(', ', $missing) . '.');
        }

        // teks opsional dari kolom (null kalau kolom tidak ada / sel kosong)
        $opt = function (array $row, string $key, int $max = 0) use ($col): ?string {
            if ($col[$key] === null) {
                return null;
            }
            $v = cellText($row[$col[$key]] ?? '');
            if ($v === '') {
                return null;
            }
            return $max > 0 ? mb_substr($v, 0, $max) : $v;
        };

        $total = $new = $updated = $failed = $dups = 0;
        $errors = $duplicates = [];
        $seen = [];

        $find   = $conn->prepare('SELECT id FROM customers WHERE cid = ? LIMIT 1');
        $insert = $conn->prepare('INSERT INTO customers (cid, nama, alamat, no_telepon, penagihan_cycle, email, paket) VALUES (?, ?, ?, ?, ?, ?, ?)');
        // kolom opsional yang kosong di file TIDAK menimpa data lama
        $update = $conn->prepare('UPDATE customers SET nama = ?, no_telepon = ?,
                                  penagihan_cycle = COALESCE(?, penagihan_cycle),
                                  alamat = COALESCE(?, alamat),
                                  email = COALESCE(?, email),
                                  paket = COALESCE(?, paket)
                                  WHERE id = ?');

        $conn->begin_transaction();

        foreach ($rows as $i => $row) {
            $line = $i + 2; // nomor baris Excel (header = 1)
            $total++;

            $cid   = cellText($row[$col['cid']] ?? '');
            $nama  = cellText($row[$col['nama']] ?? '');
            $phone = normalizePhone($row[$col['phone']] ?? '');

            $cycle  = $col['cycle'] !== null ? normalizeCycle($row[$col['cycle']] ?? '') : null;
            $alamat = $opt($row, 'alamat');
            $paket  = $opt($row, 'paket', 50);
            $email  = $opt($row, 'email', 150);
            if ($email !== null) {
                $email = strtolower($email);
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $email = null; // email tidak valid diabaikan, bukan error
                }
            }

            $err = null;
            if ($cid === '') {
                $err = 'CID / REF_NO kosong';
            } elseif (!preg_match('/^[A-Za-z0-9._\-\/]{1,50}$/', $cid)) {
                $err = 'CID tidak valid (hanya huruf, angka, titik, strip, garis miring; maks 50 karakter)';
            } elseif ($nama === '') {
                $err = 'Nama customer kosong';
            } elseif (mb_strlen($nama) > 150) {
                $err = 'Nama terlalu panjang (maks 150 karakter)';
            } elseif ($phone === '') {
                $err = 'No Telepon kosong';
            } elseif (strlen($phone) < 9 || strlen($phone) > 14) {
                $err = 'No Telepon tidak valid (' . $phone . ')';
            }

            if ($err !== null) {
                $failed++;
                if (count($errors) < IMPORT_ERR_LIMIT) {
                    $errors[] = "Baris $line · CID: " . ($cid !== '' ? $cid : '-') . " · Error: $err";
                }
                continue;
            }

            $key = strtolower($cid);
            if (isset($seen[$key])) {
                $dups++;
                if (count($duplicates) < IMPORT_ERR_LIMIT) {
                    $duplicates[] = "Baris $line · CID: $cid · sama dengan baris {$seen[$key]} di file ini";
                }
                continue;
            }
            $seen[$key] = $line;

            $find->bind_param('s', $cid);
            $find->execute();
            $existing = $find->get_result()->fetch_assoc();

            if ($existing) {
                $id = (int)$existing['id'];
                $update->bind_param('ssssssi', $nama, $phone, $cycle, $alamat, $email, $paket, $id);
                $update->execute();
                $updated++;
            } else {
                $insert->bind_param('sssssss', $cid, $nama, $alamat, $phone, $cycle, $email, $paket);
                $insert->execute();
                $new++;
            }
        }

        $conn->commit();

        $result = [
            'total'      => $total,
            'summary'    => "$new customer baru, $updated diperbarui, $failed gagal, $dups duplikat.",
            'cards'      => [
                ['TOTAL DATA',       $total,   ''],
                ['DATA BARU',        $new,     'success'],
                ['DATA DIPERBARUI',  $updated, 'update'],
                ['DATA GAGAL',       $failed,  'error'],
                ['DATA DUPLIKAT',    $dups,    'warn'],
            ],
            'errors'     => $errors,
            'duplicates' => $duplicates,
        ];
    } catch (Throwable $ex) {
        try { $conn->rollback(); } catch (Throwable $_) {}
        $warning = $ex->getMessage();
    }
}

render_import_page([
    'title'        => 'Import Data Customer',
    'brandSub'     => 'Customer Management',
    'eyebrow'      => 'CUSTOMER IMPORT',
    'h1a'          => 'Import',
    'h1b'          => 'Data Customer.',
    'intro'        => 'Masukkan master data customer dari file Excel atau CSV. CID baru ditambahkan, CID yang sudah ada diperbarui (tidak dibuat ganda). Data ini dipakai untuk semua tagihan dan reminder.',
    'panelTitle'   => 'Upload file customer',
    'panelDesc'    => 'Wajib: REF_NO (CID), NAMA, dan NO_TELP. Kolom lain di file (PENAGIHAN_CYCLE, ALAMAT, EMAIL, PAKET) dipakai bila ada; sisanya diabaikan.',
    'dropTitle'    => 'Pilih file customer',
    'submitText'   => 'Import Customer',
    'template'     => 'template_customer.php',
    'requirements' => ['CID / REF_NO wajib & unik', 'Nama wajib', 'No Telepon wajib (jadi 08xxx)', 'Cycle, alamat, email, paket opsional'],
    'formatCode'   => 'REF_NO | NAMA | NO_TELP | PENAGIHAN_CYCLE | ALAMAT | EMAIL | PAKET',
    'exampleCode'  => 'CG000010626 | NICHOLAS THAN | 081188095623 | Cycle 1 | Apt City Garden … | … | P0291',
    'rules'        => [
        ['CID', 'REF_NO / CID = identitas unik. Jika sudah ada, data customer diperbarui (bukan dibuat ganda).'],
        ['Telp', '+62 812-9151-3755, 6281291513755, dan 81291513755 otomatis menjadi 081291513755.'],
        ['Kosong', 'Kolom opsional yang kosong di file tidak menghapus data lama di database.'],
        ['Ganda', 'CID yang muncul dua kali dalam satu file: baris pertama dipakai, sisanya dihitung duplikat.'],
    ],
    'result'  => $result,
    'warning' => $warning,
]);
