-- AI + Voice endpoints: local AI box (Superchat Chat API) with cloud fallback.
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS `ai_settings` (
  `id` tinyint(1) NOT NULL DEFAULT 1,
  `box_enabled` tinyint(1) DEFAULT 0,
  `box_url` varchar(200) DEFAULT 'http://192.168.0.100:8000',
  `ai_api_key` varchar(300) DEFAULT NULL,
  `restart_token` varchar(200) DEFAULT NULL,
  `voice_url` varchar(200) DEFAULT NULL,
  `voice_api_key` varchar(300) DEFAULT NULL,
  `cloud_enabled` tinyint(1) DEFAULT 1,
  `cloud_url` varchar(200) DEFAULT NULL,
  `cloud_api_key` varchar(300) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO `ai_settings` (`id`) VALUES (1);
INSERT INTO `role_access` (`role`,`module_key`,`can_access`) VALUES
 ('admin','users',1),('manager','users',0),('sales_exec','users',0)
ON DUPLICATE KEY UPDATE can_access=VALUES(can_access);

CREATE TABLE IF NOT EXISTS `ai_documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `merchant_id` int(11) DEFAULT NULL, `lead_id` int(11) DEFAULT NULL,
  `filename` varchar(255) DEFAULT NULL, `box_session_id` varchar(80) DEFAULT NULL,
  `mode` enum('qa','summary') DEFAULT 'qa', `chunk_count` int(11) DEFAULT 0,
  `uploaded_by` int(11) DEFAULT NULL, `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), KEY `idx_m` (`merchant_id`), KEY `idx_l` (`lead_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
