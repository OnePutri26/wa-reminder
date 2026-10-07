# WA Reminder

Admin panel untuk mengelola customer, tagihan belum bayar, dan antrean reminder pembayaran via WhatsApp.

## Setup (instalasi baru)
1. `composer install` (PHP 8.1+, ekstensi mysqli, zip, gd/xml)
2. Buat database lalu import skema: `mysql -u wa_reminder -p wa_reminder < database/schema.sql`
3. Sesuaikan koneksi di `config/database.php`
4. Buat admin pertama: `php create_admin.php admin "Nama Lengkap" PasswordKuat123`
5. Arahkan web server ke folder `config/admin/` lalu buka `login.php`

## Upgrade dari versi lama
Backup database, lalu jalankan `database/migration_2026-10-07_tagihan.sql` (MariaDB). Data
`status_payment / amount / due_date` di `customers` dipindah ke tabel `tagihan`, nomor telepon
diubah ke format 08xxx.

## Konsep data
- `customers` = master data customer. **CID (REF_NO) unik.** Wajib: CID, nama, no telepon. Opsional: alamat, penagihan cycle, email, paket.
- `tagihan` = satu baris per **invoice** (`no_invoice` unik). **Tidak menyimpan nama/nomor telepon**: selalu diambil lewat
  `customer_id` ke `customers`. Ganti nomor di Data Customer, semua tagihan ikut.
- `reminder_logs` = antrean/riwayat reminder per tagihan.

## Format file Excel (.xls / .xlsx / .csv)
**Import Data Customer** (export data customer sistem akuntansi): `REF_NO`, `NAMA`, `NO_TELP` wajib;
`PENAGIHAN_CYCLE`, `ALAMAT`, `EMAIL`, `PAKET` opsional. Kolom lain diabaikan. Nomor telepon otomatis jadi 08xxx.

**Import Customer Belum Bayar** (export invoice): `REF_NO_CUSTOMER`, `TGL_JATUH_TEMPO`, dan item `QTY_n` / `HARGA_PER_QTY_n`
(`DISKON_PER_QTY_n`, `VAT_n` bila ada). Nominal dihitung: subtotal = QTY x (HARGA - DISKON); PPN = `VAT`% untuk item
`VAT_n = 1`; sisa tagihan = subtotal + PPN - `JUMLAH_n` (pembayaran). `CANCELED = 1` dilewati.
Pengaturan PPN ada di `config/admin/import_unpaid.php` (`PPN_DITAMBAHKAN`).

## Alur
1. **Import Data Customer**: CID baru ditambah, CID lama diupdate.
2. **Import Customer Belum Bayar**: CID dicari di `customers`; tidak ketemu = masuk daftar "CID tidak ditemukan",
   customer tidak dibuat otomatis. Invoice yang sama diimport ulang = diperbarui (kalau belum lunas).
3. **Customer Belum Bayar**: kirim reminder (satuan/massal) atau **Tandai Lunas** (antrean pending ikut dibatalkan).
4. `php worker.php` mengirim antrean `pending` (cek ulang status bayar dulu). Provider WhatsApp belum dipasang.

## Struktur
- `config/admin/` : halaman admin. `assets/` : CSS
- `config/helpers.php` : fungsi bersama (normalisasi, CSRF, antrean reminder, layout/sidebar)
- `database/schema.sql` : struktur tabel. `worker.php` : pengirim antrean (CLI)
