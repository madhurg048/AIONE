-- Call-recording transcription (on request) + storage on call_logs.
-- Transcription settings are independent of ai_settings/voice so they can be enabled/pointed
-- at a different URL/key without touching timesheet voice dictation (voice-transcribe.php).
CREATE TABLE IF NOT EXISTS `transcription_settings` (
  `id` tinyint(1) NOT NULL DEFAULT 1,
  `enabled` tinyint(1) DEFAULT 0,
  `api_url` varchar(200) DEFAULT NULL,
  `api_key` varchar(300) DEFAULT NULL,
  `lang` varchar(10) DEFAULT 'en',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `transcription_settings` (id) VALUES (1);

ALTER TABLE `call_logs`
  ADD COLUMN `transcript` TEXT DEFAULT NULL AFTER `recording_url`,
  ADD COLUMN `transcript_status` VARCHAR(20) DEFAULT NULL AFTER `transcript`,
  ADD COLUMN `transcribed_at` TIMESTAMP NULL DEFAULT NULL AFTER `transcript_status`;
