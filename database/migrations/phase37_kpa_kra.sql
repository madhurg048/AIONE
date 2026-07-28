-- Phase 37: KPA / KRA goals (org-group definitions with manager → team inheritance).
--
-- Model
--   • scope='org'  : an admin-defined KPA/KRA for a whole org group (Sales/Service/HR/IT/Support).
--                    Inherited by everyone in that group (managers included).
--   • scope='team' : a manager-defined KPA/KRA for a specific team member
--                    (owner_employee_id set) or the manager's whole team (owner NULL).
--                    Only applies within the creator's line of control (subtree).
--
-- Resolution for a person P (group G, manager chain A1..An):
--   goals(P) = org goals where org_group = G
--            + team goals created by any ancestor Ai where owner = P OR owner IS NULL
--
-- Access: only managers and above may open the KPA page (role_access 'kpa' below).
--         Team members never see the page; their goals are managed for them.

CREATE TABLE IF NOT EXISTS `kpa_goals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `scope` enum('org','team') NOT NULL DEFAULT 'org',
  `org_group` varchar(40) DEFAULT NULL,            -- Sales/Service/HR/IT/Support (scope=org)
  `owner_employee_id` int(11) DEFAULT NULL,        -- target member (scope=team); NULL = creator's whole team
  `created_by` int(11) NOT NULL DEFAULT 0,         -- employee id of the admin/manager who defined it
  `created_by_role` varchar(40) DEFAULT NULL,
  `title` varchar(160) NOT NULL,                   -- the KPA (Key Performance Area)
  `kra` varchar(600) DEFAULT NULL,                 -- the KRA / how it's measured
  `weight` decimal(5,2) NOT NULL DEFAULT 0.00,     -- relative weight (%)
  `target_value` varchar(120) DEFAULT NULL,        -- freeform target (e.g. "₹2 Cr", "95", "Zero escalations")
  `unit` enum('inr','pct','count','flag','text') NOT NULL DEFAULT 'text',
  `period` varchar(20) NOT NULL DEFAULT 'annual',  -- e.g. 2026-Q3 / FY26 / annual
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_scope_group` (`scope`,`org_group`),
  KEY `idx_owner` (`owner_employee_id`),
  KEY `idx_creator` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Grant the 'kpa' module ONLY to managers and above (team-member roles are excluded,
-- so the nav item and page are hidden from them). Idempotent via the unique (role,module_key).
INSERT INTO `role_access` (`role`, `module_key`, `can_access`) VALUES
  ('admin',           'kpa', 1),
  ('manager',         'kpa', 1),
  ('service_manager', 'kpa', 1),
  ('hr_manager',      'kpa', 1),
  ('it_manager',      'kpa', 1),
  ('support_manager', 'kpa', 1)
ON DUPLICATE KEY UPDATE `can_access` = VALUES(`can_access`);
