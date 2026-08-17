-- ============================================================================
-- Phase 49 — Make the quarterly scorecard the SINGLE source of incentive truth
--
-- Two independent models both wrote into `incentive_ledger`:
--   1. per-merchant  (incentive_book, on merchant activation)  -> rows keyed by merchant_id
--   2. quarterly     (incentive_book_quarterly, on scorecard)  -> rows keyed by scorecard_id
-- The leaderboard / payout totals do a blanket SUM(amount) over the whole ledger, so when
-- BOTH ran, the same GMV was effectively paid (and reported) twice.
--
-- Fix: the FY'26-'27 memo makes the quarterly scorecard the primary model, so the per-merchant
-- path is now GATED behind an explicit, admin-tunable switch that defaults OFF. With it off,
-- merchant activation still works — it simply doesn't auto-book a second incentive line.
--
-- Idempotent: safe to re-run.
-- ============================================================================
SET NAMES utf8mb4;

ALTER TABLE `incentive_config`
  ADD COLUMN IF NOT EXISTS `per_merchant_enabled` tinyint(1) NOT NULL DEFAULT 0 AFTER `fee_overachieve_pct`;

-- keep the single config row present (no-op if it already exists)
INSERT INTO `incentive_config` (`id`) VALUES (1)
ON DUPLICATE KEY UPDATE `id` = `id`;
