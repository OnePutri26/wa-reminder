<?php
/**
 * Import Data Customer -> tabel customers (master data).
 * CID = identitas unik. CID sudah ada -> UPDATE, belum ada -> INSERT.
 */
require_once __DIR__ . '/../helpers.php';
requireLogin();
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/import_page.php';

/** Nilai sel -> teks CID bersih (angka Excel tidak jadi 1.0E+5). */
function cellText($v): string
{
    if (is_float($v) || is_int($v)) {
        $v = sprintf('%.0f', $v);
    }
    return trim((string)$v);
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
            'cid'   => findColumn($headers, ['CID', 'Customer ID', 'ID Customer', 'Kode Customer', 'Kode Pelanggan', 'No Ref']),
            'nama'  => findColumn($headers, ['Nama', 'Name', 'Nama Customer', 'Nama Pelanggan']),
            'phone' => findColumn($headers, ['No Telepon', 'No Telp', 'Telepon', 'No HP', 'WhatsApp', 'Phone']),
            'cycle' => findColumn($headers, ['Penagihan Cycle', 'Cycle', 'Siklus', 'Siklus Penagihan']),
            'alamat'=> findColumn($headers, ['Alamat', 'Address']), // opsional
        ];

        $missing = [];
        foreach (['cid' => 'CID', 'nama' => 'Nama', 'phone' => 'No Telepon', 'cycle' => 'Penagihan Cycle'] as $k => $label) {
            if ($col[$k] === null) {
                $missing[] = $label;
            }
        }
        if ($missing) {
            throw new RuntimeException('Kolom wajib tidak ditemukan: ' . implode(', ', $missing) . '.');
        }

        $total = $new = $updated = $failed = $dups = 0;
        $errors = $duplicates = [];
        $seen = [];

        $find   = $conn->prepare('SELECT id FROM customers WHERE cid = ? LIMIT 1');
        $insert = $conn->prepare('INSERT INTO customers (cid, nama, alamat, no_telepon, penagihan_cycle) VALUES (?, ?, ?, ?, ?)');
        $update = $conn->prepare('UPDATE customers SET nama = ?, no_telepon = ?, penagihan_cycle = ? WHERE id = ?');
        $updateAlamat = $conn->prepare('UPDATE customers SET alamat = ? WHERE id = ?');

        $conn->begin_transaction();

        foreach ($rows as $i => $row) {
            $line  = $i + 2; // baris Excel (header = 1)
            $total++;

            $cid   = cellText($row[$col['cid']] ?? '');
            $nama  = trim((string)($row[$col['nama']] ?? ''));
            $phone = normalizePhone($row[$col['phone']] ?? '');
            $cycle = normalizeCycle($row[$col['cycle']] ?? '');
            $alamat = $col['alamat'] !== null ? trim((string)($row[$col['alamat']] ?? '')) : null;

            $err = null;
            if ($cid === '') {
                $err = 'CID kosong';
            } elseif (!preg_match('/^[A-Za-z0-9._\-\/]{1,50}$/', $cid)) {
                $err = 'CID tidak valid (hanya huruf, angka, titik, strip, garis miring; maks 50 karakter)';
            } elseif ($nama === '') {
                $err = 'Nama customer kosong';
            } elseif (mb_strlen($nama) > 150) {
                $err = 'Nama terlalu panjang (maks 150 karakter)';
            } elseif ($phone === '' || strlen($phone) < 9 || strlen($phone) > 14) {
                $err = 'No Telepon kosong atau tidak valid';
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
                $update->bind_param('sssi', $nama, $phone, $cycle, $id);
                $update->execute();
                if ($alamat !== null && $alamat !== '') {
                    $updateAlamat->bind_param('si', $alamat, $id);
                    $updateAlamat->execute();
                }
                $updated++;
            } else {
                $alamatVal = ($alamat !== null && $alamat !== '') ? $alamat : null;
                $insert->bind_param('sssss', $cid, $nama, $alamatVal, $phone, $cycle);
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
    'intro'        => 'Masukkan master data customer dari file Excel atau CSV. CID baru akan ditambahkan, sedangkan CID yang sudah ada akan diperbarui (tidak dibuat ganda). Data ini dipakai untuk semua tagihan dan reminder.',
    'panelTitle'   => 'Upload file customer',
    'panelDesc'    => 'CID, Nama, No Telepon, dan Penagihan Cycle wajib ada di header. Kolom Alamat boleh ditambahkan (opsional).',
    'dropTitle'    => 'Pilih file customer',
    'submitText'   => 'Import Customer',
    'template'     => 'template_customer.php',
    'requirements' => ['CID unik', 'Nama wajib', 'No Telepon otomatis 08xxx', 'Penagihan Cycle'],
    'formatCode'   => 'CID | Nama | No Telepon | Penagihan Cycle',
    'exampleCode'  => 'CUST001 | Budi Santoso | 081234567890 | 10',
    'rules'        => [
        ['CID', 'ID unik customer. Jika sudah ada, data customer diperbarui.'],
        ['No', '628123456789, +62 812-3456-789, dan 8123456789 otomatis menjadi 08123456789.'],
        ['Cycle', 'Siklus/tanggal penagihan, disimpan apa adanya (10, 15, 20, ...).'],
        ['Ganda', 'CID yang muncul dua kali dalam satu file: baris pertama dipakai, sisanya dihitung duplikat.'],
    ],
    'result'  => $result,
    'warning' => $warning,
]);
