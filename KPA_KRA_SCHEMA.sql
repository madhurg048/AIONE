-- ============================================================================
-- KPA / KRA feature — SQL for the NEW + MODIFIED tables only.
-- Apply on top of an existing `super_payg_crm` database. Idempotent (safe to re-run).
--
--   NEW table:      kpa_goals   (org-group goal definitions + manager team goals)
--   MODIFIED table: tasks       (adds kpa_goal_id + hours_spent for effort roll-up)
--   SEED:           role_access  (grants the 'kpa' module to managers and above)
--
--   mysql -u root super_payg_crm < KPA_KRA_SCHEMA.sql
-- ============================================================================

-- ---- NEW TABLE: kpa_goals ----------------------------------------------------
CREATE TABLE IF NOT EXISTS `kpa_goals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `scope` enum('org','team') NOT NULL DEFAULT 'org',      -- org = admin group goal, team = manager goal
  `org_group` varchar(40) DEFAULT NULL,                   -- Sales/Service/HR/IT/Support (scope=org)
  `owner_employee_id` int(11) DEFAULT NULL,               -- target member (scope=team); NULL = creator's whole team
  `created_by` int(11) NOT NULL DEFAULT 0,                -- employee id of the admin/manager who defined it
  `created_by_role` varchar(40) DEFAULT NULL,
  `title` varchar(160) NOT NULL,                          -- the KPA (Key Performance Area)
  `kra` varchar(600) DEFAULT NULL,                        -- the KRA / how it is measured
  `weight` decimal(5,2) NOT NULL DEFAULT 0.00,            -- relative weight (%)
  `target_value` varchar(120) DEFAULT NULL,               -- freeform target (e.g. "20", "Rs 2 Cr", "95%")
  `unit` enum('inr','pct','count','flag','text') NOT NULL DEFAULT 'text',
  `period` varchar(20) NOT NULL DEFAULT 'annual',         -- e.g. annual / FY26 / 2026-Q3
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_scope_group` (`scope`,`org_group`),
  KEY `idx_owner` (`owner_employee_id`),
  KEY `idx_creator` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- MODIFIED TABLE: tasks (link a task to a KPA/KRA + capture hours) ---------
ALTER TABLE `tasks`
  ADD COLUMN IF NOT EXISTS `kpa_goal_id` int(11) DEFAULT NULL AFTER `related_id`,
  ADD COLUMN IF NOT EXISTS `hours_spent` decimal(6,2) NOT NULL DEFAULT 0.00 AFTER `kpa_goal_id`;
ALTER TABLE `tasks`
  ADD INDEX IF NOT EXISTS `idx_task_kpa_goal` (`kpa_goal_id`);

-- ---- SEED: grant the 'kpa' module ONLY to managers and above -----------------
-- (team-member roles are intentionally excluded so the page/nav stays hidden from them)
INSERT INTO `role_access` (`role`, `module_key`, `can_access`) VALUES
  ('admin',           'kpa', 1),
  ('manager',         'kpa', 1),
  ('service_manager', 'kpa', 1),
  ('hr_manager',      'kpa', 1),
  ('it_manager',      'kpa', 1),
  ('support_manager', 'kpa', 1)
ON DUPLICATE KEY UPDATE `can_access` = VALUES(`can_access`);
