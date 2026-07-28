-- Inbound email: mailboxes to read (IMAP/POP3) + fetched emails with AI triage.
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS `email_mailboxes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `label` varchar(120) DEFAULT NULL,
  `protocol` enum('imap','pop3') DEFAULT 'imap',
  `host` varchar(150) DEFAULT NULL,
  `port` int(11) DEFAULT 993,
  `encryption` enum('ssl','tls','none') DEFAULT 'ssl',
  `username` varchar(190) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `folder` varchar(80) DEFAULT 'INBOX',
  `is_active` tinyint(1) DEFAULT 1,
  `last_polled_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inbox_emails` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `mailbox_id` int(11) NOT NULL,
  `msg_uid` varchar(80) NOT NULL,
  `from_email` varchar(190) DEFAULT NULL,
  `from_name` varchar(190) DEFAULT NULL,
  `subject` varchar(400) DEFAULT NULL,
  `snippet` text DEFAULT NULL,
  `received_at` datetime DEFAULT NULL,
  `lead_id` int(11) DEFAULT NULL,
  `ai_summary` text DEFAULT NULL,
  `ai_category` varchar(60) DEFAULT NULL,
  `ai_action` varchar(500) DEFAULT NULL,
  `ai_draft` text DEFAULT NULL,
  `triaged` tinyint(1) DEFAULT 0,
  `status` enum('new','replied','archived') DEFAULT 'new',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_msg` (`mailbox_id`,`msg_uid`),
  KEY `idx_lead` (`lead_id`), KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `role_access` (`role`,`module_key`,`can_access`) VALUES
 ('admin','inbox',1),('manager','inbox',1),('sales_exec','inbox',1)
ON DUPLICATE KEY UPDATE can_access=VALUES(can_access);
