-- ============================================================================
-- Phase 61 — SLA policies scoped by department & sub-department:
--   * sla_policies.department / sub_department (NULL = applies to all).
--   * Matching specificity: sub-department > department > task type / priority,
--     so "Sales / Regional Sales urgent" beats a generic urgent policy.
--   * Values are the org master strings (org_departments / employees.department).
-- Idempotent; safe to re-run.
-- ============================================================================

ALTER TABLE `sla_policies`
  ADD COLUMN IF NOT EXISTS `department` varchar(80) DEFAULT NULL AFTER `entity`,
  ADD COLUMN IF NOT EXISTS `sub_department` varchar(80) DEFAULT NULL AFTER `department`;
