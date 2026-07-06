-- =====================================================================
-- KasirKu - Struktur Database MySQL
-- Import file ini melalui phpMyAdmin di cPanel InfinityFree Anda
-- Database tujuan (contoh): if0_42328431_kasirku
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Tabel produk (mencerminkan menu Produk & Stok di aplikasi)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `products` (
  `id` VARCHAR(64) NOT NULL,
  `name` VARCHAR(190) NOT NULL,
  `category` VARCHAR(100) DEFAULT NULL,
  `barcode` VARCHAR(100) DEFAULT NULL,
  `cost` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `price` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `stock` INT NOT NULL DEFAULT 0,
  `stock_old` INT DEFAULT NULL,
  `stock_after_restock` INT DEFAULT NULL,
  `last_restock_at` BIGINT DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_products_barcode` (`barcode`),
  KEY `idx_products_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Tabel toko (mendukung multi-toko / multi-cabang, dikelola oleh Pemilik)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `stores` (
  `id` VARCHAR(64) NOT NULL,
  `name` VARCHAR(190) NOT NULL,
  `address` VARCHAR(255) DEFAULT NULL,
  `position` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Toko bawaan (dibuat otomatis saat instalasi bila belum ada toko lain)
INSERT INTO `stores` (`id`, `name`, `address`, `position`) VALUES ('default', 'Toko Utama', NULL, 0)
  ON DUPLICATE KEY UPDATE id = id;

-- ---------------------------------------------------------------------
-- Tabel transaksi (header)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `transactions` (
  `id` VARCHAR(64) NOT NULL,
  `store_id` VARCHAR(64) NOT NULL DEFAULT 'default',
  `timestamp` BIGINT NOT NULL,
  `subtotal` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `discount` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `total` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `paid` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `change_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `payment_method` VARCHAR(20) DEFAULT 'tunai',
  `kasir_name` VARCHAR(100) DEFAULT NULL,
  `shop_address` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_trx_timestamp` (`timestamp`),
  KEY `idx_trx_store` (`store_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migrasi lunak untuk database yang sudah ada sebelum fitur multi-toko:
-- tambahkan kolom store_id jika tabel transactions sudah ada tapi belum punya kolom ini.
-- (Statement ini sengaja dijalankan terpisah oleh installer, lihat install.php)

-- ---------------------------------------------------------------------
-- Tabel item per transaksi (detail belanja)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `transaction_items` (
  `id` BIGINT AUTO_INCREMENT,
  `transaction_id` VARCHAR(64) NOT NULL,
  `name` VARCHAR(190) NOT NULL,
  `price` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `qty` INT NOT NULL DEFAULT 0,
  `cost` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `subtotal` DECIMAL(14,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_trxitems_trx` (`transaction_id`),
  CONSTRAINT `fk_trxitems_trx` FOREIGN KEY (`transaction_id`)
    REFERENCES `transactions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Riwayat perubahan stok (audit trail: restock & penjualan)
-- Ini yang membuat "Stok lama" / "Stok baru" akurat dan bisa ditelusuri.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `stock_logs` (
  `id` VARCHAR(64) NOT NULL,
  `product_id` VARCHAR(64) NOT NULL,
  `type` ENUM('restock','sale','adjustment') NOT NULL,
  `qty_before` INT NOT NULL,
  `qty_change` INT NOT NULL,
  `qty_after` INT NOT NULL,
  `occurred_at` BIGINT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_stocklog_product` (`product_id`),
  KEY `idx_stocklog_time` (`occurred_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Pengaturan toko (satu baris saja, id selalu 1)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
  `id` TINYINT NOT NULL DEFAULT 1,
  `shop_name` VARCHAR(190) DEFAULT 'Toko Saya',
  `shop_address` VARCHAR(255) DEFAULT NULL,
  `shop_logo` LONGTEXT DEFAULT NULL,
  `print_paper_width` VARCHAR(10) DEFAULT '80',
  `promo_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `settings` (`id`, `shop_name`) VALUES (1, 'Toko Saya')
  ON DUPLICATE KEY UPDATE id = id;

-- ---------------------------------------------------------------------
-- Banner promo berjalan
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `promo_banners` (
  `id` BIGINT AUTO_INCREMENT,
  `text` VARCHAR(255) NOT NULL,
  `position` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Akun pemilik (PIN sudah di-hash SHA-256 di sisi aplikasi sebelum dikirim)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `owner_account` (
  `id` TINYINT NOT NULL DEFAULT 1,
  `pin_hash` VARCHAR(128) DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Akun kasir
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `kasir_accounts` (
  `id` VARCHAR(64) NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `pin_hash` VARCHAR(128) DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Kas laci / modal awal harian
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `kas_buka` (
  `tanggal` VARCHAR(40) NOT NULL,
  `modal_awal` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `kasir_name` VARCHAR(150) DEFAULT NULL,
  `kasir_id` VARCHAR(64) DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`tanggal`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Log akses API (audit keamanan sederhana: siapa/kapan/dari IP mana yang sync)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sync_access_log` (
  `id` BIGINT AUTO_INCREMENT,
  `action` VARCHAR(20) NOT NULL,
  `ip_address` VARCHAR(64) DEFAULT NULL,
  `success` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
