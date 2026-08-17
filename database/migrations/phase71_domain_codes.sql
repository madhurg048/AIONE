-- ============================================================================
-- Phase 71 — Domain-style codes, as the design writes them:
--
--     SLA-COMP-01   KRA-SETT-01   KPI-RECON-01   ESC-COMP-01
--
-- A record gets a short DOMAIN tag (COMP, RECON, SETT, AUDIT …) plus a sequence
-- inside that domain. Records without a domain keep their sequential code
-- (SLA-0001), so nothing already referenced changes shape until it is tagged —
-- and links/events reference ids, never the code text, so nothing breaks.
--
-- The code column stays VIRTUAL: derived, never stored, never able to drift.
-- Idempotent; safe to re-run.
-- ============================================================================

/* ---------------- domain + per-domain sequence ---------------- */
ALTER TABLE `sla_policies`
  ADD COLUMN IF NOT EXISTS `code_domain` varchar(8) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `code_seq` int(11) DEFAULT NULL;
ALTER TABLE `escalation_matrix`
  ADD COLUMN IF NOT EXISTS `code_domain` varchar(8) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `code_seq` int(11) DEFAULT NULL;
ALTER TABLE `kpa_goals`
  ADD COLUMN IF NOT EXISTS `code_domain` varchar(8) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `code_seq` int(11) DEFAULT NULL;

/* ---------------- tag the existing rows ----------------
   Domain comes from what the record is about, so the code reads meaningfully. */
UPDATE `sla_policies` SET code_domain = CASE
    WHEN name LIKE '%complaint%' OR name LIKE '%ticket%' OR entity = 'ticket' THEN 'COMP'
    WHEN name LIKE '%recon%'                                                  THEN 'RECON'
    WHEN name LIKE '%settle%'                                                 THEN 'SETT'
    WHEN name LIKE '%KYC%'                                                    THEN 'KYC'
    WHEN name LIKE '%audit%' OR name LIKE '%review%'                          THEN 'AUDIT'
    WHEN name LIKE '%exception%'                                              THEN 'EXCEP'
    WHEN department = 'Sales'                                                 THEN 'SALES'
    WHEN department = 'Service'                                               THEN 'SVC'
    ELSE 'GEN' END
 WHERE code_domain IS NULL;

UPDATE `kpa_goals` SET code_domain = CASE
    WHEN org_group = 'Sales'   THEN 'SALES'
    WHEN org_group = 'Service' THEN 'SVC'
    WHEN org_group = 'HR'      THEN 'HR'
    WHEN org_group = 'IT'      THEN 'IT'
    WHEN org_group = 'Support' THEN 'SUP'
    WHEN title LIKE '%settle%' THEN 'SETT'
    WHEN title LIKE '%recon%'  THEN 'RECON'
    ELSE 'ORG' END
 WHERE code_domain IS NULL;

UPDATE `escalation_matrix` m
   LEFT JOIN `employees` e ON e.id = m.employee_id
   SET m.code_domain = CASE
        WHEN m.subject_type = 'role'  THEN 'ROLE'
        WHEN m.subject_type = 'group' THEN 'GRP'
        WHEN e.department = 'Sales'    THEN 'SALES'
        WHEN e.department = 'Service'  THEN 'SVC'
        WHEN e.department = 'IT'       THEN 'IT'
        WHEN e.department = 'HR'       THEN 'HR'
        ELSE 'GEN' END
 WHERE m.code_domain IS NULL;

/* ---------------- number them inside each domain ---------------- */
UPDATE `sla_policies` p
  JOIN (SELECT id, ROW_NUMBER() OVER (PARTITION BY code_domain ORDER BY id) rn FROM `sla_policies`) x ON x.id = p.id
   SET p.code_seq = x.rn WHERE p.code_seq IS NULL;

UPDATE `kpa_goals` k
  JOIN (SELECT id, ROW_NUMBER() OVER (PARTITION BY code_domain ORDER BY id) rn FROM `kpa_goals`) x ON x.id = k.id
   SET k.code_seq = x.rn WHERE k.code_seq IS NULL;

UPDATE `escalation_matrix` m
  JOIN (SELECT id, ROW_NUMBER() OVER (PARTITION BY code_domain ORDER BY id) rn FROM `escalation_matrix`) x ON x.id = m.id
   SET m.code_seq = x.rn WHERE m.code_seq IS NULL;

/* ---------------- rebuild the code columns to prefer the domain form -------
   Virtual columns can't be MODIFYed in place, so drop and re-add. Cheap: no
   stored data, and every reader re-reads the new expression. */
ALTER TABLE `sla_policies` DROP COLUMN `sla_ref`;
ALTER TABLE `sla_policies`
  ADD COLUMN `sla_ref` varchar(24) AS (
    IF(`code_domain` IS NULL OR `code_seq` IS NULL,
       CONCAT('SLA-', LPAD(`id`, 4, '0')),
       CONCAT('SLA-', `code_domain`, '-', LPAD(`code_seq`, 2, '0')))) VIRTUAL;

ALTER TABLE `escalation_matrix` DROP COLUMN `esc_ref`;
ALTER TABLE `escalation_matrix`
  ADD COLUMN `esc_ref` varchar(24) AS (
    IF(`code_domain` IS NULL OR `code_seq` IS NULL,
       CONCAT('ESC-', LPAD(`id`, 4, '0')),
       CONCAT('ESC-', `code_domain`, '-', LPAD(`code_seq`, 2, '0')))) VIRTUAL;

ALTER TABLE `kpa_goals` DROP COLUMN `kpa_ref`, DROP COLUMN `kra_ref`, DROP COLUMN `kpi_ref`;
ALTER TABLE `kpa_goals`
  ADD COLUMN `kpa_ref` varchar(24) AS (
    IF(`code_domain` IS NULL OR `code_seq` IS NULL, CONCAT('KPA-', LPAD(`id`, 4, '0')),
       CONCAT('KPA-', `code_domain`, '-', LPAD(`code_seq`, 2, '0')))) VIRTUAL,
  ADD COLUMN `kra_ref` varchar(24) AS (
    IF(`code_domain` IS NULL OR `code_seq` IS NULL, CONCAT('KRA-', LPAD(`id`, 4, '0')),
       CONCAT('KRA-', `code_domain`, '-', LPAD(`code_seq`, 2, '0')))) VIRTUAL,
  ADD COLUMN `kpi_ref` varchar(24) AS (
    IF(`code_domain` IS NULL OR `code_seq` IS NULL, CONCAT('KPI-', LPAD(`id`, 4, '0')),
       CONCAT('KPI-', `code_domain`, '-', LPAD(`code_seq`, 2, '0')))) VIRTUAL;
