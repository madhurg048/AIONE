-- ============================================================
-- PayG CRM — Phase 3: Workflow Automation + Assignment Routing +
--                     Custom Fields + Web-to-Lead + Email templates
-- Depends on phase1 + phase2.
-- ============================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------- WORKFLOW AUTOMATION RULES ----------
-- conditions: JSON array [{"field":"lead_status","op":"eq","value":"new"}]
-- actions:    JSON array [{"type":"assign_owner","mode":"round_robin"},{"type":"notify",...}]
CREATE TABLE IF NOT EXISTS `automation_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `entity` enum('lead','deal','merchant') NOT NULL,
  `trigger_event` enum('on_create','on_update','on_stage_change','time_based') NOT NULL,
  `match_type` enum('all','any') NOT NULL DEFAULT 'all',
  `conditions` longtext DEFAULT NULL,   -- JSON
  `actions` longtext NOT NULL,          -- JSON
  `is_active` tinyint(1) DEFAULT 1,
  `run_count` int(11) DEFAULT 0,
  `last_run_at` timestamp NULL DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rule_entity` (`entity`,`trigger_event`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `automation_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `rule_id` int(11) NOT NULL,
  `entity` varchar(20) DEFAULT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `actions_run` text DEFAULT NULL,
  `status` enum('success','partial','error') DEFAULT 'success',
  `message` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_log_rule` (`rule_id`),
  KEY `idx_log_entity` (`entity`,`entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- round-robin pointer per rule (or per named pool)
CREATE TABLE IF NOT EXISTS `assignment_state` (
  `scope` varchar(60) NOT NULL,          -- e.g. 'rule:12' or 'pool:sales'
  `last_employee_id` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`scope`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- CUSTOM FIELDS (metadata-driven, no schema change) ----------
CREATE TABLE IF NOT EXISTS `custom_fields` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `entity` enum('lead','account','contact','deal','merchant') NOT NULL,
  `field_key` varchar(60) NOT NULL,      -- machine key, unique per entity
  `label` varchar(120) NOT NULL,
  `field_type` enum('text','number','date','select','checkbox','textarea') NOT NULL DEFAULT 'text',
  `options` longtext DEFAULT NULL,       -- JSON array for select
  `is_required` tinyint(1) DEFAULT 0,
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_field` (`entity`,`field_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `custom_field_values` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `field_id` int(11) NOT NULL,
  `entity` varchar(20) NOT NULL,
  `entity_id` int(11) NOT NULL,
  `value` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_val` (`field_id`,`entity`,`entity_id`),
  KEY `idx_val_entity` (`entity`,`entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- EMAIL TEMPLATES (for automation send_email) ----------
CREATE TABLE IF NOT EXISTS `sales_email_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `body_html` longtext NOT NULL,         -- supports {{first_name}}, {{company}} tokens
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- WEB-TO-LEAD source tracking ----------
CREATE TABLE IF NOT EXISTS `web_lead_sources` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `source_key` varchar(60) NOT NULL,     -- pass ?src= on the form
  `label` varchar(120) DEFAULT NULL,
  `hits` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_src` (`source_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- SEED: two starter rules + a template + demo custom field ----------
INSERT INTO `automation_rules` (`name`,`entity`,`trigger_event`,`match_type`,`conditions`,`actions`,`is_active`) VALUES
('Round-robin assign new leads','lead','on_create','all',
 '[]',
 '[{"type":"assign_owner","mode":"round_robin","pool":"all"},{"type":"notify","to":"owner","title":"New lead assigned","message":"A new lead has been assigned to you."}]',1),
('Welcome email to new web leads','lead','on_create','all',
 '[{"field":"lead_source","op":"eq","value":"web"}]',
 '[{"type":"send_email","to":"lead","subject":"Thanks for your interest in PayG","body":"Hi {{first_name}}, thanks for reaching out. A specialist will contact you shortly."}]',1),
('Reassign stale leads (14d)','lead','time_based','all',
 '[{"field":"updated_at","op":"older_than_days","value":14},{"field":"lead_status","op":"in","value":"new,contacted"}]',
 '[{"type":"assign_owner","mode":"round_robin","pool":"all"},{"type":"add_tag","tag":"stale-reassigned"},{"type":"notify","to":"owner","title":"Stale lead reassigned","message":"A lead untouched for 14 days was reassigned to you."}]',1),
('Escalate KYC stuck 7d','merchant','time_based','all',
 '[{"field":"kyc_status","op":"in","value":"pending,partial"},{"field":"created_at","op":"older_than_days","value":7}]',
 '[{"type":"notify","to":"owner","title":"KYC overdue","message":"Merchant KYC has been pending over 7 days — please follow up."},{"type":"add_tag","tag":"kyc-escalated"}]',1);

INSERT INTO `sales_email_templates` (`name`,`subject`,`body_html`) VALUES
('Welcome — Web Lead','Thanks for your interest in PayG',
 '<p>Hi {{first_name}},</p><p>Thanks for reaching out to PayG. One of our merchant specialists will contact you shortly to help you start accepting payments.</p><p>— Team PayG</p>');

INSERT INTO `custom_fields` (`entity`,`field_key`,`label`,`field_type`,`options`,`sort_order`) VALUES
('lead','gst_number','GST Number','text',NULL,1),
('lead','monthly_txn_estimate','Est. Monthly Txns','select','["<100","100-1000","1000-10000","10000+"]',2),
('merchant','settlement_cycle','Settlement Cycle','select','["T+1","T+2","T+3"]',1);

SET FOREIGN_KEY_CHECKS = 1;

-- ---------- tags column for automation add_tag action (idempotent; MariaDB) ----------
ALTER TABLE `leads`     ADD COLUMN IF NOT EXISTS `tags` varchar(255) DEFAULT NULL;
ALTER TABLE `deals`     ADD COLUMN IF NOT EXISTS `tags` varchar(255) DEFAULT NULL;
ALTER TABLE `merchants` ADD COLUMN IF NOT EXISTS `tags` varchar(255) DEFAULT NULL;
