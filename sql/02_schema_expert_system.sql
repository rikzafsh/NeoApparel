-- Neo Apparel - skema Expert System (basis pengetahuan, mesin inferensi, jejak keputusan)
-- Dijalankan pada database db_neo_apparel setelah 01_schema_main.sql
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
USE `db_neo_apparel`;

-- Basis pengetahuan: aturan IF-THEN dalam format JSON
CREATE TABLE IF NOT EXISTS `es_rules` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(60) NOT NULL,
  `name` varchar(180) NOT NULL,
  `domain` enum('TPS','OAS','KMS','MIS','DSS','STYLE','CROSS') NOT NULL,
  `subject_type` enum('variation','order','supplier','task','approval','knowledge_request','global','style_session') NOT NULL,
  `rule_kind` enum('derive','inference','recommend') NOT NULL DEFAULT 'inference',
  `description` varchar(500) DEFAULT NULL,
  `conditions` longtext NOT NULL CHECK (json_valid(`conditions`)),
  `derives` longtext DEFAULT NULL CHECK (`derives` IS NULL OR json_valid(`derives`)),
  `severity` enum('info','low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `title_tpl` varchar(255) DEFAULT NULL,
  `message_tpl` varchar(600) DEFAULT NULL,
  `recommendation_tpl` varchar(1000) DEFAULT NULL,
  `actions` longtext DEFAULT NULL CHECK (`actions` IS NULL OR json_valid(`actions`)),
  `kms_keywords` varchar(255) DEFAULT NULL,
  `cf` decimal(4,3) NOT NULL DEFAULT 0.800,
  `priority` smallint(6) NOT NULL DEFAULT 50,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_es_rules_code` (`code`),
  KEY `idx_es_rules_domain_active` (`domain`,`is_active`),
  KEY `idx_es_rules_subject` (`subject_type`,`rule_kind`,`is_active`),
  KEY `fk_es_rules_created_by` (`created_by`),
  CONSTRAINT `fk_es_rules_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Parameter ambang batas yang dipakai aturan ({"param":"nama"})
CREATE TABLE IF NOT EXISTS `es_parameters` (
  `param_key` varchar(60) NOT NULL,
  `param_value` decimal(14,2) NOT NULL,
  `label` varchar(160) NOT NULL,
  `unit` varchar(20) DEFAULT NULL,
  `description` varchar(300) DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`param_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Setiap siklus inferensi (manual, jadwal, event, API)
CREATE TABLE IF NOT EXISTS `es_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `trigger_type` enum('manual','schedule','event','api') NOT NULL DEFAULT 'manual',
  `trigger_label` varchar(120) DEFAULT NULL,
  `domains` varchar(120) DEFAULT NULL,
  `started_by` int(10) unsigned DEFAULT NULL,
  `status` enum('running','completed','failed') NOT NULL DEFAULT 'running',
  `entities_count` int(11) NOT NULL DEFAULT 0,
  `facts_count` int(11) NOT NULL DEFAULT 0,
  `rules_evaluated` int(11) NOT NULL DEFAULT 0,
  `rules_fired` int(11) NOT NULL DEFAULT 0,
  `inferences_new` int(11) NOT NULL DEFAULT 0,
  `inferences_updated` int(11) NOT NULL DEFAULT 0,
  `inferences_resolved` int(11) NOT NULL DEFAULT 0,
  `actions_executed` int(11) NOT NULL DEFAULT 0,
  `duration_ms` int(11) NOT NULL DEFAULT 0,
  `error_message` text DEFAULT NULL,
  `started_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `finished_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_es_runs_started` (`started_at`),
  KEY `fk_es_runs_user` (`started_by`),
  CONSTRAINT `fk_es_runs_user` FOREIGN KEY (`started_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Kesimpulan (inferensi) beserta penjelasan "mengapa"
CREATE TABLE IF NOT EXISTS `es_inferences` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fingerprint` char(40) NOT NULL,
  `rule_id` int(10) unsigned DEFAULT NULL,
  `rule_code` varchar(60) NOT NULL,
  `domain` enum('TPS','OAS','KMS','MIS','DSS','STYLE','CROSS') NOT NULL,
  `subject_type` varchar(30) NOT NULL,
  `subject_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `subject_label` varchar(255) DEFAULT NULL,
  `severity` enum('info','low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `certainty` decimal(4,3) NOT NULL DEFAULT 0.800,
  `title` varchar(255) NOT NULL,
  `message` text DEFAULT NULL,
  `recommendation` text DEFAULT NULL,
  `facts` longtext DEFAULT NULL CHECK (`facts` IS NULL OR json_valid(`facts`)),
  `trace` longtext DEFAULT NULL CHECK (`trace` IS NULL OR json_valid(`trace`)),
  `status` enum('open','acknowledged','in_progress','resolved','dismissed') NOT NULL DEFAULT 'open',
  `first_run_id` bigint(20) unsigned DEFAULT NULL,
  `last_run_id` bigint(20) unsigned DEFAULT NULL,
  `occurrences` int(11) NOT NULL DEFAULT 1,
  `assigned_to` int(10) unsigned DEFAULT NULL,
  `snoozed_until` datetime DEFAULT NULL,
  `resolved_by` int(10) unsigned DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `resolution_note` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_es_inference_fingerprint` (`fingerprint`),
  KEY `idx_es_inf_status_sev` (`status`,`severity`),
  KEY `idx_es_inf_domain_status` (`domain`,`status`),
  KEY `idx_es_inf_subject` (`subject_type`,`subject_id`),
  KEY `idx_es_inf_updated` (`updated_at`),
  KEY `fk_es_inf_rule` (`rule_id`),
  KEY `fk_es_inf_assigned` (`assigned_to`),
  CONSTRAINT `fk_es_inf_rule` FOREIGN KEY (`rule_id`) REFERENCES `es_rules` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_es_inf_assigned` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Jejak integrasi: tindakan yang dikirim ke OAS / DSS / KMS / TPS
CREATE TABLE IF NOT EXISTS `es_actions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `inference_id` bigint(20) unsigned NOT NULL,
  `action_type` varchar(40) NOT NULL,
  `target_system` enum('TPS','OAS','KMS','MIS','DSS') NOT NULL,
  `ref_table` varchar(60) DEFAULT NULL,
  `ref_id` bigint(20) unsigned DEFAULT NULL,
  `status` enum('done','skipped','failed') NOT NULL DEFAULT 'done',
  `detail` varchar(500) DEFAULT NULL,
  `executed_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_es_actions_inference` (`inference_id`),
  KEY `idx_es_actions_ref` (`ref_table`,`ref_id`),
  CONSTRAINT `fk_es_actions_inference` FOREIGN KEY (`inference_id`) REFERENCES `es_inferences` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Atribut gaya produk untuk Neo Stylist (occasion, style, climate, fit, material)
CREATE TABLE IF NOT EXISTS `es_product_attributes` (
  `product_id` int(10) unsigned NOT NULL,
  `attribute` varchar(30) NOT NULL,
  `value` varchar(60) NOT NULL,
  PRIMARY KEY (`product_id`,`attribute`,`value`),
  KEY `idx_es_attr_lookup` (`attribute`,`value`),
  CONSTRAINT `fk_es_attr_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Riwayat konsultasi Neo Stylist
CREATE TABLE IF NOT EXISTS `es_style_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `answers` longtext NOT NULL CHECK (json_valid(`answers`)),
  `results` longtext DEFAULT NULL CHECK (`results` IS NULL OR json_valid(`results`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_es_style_user` (`user_id`,`created_at`),
  CONSTRAINT `fk_es_style_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Token API untuk integrasi mesin pakar dengan sistem lain
CREATE TABLE IF NOT EXISTS `es_api_tokens` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `token_hint` varchar(12) DEFAULT NULL,
  `scopes` varchar(200) NOT NULL DEFAULT 'read',
  `owner_user_id` int(10) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_used_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_es_token_hash` (`token_hash`),
  KEY `fk_es_token_owner` (`owner_user_id`),
  CONSTRAINT `fk_es_token_owner` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Pembatasan percobaan login (anti brute-force)
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(150) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_login_attempts_lookup` (`ip_address`,`email`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET FOREIGN_KEY_CHECKS = 1;
