-- Struktur sesuai database produksi (wa_reminder.sql). Tanpa data.
CREATE TABLE `admins` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `customers` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `cid` varchar(50) NOT NULL,
  `nama` varchar(150) NOT NULL,
  `no_telepon` varchar(30) NOT NULL,           -- format 62xxxxxxxxxx
  `status_payment` enum('belum_bayar','sudah_bayar') NOT NULL DEFAULT 'belum_bayar',
  `amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `due_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_cid` (`cid`),
  KEY `idx_whatsapp` (`no_telepon`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `reminder_logs` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` int(10) UNSIGNED NOT NULL,
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
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_reminder_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
