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
