-- ============================================================================
-- Phase 51 — Centralized OTP APIs (email / SMS / WhatsApp), governance KPIs
-- feed (no schema needed), and the Document Management System.
-- Idempotent: guarded CREATEs + WHERE NOT EXISTS seeds; safe to re-run.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1. Centralized OTP service
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `otp_settings` (
  `id` tinyint(1) NOT NULL DEFAULT 1,
  `otp_length` int(11) NOT NULL DEFAULT 6,
  `otp_expiry_minutes` int(11) NOT NULL DEFAULT 10,
  `max_attempts` int(11) NOT NULL DEFAULT 5,
  `rate_limit_per_10min` int(11) NOT NULL DEFAULT 3,
  -- SMS provider (Indian aggregators + custom HTTP)
  `sms_provider` enum('msg91','fast2sms','textlocal','custom') NOT NULL DEFAULT 'msg91',
  `sms_api_key` varchar(300) DEFAULT NULL,
  `sms_sender_id` varchar(20) DEFAULT NULL,
  `sms_template_id` varchar(100) DEFAULT NULL,
  `sms_custom_url` varchar(500) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `otp_settings` (`id`) SELECT 1 WHERE NOT EXISTS (SELECT 1 FROM `otp_settings` WHERE id=1);

CREATE TABLE IF NOT EXISTS `otp_codes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `channel` enum('email','sms','whatsapp') NOT NULL,
  `recipient` varchar(190) NOT NULL,
  `code_hash` varchar(255) NOT NULL,
  `purpose` varchar(60) NOT NULL DEFAULT 'generic',
  `status` enum('sent','verified','expired','failed') NOT NULL DEFAULT 'sent',
  `provider_result` varchar(300) DEFAULT NULL,
  `attempts` int(11) NOT NULL DEFAULT 0,
  `expires_at` timestamp NULL DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `requested_via` varchar(40) DEFAULT NULL,     -- 'console' (IT test) or integration token label
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_lookup` (`channel`,`recipient`,`status`),
  KEY `ix_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- a ready-to-use integration token for the OTP APIs (dev seed; rotate in prod)
INSERT INTO `integration_tokens` (`label`,`token`,`scopes`,`is_active`)
SELECT 'Centralized OTP API', CONCAT('otp_', SUBSTRING(MD5(RAND()), 1, 28)), 'otp', 1
WHERE NOT EXISTS (SELECT 1 FROM `integration_tokens` WHERE `scopes` LIKE '%otp%');

-- ---------------------------------------------------------------------------
-- 2. Document Management System
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `doc_folders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `owner_employee_id` int(11) NOT NULL,
  `visibility` enum('private','all') NOT NULL DEFAULT 'private',
  `is_personal` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_owner` (`owner_employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `doc_folder_access` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `folder_id` int(11) NOT NULL,
  `grantee_type` enum('employee','group') NOT NULL,
  `grantee_id` int(11) NOT NULL,
  `can_edit` tinyint(1) NOT NULL DEFAULT 0,
  `granted_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_grant` (`folder_id`,`grantee_type`,`grantee_id`),
  KEY `ix_grantee` (`grantee_type`,`grantee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `doc_files` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `folder_id` int(11) NOT NULL,
  `orig_name` varchar(255) NOT NULL,
  `stored_name` varchar(255) NOT NULL,
  `mime` varchar(120) DEFAULT NULL,
  `size_bytes` bigint(20) NOT NULL DEFAULT 0,
  `uploaded_by` int(11) NOT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_folder` (`folder_id`,`is_deleted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3. RBAC: Documents for every role (everyone gets a personal folder).
--    The RBAC matrix (admin) can revoke per role; groups can grant extra.
-- ---------------------------------------------------------------------------
INSERT INTO `role_access` (`role`,`module_key`,`can_access`)
SELECT r.role, 'documents', 1 FROM (
  SELECT 'admin' AS role UNION ALL SELECT 'hr_head' UNION ALL SELECT 'hr_manager' UNION ALL SELECT 'hr'
  UNION ALL SELECT 'sales_head' UNION ALL SELECT 'manager' UNION ALL SELECT 'sales_exec'
  UNION ALL SELECT 'service_manager' UNION ALL SELECT 'service_exec'
  UNION ALL SELECT 'support_manager' UNION ALL SELECT 'support'
  UNION ALL SELECT 'it_manager' UNION ALL SELECT 'it'
) r
WHERE NOT EXISTS (
  SELECT 1 FROM `role_access` ra WHERE ra.`role`=r.role AND ra.`module_key`='documents'
);
