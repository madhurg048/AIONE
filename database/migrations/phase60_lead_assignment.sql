-- ============================================================================
-- Phase 60 — Lead assignment rules:
--   * lead_assign_settings (single row): team-lead switch. ON = new leads go to
--     the LOWEST-OCCUPIED active member of the chosen employee group (groups are
--     created in HR → Groups; an employee can be in many). OFF = whoever brings
--     the lead keeps it (self-assignment).
--   * A demo "Sales Team" pool group so the feature is usable out of the box.
-- Idempotent; safe to re-run.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `lead_assign_settings` (
  `id` tinyint(4) NOT NULL,
  `auto_assign` tinyint(1) NOT NULL DEFAULT 0,
  `group_id` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `lead_assign_settings` (`id`, `auto_assign`, `group_id`)
SELECT 1, 0, NULL FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM `lead_assign_settings` WHERE `id` = 1);

-- demo pool: the sales execs, so the toggle can be tried immediately
INSERT INTO `emp_groups` (`name`, `description`, `is_active`)
SELECT 'Sales Team', 'Lead-assignment pool — auto-assign picks its least-loaded member', 1 FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM `emp_groups` WHERE `name` = 'Sales Team');

INSERT INTO `emp_group_members` (`group_id`, `employee_id`)
SELECT g.id, e.id
  FROM `emp_groups` g
  JOIN `employees` e ON e.id IN (SELECT su.employee_id FROM `sales_users` su
                                  WHERE su.role = 'sales_exec' AND su.is_active = 1)
 WHERE g.`name` = 'Sales Team'
   AND NOT EXISTS (SELECT 1 FROM `emp_group_members` m
                    WHERE m.`group_id` = g.id AND m.`employee_id` = e.id);
