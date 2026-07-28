-- Outbound WhatsApp attachments (sales team -> customer) are uploaded to OUR
-- server and sent to Meta via a public link, unlike inbound media (customer ->
-- us) which stays on Meta's servers and is proxied on demand by
-- whatsapp-media.php. `local_path` holds that public data/uploads/ path so
-- the agent console can render/link it directly, with no auth proxy needed.
SET NAMES utf8mb4;

ALTER TABLE `whatsapp_messages`
  ADD COLUMN IF NOT EXISTS `local_path` varchar(255) DEFAULT NULL AFTER `mime_type`;
