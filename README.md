# WA Reminder

Admin panel untuk mengelola customer, import/export Excel, dan antrean reminder pembayaran via WhatsApp.

## Setup
1. `composer install` (butuh PHP 8.1+, ekstensi mysqli, zip, gd/xml)
2. Buat database lalu import skema: `mysql -u wa_reminder -p wa_reminder < database/schema.sql`
3. Sesuaikan koneksi di `config/database.php`
4. Buat admin pertama: `php create_admin.php admin "Nama Lengkap" PasswordKuat123`
5. Arahkan web server ke folder `config/admin/` lalu buka `login.php`

## Struktur
- `config/admin/` : halaman admin (login, reminder, history, import, export, send, send-bulk, template)
- `config/admin/assets/` : CSS
- `config/helpers.php` : fungsi bersama (escape, CSRF, antrean reminder, format nomor WA)
- `database/schema.sql` : struktur tabel

## Alur
Import Excel -> customer masuk tabel `customers` -> "Kirim" / "Reminder Massal" membuat baris
`reminder_logs` berstatus `pending` -> (belum ada) worker yang mengirim ke provider WhatsApp
lalu mengubah status jadi `terkirim` / `gagal`.
