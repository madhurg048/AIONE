-- Phase 41: Calls workspace (Service only) — inbound/outbound softphone, agent claiming,
-- presence, manual call registration, per-call customer capture, call KPIs.

ALTER TABLE `call_logs`
  ADD COLUMN IF NOT EXISTS `claimed_by`    INT(11) NULL AFTER `agent_id`,
  ADD COLUMN IF NOT EXISTS `claimed_at`    DATETIME NULL AFTER `claimed_by`,
  ADD COLUMN IF NOT EXISTS `ended_at`      DATETIME NULL AFTER `started_at`,
  ADD COLUMN IF NOT EXISTS `disposition`   VARCHAR(40) NULL,
  ADD COLUMN IF NOT EXISTS `notes`         TEXT NULL,
  ADD COLUMN IF NOT EXISTS `customer_name` VARCHAR(120) NULL,
  ADD COLUMN IF NOT EXISTS `vertical`      VARCHAR(20) NOT NULL DEFAULT 'service',
  ADD COLUMN IF NOT EXISTS `source`        ENUM('exotel','manual') NOT NULL DEFAULT 'exotel',
  ADD COLUMN IF NOT EXISTS `channel`       ENUM('telephony','mobile','whatsapp','other') NOT NULL DEFAULT 'telephony',
  ADD COLUMN IF NOT EXISTS `merchant_id`   INT(11) NULL,
  ADD KEY IF NOT EXISTS `idx_claimed_by` (`claimed_by`),
  ADD KEY IF NOT EXISTS `idx_status_direction` (`status`,`direction`),
  ADD KEY IF NOT EXISTS `idx_merchant` (`merchant_id`);

-- Every row that existed before this migration came from the Sales-only click-to-call flow
-- (call.php) and just got defaulted to 'service' by the ALTER above — backfill those as
-- 'sales' now, immediately, before any real Service-vertical calls are inserted.
UPDATE `call_logs` SET `vertical` = 'sales' WHERE `vertical` = 'service';

CREATE TABLE IF NOT EXISTS `agent_call_presence` (
  `agent_id`        INT(11) NOT NULL,
  `status`          ENUM('available','on_call','offline') NOT NULL DEFAULT 'available',
  `current_call_id` INT(11) NULL,
  `updated_at`      TIMESTAMP NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`agent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `telephony_settings`
  ADD COLUMN IF NOT EXISTS `toll_free_number` VARCHAR(40) NULL AFTER `sales_number`,
  ADD COLUMN IF NOT EXISTS `webrtc_app_id`    VARCHAR(120) NULL,
  ADD COLUMN IF NOT EXISTS `webrtc_domain`    VARCHAR(120) NULL,
  ADD COLUMN IF NOT EXISTS `flow_id`          VARCHAR(60) NULL;

-- module access: Calls workspace is Service-only for now (+ admin, for setup/oversight).
INSERT INTO `role_access` (`role`,`module_key`,`can_access`) VALUES
 ('admin','calls',1),
 ('service_manager','calls',1),
 ('service_exec','calls',1)
ON DUPLICATE KEY UPDATE can_access=VALUES(can_access);
