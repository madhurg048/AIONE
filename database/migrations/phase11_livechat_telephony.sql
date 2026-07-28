-- Live chat (visitor <-> agent), click-to-call telephony, call logs. + timesheet activity types.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `chat_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `token` varchar(48) NOT NULL,
  `visitor_name` varchar(120) DEFAULT NULL,
  `visitor_email` varchar(150) DEFAULT NULL,
  `visitor_phone` varchar(40) DEFAULT NULL,
  `lead_id` int(11) DEFAULT NULL,
  `status` enum('waiting','active','closed') DEFAULT 'waiting',
  `agent_id` int(11) DEFAULT NULL,
  `started_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `picked_at` datetime DEFAULT NULL,
  `last_activity_at` timestamp NULL DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `duration_sec` int(11) DEFAULT 0,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_token` (`token`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` int(11) NOT NULL,
  `sender` enum('visitor','agent','bot','system') NOT NULL,
  `body` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), KEY `idx_session` (`session_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `telephony_settings` (
  `id` tinyint(1) NOT NULL DEFAULT 1,
  `enabled` tinyint(1) DEFAULT 0,
  `provider` varchar(30) DEFAULT 'exotel',          -- exotel | telecmi | servetel
  `account_sid` varchar(120) DEFAULT NULL,
  `api_key` varchar(200) DEFAULT NULL,
  `api_token` varchar(300) DEFAULT NULL,
  `caller_id` varchar(40) DEFAULT NULL,             -- the ExoPhone / virtual number shown to the customer
  `subdomain` varchar(80) DEFAULT 'api.exotel.com',
  `default_cc` varchar(6) DEFAULT '91',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO `telephony_settings` (`id`) VALUES (1);

CREATE TABLE IF NOT EXISTS `call_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lead_id` int(11) DEFAULT NULL,
  `agent_id` int(11) DEFAULT NULL,
  `to_number` varchar(40) DEFAULT NULL,
  `from_number` varchar(40) DEFAULT NULL,
  `provider_call_id` varchar(120) DEFAULT NULL,
  `status` varchar(40) DEFAULT 'initiated',
  `direction` varchar(20) DEFAULT 'outbound',
  `duration_sec` int(11) DEFAULT 0,
  `started_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), KEY `idx_lead` (`lead_id`), KEY `idx_agent` (`agent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- timesheet: add chat / whatsapp activity types for auto-logging
ALTER TABLE `timesheets` MODIFY `activity_type`
  enum('call','meeting','follow_up','onboarding','admin','travel','training','chat','whatsapp','other') DEFAULT 'other';

-- module access for the new tabs (fresh installs)
INSERT INTO `role_access` (`role`,`module_key`,`can_access`) VALUES
 ('admin','chat',1),('admin','conversations',1),
 ('manager','chat',1),('manager','conversations',1),
 ('sales_exec','chat',1),('sales_exec','conversations',0)
ON DUPLICATE KEY UPDATE can_access=VALUES(can_access);

-- allow chat/whatsapp on activity + followup touchpoints (auto-logging)
ALTER TABLE `activities` MODIFY `activity_type` enum('task','call','meeting','note','email','chat','whatsapp') NOT NULL;
ALTER TABLE `lead_followups` MODIFY `channel` enum('call','whatsapp','email','meeting','sms','visit','chat','other') DEFAULT 'call';
ALTER TABLE `telephony_settings` ADD COLUMN IF NOT EXISTS `sales_number` varchar(40) DEFAULT NULL AFTER `caller_id`;
CREATE TABLE IF NOT EXISTS `chat_settings` (`id` tinyint(1) NOT NULL DEFAULT 1, `auto_assign` tinyint(1) DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(), PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO `chat_settings` (`id`) VALUES (1);
