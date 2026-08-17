-- ============================================================================
-- Phase 65 — Reviewer / approver workflow for every governance record
-- (KPA, KRA, SLA/TAT policy, escalation rule, compliance policy) on top of the
-- phase64 codes + version history, plus revert-to-a-previous-version.
--
--   draft -> pending_review -> reviewed -> approved
--                 \-> rejected (by reviewer or approver) -> draft (reopen)
--
-- Separation of duties (enforced in the API): the reviewer must not be the
-- author, and the approver must not be the author or the reviewer.
-- One row per object; existing records are seeded as 'approved' (legacy) so
-- nothing already live suddenly reads as unapproved.
-- Idempotent; safe to re-run.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `governance_approvals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `obj_type` varchar(16) NOT NULL,            -- kpa | kra | sla | esc | policy
  `obj_id` int(11) NOT NULL,
  `status` enum('draft','pending_review','reviewed','approved','rejected') NOT NULL DEFAULT 'draft',
  `version` int(11) NOT NULL DEFAULT 1,       -- the version this decision applies to
  `author_id` int(11) DEFAULT NULL,
  `submitted_by` int(11) DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `rejected_by` int(11) DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `reject_reason` varchar(400) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_obj` (`obj_type`,`obj_id`),
  KEY `ix_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- seed everything that already exists as approved-legacy
INSERT INTO `governance_approvals` (`obj_type`,`obj_id`,`status`,`version`,`approved_at`)
SELECT 'kpa', k.id, 'approved', k.version, k.created_at FROM `kpa_goals` k
 WHERE NOT EXISTS (SELECT 1 FROM `governance_approvals` a WHERE a.obj_type='kpa' AND a.obj_id=k.id);

INSERT INTO `governance_approvals` (`obj_type`,`obj_id`,`status`,`version`,`approved_at`)
SELECT 'sla', s.id, 'approved', s.version, s.created_at FROM `sla_policies` s
 WHERE NOT EXISTS (SELECT 1 FROM `governance_approvals` a WHERE a.obj_type='sla' AND a.obj_id=s.id);

INSERT INTO `governance_approvals` (`obj_type`,`obj_id`,`status`,`version`,`approved_at`)
SELECT 'esc', m.id, 'approved', m.version, m.created_at FROM `escalation_matrix` m
 WHERE NOT EXISTS (SELECT 1 FROM `governance_approvals` a WHERE a.obj_type='esc' AND a.obj_id=m.id);

INSERT INTO `governance_approvals` (`obj_type`,`obj_id`,`status`,`version`,`approved_at`)
SELECT 'policy', c.id, 'approved', c.version, c.created_at FROM `compliance_items` c
 WHERE NOT EXISTS (SELECT 1 FROM `governance_approvals` a WHERE a.obj_type='policy' AND a.obj_id=c.id);
