-- Migrasi database LAMA (customers berisi status_payment/amount/due_date) ke struktur baru.
-- BACKUP DULU:  mysqldump -u wa_reminder -p wa_reminder > backup.sql
-- Database MariaDB. Jalankan sekali.
-- Untuk database baru/kosong cukup pakai database/schema.sql.

-- 1. Pastikan kolom customers sesuai struktur baru.
ALTER TABLE `customers`
  ADD COLUMN IF NOT EXISTS `alamat` text DEFAULT NULL AFTER `nama`,
  ADD COLUMN IF NOT EXISTS `penagihan_cycle` varchar(30) DEFAULT NULL AFTER `no_telepon`,
  MODIFY `no_telepon` varchar(30) DEFAULT NULL;

-- 2. Nomor telepon ke format lokal: 628123456789 -> 08123456789
UPDATE `customers` SET `no_telepon` = CONCAT('0', SUBSTRING(`no_telepon`, 3))
WHERE `no_telepon` LIKE '62%';

-- 3. Tabel tagihan.
CREATE TABLE IF NOT EXISTS `tagihan` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` int(10) UNSIGNED NOT NULL,
  `cid` varchar(50) NOT NULL,
  `jumlah_tagihan` decimal(15,2) NOT NULL DEFAULT 0.00,
  `tanggal_jatuh_tempo` date DEFAULT NULL,
  `status_pembayaran` enum('belum_bayar','sudah_bayar') NOT NULL DEFAULT 'belum_bayar',
  `paid_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_customer_jatuh_tempo` (`customer_id`,`tanggal_jatuh_tempo`),
  KEY `idx_cid` (`cid`),
  KEY `idx_status` (`status_pembayaran`),
  KEY `idx_jatuh_tempo` (`tanggal_jatuh_tempo`),
  CONSTRAINT `fk_tagihan_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 4. Pindahkan data tagihan lama (status, amount, due_date) dari customers.
INSERT IGNORE INTO `tagihan`
  (`customer_id`, `cid`, `jumlah_tagihan`, `tanggal_jatuh_tempo`, `status_pembayaran`, `paid_at`)
SELECT `id`, `cid`, `amount`, `due_date`, `status_payment`,
       IF(`status_payment` = 'sudah_bayar', `updated_at`, NULL)
FROM `customers`
WHERE `due_date` IS NOT NULL;

-- 5. reminder_logs: hubungkan ke tagihan.
ALTER TABLE `reminder_logs`
  ADD COLUMN IF NOT EXISTS `tagihan_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `customer_id`,
  ADD KEY `idx_tagihan_id` (`tagihan_id`);

UPDATE `reminder_logs` rl
SET rl.`tagihan_id` = (
  SELECT t.`id` FROM `tagihan` t
  WHERE t.`customer_id` = rl.`customer_id`
  ORDER BY t.`tanggal_jatuh_tempo` DESC LIMIT 1
)
WHERE rl.`tagihan_id` IS NULL;

ALTER TABLE `reminder_logs`
  ADD CONSTRAINT `fk_reminder_tagihan` FOREIGN KEY (`tagihan_id`) REFERENCES `tagihan` (`id`) ON DELETE CASCADE;

-- 6. CID harus unik. (Lewati baris ini kalau cid sudah punya UNIQUE index, mis. `unique_cid`.)
-- ALTER TABLE `customers` ADD UNIQUE KEY `uk_cid` (`cid`);

-- 7. Setelah semua halaman baru dicek dan jalan, kolom lama boleh dihapus:
-- ALTER TABLE `customers`
--   DROP COLUMN `status_payment`, DROP COLUMN `amount`, DROP COLUMN `due_date`;
--   -- dan kolom lama lain bila masih ada: customer_name, full_name, whatsapp, last_sent_at
