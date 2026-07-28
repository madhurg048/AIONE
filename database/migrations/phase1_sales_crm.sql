-- ============================================================
-- PayG CRM — Phase 1: Sales CRM core (Zoho-CRM-like)
-- Bolt-on to existing PHP/MySQL app. Reuses `employees` for ownership.
-- Modules: Leads, Accounts, Contacts, Deals(+Pipeline/Stages), Activities.
-- ============================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------- ACCOUNTS (companies) ----------
CREATE TABLE IF NOT EXISTS `accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_name` varchar(150) NOT NULL,
  `website` varchar(255) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `industry` varchar(100) DEFAULT NULL,
  `annual_revenue` decimal(15,2) DEFAULT NULL,
  `employees_count` int(11) DEFAULT NULL,
  `billing_address` text DEFAULT NULL,
  `shipping_address` text DEFAULT NULL,
  `owner_id` int(11) DEFAULT NULL,          -- FK employees.id (record owner)
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_accounts_owner` (`owner_id`),
  KEY `idx_accounts_name` (`account_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- CONTACTS (people; link existing `clients` here later) ----------
CREATE TABLE IF NOT EXISTS `contacts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) DEFAULT NULL,        -- FK accounts.id
  `first_name` varchar(80) NOT NULL,
  `last_name` varchar(80) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `mobile` varchar(30) DEFAULT NULL,
  `title` varchar(100) DEFAULT NULL,        -- job title
  `department` varchar(100) DEFAULT NULL,
  `mailing_address` text DEFAULT NULL,
  `owner_id` int(11) DEFAULT NULL,
  `lead_source` varchar(80) DEFAULT NULL,
  `legacy_client_id` int(11) DEFAULT NULL,  -- link back to existing clients.id
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_contacts_account` (`account_id`),
  KEY `idx_contacts_owner` (`owner_id`),
  KEY `idx_contacts_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- LEADS (unqualified; convert -> account+contact+deal) ----------
CREATE TABLE IF NOT EXISTS `leads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `first_name` varchar(80) DEFAULT NULL,
  `last_name` varchar(80) NOT NULL,
  `company` varchar(150) DEFAULT NULL,
  `title` varchar(100) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `mobile` varchar(30) DEFAULT NULL,
  `lead_source` varchar(80) DEFAULT NULL,   -- web, whatsapp, call, campaign, referral...
  `lead_status` enum('new','contacted','qualified','unqualified','converted') DEFAULT 'new',
  `industry` varchar(100) DEFAULT NULL,
  `rating` enum('hot','warm','cold') DEFAULT NULL,
  `score` int(11) DEFAULT 0,                -- scoring rules (Phase 2) write here
  `owner_id` int(11) DEFAULT NULL,
  `campaign_id` int(11) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `description` text DEFAULT NULL,
  -- conversion bookkeeping
  `is_converted` tinyint(1) DEFAULT 0,
  `converted_at` timestamp NULL DEFAULT NULL,
  `converted_account_id` int(11) DEFAULT NULL,
  `converted_contact_id` int(11) DEFAULT NULL,
  `converted_deal_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_leads_owner` (`owner_id`),
  KEY `idx_leads_status` (`lead_status`),
  KEY `idx_leads_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- PIPELINES & STAGES (multi-pipeline like Zoho) ----------
CREATE TABLE IF NOT EXISTS `pipelines` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `is_default` tinyint(1) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `deal_stages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pipeline_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,            -- Qualification, Proposal, Negotiation...
  `probability` int(11) DEFAULT 0,          -- 0-100, drives forecast
  `sort_order` int(11) DEFAULT 0,
  `stage_type` enum('open','won','lost') DEFAULT 'open',
  PRIMARY KEY (`id`),
  KEY `idx_stage_pipeline` (`pipeline_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- DEALS / OPPORTUNITIES (the core) ----------
CREATE TABLE IF NOT EXISTS `deals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `deal_name` varchar(200) NOT NULL,
  `account_id` int(11) DEFAULT NULL,        -- FK accounts.id
  `contact_id` int(11) DEFAULT NULL,        -- FK contacts.id (primary contact)
  `pipeline_id` int(11) NOT NULL,
  `stage_id` int(11) NOT NULL,              -- FK deal_stages.id
  `amount` decimal(15,2) DEFAULT 0.00,
  `currency` varchar(3) DEFAULT 'INR',
  `probability` int(11) DEFAULT 0,          -- copied from stage, editable
  `expected_close_date` date DEFAULT NULL,
  `closed_at` timestamp NULL DEFAULT NULL,
  `lead_source` varchar(80) DEFAULT NULL,
  `owner_id` int(11) DEFAULT NULL,
  `campaign_id` int(11) DEFAULT NULL,
  `lost_reason` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_deals_account` (`account_id`),
  KEY `idx_deals_stage` (`stage_id`),
  KEY `idx_deals_owner` (`owner_id`),
  KEY `idx_deals_close` (`expected_close_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stage change history (for velocity / funnel reports later)
CREATE TABLE IF NOT EXISTS `deal_stage_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `deal_id` int(11) NOT NULL,
  `from_stage_id` int(11) DEFAULT NULL,
  `to_stage_id` int(11) NOT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_dsh_deal` (`deal_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- ACTIVITIES (polymorphic: attach to any record) ----------
CREATE TABLE IF NOT EXISTS `activities` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `activity_type` enum('task','call','meeting','note','email') NOT NULL,
  `subject` varchar(255) NOT NULL,
  `related_type` enum('lead','account','contact','deal') NOT NULL,
  `related_id` int(11) NOT NULL,            -- id within related_type
  `owner_id` int(11) DEFAULT NULL,
  `status` enum('open','completed','cancelled') DEFAULT 'open',
  `priority` enum('low','medium','high') DEFAULT 'medium',
  `due_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `duration_minutes` int(11) DEFAULT NULL,  -- calls/meetings
  `body` text DEFAULT NULL,                 -- note/email/description
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_act_related` (`related_type`,`related_id`),
  KEY `idx_act_owner` (`owner_id`),
  KEY `idx_act_due` (`due_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- SEED: default pipeline + stages ----------
INSERT INTO `pipelines` (`id`,`name`,`is_default`,`is_active`) VALUES (1,'Standard Sales Pipeline',1,1);
INSERT INTO `deal_stages` (`pipeline_id`,`name`,`probability`,`sort_order`,`stage_type`) VALUES
  (1,'Qualification',10,1,'open'),
  (1,'Needs Analysis',20,2,'open'),
  (1,'Proposal',40,3,'open'),
  (1,'Negotiation',70,4,'open'),
  (1,'Closed Won',100,5,'won'),
  (1,'Closed Lost',0,6,'lost');

SET FOREIGN_KEY_CHECKS = 1;
