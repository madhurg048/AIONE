-- ============================================================
-- PayG CRM — Phase 8: Timesheets (+AI/voice fill, approval) + configurable RBAC
-- ============================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `timesheets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `entry_date` date NOT NULL,
  `hours` decimal(4,2) NOT NULL DEFAULT 0.00,
  `activity_type` enum('call','meeting','follow_up','onboarding','admin','travel','training','other') DEFAULT 'other',
  `related_type` enum('lead','deal','merchant','none') DEFAULT 'none',
  `related_id` int(11) DEFAULT NULL,
  `description` varchar(500) DEFAULT NULL,
  `status` enum('draft','submitted','approved','rejected') DEFAULT 'draft',
  `source` enum('manual','ai','voice') DEFAULT 'manual',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ts_emp_date` (`employee_id`,`entry_date`),
  KEY `idx_ts_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- admin-configurable role -> module access
CREATE TABLE IF NOT EXISTS `role_access` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `role` varchar(40) NOT NULL,
  `module_key` varchar(40) NOT NULL,
  `can_access` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_role_module` (`role`,`module_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- seed sensible defaults (admin=all; manager=most; sales_exec=field tools)
INSERT IGNORE INTO `role_access` (`role`,`module_key`,`can_access`) VALUES
 ('admin','dashboard',1),('admin','leads',1),('admin','pipeline',1),('admin','merchants',1),('admin','incentives',1),('admin','reports',1),('admin','automation',1),('admin','settings',1),('admin','admin',1),('admin','integrations',1),('admin','timesheet',1),
 ('manager','dashboard',1),('manager','leads',1),('manager','pipeline',1),('manager','merchants',1),('manager','incentives',1),('manager','reports',1),('manager','automation',1),('manager','settings',1),('manager','admin',0),('manager','integrations',0),('manager','timesheet',1),
 ('sales_exec','dashboard',1),('sales_exec','leads',1),('sales_exec','pipeline',1),('sales_exec','merchants',1),('sales_exec','incentives',0),('sales_exec','reports',0),('sales_exec','automation',0),('sales_exec','settings',0),('sales_exec','admin',0),('sales_exec','integrations',0),('sales_exec','timesheet',1);
INSERT INTO `role_access` (`role`,`module_key`,`can_access`) VALUES
 ('admin','activity',1),('manager','activity',1),('sales_exec','activity',1),('hr','activity',1),('it','activity',1),
 ('hr','hr',1),('admin','hr',1),('manager','hr',1),('it','it',1),('admin','it',1),
 ('hr','dashboard',0),('hr','timesheet',1),('it','dashboard',0),('it','timesheet',1)
ON DUPLICATE KEY UPDATE can_access=VALUES(can_access);
