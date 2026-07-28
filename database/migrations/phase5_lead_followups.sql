-- ============================================================
-- PayG CRM — Phase 5: Lead follow-up process + feedback + final status
--                     + WhatsApp message log
-- ============================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `lead_followups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lead_id` int(11) NOT NULL,
  `channel` enum('call','whatsapp','email','meeting','sms','visit','other') DEFAULT 'call',
  `direction` enum('outbound','inbound') DEFAULT 'outbound',
  `outcome` enum('connected','no_answer','busy','callback_requested','interested',
                 'not_interested','not_reachable','converted','message_sent','other') DEFAULT 'connected',
  `feedback` text DEFAULT NULL,            -- what the lead said / notes
  `next_followup_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_fu_lead` (`lead_id`),
  KEY `idx_fu_next` (`next_followup_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `whatsapp_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lead_id` int(11) DEFAULT NULL,
  `phone` varchar(30) NOT NULL,
  `direction` enum('outbound','inbound') DEFAULT 'outbound',
  `body` text DEFAULT NULL,
  `template` varchar(120) DEFAULT NULL,
  `status` enum('link_generated','queued','sent','delivered','read','failed') DEFAULT 'link_generated',
  `provider_msg_id` varchar(120) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_wa_lead` (`lead_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- lead disposition / follow-up scheduling fields
ALTER TABLE `leads` ADD COLUMN IF NOT EXISTS `next_followup_at` datetime DEFAULT NULL;
ALTER TABLE `leads` ADD COLUMN IF NOT EXISTS `last_contacted_at` datetime DEFAULT NULL;
ALTER TABLE `leads` ADD COLUMN IF NOT EXISTS `followup_count` int(11) DEFAULT 0;
ALTER TABLE `leads` ADD COLUMN IF NOT EXISTS `lost_reason` varchar(255) DEFAULT NULL;
