-- ============================================================================
-- Phase 63 — Merchant settlements REMOVED from the product (2026-08-06).
--   Revokes the 'settlements' module from every role and every employee group,
--   so no user of any tier can reach it. The settlements / approval_actions
--   tables and their rows are deliberately LEFT IN PLACE (history is kept and
--   the module can be switched back on) — see api/sales/settlements.php.
-- Idempotent; safe to re-run.
-- ============================================================================

UPDATE `role_access`      SET `can_access` = 0 WHERE `module_key` = 'settlements';
UPDATE `emp_group_access` SET `can_access` = 0 WHERE `module_key` = 'settlements';
