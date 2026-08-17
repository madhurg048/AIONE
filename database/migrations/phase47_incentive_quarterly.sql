-- ============================================================================
-- Phase 47 — Quarterly, scorecard-driven incentive PAYOUT
--
-- Turns the FY'26-'27 memo's incentive maths into a tunable, bookable payout that
-- rides on top of the existing quarterly scorecard (phase14). The scorecard already
-- grades eligibility (GMV gate, fee slab, governance); this phase adds:
--   1. incentive_config  -> the memo's rates/slabs in ONE editable row, so a Sales
--                           Head / Sales Manager can revise the structure over time
--                           without a code change (capability 'incentive_manage').
--   2. incentive_ledger.scorecard_id / period_quarter -> so a quarterly payout can be
--                           booked, traced back to its scorecard, and safely re-computed.
--
-- Idempotent: safe to re-run. NO demo/sample rows here (prod-clean) — see
-- database/seeds/demo_sales_kpa_scorecards.sql for dev-only sample data.
-- ============================================================================
SET NAMES utf8mb4;

-- ---- the memo's incentive structure, one editable row (id=1) -----------------
CREATE TABLE IF NOT EXISTS `incentive_config` (
  `id`                  int(11) NOT NULL DEFAULT 1,
  -- GMV achievement payout (memo §7): base = base_share% of (base_bps bps of quarterly volume)
  `gmv_base_bps`        decimal(6,3) NOT NULL DEFAULT 1.000,    -- 1 bps fee-income reference on volume
  `gmv_base_share_pct`  decimal(6,3) NOT NULL DEFAULT 10.000,   -- base income scale = 10% of that
  `gmv_gate_min_pct`    decimal(6,2) NOT NULL DEFAULT 100.00,   -- below this % attainment -> no payout (100% docking)
  `gmv_accel_pct`       decimal(6,2) NOT NULL DEFAULT 110.00,   -- above this % -> accelerator
  `gmv_pass_mult_pct`   decimal(6,2) NOT NULL DEFAULT 100.00,   -- 100%-110% band multiplier
  `gmv_accel_mult_pct`  decimal(6,2) NOT NULL DEFAULT 125.00,   -- >110% band multiplier
  -- Fee income incentive (memo §8), paid over & above GMV
  `fee_min`             decimal(15,2) NOT NULL DEFAULT 100000,  -- eligibility floor (Rs 1L)
  `fee_slab2`           decimal(15,2) NOT NULL DEFAULT 200000,  -- Rs 2L
  `fee_slab3`           decimal(15,2) NOT NULL DEFAULT 500000,  -- Rs 5L (overachievement)
  `fee_rate1_pct`       decimal(6,2) NOT NULL DEFAULT 5.00,     -- Rs 1L-2L slab
  `fee_rate2_pct`       decimal(6,2) NOT NULL DEFAULT 10.00,    -- above Rs 2L
  `fee_overachieve_pct` decimal(6,2) NOT NULL DEFAULT 5.00,     -- extra multiplier above Rs 5L
  `updated_by`          int(11) DEFAULT NULL,
  `updated_at`          timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- seed the single config row with the memo's defaults (only if the table is empty)
INSERT INTO `incentive_config` (`id`) VALUES (1)
ON DUPLICATE KEY UPDATE `id` = `id`;

-- ---- trace a booked payout back to the scorecard + quarter it came from -------
ALTER TABLE `incentive_ledger`
  ADD COLUMN IF NOT EXISTS `scorecard_id`   int(11) DEFAULT NULL AFTER `rule_id`,
  ADD COLUMN IF NOT EXISTS `period_quarter` char(7) DEFAULT NULL AFTER `period_month`;
ALTER TABLE `incentive_ledger`
  ADD INDEX IF NOT EXISTS `idx_ledger_scorecard` (`scorecard_id`);

-- ---- let the Sales Head reach the Scorecards page (manager/exec/admin already do) -
INSERT IGNORE INTO `role_access` (`role`, `module_key`, `can_access`) VALUES
  ('sales_head','scorecards',1),
  ('sales_head','kpa',1);

-- ---- a Sales Exec must NOT see incentive amounts or the incentive/RBAC admin ----------
-- Policy: reps are measured on their KPIs/KRAs; how much they are PAID is management-only
-- (Sales Head / Manager). These nav modules were over-granted (nearly every role had them).
DELETE FROM `role_access` WHERE `role`='sales_exec' AND `module_key` IN ('incentives','admin');
