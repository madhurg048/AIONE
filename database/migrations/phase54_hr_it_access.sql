-- ============================================================================
-- Phase 54 — Departmental access completion:
--   * IT: the Tickets module (view / claim / reply across both verticals — IT
--     has no vertical, so vertical_scope() is already unrestricted for them).
--   * HR: org-wide KPA/KRA + users were already granted (phase44/46); their
--     Reports gate is fixed in code (kpi_govern -> unrestricted scope).
-- Idempotent; safe to re-run.
-- ============================================================================

INSERT INTO `role_access` (`role`,`module_key`,`can_access`)
SELECT s.role, s.mk, 1 FROM (
  SELECT 'it' AS role, 'tickets' AS mk UNION ALL
  SELECT 'it_manager', 'tickets'
) s
WHERE NOT EXISTS (
  SELECT 1 FROM `role_access` ra WHERE ra.`role`=s.role AND ra.`module_key`=s.mk
);
