-- Rate limiting for the public chatbot widget's mobile OTP send (api/sales/chatbot-otp-*.php).
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `otp_rate_limits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `identifier` varchar(150) NOT NULL,
  `channel` enum('mobile','email') NOT NULL DEFAULT 'mobile',
  `attempt_count` int(11) NOT NULL DEFAULT 0,
  `window_started_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_identifier_channel` (`identifier`,`channel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
