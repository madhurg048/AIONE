-- ============================================================================
-- Phase 69 — SLA policies and escalation rules can target a PERSON, a ROLE or a
-- GROUP, not just a department.
--
--   sla_policies.applies_to_*    who the TAT applies to
--        all       -> everyone in the department/type scope (unchanged default)
--        employee  -> one person
--        role      -> everyone holding that login role (e.g. every Sales Exec)
--        group     -> every member of an employee group (e.g. "Sales Team")
--
--   escalation_matrix subject_*  whose work the rule governs (person/role/group)
--   escalation_matrix target_*   who it escalates TO (person/role/group)
--        A role/group target resolves at escalation time to the least-loaded
--        active member, so "escalate to the Compliance & Legal group" always
--        lands on a real person while staying a group-level rule.
--
-- The old columns stay authoritative for employee-typed rows, so every existing
-- policy and rule keeps working untouched.
-- Idempotent; safe to re-run.
-- ============================================================================

ALTER TABLE `sla_policies`
  ADD COLUMN IF NOT EXISTS `applies_to_type` enum('all','employee','role','group') NOT NULL DEFAULT 'all' AFTER `sub_department`,
  ADD COLUMN IF NOT EXISTS `applies_to_id`   int(11) DEFAULT NULL AFTER `applies_to_type`,
  ADD COLUMN IF NOT EXISTS `applies_to_role` varchar(40) DEFAULT NULL AFTER `applies_to_id`;

-- a role/group rule has no single employee, so employee_id must accept NULL
ALTER TABLE `escalation_matrix`
  MODIFY `employee_id` int(11) DEFAULT NULL,
  MODIFY `escalate_to` int(11) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `subject_type` enum('employee','role','group') NOT NULL DEFAULT 'employee' AFTER `employee_id`,
  ADD COLUMN IF NOT EXISTS `subject_role` varchar(40) DEFAULT NULL AFTER `subject_type`,
  ADD COLUMN IF NOT EXISTS `subject_group_id` int(11) DEFAULT NULL AFTER `subject_role`,
  ADD COLUMN IF NOT EXISTS `target_type` enum('employee','role','group') NOT NULL DEFAULT 'employee' AFTER `escalate_to`,
  ADD COLUMN IF NOT EXISTS `target_role` varchar(40) DEFAULT NULL AFTER `target_type`,
  ADD COLUMN IF NOT EXISTS `target_group_id` int(11) DEFAULT NULL AFTER `target_role`;

-- the old unique key assumed one row per employee+level; role/group rows share
-- a NULL employee_id, so widen it to the full subject identity
ALTER TABLE `escalation_matrix` DROP INDEX `uq_emp_level`;
ALTER TABLE `escalation_matrix`
  ADD UNIQUE KEY `uq_subject_level` (`employee_id`,`subject_type`,`subject_role`,`subject_group_id`,`level`);

-- ---------------------------------------------------------------------------
-- Worked examples of the new targeting (guarded, so re-running is a no-op)
-- ---------------------------------------------------------------------------

-- every Sales Exec's level-1 breach goes to the Sales Team group
INSERT INTO `escalation_matrix` (`employee_id`,`subject_type`,`subject_role`,`level`,`target_type`,`target_group_id`,`tat_hours`)
SELECT NULL, 'role', 'sales_exec', 1, 'group', g.id, 4.0
  FROM `emp_groups` g
 WHERE g.name = 'Sales Team' AND g.is_active = 1
   AND NOT EXISTS (SELECT 1 FROM `escalation_matrix` m
                    WHERE m.subject_type='role' AND m.subject_role='sales_exec' AND m.level=1);

-- anything a Service Exec lets slip at level 2 goes to the Compliance & Legal group
INSERT INTO `escalation_matrix` (`employee_id`,`subject_type`,`subject_role`,`level`,`target_type`,`target_group_id`,`tat_hours`)
SELECT NULL, 'role', 'service_exec', 2, 'group', g.id, 8.0
  FROM `emp_groups` g
 WHERE g.name = 'Compliance & Legal' AND g.is_active = 1
   AND NOT EXISTS (SELECT 1 FROM `escalation_matrix` m
                    WHERE m.subject_type='role' AND m.subject_role='service_exec' AND m.level=2);

-- the Board picks up anything a group member escalates at level 3
INSERT INTO `escalation_matrix` (`employee_id`,`subject_type`,`subject_group_id`,`level`,`target_type`,`target_group_id`,`tat_hours`)
SELECT NULL, 'group', s.id, 3, 'group', b.id, 24.0
  FROM `emp_groups` s JOIN `emp_groups` b ON b.name = 'Board' AND b.is_active = 1
 WHERE s.name = 'Sales Team' AND s.is_active = 1
   AND NOT EXISTS (SELECT 1 FROM `escalation_matrix` m
                    WHERE m.subject_type='group' AND m.subject_group_id = s.id AND m.level=3);

-- a TAT policy that applies to one role only
INSERT INTO `sla_policies` (`name`,`entity`,`applies_to_type`,`applies_to_role`,`tat_hours`,`escalate_every_hours`,`max_level`,`is_active`)
SELECT 'Sales Exec follow-up — 6h TAT', 'task', 'role', 'sales_exec', 6.0, 6.0, 10, 1 FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM `sla_policies` WHERE `name` = 'Sales Exec follow-up — 6h TAT');

-- and one that applies to a group
INSERT INTO `sla_policies` (`name`,`entity`,`applies_to_type`,`applies_to_id`,`tat_hours`,`escalate_every_hours`,`max_level`,`is_active`)
SELECT 'Compliance & Legal review — 12h TAT', 'task', 'group', g.id, 12.0, 12.0, 10, 1
  FROM `emp_groups` g
 WHERE g.name = 'Compliance & Legal'
   AND NOT EXISTS (SELECT 1 FROM `sla_policies` WHERE `name` = 'Compliance & Legal review — 12h TAT');

-- keep the governance records complete for anything new
INSERT INTO `governance_approvals` (`obj_type`,`obj_id`,`status`,`version`,`approved_at`)
SELECT 'esc', m.id, 'approved', m.version, m.created_at FROM `escalation_matrix` m
 WHERE NOT EXISTS (SELECT 1 FROM `governance_approvals` a WHERE a.obj_type='esc' AND a.obj_id=m.id);
INSERT INTO `governance_approvals` (`obj_type`,`obj_id`,`status`,`version`,`approved_at`)
SELECT 'sla', p.id, 'approved', p.version, p.created_at FROM `sla_policies` p
 WHERE NOT EXISTS (SELECT 1 FROM `governance_approvals` a WHERE a.obj_type='sla' AND a.obj_id=p.id);
