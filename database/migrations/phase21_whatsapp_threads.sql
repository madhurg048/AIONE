-- WhatsApp Business API (Meta Cloud API): live threads + webhook config.
-- Turns the existing flat whatsapp_messages log into a threaded inbox (like chat_sessions/chat_messages)
-- and adds webhook verification fields to whatsapp_settings.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `whatsapp_threads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `phone` varchar(30) NOT NULL,
  `lead_id` int(11) DEFAULT NULL,
  `contact_name` varchar(120) DEFAULT NULL,
  `agent_id` int(11) DEFAULT NULL,
  `status` enum('open','closed') DEFAULT 'open',
  `last_inbound_at` timestamp NULL DEFAULT NULL,
  `last_msg_at` timestamp NULL DEFAULT NULL,
  `unread_count` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_phone` (`phone`),
  KEY `idx_lead` (`lead_id`), KEY `idx_agent` (`agent_id`), KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `whatsapp_messages`
  ADD COLUMN IF NOT EXISTS `thread_id` int(11) DEFAULT NULL AFTER `lead_id`,
  ADD COLUMN IF NOT EXISTS `sender` enum('customer','agent','bot','system') DEFAULT NULL AFTER `direction`,
  ADD COLUMN IF NOT EXISTS `media_id` varchar(120) DEFAULT NULL AFTER `template`,
  ADD COLUMN IF NOT EXISTS `media_type` varchar(40) DEFAULT NULL AFTER `media_id`,
  ADD COLUMN IF NOT EXISTS `mime_type` varchar(80) DEFAULT NULL AFTER `media_type`,
  ADD COLUMN IF NOT EXISTS `read_at` datetime DEFAULT NULL,
  ADD KEY `idx_thread` (`thread_id`, `id`);

-- backfill: one thread per distinct phone already seen in the flat log
INSERT INTO `whatsapp_threads` (`phone`, `lead_id`, `last_inbound_at`, `last_msg_at`, `status`, `created_at`)
SELECT wm.phone,
       MAX(wm.lead_id),
       MAX(CASE WHEN wm.direction='inbound' THEN wm.created_at END),
       MAX(wm.created_at),
       'open',
       MIN(wm.created_at)
FROM `whatsapp_messages` wm
WHERE wm.thread_id IS NULL
GROUP BY wm.phone
ON DUPLICATE KEY UPDATE phone = VALUES(phone);

UPDATE `whatsapp_messages` wm
JOIN `whatsapp_threads` wt ON wt.phone = wm.phone
SET wm.thread_id = wt.id
WHERE wm.thread_id IS NULL;

UPDATE `whatsapp_messages`
SET `sender` = CASE WHEN `direction`='inbound' THEN 'customer' ELSE 'agent' END
WHERE `sender` IS NULL;

-- webhook + template config
ALTER TABLE `whatsapp_settings`
  ADD COLUMN IF NOT EXISTS `webhook_verify_token` varchar(120) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `app_secret` varchar(190) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `template_name` varchar(120) DEFAULT 'hello_world',
  ADD COLUMN IF NOT EXISTS `template_lang` varchar(10) DEFAULT 'en_US',
  ADD COLUMN IF NOT EXISTS `graph_version` varchar(10) DEFAULT 'v21.0';

-- module access for the new rep-facing WhatsApp console
INSERT INTO `role_access` (`role`,`module_key`,`can_access`) VALUES
 ('admin','whatsapp',1),('manager','whatsapp',1),('sales_exec','whatsapp',1)
ON DUPLICATE KEY UPDATE can_access=VALUES(can_access);
