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
- `customers` = master data customer. **CID unik.** Nama, alamat, no telepon, penagihan cycle.
- `tagihan` = kewajiban bayar per customer (jumlah, jatuh tempo, status). **Tidak menyimpan nomor telepon**:
  nomor selalu diambil lewat `customer_id` ke `customers`. Ganti nomor di Data Customer, semua tagihan ikut.
- `reminder_logs` = antrean/riwayat reminder per tagihan.

## Alur
1. **Import Data Customer** (CID, Nama, No Telepon, Penagihan Cycle): CID baru ditambah, CID lama diupdate.
2. **Import Customer Belum Bayar** (CID, Tagihan, Jatuh Tempo): CID dicari di `customers`; tidak ketemu = masuk daftar
   "CID tidak ditemukan", customer tidak dibuat otomatis.
3. **Customer Belum Bayar**: kirim reminder (satuan/massal) atau **Tandai Lunas** (antrean pending ikut dibatalkan).
4. `php worker.php` mengirim antrean `pending` (cek ulang status bayar dulu). Provider WhatsApp belum dipasang.

## Struktur
- `config/admin/` : halaman admin. `assets/` : CSS
- `config/helpers.php` : fungsi bersama (normalisasi, CSRF, antrean reminder, layout/sidebar)
- `database/schema.sql` : struktur tabel. `worker.php` : pengirim antrean (CLI)
