-- ============================================================
-- PayG CRM — Phase 2: Incentive Management + Merchant PIMS
-- Depends on phase1_sales_crm.sql. Funnel: Lead -> Deal -> Merchant.
-- On a deal reaching "Closed Won" a Merchant (PIMS) record is created
-- and an incentive is booked to the owning employee's ledger.
-- ============================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------- MERCHANTS (PIMS — Merchant Information Mgmt System) ----------
CREATE TABLE IF NOT EXISTS `merchants` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `merchant_code` varchar(40) NOT NULL,          -- MID, human-readable, unique
  `legal_name` varchar(200) NOT NULL,
  `display_name` varchar(200) DEFAULT NULL,
  `business_type` enum('proprietorship','partnership','pvt_ltd','llp','public_ltd','trust','other') DEFAULT 'proprietorship',
  `category` varchar(100) DEFAULT NULL,          -- MCC / industry
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `pincode` varchar(12) DEFAULT NULL,
  -- lineage back to the funnel
  `account_id` int(11) DEFAULT NULL,             -- FK accounts.id
  `contact_id` int(11) DEFAULT NULL,             -- FK contacts.id
  `deal_id` int(11) DEFAULT NULL,                -- FK deals.id (the won deal)
  `source_lead_id` int(11) DEFAULT NULL,         -- FK leads.id
  `owner_id` int(11) DEFAULT NULL,               -- FK employees.id (acquiring agent)
  -- commercial
  `mdr_percent` decimal(5,3) DEFAULT NULL,       -- merchant discount rate
  `expected_monthly_volume` decimal(15,2) DEFAULT NULL,
  `products` varchar(255) DEFAULT NULL,          -- CSV: upi,cards,netbanking,pg,pos...
  -- onboarding lifecycle
  `onboarding_stage` enum('created','kyc_pending','kyc_submitted','under_review','activated','suspended','rejected') DEFAULT 'created',
  `kyc_status` enum('pending','partial','verified','rejected') DEFAULT 'pending',
  `risk_rating` enum('low','medium','high') DEFAULT NULL,
  `activated_at` timestamp NULL DEFAULT NULL,
  `go_live_at` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_merchant_code` (`merchant_code`),
  KEY `idx_merchant_owner` (`owner_id`),
  KEY `idx_merchant_stage` (`onboarding_stage`),
  KEY `idx_merchant_deal` (`deal_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- MERCHANT KYC DOCUMENTS ----------
CREATE TABLE IF NOT EXISTS `merchant_documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `merchant_id` int(11) NOT NULL,
  `doc_type` enum('pan','gst','aadhaar','bank_proof','incorporation','address_proof','other') NOT NULL,
  `doc_number` varchar(100) DEFAULT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `status` enum('pending','verified','rejected') DEFAULT 'pending',
  `verified_by` int(11) DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_mdoc_merchant` (`merchant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- INCENTIVE PLANS (rule sets) ----------
CREATE TABLE IF NOT EXISTS `incentive_plans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `effective_from` date DEFAULT NULL,
  `effective_to` date DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- INCENTIVE RULES (per plan) ----------
-- Rule types:
--   flat        -> fixed amount per activated merchant
--   percent     -> percent of deal amount
--   slab_volume -> amount based on expected_monthly_volume slabs (min/max)
CREATE TABLE IF NOT EXISTS `incentive_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `plan_id` int(11) NOT NULL,
  `rule_type` enum('flat','percent','slab_volume') NOT NULL DEFAULT 'flat',
  `product` varchar(50) DEFAULT NULL,            -- optional: applies only to a product
  `flat_amount` decimal(12,2) DEFAULT NULL,
  `percent_rate` decimal(5,2) DEFAULT NULL,
  `slab_min` decimal(15,2) DEFAULT NULL,
  `slab_max` decimal(15,2) DEFAULT NULL,
  `slab_amount` decimal(12,2) DEFAULT NULL,
  `priority` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_rule_plan` (`plan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- INCENTIVE LEDGER (one row per earned incentive) ----------
CREATE TABLE IF NOT EXISTS `incentive_ledger` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,                -- who earned it
  `merchant_id` int(11) DEFAULT NULL,
  `deal_id` int(11) DEFAULT NULL,
  `plan_id` int(11) DEFAULT NULL,
  `rule_id` int(11) DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(3) DEFAULT 'INR',
  `basis` varchar(255) DEFAULT NULL,             -- human explanation of how it was computed
  `status` enum('accrued','approved','on_hold','paid','clawed_back') DEFAULT 'accrued',
  `period_month` char(7) DEFAULT NULL,           -- YYYY-MM for payout batching
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ledger_emp` (`employee_id`),
  KEY `idx_ledger_status` (`status`),
  KEY `idx_ledger_period` (`period_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- OPTIONAL: monthly sales targets per employee (for metrics) ----------
CREATE TABLE IF NOT EXISTS `sales_targets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `period_month` char(7) NOT NULL,               -- YYYY-MM
  `target_merchants` int(11) DEFAULT 0,
  `target_volume` decimal(15,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_target` (`employee_id`,`period_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- AI insight cache (store agentic outputs, avoid re-calling LLM) ----------
CREATE TABLE IF NOT EXISTS `ai_insights` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `entity_type` enum('lead','deal','merchant','employee','funnel') NOT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `insight_type` varchar(60) NOT NULL,           -- score, next_best_action, summary, coaching...
  `payload` longtext DEFAULT NULL,               -- JSON returned by the model
  `score` int(11) DEFAULT NULL,                  -- optional numeric (e.g. lead score 0-100)
  `generated_by` varchar(60) DEFAULT 'chat_v3',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ai_entity` (`entity_type`,`entity_id`,`insight_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- SEED: a starter incentive plan ----------
INSERT INTO `incentive_plans` (`id`,`name`,`description`,`is_active`)
VALUES (1,'Standard Acquisition FY25','Default merchant acquisition incentive',1);

INSERT INTO `incentive_rules` (`plan_id`,`rule_type`,`flat_amount`,`priority`,`is_active`) VALUES
  (1,'flat',500.00,10,1);                          -- ₹500 per activated merchant (base)
INSERT INTO `incentive_rules` (`plan_id`,`rule_type`,`slab_min`,`slab_max`,`slab_amount`,`priority`,`is_active`) VALUES
  (1,'slab_volume',0,100000,250.00,20,1),          -- + ₹250 small merchants
  (1,'slab_volume',100000,500000,750.00,20,1),     -- + ₹750 mid
  (1,'slab_volume',500000,999999999,2000.00,20,1); -- + ₹2000 large

SET FOREIGN_KEY_CHECKS = 1;
