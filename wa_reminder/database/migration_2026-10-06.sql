-- Jalankan sekali di database wa_reminder yang sudah ada.

-- WAJIB: nama customer tidak boleh unik. Dua orang bisa punya nama sama,
-- dan import Excel akan gagal "Duplicate entry" kalau index ini masih ada.
ALTER TABLE `customers` DROP INDEX `uk_customer_cid`;

-- OPSIONAL: kolom lama yang sudah tidak dipakai kode (isinya duplikat nama/no_telepon).
-- ALTER TABLE `customers`
--   DROP COLUMN `customer_name`,
--   DROP COLUMN `full_name`,
--   DROP COLUMN `whatsapp`;
