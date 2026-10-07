<?php
/**
 * Import Customer Belum Bayar -> tabel tagihan (satu baris per INVOICE).
 *
 * File dari sistem akuntansi (satu baris = satu invoice):
 *   NO_INV, NO_FAKTUR, TGL, TGL_JATUH_TEMPO, PEMBAYARAN_UNTUK_DARI/KE,
 *   REF_NO_CUSTOMER, NAMA_CUSTOMER, VAT, CANCELED,
 *   KODE_ITEM_n, QTY_n, VAT_n, HARGA_PER_QTY_n, DISKON_PER_QTY_n   (n = 1..10)
 *   TGL_BAYAR_n, METODE_BAYAR_n, ACCOUNT_n, JUMLAH_n               (n = 1..10)
 *
 * File ini TIDAK punya kolom "total tagihan", jadi dihitung:
 *   subtotal = jumlah( QTY x (HARGA_PER_QTY - DISKON_PER_QTY) )
 *   ppn      = subtotal item ber-VAT_n=1 x VAT% / 100      (lihat PPN_DITAMBAHKAN)
 *   tagihan  = subtotal + ppn - jumlah( JUMLAH_n )         (sisa yang harus dibayar)
 *
 * Format sederhana juga diterima: CID, Tagihan, Jatuh Tempo (tanpa item).
 * Nama & nomor telepon TIDAK diambil dari file: selalu dari tabel customers lewat CID.
 */
require_once __DIR__ . '/../helpers.php';
requireLogin();
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/import_page.php';

/**
 * true  = HARGA_PER_QTY belum termasuk PPN, PPN ditambahkan (VAT 12 => +12%).
 * false = HARGA_PER_QTY sudah termasuk PPN, tagihan = harga apa adanya.
 * Cek dengan 1 invoice yang total aslinya kamu tahu, lalu sesuaikan.
 */
const PPN_DITAMBAHKAN = true;
const MAX_ITEM_COLS   = 10;

function isTruthy($v): bool
{
    if (is_float($v) || is_int($v)) {
        return (float)$v != 0.0;
    }
    return in_array(strtolower(trim((string)$v)), ['1', 'y', 'ya', 'yes', 'true'], true);
}

