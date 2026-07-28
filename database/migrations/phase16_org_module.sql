-- ============================================================================
-- Phase 16 — Org & Roles admin screen module grant. Idempotent.
-- Admin can reassign any person's login role and reporting line.
-- ============================================================================
INSERT IGNORE INTO role_access (role, module_key, can_access) VALUES
 ('admin','org',1);
