-- ============================================================================
-- Phase 48 — Role visibility hardening + resizable dashboard cards
--
--   1. dashboard_kpis.col_span : lets a card be 1–4 grid columns wide, so HR /
--      managers can resize any custom card on every department dashboard.
--   2. Sales Exec must NOT see the Scorecard page (GMV/fee/payout are management
--      information). Reps see their KPA/KRA goals instead (rendered live on the
--      dashboard). Row-level scoping for managers (own + their reports only) is
--      enforced in PHP (visible_owner_ids()), no schema change needed.
--
-- Idempotent, prod-clean (no sample data).
-- ============================================================================
SET NAMES utf8mb4;

-- ---- resizable cards -----------------------------------------------------------
ALTER TABLE `dashboard_kpis`
  ADD COLUMN IF NOT EXISTS `col_span` tinyint(1) NOT NULL DEFAULT 1 AFTER `display`;

-- ---- a Sales Exec sees KPIs/KRAs, not the incentive-eligibility scorecard -------
DELETE FROM `role_access` WHERE `role`='sales_exec' AND `module_key`='scorecards';
