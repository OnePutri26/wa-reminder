<?php
/**
 * Import Customer Belum Bayar -> tabel tagihan.
 * Nama & nomor telepon TIDAK diambil dari file: selalu dari tabel customers
 * lewat CID. CID yang tidak ada di customers tidak dibuat otomatis.
 */
require_once __DIR__ . '/../helpers.php';
requireLogin();
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/import_page.php';

$result  = null;
$warning = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!csrf_valid()) {
            throw new RuntimeException('Token tidak valid. Muat ulang halaman lalu coba lagi.');
        }

        [$headers, $rows] = readUploadedSheet($_FILES['import_file'] ?? []);

        $col = [
            'cid'    => findColumn($headers, ['CID', 'Customer ID', 'ID Customer', 'Kode Customer', 'Kode Pelanggan', 'No Ref']),
            'amount' => findColumn($headers, ['Tagihan', 'Jumlah Tagihan', 'Amount', 'Total Tagihan', 'Nominal']),
            'due'    => findColumn($headers, ['Jatuh Tempo', 'Tanggal Jatuh Tempo', 'Due Date', 'Tgl Jatuh Tempo']),
        ];

        $missing = [];
        foreach (['cid' => 'CID', 'amount' => 'Tagihan', 'due' => 'Jatuh Tempo'] as $k => $label) {
            if ($col[$k] === null) {
                $missing[] = $label;
            }
        }
        if ($missing) {
            throw new RuntimeException('Kolom wajib tidak ditemukan: ' . implode(', ', $missing) . '.');
        }

        $total = $ok = $failed = $dups = $notFoundTotal = 0;
        $errors = $duplicates = $notFound = [];
        $seen = [];

        $findCustomer = $conn->prepare('SELECT id, cid, nama FROM customers WHERE cid = ? LIMIT 1');
        $findTagihan  = $conn->prepare('SELECT status_pembayaran FROM tagihan WHERE customer_id = ? AND tanggal_jatuh_tempo = ? LIMIT 1');
        $insert       = $conn->prepare("INSERT INTO tagihan (customer_id, cid, jumlah_tagihan, tanggal_jatuh_tempo, status_pembayaran) VALUES (?, ?, ?, ?, 'belum_bayar')");

        $conn->begin_transaction();

        foreach ($rows as $i => $row) {
            $line = $i + 2;
            $total++;

            $cidRaw = $row[$col['cid']] ?? '';
            if (is_float($cidRaw) || is_int($cidRaw)) {
                $cidRaw = sprintf('%.0f', $cidRaw);
            }
            $cid    = trim((string)$cidRaw);
            $amount = parseAmount($row[$col['amount']] ?? '');
            $due    = parseDateValue($row[$col['due']] ?? '');

            if ($cid === '') {
                $failed++;
                if (count($errors) < IMPORT_ERR_LIMIT) {
                    $errors[] = "Baris $line · CID kosong";
                }
                continue;
            }

            $findCustomer->bind_param('s', $cid);
            $findCustomer->execute();
            $customer = $findCustomer->get_result()->fetch_assoc();

            if (!$customer) {
                $notFoundTotal++;
                if (count($notFound) < IMPORT_ERR_LIMIT) {
                    $notFound[] = "Baris $line · $cid";
                }
                continue;
            }

            $err = null;
            if ($amount === null || $amount < 0) {
                $err = 'Tagihan kosong atau bukan angka';
            } elseif ($due === null) {
                $err = 'Jatuh Tempo kosong atau format tanggal tidak dikenali (pakai 2026-10-10 atau 10-10-2026)';
            }
            if ($err !== null) {
                $failed++;
                if (count($errors) < IMPORT_ERR_LIMIT) {
                    $errors[] = "Baris $line · CID: $cid · Error: $err";
                }
                continue;
            }

            $customerId = (int)$customer['id'];
            $dbCid      = $customer['cid'];

            // duplikat dalam file
            $key = $customerId . '|' . $due;
            if (isset($seen[$key])) {
                $dups++;
                if (count($duplicates) < IMPORT_ERR_LIMIT) {
                    $duplicates[] = "Baris $line · CID: $cid · jatuh tempo " . tanggal($due) . " sama dengan baris {$seen[$key]} di file ini";
                }
                continue;
            }
            $seen[$key] = $line;

            // duplikat di database (customer + jatuh tempo yang sama)
            $findTagihan->bind_param('is', $customerId, $due);
            $findTagihan->execute();
            $existing = $findTagihan->get_result()->fetch_assoc();
            if ($existing) {
                $dups++;
                if (count($duplicates) < IMPORT_ERR_LIMIT) {
                    $duplicates[] = "Baris $line · CID: $cid · tagihan jatuh tempo " . tanggal($due)
                        . ' sudah ada (' . paymentLabel($existing['status_pembayaran']) . ')';
                }
                continue;
            }

            $insert->bind_param('isds', $customerId, $dbCid, $amount, $due);
            $insert->execute();
            $ok++;
        }

        $conn->commit();

        $result = [
            'total'         => $total,
            'summary'       => "$ok tagihan berhasil, $notFoundTotal CID tidak ditemukan, $dups duplikat, $failed gagal.",
            'cards'         => [
                ['TOTAL DATA',            $total,         ''],
                ['BERHASIL',              $ok,            'success'],
                ['CID TIDAK DITEMUKAN',   $notFoundTotal, 'warn'],
                ['DUPLIKAT',              $dups,          'warn'],
                ['GAGAL',                 $failed,        'error'],
            ],
            'notFound'      => $notFound,
            'notFoundTotal' => $notFoundTotal,
            'duplicates'    => $duplicates,
            'errors'        => $errors,
        ];
    } catch (Throwable $ex) {
        try { $conn->rollback(); } catch (Throwable $_) {}
        $warning = $ex->getMessage();
    }
}

