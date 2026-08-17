-- ============================================================================
-- Phase 57 — Centralized messaging platform for OTHER PayG products:
--   * api_apps: per-app API keys for the centralized OTP / SMS / Email APIs
--   * message_templates: per-app (or global) DLT-registered SMS + email
--     templates ({#var#} placeholders; OTP templates flagged)
--   * email_servers: multiple named sender accounts (noreply, onboarding,
--     transaction receipts, info …) — admin & IT head configurable
--   * otp_settings: HSP SMS gateway (sms.hspsms.com) as a first-class provider
-- The 70+ DLT SMS templates from the client sheet are seeded by
-- database/migrations/phase57b_template_seed.sql; email template HTML lives in
-- data/email-templates/<Product>/ and is imported via seed-templates.php.
-- Idempotent; safe to re-run.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `api_apps` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `app_name` varchar(120) NOT NULL,
  `app_code` varchar(40) NOT NULL,
  `description` varchar(300) DEFAULT NULL,
  `api_key` varchar(64) NOT NULL,
  `scopes` varchar(120) NOT NULL DEFAULT 'otp',      -- comma list: otp,sms,email
  `sms_sender_id` varchar(12) DEFAULT NULL,           -- DLT header, e.g. PAYGin
  `email_server_id` int(11) DEFAULT NULL,             -- which sender account (NULL = default)
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_code` (`app_code`),
  UNIQUE KEY `uq_key` (`api_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `message_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `app_id` int(11) DEFAULT NULL,                      -- NULL = global library
  `channel` enum('sms','email') NOT NULL DEFAULT 'sms',
  `purpose` varchar(80) NOT NULL DEFAULT 'generic',   -- e.g. merchant_registration, forgot_password
  `name` varchar(160) NOT NULL,
  `dlt_template_id` varchar(30) DEFAULT NULL,
  `sender_header` varchar(60) DEFAULT NULL,
  `subject` varchar(200) DEFAULT NULL,                -- email only
  `content` mediumtext NOT NULL,                      -- {#var#} positional or {#otp#}/{#minutes#} named
  `is_otp` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_lookup` (`channel`,`purpose`,`app_id`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_servers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL,
  `from_name` varchar(120) DEFAULT 'PayG',
  `from_email` varchar(150) NOT NULL,
  `smtp_host` varchar(150) DEFAULT NULL,
  `smtp_port` int(11) DEFAULT 587,
  `encryption` enum('none','ssl','tls') DEFAULT 'tls',
  `username` varchar(190) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- the client's four sender accounts, credentials to be entered by IT (never shipped)
INSERT INTO `email_servers` (`name`,`from_name`,`from_email`,`smtp_host`,`smtp_port`,`encryption`,`username`,`is_active`)
SELECT * FROM (
  SELECT 'Onboarding (noreply)' n, 'PayG Onboarding' fn, 'Noreply.Onboarding@payg.in' fe, 'smtp.office365.com' h, 587 p, 'tls' e, 'Noreply.Onboarding@payg.in' u, 0 a UNION ALL
  SELECT 'Transaction Receipts', 'PayG Receipts', 'TransactionReceipt@payg.in', 'smtp.office365.com', 587, 'tls', 'TransactionReceipt@payg.in', 0 UNION ALL
  SELECT 'Info', 'PayG', 'info@payg.in', 'smtp.office365.com', 587, 'tls', 'info@payg.in', 0 UNION ALL
  SELECT 'No-Reply (general)', 'PayG', 'noreply@payg.in', 'smtp.office365.com', 587, 'tls', 'noreply@payg.in', 0
) s
WHERE NOT EXISTS (SELECT 1 FROM `email_servers`);

-- HSP SMS gateway as a first-class provider (per the client's SendOtp code)
ALTER TABLE `otp_settings`
  MODIFY `sms_provider` enum('hspsms','msg91','fast2sms','textlocal','custom') NOT NULL DEFAULT 'hspsms',
  ADD COLUMN IF NOT EXISTS `sms_username` varchar(80) DEFAULT NULL;

-- a demo app so the IT screen has a working example (rotate in prod)
INSERT INTO `api_apps` (`app_name`,`app_code`,`description`,`api_key`,`scopes`,`sms_sender_id`)
SELECT 'PayG MMS','mms','Merchant Management System', CONCAT('app_', SUBSTRING(MD5(RAND()),1,28)), 'otp,sms,email', 'PAYGin'
WHERE NOT EXISTS (SELECT 1 FROM `api_apps`);

