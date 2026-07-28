-- WhatsApp integration settings (single row).
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS `whatsapp_settings` (
  `id` tinyint(1) NOT NULL DEFAULT 1,
  `enabled` tinyint(1) DEFAULT 0,
  `provider` varchar(40) DEFAULT 'meta_cloud',
  `api_token` varchar(600) DEFAULT NULL,
  `phone_number_id` varchar(60) DEFAULT NULL,
  `business_account_id` varchar(60) DEFAULT NULL,
  `from_display` varchar(40) DEFAULT NULL,
  `default_cc` varchar(6) DEFAULT '91',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO `whatsapp_settings` (`id`) VALUES (1);
