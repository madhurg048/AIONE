-- ============================================================================
-- Phase 53 — People grid fields: employment type (employee/contractor) and
-- governance tier (board / advisory / executive / execution). Governance maps
-- to access profiles: board -> "Board" full-access group, executive -> "C-Suite".
-- Idempotent; safe to re-run.
-- ============================================================================

ALTER TABLE `employees`
  ADD COLUMN IF NOT EXISTS `emp_type` enum('employee','contractor') NOT NULL DEFAULT 'employee',
  ADD COLUMN IF NOT EXISTS `governance` enum('board','advisory','executive','execution') NOT NULL DEFAULT 'execution';
