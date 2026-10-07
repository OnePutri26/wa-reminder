-- WA Reminder: struktur database (master customer dipisah dari tagihan).
-- Untuk database yang sudah berjalan, gunakan database/migration_2026-10-07_tagihan.sql

CREATE TABLE `admins` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- MASTER DATA CUSTOMER. CID = identitas unik. No telepon format lokal (08xxxxxxxxxx).
CREATE TABLE `customers` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `cid` varchar(50) NOT NULL,
  `nama` varchar(150) NOT NULL,
  `alamat` text DEFAULT NULL,
  `no_telepon` varchar(30) DEFAULT NULL,
  `penagihan_cycle` varchar(30) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cid` (`cid`),
  KEY `idx_no_telepon` (`no_telepon`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- TAGIHAN. Tidak menyimpan nomor telepon: ambil lewat customer_id -> customers.
CREATE TABLE `tagihan` (
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

-- ANTREAN / RIWAYAT REMINDER (satu baris per tagihan yang diingatkan).
CREATE TABLE `reminder_logs` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` int(10) UNSIGNED NOT NULL,
  `tagihan_id` bigint(20) UNSIGNED DEFAULT NULL,
  `template_name` varchar(100) NOT NULL,
  `message_id` varchar(255) DEFAULT NULL,
  `status` enum('pending','terkirim','gagal') NOT NULL DEFAULT 'pending',
  `error_message` text DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `delivered_at` datetime DEFAULT NULL,
  `read_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_customer_id` (`customer_id`),
  KEY `idx_tagihan_id` (`tagihan_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_reminder_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_reminder_tagihan` FOREIGN KEY (`tagihan_id`) REFERENCES `tagihan` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