render_import_page([
    'title'        => 'Import Customer Belum Bayar',
    'brandSub'     => 'Tagihan',
    'eyebrow'      => 'TAGIHAN IMPORT',
    'h1a'          => 'Import Customer',
    'h1b'          => 'Belum Bayar.',
    'intro'        => 'Masukkan daftar customer yang belum membayar. Cukup CID, Tagihan, dan Jatuh Tempo: nama dan nomor WhatsApp otomatis diambil dari Data Customer, jadi tidak perlu diinput ulang.',
    'panelTitle'   => 'Upload file belum bayar',
    'panelDesc'    => 'Kolom wajib: CID, Tagihan, Jatuh Tempo. Kolom Nama boleh ada tetapi diabaikan (nama dari database customer).',
    'dropTitle'    => 'Pilih file belum bayar',
    'submitText'   => 'Import Data Belum Bayar',
    'template'     => 'template_unpaid.php',
    'requirements' => ['CID harus sudah ada di Data Customer', 'Tagihan angka', 'Jatuh Tempo tanggal', 'No telepon dari master'],
    'formatCode'   => 'CID | Nama (opsional) | Tagihan | Jatuh Tempo',
    'exampleCode'  => 'CUST001 | Budi Santoso | 150000 | 2026-10-10',
    'rules'        => [
        ['CID', 'Dicari di Data Customer. Tidak ditemukan = tidak dibuat otomatis, masuk daftar "CID tidak ditemukan".'],
        ['No', 'Nomor telepon selalu dari tabel customers, bukan dari file ini.'],
        ['Ganda', 'Customer yang sama dengan jatuh tempo yang sama dianggap duplikat dan dilewati.'],
        ['Tgl', 'Format tanggal: 2026-10-10, 10-10-2026, 10/10/2026, atau sel tanggal Excel.'],
    ],
    'result'  => $result,
    'warning' => $warning,
]);
