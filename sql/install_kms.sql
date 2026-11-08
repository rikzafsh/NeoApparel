-- KMS
-- Neo Apparel - skema database
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
CREATE DATABASE IF NOT EXISTS `db_neo_apparel_kms` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `db_neo_apparel_kms`;

CREATE TABLE IF NOT EXISTS `kms_access_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `knowledge_id` bigint(20) unsigned NOT NULL,
  `action` enum('view','download','share') NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_kms_access_user` (`user_id`),
  KEY `idx_kms_access_knowledge` (`knowledge_id`),
  KEY `idx_kms_access_action` (`action`),
  KEY `idx_kms_access_created` (`created_at`),
  CONSTRAINT `fk_kms_access_knowledge` FOREIGN KEY (`knowledge_id`) REFERENCES `kms_knowledge_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `kms_categories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `parent_id` int(10) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  KEY `idx_kms_categories_parent` (`parent_id`),
  KEY `idx_kms_categories_active` (`is_active`),
  CONSTRAINT `fk_kms_categories_parent` FOREIGN KEY (`parent_id`) REFERENCES `kms_categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `kms_experts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `expertise_title` varchar(150) NOT NULL,
  `bio` text DEFAULT NULL,
  `expertise_level` enum('beginner','intermediate','advanced','expert') NOT NULL DEFAULT 'advanced',
  `is_available` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  KEY `idx_kms_experts_available` (`is_available`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `kms_knowledge_attachments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `knowledge_id` bigint(20) unsigned NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `file_size` bigint(20) unsigned DEFAULT NULL,
  `uploaded_by_user_id` int(10) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_kms_attachment_knowledge` (`knowledge_id`),
  KEY `idx_kms_attachment_uploaded_by` (`uploaded_by_user_id`),
  CONSTRAINT `fk_kms_attachment_knowledge` FOREIGN KEY (`knowledge_id`) REFERENCES `kms_knowledge_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `kms_knowledge_bookmarks` (
  `user_id` int(10) unsigned NOT NULL,
  `knowledge_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`,`knowledge_id`),
  KEY `fk_kms_bookmark_knowledge` (`knowledge_id`),
  CONSTRAINT `fk_kms_bookmark_knowledge` FOREIGN KEY (`knowledge_id`) REFERENCES `kms_knowledge_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `kms_knowledge_comments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `knowledge_id` bigint(20) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `parent_comment_id` bigint(20) unsigned DEFAULT NULL,
  `comment` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_kms_comment_knowledge` (`knowledge_id`),
  KEY `idx_kms_comment_user` (`user_id`),
  KEY `idx_kms_comment_parent` (`parent_comment_id`),
  CONSTRAINT `fk_kms_comment_knowledge` FOREIGN KEY (`knowledge_id`) REFERENCES `kms_knowledge_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_kms_comment_parent` FOREIGN KEY (`parent_comment_id`) REFERENCES `kms_knowledge_comments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `kms_knowledge_experts` (
  `knowledge_id` bigint(20) unsigned NOT NULL,
  `expert_id` bigint(20) unsigned NOT NULL,
  `expert_role` enum('subject_expert','contributor','reviewer') NOT NULL DEFAULT 'subject_expert',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`knowledge_id`,`expert_id`),
  KEY `idx_kms_knowledge_expert_expert` (`expert_id`),
  CONSTRAINT `fk_kms_knowledge_experts_expert` FOREIGN KEY (`expert_id`) REFERENCES `kms_experts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_kms_knowledge_experts_knowledge` FOREIGN KEY (`knowledge_id`) REFERENCES `kms_knowledge_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `kms_knowledge_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `slug` varchar(300) NOT NULL,
  `summary` text DEFAULT NULL,
  `knowledge_nature` enum('explicit','tacit','combined') NOT NULL DEFAULT 'explicit',
  `knowledge_type` enum('article','sop','guide','faq','best_practice','lesson_learned','troubleshooting','policy','other') NOT NULL DEFAULT 'article',
  `category_id` int(10) unsigned DEFAULT NULL,
  `status` enum('draft','under_review','published','archived') NOT NULL DEFAULT 'draft',
  `visibility` enum('public','employees','management') NOT NULL DEFAULT 'employees',
  `owner_user_id` int(10) unsigned NOT NULL,
  `created_by_user_id` int(10) unsigned NOT NULL,
  `updated_by_user_id` int(10) unsigned DEFAULT NULL,
  `published_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `idx_kms_knowledge_category` (`category_id`),
  KEY `idx_kms_knowledge_status` (`status`),
  KEY `idx_kms_knowledge_type` (`knowledge_type`),
  KEY `idx_kms_knowledge_nature` (`knowledge_nature`),
  KEY `idx_kms_knowledge_owner` (`owner_user_id`),
  KEY `idx_kms_knowledge_created_by` (`created_by_user_id`),
  KEY `idx_kms_knowledge_published` (`published_at`),
  CONSTRAINT `fk_kms_knowledge_category` FOREIGN KEY (`category_id`) REFERENCES `kms_categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `kms_knowledge_ratings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `knowledge_id` bigint(20) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `rating` tinyint(3) unsigned NOT NULL,
  `feedback` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_kms_rating_user` (`knowledge_id`,`user_id`),
  KEY `idx_kms_rating_knowledge` (`knowledge_id`),
  KEY `idx_kms_rating_user` (`user_id`),
  CONSTRAINT `fk_kms_rating_knowledge` FOREIGN KEY (`knowledge_id`) REFERENCES `kms_knowledge_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `kms_knowledge_relations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `knowledge_id` bigint(20) unsigned NOT NULL,
  `related_knowledge_id` bigint(20) unsigned NOT NULL,
  `relation_type` enum('related','derived_from','supersedes','duplicate_of','supports') NOT NULL DEFAULT 'related',
  `created_by_user_id` int(10) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_kms_knowledge_relation` (`knowledge_id`,`related_knowledge_id`,`relation_type`),
  KEY `idx_kms_relation_knowledge` (`knowledge_id`),
  KEY `idx_kms_relation_related` (`related_knowledge_id`),
  CONSTRAINT `fk_kms_relation_knowledge` FOREIGN KEY (`knowledge_id`) REFERENCES `kms_knowledge_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_kms_relation_related` FOREIGN KEY (`related_knowledge_id`) REFERENCES `kms_knowledge_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `kms_knowledge_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `requester_user_id` int(10) unsigned NOT NULL,
  `category_id` int(10) unsigned DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `priority` enum('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
  `status` enum('open','in_progress','fulfilled','rejected','cancelled') NOT NULL DEFAULT 'open',
  `assigned_to_user_id` int(10) unsigned DEFAULT NULL,
  `resolved_knowledge_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_kms_request_requester` (`requester_user_id`),
  KEY `idx_kms_request_category` (`category_id`),
  KEY `idx_kms_request_status` (`status`),
  KEY `idx_kms_request_assigned` (`assigned_to_user_id`),
  KEY `idx_kms_request_resolved` (`resolved_knowledge_id`),
  CONSTRAINT `fk_kms_request_category` FOREIGN KEY (`category_id`) REFERENCES `kms_categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_kms_request_knowledge` FOREIGN KEY (`resolved_knowledge_id`) REFERENCES `kms_knowledge_items` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `kms_knowledge_reviews` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `knowledge_id` bigint(20) unsigned NOT NULL,
  `reviewer_user_id` int(10) unsigned NOT NULL,
  `status` enum('pending','approved','rejected','revision_required') NOT NULL DEFAULT 'pending',
  `comments` text DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_kms_review_knowledge` (`knowledge_id`),
  KEY `idx_kms_review_reviewer` (`reviewer_user_id`),
  KEY `idx_kms_review_status` (`status`),
  CONSTRAINT `fk_kms_review_knowledge` FOREIGN KEY (`knowledge_id`) REFERENCES `kms_knowledge_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `kms_knowledge_revisions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `knowledge_id` bigint(20) unsigned NOT NULL,
  `version_number` int(10) unsigned NOT NULL,
  `title` varchar(255) NOT NULL,
  `content` longtext NOT NULL,
  `change_summary` text DEFAULT NULL,
  `created_by_user_id` int(10) unsigned NOT NULL,
  `is_current` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_kms_revision_version` (`knowledge_id`,`version_number`),
  KEY `idx_kms_revision_knowledge` (`knowledge_id`),
  KEY `idx_kms_revision_created_by` (`created_by_user_id`),
  CONSTRAINT `fk_kms_revision_knowledge` FOREIGN KEY (`knowledge_id`) REFERENCES `kms_knowledge_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `kms_knowledge_sources` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `knowledge_id` bigint(20) unsigned NOT NULL,
  `source_type` enum('manual','oas_document','oas_task','tps_product','tps_order','tps_review','other') NOT NULL,
  `source_id` bigint(20) unsigned DEFAULT NULL,
  `source_title` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_by_user_id` int(10) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_kms_source_knowledge` (`knowledge_id`),
  KEY `idx_kms_source_reference` (`source_type`,`source_id`),
  KEY `idx_kms_source_created_by` (`created_by_user_id`),
  CONSTRAINT `fk_kms_source_knowledge` FOREIGN KEY (`knowledge_id`) REFERENCES `kms_knowledge_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `kms_knowledge_tags` (
  `knowledge_id` bigint(20) unsigned NOT NULL,
  `tag_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`knowledge_id`,`tag_id`),
  KEY `fk_kms_knowledge_tags_tag` (`tag_id`),
  CONSTRAINT `fk_kms_knowledge_tags_knowledge` FOREIGN KEY (`knowledge_id`) REFERENCES `kms_knowledge_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_kms_knowledge_tags_tag` FOREIGN KEY (`tag_id`) REFERENCES `kms_tags` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `kms_search_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `keyword` varchar(255) NOT NULL,
  `filters` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`filters`)),
  `result_count` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_kms_search_user` (`user_id`),
  KEY `idx_kms_search_keyword` (`keyword`),
  KEY `idx_kms_search_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `kms_tags` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET FOREIGN_KEY_CHECKS = 1;

SET FOREIGN_KEY_CHECKS = 0;
DELETE FROM `kms_knowledge_ratings`;
DELETE FROM `kms_knowledge_bookmarks`;
DELETE FROM `kms_knowledge_tags`;
DELETE FROM `kms_knowledge_experts`;
DELETE FROM `kms_knowledge_reviews`;
DELETE FROM `kms_knowledge_relations`;
DELETE FROM `kms_knowledge_comments`;
DELETE FROM `kms_knowledge_attachments`;
DELETE FROM `kms_knowledge_sources`;
DELETE FROM `kms_access_logs`;
DELETE FROM `kms_search_logs`;
DELETE FROM `kms_knowledge_revisions`;
DELETE FROM `kms_knowledge_requests`;
DELETE FROM `kms_knowledge_items`;
DELETE FROM `kms_experts`;
DELETE FROM `kms_tags`;
DELETE FROM `kms_categories`;
ALTER TABLE `kms_knowledge_requests` AUTO_INCREMENT = 1;
ALTER TABLE `kms_knowledge_items` AUTO_INCREMENT = 1;
ALTER TABLE `kms_experts` AUTO_INCREMENT = 1;
ALTER TABLE `kms_tags` AUTO_INCREMENT = 1;
ALTER TABLE `kms_categories` AUTO_INCREMENT = 1;
ALTER TABLE `kms_knowledge_revisions` AUTO_INCREMENT = 1;
INSERT INTO `kms_categories` (`id`,`name`,`description`) VALUES
(1,'Operasional Toko','SOP harian: pengemasan, pengiriman, pembayaran'),
(2,'Pengadaan & Supplier','Restock, PO, evaluasi supplier'),
(3,'Penjualan & Promosi','Strategi promosi dan penjualan'),
(4,'Kualitas & Layanan','Kualitas produk, retur, layanan pelanggan'),
(5,'Panduan Produk','Ukuran, perawatan kain, panduan gaya');
INSERT INTO `kms_tags` (`id`,`name`) VALUES
(1,'restock'),
(2,'pengadaan'),
(3,'stok'),
(4,'purchase order'),
(5,'evaluasi supplier'),
(6,'dss'),
(7,'sop pengemasan'),
(8,'pengiriman'),
(9,'kurir'),
(10,'promo'),
(11,'diskon'),
(12,'dead stock'),
(13,'kualitas'),
(14,'retur'),
(15,'quality control'),
(16,'pembayaran'),
(17,'ukuran'),
(18,'perawatan'),
(19,'lonjakan'),
(20,'negosiasi supplier'),
(21,'strategi penjualan');
INSERT INTO `kms_knowledge_items` (`id`,`title`,`slug`,`summary`,`knowledge_nature`,`knowledge_type`,`category_id`,`status`,`visibility`,`owner_user_id`,`created_by_user_id`,`updated_by_user_id`,`published_at`,`created_at`,`updated_at`) VALUES
(1,'SOP Restock Stok Kritis','sop-restock-stok-kritis','Menjaga ketersediaan variasi produk laris agar penjualan tidak hilang.','explicit','sop',2,'published','employees',3,3,3,DATE_SUB(NOW(), INTERVAL 70 DAY),DATE_SUB(NOW(), INTERVAL 70 DAY),DATE_SUB(NOW(), INTERVAL 5 DAY)),
(2,'Panduan Menerbitkan Purchase Order ke Supplier','panduan-menerbitkan-purchase-order-ke-supplier','Pastikan keputusan DSS sudah disetujui dan penawaran masih berlaku.','explicit','guide',2,'published','employees',1,1,1,DATE_SUB(NOW(), INTERVAL 65 DAY),DATE_SUB(NOW(), INTERVAL 65 DAY),DATE_SUB(NOW(), INTERVAL 40 DAY)),
(3,'Panduan Memakai DSS untuk Memilih Supplier','panduan-memakai-dss-untuk-memilih-supplier','Sistem memakai Simple Additive Weighting (SAW) dengan 7 kriteria: harga, tingkat penerimaan, lead time, ketepatan waktu, minimum order, termin pembayaran, dan pelayanan.','combined','guide',2,'published','employees',1,1,1,DATE_SUB(NOW(), INTERVAL 60 DAY),DATE_SUB(NOW(), INTERVAL 60 DAY),DATE_SUB(NOW(), INTERVAL 12 DAY)),
(4,'SOP Pengemasan dan Pengiriman Pesanan','sop-pengemasan-dan-pengiriman-pesanan','Pesanan lunas wajib dikirim maksimal 48 jam.','explicit','sop',1,'published','employees',3,3,3,DATE_SUB(NOW(), INTERVAL 80 DAY),DATE_SUB(NOW(), INTERVAL 80 DAY),DATE_SUB(NOW(), INTERVAL 20 DAY)),
(5,'Strategi Promo untuk Dead Stock','strategi-promo-untuk-dead-stock','Jangan mengobral sekaligus; turunkan harga bertahap.','tacit','best_practice',3,'published','employees',2,2,2,DATE_SUB(NOW(), INTERVAL 55 DAY),DATE_SUB(NOW(), INTERVAL 55 DAY),DATE_SUB(NOW(), INTERVAL 30 DAY)),
(6,'Troubleshooting Keluhan Kualitas Produk','troubleshooting-keluhan-kualitas-produk','Jahitan lepas, warna luntur, ukuran tidak sesuai.','explicit','troubleshooting',4,'published','employees',3,3,3,DATE_SUB(NOW(), INTERVAL 50 DAY),DATE_SUB(NOW(), INTERVAL 50 DAY),DATE_SUB(NOW(), INTERVAL 25 DAY)),
(7,'Kebijakan Retur dan Penukaran','kebijakan-retur-dan-penukaran','Retur diterima maksimal 7 hari setelah barang diterima, produk belum dicuci, label utuh. Ongkir retur ditanggung toko bila barang cacat atau salah kirim.','explicit','policy',4,'published','public',2,2,2,DATE_SUB(NOW(), INTERVAL 90 DAY),DATE_SUB(NOW(), INTERVAL 90 DAY),DATE_SUB(NOW(), INTERVAL 60 DAY)),
(8,'FAQ Pembayaran dan Pesanan Kedaluwarsa','faq-pembayaran-dan-pesanan-kedaluwarsa','**Berapa lama batas pembayaran?** 24 jam setelah checkout. Setelah itu staf dapat membatalkan pesanan dan stok dikembalikan.','explicit','faq',1,'published','employees',3,3,3,DATE_SUB(NOW(), INTERVAL 45 DAY),DATE_SUB(NOW(), INTERVAL 45 DAY),DATE_SUB(NOW(), INTERVAL 18 DAY)),
(9,'Panduan Ukuran dan Perawatan Kain','panduan-ukuran-dan-perawatan-kain','Gunakan tabel ukuran di halaman produk. Cuci dengan air dingin, balik pakaian, jangan gunakan pemutih, setrika suhu sedang.','explicit','guide',5,'published','public',5,5,5,DATE_SUB(NOW(), INTERVAL 85 DAY),DATE_SUB(NOW(), INTERVAL 85 DAY),DATE_SUB(NOW(), INTERVAL 35 DAY)),
(10,'Pelajaran: Lonjakan Permintaan Hoodie','pelajaran-lonjakan-permintaan-hoodie','Saat hoodie viral, stok habis dalam 5 hari karena PO terlambat. Pelajaran: pantau tren 7 hari, siapkan 2 supplier aktif, dan jangan menunggu stok nol untuk memesan.','tacit','lesson_learned',2,'published','employees',2,2,2,DATE_SUB(NOW(), INTERVAL 30 DAY),DATE_SUB(NOW(), INTERVAL 30 DAY),DATE_SUB(NOW(), INTERVAL 3 DAY)),
(11,'Draf: Negosiasi Harga dengan Supplier','draf-negosiasi-harga-dengan-supplier','Draf awal poin negosiasi: volume, termin, penalti keterlambatan.','tacit','guide',2,'draft','employees',1,1,1,NULL,DATE_SUB(NOW(), INTERVAL 8 DAY),DATE_SUB(NOW(), INTERVAL 8 DAY)),
(12,'Rencana Strategi Penjualan Bulanan','rencana-strategi-penjualan-bulanan','Rumus target: 1,2x rata-rata omzet 3 bulan. Tinjau laju mingguan; bila tertinggal >15 poin, aktifkan promosi dan percepat restock produk laris.','explicit','best_practice',3,'published','management',2,2,2,DATE_SUB(NOW(), INTERVAL 40 DAY),DATE_SUB(NOW(), INTERVAL 40 DAY),DATE_SUB(NOW(), INTERVAL 10 DAY)),
(13,'SOP Lama: Pencatatan Stok Manual','sop-lama-pencatatan-stok-manual','SOP pencatatan stok manual sebelum sistem digital. Dipertahankan sebagai arsip rujukan.','explicit','sop',1,'published','employees',3,3,3,DATE_SUB(NOW(), INTERVAL 400 DAY),DATE_SUB(NOW(), INTERVAL 400 DAY),DATE_SUB(NOW(), INTERVAL 380 DAY));
INSERT INTO `kms_knowledge_revisions` (`id`,`knowledge_id`,`version_number`,`title`,`content`,`change_summary`,`created_by_user_id`,`is_current`) VALUES
(1,1,1,'SOP Restock Stok Kritis','## Tujuan
Menjaga ketersediaan variasi produk laris agar penjualan tidak hilang.

## Langkah
1. Buka dashboard Expert System dan lihat inferensi stok kritis.
2. Pastikan tidak ada PO berjalan untuk variasi tersebut.
3. Bila ada >=2 penawaran, jalankan evaluasi DSS (SAW) lalu setujui supplier peringkat 1.
4. Terbitkan PO sebanyak saran restock (cakupan 30 hari).
5. Catat tanggal estimasi tiba dan pantau hingga barang diterima.

## Catatan
Untuk lonjakan permintaan, utamakan supplier dengan lead time terpendek.','Versi awal',3,1),
(2,2,1,'Panduan Menerbitkan Purchase Order ke Supplier','## Persiapan
Pastikan keputusan DSS sudah disetujui dan penawaran masih berlaku.

## Langkah
1. Buka Pembelian Supplier.
2. Pilih supplier hasil keputusan DSS.
3. Masukkan variasi dan jumlah, periksa harga satuan.
4. Simpan lalu kirim ke supplier.
5. Saat barang tiba, catat jumlah diterima dan diterima baik (accepted).','Versi awal',1,1),
(3,3,1,'Panduan Memakai DSS untuk Memilih Supplier','## Metode
Sistem memakai Simple Additive Weighting (SAW) dengan 7 kriteria: harga, tingkat penerimaan, lead time, ketepatan waktu, minimum order, termin pembayaran, dan pelayanan.

## Cara
1. Buat evaluasi atau gunakan evaluasi otomatis dari Expert System.
2. Periksa skor otomatis dari penawaran dan riwayat PO.
3. Isi skor SERVICE secara manual.
4. Hitung dan setujui hasil.','Versi awal',1,1),
(4,4,1,'SOP Pengemasan dan Pengiriman Pesanan','## Prinsip
Pesanan lunas wajib dikirim maksimal 48 jam.

## Langkah
1. Cek SKU dan jumlah.
2. Lipat, masukkan polybag, tempel label.
3. Serahkan ke kurir sebelum pukul 16.00.
4. Input nomor resi di sistem.

## Paket tertahan
Hubungi kurir dengan nomor resi; bila >7 hari tanpa pembaruan, ajukan klaim.','Versi awal',3,1),
(5,5,1,'Strategi Promo untuk Dead Stock','## Prinsip
Jangan mengobral sekaligus; turunkan harga bertahap.

## Taktik
- Minggu 1: diskon 10% + tampil di beranda.
- Minggu 2: bundling dengan produk laris (kaos + celana).
- Minggu 3: diskon 20% dan voucher ongkir.
- Setelah itu hentikan pembelian ulang.','Versi awal',2,1),
(6,6,1,'Troubleshooting Keluhan Kualitas Produk','## Gejala umum
Jahitan lepas, warna luntur, ukuran tidak sesuai.

## Langkah
1. Kumpulkan ulasan dan foto.
2. Cek batch dan supplier pada riwayat PO.
3. Lakukan inspeksi sampel stok yang ada.
4. Bila terbukti, hentikan penjualan sementara dan ajukan klaim ke supplier.','Versi awal',3,1),
(7,7,1,'Kebijakan Retur dan Penukaran','Retur diterima maksimal 7 hari setelah barang diterima, produk belum dicuci, label utuh. Ongkir retur ditanggung toko bila barang cacat atau salah kirim.','Versi awal',2,1),
(8,8,1,'FAQ Pembayaran dan Pesanan Kedaluwarsa','**Berapa lama batas pembayaran?** 24 jam setelah checkout. Setelah itu staf dapat membatalkan pesanan dan stok dikembalikan.

**Pelanggan sudah bayar tetapi status pending?** Periksa transaksi di tabel pembayaran dan hubungi manager.','Versi awal',3,1),
(9,9,1,'Panduan Ukuran dan Perawatan Kain','Gunakan tabel ukuran di halaman produk. Cuci dengan air dingin, balik pakaian, jangan gunakan pemutih, setrika suhu sedang.','Versi awal',5,1),
(10,10,1,'Pelajaran: Lonjakan Permintaan Hoodie','Saat hoodie viral, stok habis dalam 5 hari karena PO terlambat. Pelajaran: pantau tren 7 hari, siapkan 2 supplier aktif, dan jangan menunggu stok nol untuk memesan.','Versi awal',2,1),
(11,11,1,'Draf: Negosiasi Harga dengan Supplier','Draf awal poin negosiasi: volume, termin, penalti keterlambatan.','Versi awal',1,1),
(12,12,1,'Rencana Strategi Penjualan Bulanan','Rumus target: 1,2x rata-rata omzet 3 bulan. Tinjau laju mingguan; bila tertinggal >15 poin, aktifkan promosi dan percepat restock produk laris.','Versi awal',2,1),
(13,13,1,'SOP Lama: Pencatatan Stok Manual','SOP pencatatan stok manual sebelum sistem digital. Dipertahankan sebagai arsip rujukan.','Versi awal',3,1);
INSERT INTO `kms_knowledge_tags` (`knowledge_id`,`tag_id`) VALUES
(1,1),
(1,2),
(1,3),
(2,4),
(2,2),
(3,5),
(3,6),
(4,7),
(4,8),
(4,9),
(5,10),
(5,11),
(5,12),
(6,13),
(6,14),
(6,15),
(7,14),
(8,16),
(9,17),
(9,18),
(10,1),
(10,19),
(11,20),
(12,21),
(12,10),
(13,3);
INSERT INTO `kms_experts` (`id`,`user_id`,`expertise_title`,`bio`,`expertise_level`,`is_available`) VALUES
(1,2,'Strategi penjualan & pengadaan','Manager operasional dengan pengalaman promo dan negosiasi supplier.','expert',1),
(2,3,'Operasional pesanan & gudang','Staf operasional untuk SOP pengemasan dan pengiriman.','advanced',1),
(3,5,'Katalog & gaya produk','Seller pengelola katalog dan panduan ukuran.','advanced',1);
INSERT INTO `kms_knowledge_requests` (`id`,`requester_user_id`,`category_id`,`title`,`description`,`priority`,`status`,`assigned_to_user_id`,`created_at`) VALUES
(1,3,4,'Panduan menghitung margin per produk','Staf butuh rumus margin untuk membandingkan harga jual dan penawaran supplier.','medium','open',NULL,DATE_SUB(NOW(), INTERVAL 10 DAY)),
(2,4,1,'Prosedur paket hilang dari kurir','Butuh langkah klaim bila paket hilang.','high','open',NULL,DATE_SUB(NOW(), INTERVAL 3 DAY)),
(3,3,2,'Template negosiasi supplier','Pembahasan poin tawar-menawar dengan supplier.','medium','in_progress',2,DATE_SUB(NOW(), INTERVAL 6 DAY));
INSERT INTO `kms_knowledge_ratings` (`knowledge_id`,`user_id`,`rating`,`feedback`) VALUES
(1,3,5,'Sangat jelas.'),
(1,4,4,NULL),
(4,3,5,'Dipakai tiap hari.'),
(5,4,4,'Taktik bertahap membantu.'),
(6,3,4,NULL),
(3,2,5,'Mudah diikuti.');
INSERT INTO `kms_knowledge_bookmarks` (`user_id`,`knowledge_id`) VALUES
(3,1),
(3,4),
(4,4),
(2,12);
SET FOREIGN_KEY_CHECKS = 1;
SET FOREIGN_KEY_CHECKS = 1;
