-- Lets live-chat messages (visitor or agent) carry a file attachment
-- (screenshot, PAN/GST doc, etc.) alongside the text body.
SET NAMES utf8mb4;

ALTER TABLE `chat_messages`
  ADD COLUMN IF NOT EXISTS `attachment_url` varchar(255) DEFAULT NULL AFTER `body`,
  ADD COLUMN IF NOT EXISTS `attachment_type` varchar(100) DEFAULT NULL AFTER `attachment_url`;
