-- ============================================================
-- PayG CRM — Phase 6: Single sign-on users (role-based) +
--                     flexible incentive structure fields
-- ============================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `sales_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(60) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(120) NOT NULL,
  `role` enum('admin','manager','sales_exec') NOT NULL DEFAULT 'sales_exec',
  `employee_id` int(11) DEFAULT NULL,   -- link to employees for ownership
  `is_active` tinyint(1) DEFAULT 1,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Flexible incentive structure ----
-- widen rule types + add optional filters so admins can target rules precisely
ALTER TABLE `incentive_rules`
  MODIFY `rule_type` enum('flat','percent','percent_volume','slab_volume','per_product') NOT NULL DEFAULT 'flat';
ALTER TABLE `incentive_rules` ADD COLUMN IF NOT EXISTS `category` varchar(100) DEFAULT NULL;      -- merchant category filter
ALTER TABLE `incentive_rules` ADD COLUMN IF NOT EXISTS `business_type` varchar(50) DEFAULT NULL;  -- merchant business_type filter
ALTER TABLE `incentive_rules` ADD COLUMN IF NOT EXISTS `applies_per` enum('merchant','deal') DEFAULT 'merchant';
ALTER TABLE `incentive_rules` ADD COLUMN IF NOT EXISTS `label` varchar(150) DEFAULT NULL;         -- human name for the rule
ALTER TABLE `incentive_plans` ADD COLUMN IF NOT EXISTS `stack_rules` tinyint(1) DEFAULT 1;        -- 1=sum all matching, 0=highest only