function textCell($v): string
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
            'cid'      => findColumn($headers, ['REF_NO_CUSTOMER', 'CID', 'REF_NO', 'Ref No Customer', 'No Ref', 'Customer ID', 'ID Customer', 'Kode Customer', 'Kode Pelanggan']),
            'due'      => findColumn($headers, ['TGL_JATUH_TEMPO', 'Jatuh Tempo', 'Tanggal Jatuh Tempo', 'Due Date']),
            'inv'      => findColumn($headers, ['NO_INV', 'No Invoice', 'Invoice']),
            'faktur'   => findColumn($headers, ['NO_FAKTUR', 'No Faktur']),
            'tgl'      => findColumn($headers, ['TGL', 'Tanggal', 'Tanggal Invoice']),
            'dari'     => findColumn($headers, ['PEMBAYARAN_UNTUK_DARI', 'Periode Dari']),
            'sampai'   => findColumn($headers, ['PEMBAYARAN_UNTUK_KE', 'Periode Sampai']),
            'vat'      => findColumn($headers, ['VAT', 'PPN']),
            'canceled' => findColumn($headers, ['CANCELED', 'Batal', 'Dibatalkan']),
            'tagihan'  => findColumn($headers, ['Tagihan', 'Jumlah Tagihan', 'Total Tagihan', 'Amount', 'Nominal']),
        ];

        // kolom item (QTY_n / HARGA_PER_QTY_n / DISKON_PER_QTY_n / VAT_n) dan pembayaran (JUMLAH_n)
        $items = [];
        $pays  = [];
        for ($n = 1; $n <= MAX_ITEM_COLS; $n++) {
            $qty   = findColumn($headers, ["QTY_$n"]);
            $harga = findColumn($headers, ["HARGA_PER_QTY_$n"]);
            if ($qty !== null && $harga !== null) {
                $items[] = [
                    'qty'    => $qty,
                    'harga'  => $harga,
                    'diskon' => findColumn($headers, ["DISKON_PER_QTY_$n"]),
                    'vat'    => findColumn($headers, ["VAT_$n"]),
                ];
            }
            $pay = findColumn($headers, ["JUMLAH_$n"]);
            if ($pay !== null) {
                $pays[] = $pay;
            }
        }

        $missing = [];
        if ($col['cid'] === null) { $missing[] = 'REF_NO_CUSTOMER / CID'; }
        if ($col['due'] === null) { $missing[] = 'TGL_JATUH_TEMPO / Jatuh Tempo'; }
        if (!$items && $col['tagihan'] === null) { $missing[] = 'item (QTY_1, HARGA_PER_QTY_1, ...) atau kolom Tagihan'; }
        if ($missing) {
            throw new RuntimeException('Kolom wajib tidak ditemukan: ' . implode(', ', $missing) . '.');
        }

        $total = $ok = $updated = $failed = $dups = $notFoundTotal = $skippedTotal = 0;
        $errors = $duplicates = $notFound = $skipped = [];
        $seen = [];

        $findCustomer = $conn->prepare('SELECT id, cid FROM customers WHERE cid = ? LIMIT 1');
        $findInvoice  = $conn->prepare('SELECT id, status_pembayaran FROM tagihan WHERE no_invoice = ? LIMIT 1');
        $insert = $conn->prepare("INSERT INTO tagihan
            (customer_id, cid, no_invoice, no_faktur, tanggal_invoice, periode_dari, periode_sampai,
             subtotal, ppn, jumlah_dibayar, jumlah_tagihan, tanggal_jatuh_tempo, status_pembayaran)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'belum_bayar')");
        $update = $conn->prepare("UPDATE tagihan SET
            customer_id = ?, cid = ?, no_faktur = ?, tanggal_invoice = ?, periode_dari = ?, periode_sampai = ?,
            subtotal = ?, ppn = ?, jumlah_dibayar = ?, jumlah_tagihan = ?, tanggal_jatuh_tempo = ?
            WHERE id = ?");

        $conn->begin_transaction();

        foreach ($rows as $i => $row) {
            $line = $i + 2;
            $total++;

            $cid = textCell($row[$col['cid']] ?? '');
            if ($cid === '') {
                $failed++;
                if (count($errors) < IMPORT_ERR_LIMIT) { $errors[] = "Baris $line · CID / REF_NO_CUSTOMER kosong"; }
                continue;
            }

            $invNo = $col['inv'] !== null ? textCell($row[$col['inv']] ?? '') : '';

            // invoice dibatalkan -> dilewati
            if ($col['canceled'] !== null && isTruthy($row[$col['canceled']] ?? '')) {
                $skippedTotal++;
                if (count($skipped) < IMPORT_ERR_LIMIT) { $skipped[] = "Baris $line · " . ($invNo ?: $cid) . ' · invoice dibatalkan (CANCELED)'; }
                continue;
            }

            // customer harus sudah ada di Data Customer
            $findCustomer->bind_param('s', $cid);
            $findCustomer->execute();
            $customer = $findCustomer->get_result()->fetch_assoc();
            if (!$customer) {
                $notFoundTotal++;
                if (count($notFound) < IMPORT_ERR_LIMIT) { $notFound[] = "Baris $line · $cid" . ($invNo !== '' ? " · $invNo" : ''); }
                continue;
            }
            $customerId = (int)$customer['id'];
            $dbCid      = $customer['cid'];

            $due = parseDateValue($row[$col['due']] ?? '');
            if ($due === null) {
                $failed++;
                if (count($errors) < IMPORT_ERR_LIMIT) { $errors[] = "Baris $line · CID: $cid · Error: Jatuh Tempo kosong atau format tanggal tidak dikenali"; }
                continue;
            }

            // ---- hitung nominal ----
            $subtotal = 0.0; $ppn = 0.0; $paid = 0.0;
            $rate = $col['vat'] !== null ? (parseAmount($row[$col['vat']] ?? '') ?? 0.0) : 0.0;

            if ($items) {
                foreach ($items as $it) {
                    $qty   = parseAmount($row[$it['qty']] ?? '');
                    $harga = parseAmount($row[$it['harga']] ?? '');
                    if ($qty === null || $harga === null) {
                        continue; // slot item kosong
                    }
                    $diskon = $it['diskon'] !== null ? (parseAmount($row[$it['diskon']] ?? '') ?? 0.0) : 0.0;
                    $lineTotal = $qty * ($harga - $diskon);
                    $subtotal += $lineTotal;

                    $kenaPpn = $it['vat'] !== null ? isTruthy($row[$it['vat']] ?? '') : true;
                    if (PPN_DITAMBAHKAN && $kenaPpn) {
                        $ppn += $lineTotal * $rate / 100;
                    }
                }
            } else {
                $subtotal = parseAmount($row[$col['tagihan']] ?? '') ?? 0.0;
            }
            foreach ($pays as $p) {
                $paid += parseAmount($row[$p] ?? '') ?? 0.0;
            }
            $subtotal = round($subtotal, 2);
            $ppn      = round($ppn, 2);
            $paid     = round($paid, 2);
            $sisa     = round($subtotal + $ppn - $paid, 2);

            if ($subtotal <= 0) {
                $failed++;
                if (count($errors) < IMPORT_ERR_LIMIT) { $errors[] = "Baris $line · CID: $cid · Error: Tagihan tidak bisa dihitung (item/harga kosong atau 0)"; }
                continue;
            }
            if ($sisa <= 0) {
                $skippedTotal++;
                if (count($skipped) < IMPORT_ERR_LIMIT) { $skipped[] = "Baris $line · " . ($invNo ?: $cid) . ' · sudah lunas di file (pembayaran >= tagihan)'; }
                continue;
            }

            if ($invNo === '') {
                $invNo = 'AUTO-' . $dbCid . '-' . $due; // format sederhana tanpa NO_INV
            }
            if (mb_strlen($invNo) > 80) {
                $failed++;
                if (count($errors) < IMPORT_ERR_LIMIT) { $errors[] = "Baris $line · CID: $cid · Error: No invoice terlalu panjang"; }
                continue;
            }

            // duplikat dalam file
            $key = strtolower($invNo);
            if (isset($seen[$key])) {
                $dups++;
                if (count($duplicates) < IMPORT_ERR_LIMIT) { $duplicates[] = "Baris $line · $invNo · sama dengan baris {$seen[$key]} di file ini"; }
                continue;
            }
            $seen[$key] = $line;

            $faktur = $col['faktur'] !== null ? mb_substr(textCell($row[$col['faktur']] ?? ''), 0, 50) : '';
            $faktur = $faktur !== '' ? $faktur : null;
            $tgl    = $col['tgl'] !== null ? parseDateValue($row[$col['tgl']] ?? '') : null;
            $dari   = $col['dari'] !== null ? parseDateValue($row[$col['dari']] ?? '') : null;
            $sampai = $col['sampai'] !== null ? parseDateValue($row[$col['sampai']] ?? '') : null;

            $findInvoice->bind_param('s', $invNo);
            $findInvoice->execute();
            $existing = $findInvoice->get_result()->fetch_assoc();

            if ($existing) {
                if ($existing['status_pembayaran'] === 'sudah_bayar') {
                    $dups++;
                    if (count($duplicates) < IMPORT_ERR_LIMIT) { $duplicates[] = "Baris $line · $invNo · sudah ada di database dan sudah lunas"; }
                    continue;
                }
                // invoice belum bayar yang sama diimport ulang -> perbarui nominal & jatuh tempo
                $tid = (int)$existing['id'];
                $update->bind_param('isssssddddsi', $customerId, $dbCid, $faktur, $tgl, $dari, $sampai, $subtotal, $ppn, $paid, $sisa, $due, $tid);
                $update->execute();
                $updated++;
                continue;
            }

            $insert->bind_param('issssssdddds', $customerId, $dbCid, $invNo, $faktur, $tgl, $dari, $sampai, $subtotal, $ppn, $paid, $sisa, $due);
            $insert->execute();
            $ok++;
        }

        $conn->commit();

        $result = [
            'total'         => $total,
            'summary'       => "$ok invoice baru, $updated diperbarui, $notFoundTotal CID tidak ditemukan, $dups duplikat, $skippedTotal dilewati, $failed gagal.",
            'cards'         => [
                ['TOTAL DATA',           $total,         ''],
                ['INVOICE BARU',         $ok,            'success'],
                ['DIPERBARUI',           $updated,       'update'],
                ['CID TIDAK DITEMUKAN',  $notFoundTotal, 'warn'],
                ['DUPLIKAT',             $dups,          'warn'],
                ['DILEWATI',             $skippedTotal,  'warn'],
                ['GAGAL',                $failed,        'error'],
            ],
            'notFound'      => $notFound,
            'notFoundTotal' => $notFoundTotal,
            'duplicates'    => $duplicates,
            'skipped'       => $skipped,
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
    'intro'        => 'Masukkan daftar invoice customer yang belum membayar. Customer dikenali lewat REF_NO_CUSTOMER (CID); nama dan nomor WhatsApp otomatis diambil dari Data Customer, jadi tidak perlu ada di file ini.',
    'panelTitle'   => 'Upload file belum bayar',
    'panelDesc'    => 'Wajib: REF_NO_CUSTOMER dan TGL_JATUH_TEMPO, plus item (QTY_n, HARGA_PER_QTY_n) untuk menghitung tagihan. NAMA_CUSTOMER di file diabaikan.',
    'dropTitle'    => 'Pilih file belum bayar',
    'submitText'   => 'Import Data Belum Bayar',
    'template'     => 'template_unpaid.php',
    'requirements' => ['CID harus sudah ada di Data Customer', 'Satu baris = satu invoice (NO_INV)', 'Tagihan dihitung dari item', 'No telepon dari master'],
    'formatCode'   => 'NO_INV | TGL_JATUH_TEMPO | REF_NO_CUSTOMER | VAT | QTY_1 | VAT_1 | HARGA_PER_QTY_1 | DISKON_PER_QTY_1 | JUMLAH_1 …',
    'exampleCode'  => '026055-SAA-INV-10-26 | 2026-10-07 | CG000010626 | 12 | 1.00 | 1 | 200000.00 | 0.00',
    'rules'        => [
        ['Hitung', 'Subtotal = QTY × (HARGA − DISKON) tiap item. PPN = VAT% untuk item ber-VAT_n = 1. Dikurangi pembayaran (JUMLAH_n).'],
        ['CID', 'Dicari di Data Customer. Tidak ditemukan = tidak dibuat otomatis, masuk daftar "CID tidak ditemukan".'],
        ['Invoice', 'NO_INV unik. Invoice belum bayar yang diimport ulang diperbarui; yang sudah lunas dilewati (duplikat).'],
        ['Skip', 'CANCELED = 1 dan invoice yang pembayarannya sudah menutup tagihan dilewati.'],
    ],
    'result'  => $result,
    'warning' => $warning,
]);
