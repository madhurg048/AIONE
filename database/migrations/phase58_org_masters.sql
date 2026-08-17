-- ============================================================================
-- Phase 58 — Organisation masters (from the approved All-In-One design):
--   * org_profile: "My Organisation" — profile, tax details, locations
--   * org_departments: departments + sub-departments master
--   * org_designations: job titles with level / department / exec-leadership
-- Idempotent; safe to re-run.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `org_profile` (
  `id` tinyint(1) NOT NULL DEFAULT 1,
  `org_name` varchar(160) DEFAULT NULL,
  `legal_name` varchar(200) DEFAULT NULL,
  `industry` varchar(100) DEFAULT NULL,
  `founded_year` int(11) DEFAULT NULL,
  `gstin` varchar(20) DEFAULT NULL,
  `pan` varchar(15) DEFAULT NULL,
  `cin` varchar(25) DEFAULT NULL,
  `website` varchar(200) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `hq_address` varchar(400) DEFAULT NULL,
  `locations` text DEFAULT NULL,               -- one per line: "Hyderabad — HQ"
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `org_profile` (`id`,`org_name`) SELECT 1,'PayG' WHERE NOT EXISTS (SELECT 1 FROM `org_profile` WHERE id=1);

CREATE TABLE IF NOT EXISTS `org_departments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `department` varchar(80) NOT NULL,
  `sub_department` varchar(80) DEFAULT NULL,   -- NULL row = the department itself
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dept_sub` (`department`,`sub_department`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- seed from what employees already use + the app's role model departments
INSERT IGNORE INTO `org_departments` (`department`,`sub_department`)
SELECT DISTINCT e.department, NULLIF(e.sub_department,'') FROM employees e WHERE e.department IS NOT NULL AND e.department<>'';
INSERT IGNORE INTO `org_departments` (`department`,`sub_department`)
SELECT d.d, NULL FROM (SELECT 'Sales' d UNION ALL SELECT 'Service' UNION ALL SELECT 'HR' UNION ALL SELECT 'IT'
                       UNION ALL SELECT 'Support' UNION ALL SELECT 'Operations') d;

CREATE TABLE IF NOT EXISTS `org_designations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(120) NOT NULL,
  `level` int(11) DEFAULT NULL,                -- seniority 1..14 like the design
  `department` varchar(80) DEFAULT NULL,
  `exec_leadership` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_title` (`title`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `org_designations` (`title`,`department`)
SELECT DISTINCT e.designation, e.department FROM employees e
 WHERE e.designation IS NOT NULL AND e.designation<>'' AND e.designation<>'To be confirmed';
