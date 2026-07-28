-- Email / notification account used to send app notifications (single row).
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS `email_settings` (
  `id` tinyint(1) NOT NULL DEFAULT 1,
  `method` enum('smtp','ad_exchange','imap_pop') NOT NULL DEFAULT 'smtp',
  `from_name` varchar(120) DEFAULT 'PayG CRM',
  `from_email` varchar(150) DEFAULT NULL,
  `smtp_host` varchar(150) DEFAULT NULL,
  `smtp_port` int(11) DEFAULT 587,
  `encryption` enum('none','ssl','tls') DEFAULT 'tls',
  `username` varchar(190) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `ad_domain` varchar(120) DEFAULT NULL,
  `imap_host` varchar(150) DEFAULT NULL,
  `imap_port` int(11) DEFAULT 993,
  `is_active` tinyint(1) DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO `email_settings` (`id`,`from_name`) VALUES (1,'PayG CRM');
