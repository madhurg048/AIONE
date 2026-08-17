-- ============================================================================
-- Phase 64 — Every governance object gets a human-quotable CODE, and the codes
-- can be LINKED to each other (traceability).
--
--   KPA-0001 / KRA-0001   kpa_goals        (one row carries a KPA and its KRA)
--   SLA-0001              sla_policies     (the TAT policy)
--   ESC-0001              escalation_matrix(the escalation rule)
--   ESL-0001              task_escalations (an actual escalation event)
--   POL/CRT/LIC/CMP-0001  compliance_items (policy number — always present)
--   LNK-0001              governance_links (a link between any two of the above)
--
-- Codes are VIRTUAL generated columns: derived from the row's own id, so they
-- can never drift, need no backfill, and cost no storage.
-- Idempotent; safe to re-run.
-- ============================================================================

ALTER TABLE `kpa_goals`
  ADD COLUMN IF NOT EXISTS `kpa_ref` varchar(20)
      AS (CONCAT('KPA-', LPAD(`id`, 4, '0'))) VIRTUAL,
  ADD COLUMN IF NOT EXISTS `kra_ref` varchar(20)
      AS (CONCAT('KRA-', LPAD(`id`, 4, '0'))) VIRTUAL,
  -- a KRA may be measured by a TAT/SLA policy
  ADD COLUMN IF NOT EXISTS `sla_policy_id` int(11) DEFAULT NULL;

ALTER TABLE `sla_policies`
  ADD COLUMN IF NOT EXISTS `sla_ref` varchar(20)
      AS (CONCAT('SLA-', LPAD(`id`, 4, '0'))) VIRTUAL;

ALTER TABLE `escalation_matrix`
  ADD COLUMN IF NOT EXISTS `esc_ref` varchar(20)
      AS (CONCAT('ESC-', LPAD(`id`, 4, '0'))) VIRTUAL;

ALTER TABLE `task_escalations`
  ADD COLUMN IF NOT EXISTS `esl_ref` varchar(20)
      AS (CONCAT('ESL-', LPAD(`id`, 4, '0'))) VIRTUAL,
  -- which escalation-matrix rule produced this hop (NULL = plain manager chain)
  ADD COLUMN IF NOT EXISTS `matrix_id` int(11) DEFAULT NULL;

ALTER TABLE `compliance_items`
  ADD COLUMN IF NOT EXISTS `policy_no` varchar(24)
      AS (CONCAT(CASE `item_type`
                   WHEN 'policy'        THEN 'POL-'
                   WHEN 'certification' THEN 'CRT-'
                   WHEN 'license'       THEN 'LIC-'
                   ELSE 'CMP-' END, LPAD(`id`, 4, '0'))) VIRTUAL;

-- ---------------------------------------------------------------------------
-- Traceability: any coded object linked to any other, with a relationship verb.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `governance_links` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `from_type` enum('kpa','kra','sla','esc','policy') NOT NULL,
  `from_id` int(11) NOT NULL,
  `to_type` enum('kpa','kra','sla','esc','policy') NOT NULL,
  `to_id` int(11) NOT NULL,
  `relation` varchar(40) NOT NULL DEFAULT 'relates_to',
  `note` varchar(300) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_link` (`from_type`,`from_id`,`to_type`,`to_id`,`relation`),
  KEY `ix_from` (`from_type`,`from_id`),
  KEY `ix_to` (`to_type`,`to_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `governance_links`
  ADD COLUMN IF NOT EXISTS `link_ref` varchar(20)
      AS (CONCAT('LNK-', LPAD(`id`, 4, '0'))) VIRTUAL;

-- ---------------------------------------------------------------------------
-- Version control: every coded object carries a version number, and every
-- create/edit/delete/link/unlink is snapshotted so you can answer "who changed
-- POL-0007, when, and what did it say before?".
-- ---------------------------------------------------------------------------
ALTER TABLE `kpa_goals`         ADD COLUMN IF NOT EXISTS `version` int(11) NOT NULL DEFAULT 1;
ALTER TABLE `sla_policies`      ADD COLUMN IF NOT EXISTS `version` int(11) NOT NULL DEFAULT 1;
ALTER TABLE `escalation_matrix` ADD COLUMN IF NOT EXISTS `version` int(11) NOT NULL DEFAULT 1;
ALTER TABLE `compliance_items`  ADD COLUMN IF NOT EXISTS `version` int(11) NOT NULL DEFAULT 1;
ALTER TABLE `governance_links`  ADD COLUMN IF NOT EXISTS `version` int(11) NOT NULL DEFAULT 1;

