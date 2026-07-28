-- ============================================================================
-- Phase 18 — Transactions & Tickets list modules (dashboard drill-downs).
-- Idempotent.
-- ============================================================================
INSERT IGNORE INTO role_access (role, module_key, can_access) VALUES
 -- Transactions: sales roles (reps scoped to their own merchants' txns)
 ('admin','transactions',1),
 ('manager','transactions',1),
 ('sales_exec','transactions',1),
 -- Tickets: sales roles + support (support owns the ticket queue)
 ('admin','tickets',1),
 ('manager','tickets',1),
 ('sales_exec','tickets',1),
 ('support','tickets',1),
 ('support_manager','tickets',1);
