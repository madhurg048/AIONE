-- ============================================================
-- PayG CRM — Phase 7: External integration layer
--   • record real merchant transactions (payment gateway)
--   • merchant confirmation from an external PIMS
--   • ITSM ticket status push
--   • API tokens for machine-to-machine auth
-- ============================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `integration_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `label` varchar(120) NOT NULL,
  `token` varchar(80) NOT NULL,
  `scopes` varchar(255) DEFAULT 'transaction,merchant_confirm,ticket_update',
  `is_active` tinyint(1) DEFAULT 1,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `merchant_transactions` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `merchant_id` int(11) NOT NULL,
  `txn_ref` varchar(80) NOT NULL,
  `amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(3) DEFAULT 'INR',
  `status` enum('success','failed','refunded','pending') DEFAULT 'success',
  `txn_date` datetime DEFAULT NULL,
  `product` varchar(50) DEFAULT NULL,   -- upi/cards/netbanking…
  `source` varchar(60) DEFAULT 'pg',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_txn` (`txn_ref`),
  KEY `idx_txn_merchant` (`merchant_id`,`txn_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_tickets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_ref` varchar(80) NOT NULL,
  `source` varchar(60) DEFAULT 'itsm',
  `subject` varchar(255) DEFAULT NULL,
  `status` varchar(60) DEFAULT NULL,          -- open, in_progress, waiting_customer, resolved, closed…
  `assigned_to_name` varchar(120) DEFAULT NULL,
  `waiting_on` varchar(60) DEFAULT NULL,       -- us | customer | vendor
  `priority` varchar(30) DEFAULT NULL,
  `merchant_id` int(11) DEFAULT NULL,
  `lead_id` int(11) DEFAULT NULL,
  `last_update_note` varchar(500) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ticket` (`ticket_ref`),
  KEY `idx_ticket_merchant` (`merchant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- actual (transacted) volume vs expected
ALTER TABLE `merchants` ADD COLUMN IF NOT EXISTS `actual_monthly_volume` decimal(15,2) DEFAULT 0.00;
ALTER TABLE `merchants` ADD COLUMN IF NOT EXISTS `last_txn_at` datetime DEFAULT NULL;

-- seed a demo token (regenerate from the Integrations admin page)
INSERT INTO `integration_tokens` (`label`,`token`)
SELECT 'Default integration key', 'payg_demo_0000000000000000'
WHERE NOT EXISTS (SELECT 1 FROM `integration_tokens`);
