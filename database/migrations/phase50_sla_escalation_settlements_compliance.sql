-- ============================================================================
-- Phase 50 — SLA/TAT policies + auto-escalation matrix, maker-checker
-- settlements approvals, employee groups (multi-membership + group access),
-- and the compliance / certification registry with expiry reminders.
-- Idempotent: guarded CREATEs + WHERE NOT EXISTS seeds; safe to re-run.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1. SLA policies (TAT definitions for tasks)
--    A policy matches a task by type and/or priority (NULL = any). The most
--    specific active match wins: type+priority > type > priority > catch-all.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sla_policies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `task_type` varchar(40) DEFAULT NULL,
  `priority` varchar(20) DEFAULT NULL,
  `tat_hours` decimal(7,1) NOT NULL DEFAULT 24.0,
  `escalate_every_hours` decimal(7,1) DEFAULT NULL,
  `max_level` int(11) NOT NULL DEFAULT 10,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SLA state carried on each task
ALTER TABLE `tasks`
  ADD COLUMN IF NOT EXISTS `sla_policy_id` int(11) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `sla_due_at` timestamp NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `escalation_level` int(11) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `escalated_to` int(11) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `last_escalated_at` timestamp NULL DEFAULT NULL;

-- ---------------------------------------------------------------------------
-- 2. Escalation matrix
--    Default path is the employees.manager_id chain (manager -> manager's
--    manager -> ... -> admin). A row here OVERRIDES the default hop for one
--    employee at one level; per-level TAT optionally overrides the policy's
--    escalate_every_hours for the next hop.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `escalation_matrix` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `level` int(11) NOT NULL,
  `escalate_to` int(11) NOT NULL,
  `tat_hours` decimal(7,1) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_emp_level` (`employee_id`,`level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every escalation hop that actually happened (the audit trail the admin
-- report + AI summary read from).
CREATE TABLE IF NOT EXISTS `task_escalations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `task_id` int(11) NOT NULL,
  `level` int(11) NOT NULL,
  `from_employee_id` int(11) DEFAULT NULL,
  `to_employee_id` int(11) NOT NULL,
  `sla_policy_id` int(11) DEFAULT NULL,
  `tat_hours` decimal(7,1) DEFAULT NULL,
  `breached_by_hours` decimal(9,1) DEFAULT NULL,
  `reason` varchar(500) DEFAULT NULL,
  `source` enum('auto','manual') NOT NULL DEFAULT 'auto',
  `status` enum('open','resolved') NOT NULL DEFAULT 'open',
  `resolved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_task` (`task_id`),
  KEY `ix_to_emp` (`to_employee_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default SLA policies (only when none exist yet)
INSERT INTO `sla_policies` (`name`,`task_type`,`priority`,`tat_hours`,`escalate_every_hours`,`max_level`,`is_active`)
SELECT * FROM (
  SELECT 'Urgent — 4h TAT'        AS name, NULL           AS task_type, 'urgent' AS priority,  4.0 AS tat, 4.0  AS esc, 10 AS ml, 1 AS act UNION ALL
  SELECT 'High — 24h TAT',              NULL,            'high',   24.0,  12.0, 10, 1 UNION ALL
  SELECT 'KYC validation — 8h TAT',     'kyc_validation', NULL,     8.0,   8.0, 10, 1 UNION ALL
  SELECT 'Standard — 48h TAT',          NULL,             NULL,    48.0,  24.0, 10, 1
) seed
WHERE NOT EXISTS (SELECT 1 FROM `sla_policies`);

-- ---------------------------------------------------------------------------
-- 3. Merchant settlements + maker-checker-approver workflow
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settlements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `settlement_ref` varchar(40) NOT NULL,
  `merchant_id` int(11) NOT NULL,
  `period_from` date DEFAULT NULL,
  `period_to` date DEFAULT NULL,
  `gross_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `mdr_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `adjustments` decimal(15,2) NOT NULL DEFAULT 0.00,
  `net_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `bank_account` varchar(60) DEFAULT NULL,
  `utr` varchar(60) DEFAULT NULL,
  `remarks` varchar(500) DEFAULT NULL,
  `status` enum('draft','pending_check','checked','approved','rejected','paid') NOT NULL DEFAULT 'draft',
  `maker_id` int(11) NOT NULL,
  `checked_by` int(11) DEFAULT NULL,
  `checked_at` timestamp NULL DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `rejected_by` int(11) DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `reject_reason` varchar(500) DEFAULT NULL,
  `paid_by` int(11) DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ref` (`settlement_ref`),
  KEY `ix_merchant` (`merchant_id`),
  KEY `ix_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Full audit trail of every action on an approval-governed entity
CREATE TABLE IF NOT EXISTS `approval_actions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `entity_type` varchar(30) NOT NULL DEFAULT 'settlement',
  `entity_id` int(11) NOT NULL,
  `action` varchar(30) NOT NULL,
  `actor_id` int(11) DEFAULT NULL,
  `actor_role` varchar(40) DEFAULT NULL,
  `remarks` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_entity` (`entity_type`,`entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- General approval-level config (maker/checker/approver per entity type).