CREATE TABLE IF NOT EXISTS `governance_revisions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `obj_type` varchar(16) NOT NULL,          -- kpa | sla | esc | policy | link
  `obj_id` int(11) NOT NULL,
  `obj_code` varchar(24) DEFAULT NULL,      -- the code as at the time (KPA-0007 …)
  `version` int(11) NOT NULL DEFAULT 1,
  `action` varchar(16) NOT NULL,            -- created | updated | deleted | linked | unlinked
  `summary` varchar(400) DEFAULT NULL,      -- human sentence: what changed
  `snapshot` mediumtext DEFAULT NULL,       -- JSON of the row AFTER the change
  `changed_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_obj` (`obj_type`,`obj_id`),
  KEY `ix_when` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `governance_revisions`
  ADD COLUMN IF NOT EXISTS `rev_ref` varchar(20)
      AS (CONCAT('REV-', LPAD(`id`, 5, '0'))) VIRTUAL;

-- the traceability screen is a governance module: admin + heads manage it
INSERT INTO `role_access` (`role`,`module_key`,`can_access`)
SELECT r.role, 'govmap', 1 FROM (
  SELECT 'admin' role UNION ALL SELECT 'sales_head' UNION ALL SELECT 'hr_head'
  UNION ALL SELECT 'manager' UNION ALL SELECT 'service_manager' UNION ALL SELECT 'it_manager'
  UNION ALL SELECT 'hr_manager'
) r
WHERE NOT EXISTS (SELECT 1 FROM `role_access` a WHERE a.`role`=r.role AND a.`module_key`='govmap');

-- Compliance & Legal own the policy register, so give the group the map too
INSERT INTO `emp_group_access` (`group_id`,`module_key`,`can_access`)
SELECT g.id,'govmap',1 FROM `emp_groups` g
 WHERE g.`name`='Compliance & Legal'
   AND NOT EXISTS (SELECT 1 FROM `emp_group_access` a WHERE a.`group_id`=g.id AND a.`module_key`='govmap');

-- the org chart is a directory for EVERYONE: grant to every role
INSERT INTO `role_access` (`role`,`module_key`,`can_access`)
SELECT r.role, 'orgchart', 1 FROM (
  SELECT 'admin' role UNION ALL SELECT 'hr_head' UNION ALL SELECT 'hr_manager' UNION ALL SELECT 'hr'
  UNION ALL SELECT 'sales_head' UNION ALL SELECT 'manager' UNION ALL SELECT 'sales_exec'
  UNION ALL SELECT 'service_manager' UNION ALL SELECT 'service_exec'
  UNION ALL SELECT 'support_manager' UNION ALL SELECT 'support'
  UNION ALL SELECT 'it_manager' UNION ALL SELECT 'it'
) r
WHERE NOT EXISTS (SELECT 1 FROM `role_access` a WHERE a.`role`=r.role AND a.`module_key`='orgchart');

-- performance view: everyone sees their own record; leads/heads/admin see the roll-up
INSERT INTO `role_access` (`role`,`module_key`,`can_access`)
SELECT r.role, 'performance', 1 FROM (
  SELECT 'admin' role UNION ALL SELECT 'hr_head' UNION ALL SELECT 'hr_manager' UNION ALL SELECT 'hr'
  UNION ALL SELECT 'sales_head' UNION ALL SELECT 'manager' UNION ALL SELECT 'sales_exec'
  UNION ALL SELECT 'service_manager' UNION ALL SELECT 'service_exec'
  UNION ALL SELECT 'support_manager' UNION ALL SELECT 'support'
  UNION ALL SELECT 'it_manager' UNION ALL SELECT 'it'
) r
WHERE NOT EXISTS (SELECT 1 FROM `role_access` a WHERE a.`role`=r.role AND a.`module_key`='performance');
