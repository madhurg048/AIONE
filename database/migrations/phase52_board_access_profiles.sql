-- ============================================================================
-- Phase 52 — Access profiles via groups:
--   * emp_groups.grant_all: a FULL-ACCESS group ("the Board") — members get
--     every module and organisation-wide record visibility, whatever their role.
--   * C-suite / leadership areas keep using ordinary module-granting groups
--     (emp_group_access), e.g. "C-Suite Sales" -> the sales modules.
-- Idempotent; safe to re-run.
-- ============================================================================

ALTER TABLE `emp_groups`
  ADD COLUMN IF NOT EXISTS `grant_all` tinyint(1) NOT NULL DEFAULT 0;

-- Seed the Board (full access) and an example C-Suite area group
INSERT INTO `emp_groups` (`name`,`description`,`is_active`,`grant_all`)
SELECT 'Board','Board of directors — FULL access to every module and record',1,1
WHERE NOT EXISTS (SELECT 1 FROM `emp_groups` WHERE `name`='Board');

INSERT INTO `emp_groups` (`name`,`description`,`is_active`,`grant_all`)
SELECT 'C-Suite','Leadership — dashboards, reports and governance for their areas',1,0
WHERE NOT EXISTS (SELECT 1 FROM `emp_groups` WHERE `name`='C-Suite');

INSERT INTO `emp_group_access` (`group_id`,`module_key`,`can_access`)
SELECT g.id, s.mk, 1 FROM `emp_groups` g
JOIN (SELECT 'dashboard' mk UNION ALL SELECT 'reports' UNION ALL SELECT 'scorecards'
      UNION ALL SELECT 'escalations' UNION ALL SELECT 'compliance' UNION ALL SELECT 'settlements') s
WHERE g.`name`='C-Suite'
  AND NOT EXISTS (SELECT 1 FROM `emp_group_access` a WHERE a.`group_id`=g.id AND a.`module_key`=s.mk);
