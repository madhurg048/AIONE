-- ============================================================================
-- Phase 72 — CFO product: the FinOps Compliance Cockpit, inside PayG.
--
-- Twelve screens, one per menu item in the reference app:
--   Dashboard · Document Library · Calendar & Tasks · Review & Sign-off
--   Forms & Submissions · Actions/CAPA · Risk Register · KPI/KRI
--   Evidence Library · Reports & Export · Audit Trail · Admin & Settings
--
-- Design notes:
--   * obligations are the SOURCE of dated tasks — a scheduler expands each
--     obligation's cadence into cfo_tasks, so "overdue" is computed, not typed
--   * every form template is versioned and carries its field schema as JSON,
--     so new working papers need no code (their "Form Template Builder")
--   * risks store likelihood x impact for BOTH inherent and residual, and the
--     rating is derived — never hand-entered
--   * cfo_audit is append-only: no UPDATE/DELETE path exists in the API
-- Idempotent; safe to re-run.
-- ============================================================================

/* ---------------- obligations: statutory + internal, with cadence ---------- */
CREATE TABLE IF NOT EXISTS `cfo_obligations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(40) NOT NULL,
  `title` varchar(200) NOT NULL,
  `obligation_type` enum('statutory','internal_review','rbi','tax','board') NOT NULL DEFAULT 'statutory',
  `authority` varchar(120) DEFAULT NULL,
  `cadence` enum('daily','weekly','monthly','quarterly','half_yearly','yearly','per_rbi_cadence','on_demand') NOT NULL DEFAULT 'monthly',
  `due_day` int(11) DEFAULT NULL              COMMENT 'day of month / day offset for the cadence',
  `owner_role` varchar(60) DEFAULT NULL       COMMENT 'free-text role label, e.g. "Tax Lead"',
  `owner_employee_id` int(11) DEFAULT NULL,
  `form_template_id` int(11) DEFAULT NULL     COMMENT 'working paper this obligation requires',
  `reference` varchar(200) DEFAULT NULL       COMMENT 'section / circular reference',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/* ---------------- form templates + submissions (maker-checker) ------------- */
CREATE TABLE IF NOT EXISTS `cfo_form_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(48) NOT NULL,
  `title` varchar(200) NOT NULL,
  `cadence` enum('daily','weekly','monthly','quarterly','half_yearly','yearly','per_rbi_cadence','on_demand') NOT NULL DEFAULT 'monthly',
  `version` int(11) NOT NULL DEFAULT 1,
  `approver_role` varchar(60) DEFAULT NULL,
  `checker_role` varchar(60) DEFAULT NULL,
  `fields_json` mediumtext DEFAULT NULL       COMMENT 'field + table-column definitions; no code needed',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `cfo_form_submissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `template_id` int(11) NOT NULL,
  `period` varchar(24) NOT NULL               COMMENT '2026-08-10 | Aug 2026 | Q2 FY2026-27',
  `due_on` date DEFAULT NULL,
  `status` enum('draft','submitted','reviewed','approved','rejected','overdue') NOT NULL DEFAULT 'draft',
  `data_json` mediumtext DEFAULT NULL,
  `prepared_by` int(11) DEFAULT NULL, `prepared_at` timestamp NULL DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL, `reviewed_at` timestamp NULL DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL, `approved_at` timestamp NULL DEFAULT NULL,
  `remarks` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`), KEY `ix_tpl` (`template_id`), KEY `ix_status` (`status`), KEY `ix_due` (`due_on`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/* ---------------- compliance tasks (expanded from obligations) ------------- */
CREATE TABLE IF NOT EXISTS `cfo_tasks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `obligation_id` int(11) DEFAULT NULL,
  `title` varchar(240) NOT NULL,
  `task_type` enum('statutory','internal_review','rbi','tax','board') NOT NULL DEFAULT 'statutory',
  `period` varchar(24) DEFAULT NULL,
  `owner_role` varchar(60) DEFAULT NULL,
  `owner_employee_id` int(11) DEFAULT NULL,
  `due_on` date NOT NULL,
  `status` enum('open','in_progress','done','overdue','waived') NOT NULL DEFAULT 'open',
  `submission_id` int(11) DEFAULT NULL        COMMENT 'the working paper that evidences it',
  `completed_at` timestamp NULL DEFAULT NULL,
  `completed_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_oblig_period` (`obligation_id`,`period`),
  KEY `ix_due` (`due_on`), KEY `ix_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/* ---------------- risk register (versioned, derived ratings) --------------- */
CREATE TABLE IF NOT EXISTS `cfo_risks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ref` varchar(24) NOT NULL,
  `sub_process` varchar(120) NOT NULL,
  `description` varchar(600) NOT NULL,
  `inherent_likelihood` tinyint(4) NOT NULL DEFAULT 3,
  `inherent_impact` tinyint(4) NOT NULL DEFAULT 3,
  `controls` varchar(600) DEFAULT NULL,
  `residual_likelihood` tinyint(4) NOT NULL DEFAULT 2,
  `residual_impact` tinyint(4) NOT NULL DEFAULT 2,
  `owner_role` varchar(60) DEFAULT NULL,
  `owner_employee_id` int(11) DEFAULT NULL,
  `next_review` date DEFAULT NULL,
  `status` enum('open','mitigated','accepted','closed') NOT NULL DEFAULT 'open',
  `version` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_ref` (`ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ratings are DERIVED, so a stored rating can never contradict its inputs
ALTER TABLE `cfo_risks`
  ADD COLUMN IF NOT EXISTS `inherent_score` int(11) AS (`inherent_likelihood` * `inherent_impact`) VIRTUAL,
  ADD COLUMN IF NOT EXISTS `residual_score` int(11) AS (`residual_likelihood` * `residual_impact`) VIRTUAL;

/* ---------------- CAPA / actions ------------------------------------------- */
CREATE TABLE IF NOT EXISTS `cfo_actions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ref` varchar(24) NOT NULL,
  `title` varchar(240) NOT NULL,
  `description` varchar(1000) DEFAULT NULL,
  `source_type` enum('review','audit','incident','risk','kpi_breach','regulator','other') NOT NULL DEFAULT 'review',
  `source_ref` varchar(48) DEFAULT NULL       COMMENT 'e.g. FR-01, the review period, a form code',
  `action_kind` enum('corrective','preventive') NOT NULL DEFAULT 'corrective',
  `owner_role` varchar(60) DEFAULT NULL,
  `owner_employee_id` int(11) DEFAULT NULL,
  `due_on` date DEFAULT NULL,
  `priority` enum('critical','high','medium','low') NOT NULL DEFAULT 'medium',
  `status` enum('open','in_progress','done','verified','closed','overdue') NOT NULL DEFAULT 'open',
  `closure_evidence` varchar(500) DEFAULT NULL,
  `closed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_ref` (`ref`), KEY `ix_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/* ---------------- review cycles + sign-off -------------------------------- */
CREATE TABLE IF NOT EXISTS `cfo_reviews` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `period` varchar(32) NOT NULL               COMMENT 'Aug 2026 | Q3 FY2026-27',
  `review_type` enum('monthly','quarterly','annual','adhoc') NOT NULL DEFAULT 'monthly',
  `scheduled_on` date NOT NULL,
  `status` enum('scheduled','in_progress','signed','skipped') NOT NULL DEFAULT 'scheduled',
  `agenda` varchar(1000) DEFAULT NULL,
  `findings` mediumtext DEFAULT NULL,
  `signed_by` int(11) DEFAULT NULL, `signed_at` timestamp NULL DEFAULT NULL,
  `signature_note` varchar(300) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_period_type` (`period`,`review_type`), KEY `ix_sched` (`scheduled_on`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/* ---------------- KPI / KRI monitoring with RAG thresholds ---------------- */
CREATE TABLE IF NOT EXISTS `cfo_kri` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(32) NOT NULL,
  `name` varchar(200) NOT NULL,
  `indicator_kind` enum('kpi','kri') NOT NULL DEFAULT 'kpi',
  `unit` varchar(24) DEFAULT NULL             COMMENT 'pct | count | inr | days',
  `direction` enum('higher_better','lower_better') NOT NULL DEFAULT 'higher_better',
  `target_value` decimal(14,2) DEFAULT NULL,
  `green_from` decimal(14,2) DEFAULT NULL,
  `amber_from` decimal(14,2) DEFAULT NULL,
  `frequency` enum('daily','weekly','monthly','quarterly') NOT NULL DEFAULT 'monthly',
  `owner_role` varchar(60) DEFAULT NULL,
  `kpa_goal_id` int(11) DEFAULT NULL          COMMENT 'links to the org KPI library (kpa_goals)',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `cfo_kri_readings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `kri_id` int(11) NOT NULL,
  `period` varchar(24) NOT NULL,
  `value` decimal(14,2) DEFAULT NULL,
  `rag` enum('green','amber','red','no_data') NOT NULL DEFAULT 'no_data',
  `note` varchar(400) DEFAULT NULL,
  `captured_by` int(11) DEFAULT NULL,
  `captured_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_kri_period` (`kri_id`,`period`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/* ---------------- evidence library ---------------------------------------- */
CREATE TABLE IF NOT EXISTS `cfo_evidence` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(240) NOT NULL,
  `entity_type` enum('task','submission','review','risk','action','kri','obligation') NOT NULL,
  `entity_id` int(11) NOT NULL,
  `doc_id` int(11) DEFAULT NULL               COMMENT 'links into the existing DMS (doc_files)',
  `file_name` varchar(240) DEFAULT NULL,
  `url` varchar(500) DEFAULT NULL,
  `note` varchar(500) DEFAULT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), KEY `ix_entity` (`entity_type`,`entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/* ---------------- append-only audit trail --------------------------------- */
CREATE TABLE IF NOT EXISTS `cfo_audit` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) DEFAULT NULL,
  `user_name` varchar(120) DEFAULT NULL,
  `action` varchar(32) NOT NULL               COMMENT 'CREATE | UPDATE | APPROVE | SIGN | LOGIN | SCHEDULER …',
  `entity` varchar(48) NOT NULL,
  `entity_id` varchar(48) DEFAULT NULL,
  `summary` varchar(400) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), KEY `ix_when` (`created_at`), KEY `ix_entity` (`entity`,`entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/* ---------------- codes for the CFO records (same style as governance) ----- */
ALTER TABLE `cfo_obligations`      ADD COLUMN IF NOT EXISTS `obl_ref` varchar(24) AS (CONCAT('OBL-', LPAD(`id`,3,'0'))) VIRTUAL;
ALTER TABLE `cfo_tasks`            ADD COLUMN IF NOT EXISTS `task_ref` varchar(24) AS (CONCAT('CT-', LPAD(`id`,5,'0'))) VIRTUAL;
ALTER TABLE `cfo_form_submissions` ADD COLUMN IF NOT EXISTS `sub_ref` varchar(24) AS (CONCAT('SUB-', LPAD(`id`,5,'0'))) VIRTUAL;
ALTER TABLE `cfo_reviews`          ADD COLUMN IF NOT EXISTS `rev_ref` varchar(24) AS (CONCAT('RVW-', LPAD(`id`,4,'0'))) VIRTUAL;
ALTER TABLE `cfo_evidence`         ADD COLUMN IF NOT EXISTS `ev_ref` varchar(24) AS (CONCAT('EV-', LPAD(`id`,5,'0'))) VIRTUAL;

/* ==========================================================================
   ACCESS: a CFO product, a cfo role, and module grants
   ========================================================================== */
INSERT INTO `role_access` (`role`,`module_key`,`can_access`)
SELECT r.role, m.mk, 1 FROM
  (SELECT 'admin' role UNION ALL SELECT 'cfo' UNION ALL SELECT 'finance_manager') r,
  (SELECT 'cfo_dashboard' mk UNION ALL SELECT 'cfo_calendar' UNION ALL SELECT 'cfo_reviews'
   UNION ALL SELECT 'cfo_forms' UNION ALL SELECT 'cfo_capa' UNION ALL SELECT 'cfo_risk'
   UNION ALL SELECT 'cfo_kri' UNION ALL SELECT 'cfo_evidence' UNION ALL SELECT 'cfo_reports'
   UNION ALL SELECT 'cfo_audit' UNION ALL SELECT 'cfo_settings') m
WHERE NOT EXISTS (SELECT 1 FROM `role_access` a WHERE a.role=r.role AND a.module_key=m.mk);

-- the Board and C-Suite groups see the cockpit too
INSERT INTO `emp_group_access` (`group_id`,`module_key`,`can_access`)
SELECT g.id, m.mk, 1 FROM `emp_groups` g,
  (SELECT 'cfo_dashboard' mk UNION ALL SELECT 'cfo_risk' UNION ALL SELECT 'cfo_reports'
   UNION ALL SELECT 'cfo_kri' UNION ALL SELECT 'cfo_audit') m
 WHERE g.name IN ('Board','C-Suite','Compliance & Legal') AND g.is_active=1
   AND NOT EXISTS (SELECT 1 FROM `emp_group_access` a WHERE a.group_id=g.id AND a.module_key=m.mk);