-- min_tier: 1=exec 2=manager 3=head 4=admin (matches PAYG_TIER_*).
CREATE TABLE IF NOT EXISTS `approval_configs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `entity_type` varchar(30) NOT NULL,
  `level` int(11) NOT NULL,
  `level_name` varchar(40) NOT NULL,
  `min_tier` int(11) NOT NULL DEFAULT 1,
  `min_amount` decimal(15,2) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_entity_level` (`entity_type`,`level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `approval_configs` (`entity_type`,`level`,`level_name`,`min_tier`,`min_amount`,`is_active`)
SELECT * FROM (
  SELECT 'settlement' AS et, 1 AS lvl, 'Maker'    AS ln, 1 AS mt, NULL AS ma, 1 AS act UNION ALL
  SELECT 'settlement', 2, 'Checker',  2, NULL, 1 UNION ALL
  SELECT 'settlement', 3, 'Approver', 3, NULL, 1
) seed
WHERE NOT EXISTS (SELECT 1 FROM `approval_configs` WHERE `entity_type`='settlement');

-- ---------------------------------------------------------------------------
-- 4. Employee groups — multi-membership; a group grants extra module access
--    on top of the member's role_access (e.g. "Compliance Team" grants the
--    compliance module to whoever is in it).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `emp_groups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL,
  `description` varchar(300) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `emp_group_members` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `group_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `added_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_grp_emp` (`group_id`,`employee_id`),
  KEY `ix_emp` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `emp_group_access` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `group_id` int(11) NOT NULL,
  `module_key` varchar(40) NOT NULL,
  `can_access` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_grp_mod` (`group_id`,`module_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed the Compliance Team group with access to the compliance module
INSERT INTO `emp_groups` (`name`,`description`,`is_active`)
SELECT 'Compliance Team','Owns RBI / regulatory policies, certifications and their renewals',1
WHERE NOT EXISTS (SELECT 1 FROM `emp_groups` WHERE `name`='Compliance Team');

INSERT INTO `emp_group_access` (`group_id`,`module_key`,`can_access`)
SELECT g.id,'compliance',1 FROM `emp_groups` g
WHERE g.`name`='Compliance Team'
  AND NOT EXISTS (SELECT 1 FROM `emp_group_access` a WHERE a.`group_id`=g.id AND a.`module_key`='compliance');

-- ---------------------------------------------------------------------------
-- 5. Compliance / policy / certification registry with expiry reminders
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `compliance_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `item_type` enum('policy','compliance','certification','license') NOT NULL DEFAULT 'compliance',
  `category` varchar(80) DEFAULT NULL,
  `reference_no` varchar(100) DEFAULT NULL,
  `authority` varchar(120) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `doc_url` varchar(500) DEFAULT NULL,
  `owner_group_id` int(11) DEFAULT NULL,
  `owner_employee_id` int(11) DEFAULT NULL,
  `issued_on` date DEFAULT NULL,
  `valid_till` date DEFAULT NULL,
  `reminder_days` int(11) NOT NULL DEFAULT 30,
  `renewal_frequency` enum('once','monthly','quarterly','half_yearly','yearly','custom') NOT NULL DEFAULT 'yearly',
  `status` enum('active','archived') NOT NULL DEFAULT 'active',
  `last_reminded_at` timestamp NULL DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_valid_till` (`status`,`valid_till`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `compliance_stakeholders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `item_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `role` varchar(60) NOT NULL DEFAULT 'stakeholder',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_item_emp` (`item_id`,`employee_id`),
  KEY `ix_emp` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `compliance_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `item_id` int(11) NOT NULL,
  `action` varchar(40) NOT NULL,
  `actor_id` int(11) DEFAULT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_item` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 6. RBAC: register the new modules for admin (and HR for compliance/groups).
--    Other roles gain access via the RBAC matrix or via group membership.
-- ---------------------------------------------------------------------------
INSERT INTO `role_access` (`role`,`module_key`,`can_access`)
SELECT s.role, s.mk, 1 FROM (
  SELECT 'admin' AS role, 'escalations' AS mk UNION ALL
  SELECT 'admin', 'settlements' UNION ALL
  SELECT 'admin', 'compliance' UNION ALL
  SELECT 'admin', 'groups' UNION ALL
  SELECT 'hr_head', 'groups' UNION ALL
  SELECT 'hr_manager', 'groups' UNION ALL
  SELECT 'hr', 'groups' UNION ALL
  SELECT 'sales_head', 'settlements' UNION ALL
  SELECT 'manager', 'settlements' UNION ALL
  SELECT 'sales_exec', 'settlements'
) s
WHERE NOT EXISTS (
  SELECT 1 FROM `role_access` ra WHERE ra.`role`=s.role AND ra.`module_key`=s.mk
);
