-- Store the Meta App ID alongside the App Secret so scripts/tools can call the
-- Graph API app-webhooks endpoint (POST /{app-id}/subscriptions) to auto-register
-- the current tunnel URL without a manual dashboard visit each time it changes.
SET NAMES utf8mb4;

ALTER TABLE `whatsapp_settings`
  ADD COLUMN IF NOT EXISTS `app_id` varchar(40) DEFAULT NULL AFTER `app_secret`;
