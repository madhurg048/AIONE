-- ============================================================================
-- Phase 77 — link the KRA/KPI governance library to the Sales scorecard engine
--
-- kpa_goals (KRA/KPI Library, company-wide catalogue) and kpi_definitions +
-- scorecard_lines (the Sales scorecard scoring engine — GMV, fee income,
-- segment mix, active merchants, governance) were two independent systems:
-- a governor could define "Grow GMV" in the library with no way to say it
-- IS the same KPI the scorecard scores every quarter.
--
-- This adds a single OPTIONAL, nullable pointer from a library entry to the
-- scorecard KPI catalog code. It is pure metadata:
--   * scorecards.php never reads this column — scoring, weights, targets,
--     gates and payouts are completely unaffected.
--   * NULL by default on every existing and new row, so nothing changes
--     until a governor explicitly links a library entry.
--
-- Idempotent: safe to re-run.
-- ============================================================================

ALTER TABLE `kpa_goals`
  ADD COLUMN IF NOT EXISTS `linked_kpi_code` varchar(50) DEFAULT NULL
    COMMENT 'Optional FK-by-value to kpi_definitions.code — ties this library entry to the live Sales scorecard KPI it represents (informational only; scoring logic does not read this column)';

ALTER TABLE `kpa_goals`
  ADD KEY IF NOT EXISTS `ix_kpa_goals_linked_kpi_code` (`linked_kpi_code`);
