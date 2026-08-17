-- ============================================================================
-- Phase 70 — Bring KRA / KPI / SLA up to the design:
--
--   * types (KRA type, KPI type, SLA type) and descriptions
--   * a real lifecycle: active | draft | archived
--   * TAT expressed the way the business states it — "3 Business Days",
--     "T+1 by 11:00 AM", "48 hours" — via tat_basis + tat_value + tat_clock,
--     with WORKING HOURS so a deadline doesn't tick through nights/weekends
--   * KRAs can be linked to roles (Figma's "Linked Roles")
--
-- Existing rows keep working: tat_basis defaults to 'hours' with the current
-- tat_hours value, is_24x7 = 1, so nothing changes until someone opts in.
-- Idempotent; safe to re-run.
-- ============================================================================

/* ---------------- KRA / KPI (both live on kpa_goals) ---------------- */
ALTER TABLE `kpa_goals`
  ADD COLUMN IF NOT EXISTS `kra_type` varchar(40) DEFAULT NULL       COMMENT 'Operational | Service | Financial | Compliance | People',
  ADD COLUMN IF NOT EXISTS `kpi_type` varchar(40) DEFAULT NULL       COMMENT 'Efficiency | Service | Quality | Growth | Risk',
  ADD COLUMN IF NOT EXISTS `description` varchar(600) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `status` enum('active','draft','archived') NOT NULL DEFAULT 'active',
  ADD COLUMN IF NOT EXISTS `tat_deadline` varchar(80) DEFAULT NULL   COMMENT 'business-stated deadline for the KPI, e.g. "T+1 by 11:00 AM"',
  ADD COLUMN IF NOT EXISTS `linked_roles` varchar(400) DEFAULT NULL  COMMENT 'CSV of role keys this KRA applies to';

-- keep status in step with the is_active flag that already existed
UPDATE `kpa_goals` SET `status` = IF(`is_active` = 1, 'active', 'archived') WHERE `status` = 'active' AND `is_active` = 0;

/* ---------------- SLA policies ---------------- */
ALTER TABLE `sla_policies`
  ADD COLUMN IF NOT EXISTS `sla_type` varchar(40) DEFAULT NULL       COMMENT 'Service | Operational | Control | Compliance',
  ADD COLUMN IF NOT EXISTS `description` varchar(600) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `status` enum('active','draft','archived') NOT NULL DEFAULT 'active',
  -- how the TAT is measured
  ADD COLUMN IF NOT EXISTS `tat_basis` enum('hours','business_hours','business_days','calendar_days','clock') NOT NULL DEFAULT 'hours',
  ADD COLUMN IF NOT EXISTS `tat_value` decimal(7,1) DEFAULT NULL     COMMENT 'hours or days, per tat_basis',
  ADD COLUMN IF NOT EXISTS `tat_clock` time DEFAULT NULL             COMMENT 'deadline time of day for basis=clock (e.g. 11:00 for "T+1 by 11 AM")',
  ADD COLUMN IF NOT EXISTS `tat_offset_days` int(11) NOT NULL DEFAULT 1 COMMENT 'T+n for basis=clock',
  -- working hours the TAT respects
  ADD COLUMN IF NOT EXISTS `is_24x7` tinyint(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS `work_days` varchar(40) NOT NULL DEFAULT 'Mon,Tue,Wed,Thu,Fri',
  ADD COLUMN IF NOT EXISTS `work_start` time NOT NULL DEFAULT '09:00:00',
  ADD COLUMN IF NOT EXISTS `work_end` time NOT NULL DEFAULT '18:00:00';

UPDATE `sla_policies` SET `status` = IF(`is_active` = 1, 'active', 'archived') WHERE `is_active` = 0 AND `status` = 'active';
UPDATE `sla_policies` SET `tat_value` = `tat_hours` WHERE `tat_value` IS NULL;

/* ---------------- escalation matrix: name + timeline basis + status ------- */
ALTER TABLE `escalation_matrix`
  ADD COLUMN IF NOT EXISTS `matrix_name` varchar(120) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `timeline_basis` enum('working_hours','calendar_days','hours') NOT NULL DEFAULT 'hours',
  ADD COLUMN IF NOT EXISTS `status` enum('active','draft','archived') NOT NULL DEFAULT 'active',
  ADD COLUMN IF NOT EXISTS `sla_policy_id` int(11) DEFAULT NULL COMMENT 'the TAT policy this matrix serves (Figma: Linked SLA Policy)';

/* ---------------- seed realistic values on the demo rows ---------------- */
UPDATE `sla_policies` SET
   sla_type = CASE
        WHEN entity = 'ticket' THEN 'Service'
        WHEN name LIKE '%KYC%' OR name LIKE '%complian%' THEN 'Compliance'
        WHEN name LIKE '%review%' OR name LIKE '%audit%' THEN 'Control'
        ELSE 'Operational' END,
   description = COALESCE(description, CONCAT('Complete ', LOWER(COALESCE(task_type, entity)), ' work within the agreed turnaround.'))
 WHERE sla_type IS NULL;

-- a couple of policies that show off the new TAT styles
UPDATE `sla_policies`
   SET tat_basis = 'business_days', tat_value = 3, is_24x7 = 0,
       work_days = 'Mon,Tue,Wed,Thu,Fri', work_start = '09:00:00', work_end = '18:00:00'
 WHERE entity = 'ticket' AND tat_basis = 'hours' AND tat_hours >= 24 LIMIT 2;

UPDATE `sla_policies`
   SET tat_basis = 'business_hours', tat_value = tat_hours, is_24x7 = 0,
       work_days = 'Mon,Tue,Wed,Thu,Fri,Sat', work_start = '09:00:00', work_end = '18:00:00'
 WHERE tat_basis = 'hours' AND tat_hours < 24 AND entity = 'task' LIMIT 3;

-- KRA / KPI classification for the seeded goals
UPDATE `kpa_goals` SET
   kra_type = CASE
        WHEN org_group = 'Service' THEN 'Service'
        WHEN org_group = 'HR' THEN 'People'
        WHEN title LIKE '%Revenue%' OR title LIKE '%Sales%' THEN 'Financial'
        WHEN title LIKE '%Complian%' OR title LIKE '%Risk%' THEN 'Compliance'
        ELSE 'Operational' END,
   kpi_type = CASE
        WHEN unit = 'pct'  THEN 'Efficiency'
        WHEN unit = 'inr'  THEN 'Growth'
        WHEN unit = 'flag' THEN 'Risk'
        ELSE 'Quality' END,
   description = COALESCE(description, kra, title)
 WHERE kra_type IS NULL;

-- give the sales-side goals the roles they belong to
UPDATE `kpa_goals` SET linked_roles = 'sales_exec,manager'
 WHERE linked_roles IS NULL AND (org_group = 'Sales' OR title LIKE '%Sales%');
UPDATE `kpa_goals` SET linked_roles = 'service_exec,service_manager'
 WHERE linked_roles IS NULL AND org_group = 'Service';
UPDATE `kpa_goals` SET linked_roles = 'hr,hr_manager' WHERE linked_roles IS NULL AND org_group = 'HR';

-- name the escalation matrices after the person/role/group they serve
UPDATE `escalation_matrix` m
   LEFT JOIN `employees` e ON e.id = m.employee_id
   LEFT JOIN `emp_groups` g ON g.id = m.subject_group_id
   SET m.matrix_name = CONCAT(
        COALESCE(e.full_name, g.name, m.subject_role, 'Default'), ' escalation path')
 WHERE m.matrix_name IS NULL;

-- the two library screens ride on the existing 'kpa' grants
INSERT INTO `role_access` (`role`,`module_key`,`can_access`)
SELECT ra.role, m.mk, 1
  FROM `role_access` ra
  JOIN (SELECT 'kra' mk UNION ALL SELECT 'kpi') m
 WHERE ra.module_key='kpa' AND ra.can_access=1
   AND NOT EXISTS (SELECT 1 FROM `role_access` x WHERE x.role=ra.role AND x.module_key=m.mk);
