-- Phase 46: HR department governance — Head/Manager/Exec hierarchy, HR owns people +
--           performance company-wide, and one person = one login.
--
-- 1. New roles ...... hr_head and sales_head, layered ABOVE the existing manager tier:
--                       HR      : hr_head    → hr_manager      → hr
--                       Sales   : sales_head → manager         → sales_exec
--                     'manager' keeps its key (it IS the Sales Manager tier) because
--                     can_manage_all_records(), current_vertical(), scorecards,
--                     reimbursements, dashboard-kpis and timesheets all branch on that exact
--                     string. Renaming it would have rewired every permission check in the
--                     app; sales_head is layered on top instead. Only the Admin may grant or
--                     revoke admin / *_head (payg_is_privileged_role(), _roles.php).
--
-- 2. Maker-checker .. hr_change_requests: an HR Manager / HR Executive's STRUCTURAL changes
--                     (people, roles, reporting lines, global KPAs, module access) are queued
--                     here and applied only when the HR Head approves. Routine work
--                     (approving a timesheet) is not gated — see _hr_approvals.php.
--
-- 3. One person = one login. The employees ↔ sales_users split stays (33 columns across 20+
--    tables point at employees.id — leads.owner_id, tasks.assigned_to, timesheets.employee_id,
--    kpa_goals.created_by, scorecards.employee_id …), but it becomes a true 1:1 and the UI
--    shows a single People table.
--
--    Today it is NOT 1:1, and that is a live bug: several logins can share one employee record,
--    which makes them the same person to every part of the CRM that works off employees.id.
--    A shared record is why an admin login can inherit another person's goals, KPAs, scorecards
--    and timesheets (CLAUDE.md records the symptom). Section 3 gives every extra login its own
--    employee record — nothing is deleted, and every existing employees.id keeps its meaning,
--    so no foreign key moves.
--
-- This migration seeds NO users and NO test data. Section 2's role_access rows are permission
-- grants, and section 3 is entirely data-driven: it acts only on whatever duplicate logins
-- already exist on the server it is run against. Create the real HR Head afterwards from the
-- People screen as an Admin.
--
-- Safe to re-run: every step is idempotent.

/* ------------------------------------------------------------------ *
 * 0. sales_users.role: ENUM -> varchar
 * ------------------------------------------------------------------ *
 * role was an ENUM listing every role literal. Two problems, both live:
 *
 *   a) It silently corrupts data. This database does not run in strict mode
 *      (sql_mode = NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION — no
 *      STRICT_TRANS_TABLES), so writing a role the ENUM doesn't list does NOT raise an error:
 *      MySQL stores '' instead. Creating an hr_head produced a login with an EMPTY role that
 *      authenticated fine and then matched no permission check anywhere — a broken account
 *      that looks perfectly healthy in the UI.
 *
 *   b) It duplicates the role list. _roles.php (payg_roles()) is the single source of truth and
 *      every grant/label/capability derives from it; an ENUM is a second copy that only fails
 *      once someone hits it in production.
 *
 * role_access.role is already varchar(40), which is why phase46's grants applied cleanly while
 * the sales_users writes silently blanked. Match it, and let payg_role_exists() validate —
 * adding a role is then a code change, not a schema change.
 */
ALTER TABLE `sales_users` MODIFY COLUMN `role` varchar(40) NOT NULL DEFAULT 'sales_exec';

/* ------------------------------------------------------------------ *
 * 1. Maker-checker queue
 * ------------------------------------------------------------------ */
CREATE TABLE IF NOT EXISTS `hr_change_requests` (
  `id`                int(11)      NOT NULL AUTO_INCREMENT,
  `change_type`       varchar(40)  NOT NULL,                 -- person.create | org.role | kpa.update | rbac.set …
  `summary`           varchar(300) NOT NULL,                 -- human sentence shown in the approval queue
  `target_label`      varchar(160)          DEFAULT NULL,    -- who/what it affects
  `payload`           longtext     NOT NULL,                 -- JSON, replayed verbatim on approval
  `status`            enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `requested_by`      int(11)      NOT NULL DEFAULT 0,       -- employees.id
  `requested_by_role` varchar(40)           DEFAULT NULL,
  `requested_at`      timestamp    NOT NULL DEFAULT current_timestamp(),
  `decided_by`        int(11)               DEFAULT NULL,    -- employees.id of the HR Head / Admin
  `decided_at`        timestamp    NULL     DEFAULT NULL,
  `decision_note`     varchar(400)          DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_requested_by` (`requested_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* ------------------------------------------------------------------ *
 * 2. Module access for the new roles
 * ------------------------------------------------------------------ *
 * HR (all three tiers) get the SAME modules — they differ in authority, not reach: an HR
 * Manager/Exec can open every HR screen, but their structural changes queue for the Head.
 * Deliberately NO leads/pipeline/merchants/transactions/incentives/chat: HR has no business
 * in the sales funnel, and those tabs are what made the HR login look like a sales login.
 */
INSERT INTO `role_access` (`role`, `module_key`, `can_access`) VALUES
  -- HR Head — the department's full surface
  ('hr_head', 'hr', 1), ('hr_head', 'kpa', 1), ('hr_head', 'scorecards', 1),
  ('hr_head', 'timesheet', 1), ('hr_head', 'activity', 1), ('hr_head', 'users', 1),
  ('hr_head', 'org', 1), ('hr_head', 'reports', 1), ('hr_head', 'dashboard', 1),
  -- HR Manager — same screens, changes need Head approval
  ('hr_manager', 'hr', 1), ('hr_manager', 'kpa', 1), ('hr_manager', 'scorecards', 1),
  ('hr_manager', 'timesheet', 1), ('hr_manager', 'activity', 1), ('hr_manager', 'users', 1),
  ('hr_manager', 'org', 1), ('hr_manager', 'reports', 1), ('hr_manager', 'dashboard', 1),
  -- HR Executive — same screens, changes need Head approval
  ('hr', 'hr', 1), ('hr', 'kpa', 1), ('hr', 'scorecards', 1),
  ('hr', 'timesheet', 1), ('hr', 'activity', 1), ('hr', 'users', 1),
  ('hr', 'org', 1), ('hr', 'reports', 1), ('hr', 'dashboard', 1),
  -- Sales Head — everything the Sales Manager sees, plus goal-setting
  ('sales_head', 'dashboard', 1), ('sales_head', 'leads', 1), ('sales_head', 'pipeline', 1),
  ('sales_head', 'merchants', 1), ('sales_head', 'transactions', 1), ('sales_head', 'incentives', 1),
  ('sales_head', 'reimbursements', 1), ('sales_head', 'scorecards', 1), ('sales_head', 'reports', 1),
  ('sales_head', 'kpa', 1), ('sales_head', 'timesheet', 1), ('sales_head', 'activity', 1),
  ('sales_head', 'inbox', 1), ('sales_head', 'conversations', 1), ('sales_head', 'whatsapp', 1),
  ('sales_head', 'tickets', 1), ('sales_head', 'automation', 1)
ON DUPLICATE KEY UPDATE `can_access` = VALUES(`can_access`);

-- HR has no place in the sales funnel: revoke anything that leaked in from earlier phases.
UPDATE `role_access` SET `can_access` = 0
 WHERE `role` IN ('hr_head', 'hr_manager', 'hr')
   AND `module_key` IN ('leads','pipeline','merchants','transactions','incentives',
                        'reimbursements','chat','whatsapp','calls','inbox','conversations',
                        'tickets','automation','settings','integrations','admin','it');

/* ------------------------------------------------------------------ *
 * 3. One person = one login
 * ------------------------------------------------------------------ *
 * 3a. Work out which login KEEPS each shared employee record.
 *
 *     The keeper is the login that actually IS that person — the one whose full_name matches
 *     the employee record's. Everything that person owns (leads.owner_id, timesheets,
 *     scorecards, KPAs) points at that employees.id, so picking the wrong keeper would
 *     silently hand their entire history to whichever other login was squatting on the record.
 *
 *     MIN(id) alone is NOT safe as the rule: an admin or service account created early often
 *     holds the lowest sales_users.id while the real person's login was added later, so
 *     "oldest login wins" hands that person's history to the admin account. It is kept only as
 *     a tie-breaker for shared records where no login's name matches, so the migration still
 *     terminates on unexpected data.
 */
DROP TEMPORARY TABLE IF EXISTS `_hr_keep`;
CREATE TEMPORARY TABLE `_hr_keep` (
  `employee_id` int(11) NOT NULL PRIMARY KEY,
  `user_id`     int(11) NOT NULL
) ENGINE=MEMORY;

-- employees.full_name is utf8mb4_general_ci while sales_users.full_name is utf8mb4_unicode_ci,
-- so comparing them raw raises "Illegal mix of collations". Force one collation on both sides.
INSERT INTO `_hr_keep` (`employee_id`, `user_id`)
SELECT su.`employee_id`,
       COALESCE(
         MIN(CASE WHEN su.`full_name` COLLATE utf8mb4_general_ci
                     = e.`full_name`  COLLATE utf8mb4_general_ci
                  THEN su.`id` END),                                        -- the real person
         MIN(su.`id`)                                                       -- tie-break
       )
FROM `sales_users` su
JOIN `employees` e ON e.`id` = su.`employee_id`
WHERE su.`employee_id` IS NOT NULL
GROUP BY su.`employee_id`;

/* 3b. Give every OTHER login its own employee record.
 *
 * The new row is linked back to its login through a temporary `_from_user_id` marker rather
 * than by matching names: two logins can legitimately share a full_name, and a name match
 * would then re-point both at one row — silently recreating the very duplication this
 * migration exists to remove.
 *
 * The new rows inherit the login's own full_name, so a login that was squatting on someone
 * else's record becomes its own person instead of continuing to masquerade as them.
 *
 * email/phone are left NULL: employees.email is UNIQUE and these logins have no contact of
 * their own to claim. HR fills them in from the People screen, which now requires both for
 * anyone with a login so they can actually receive a reset OTP. Anyone split out here lands
 * with designation 'To be confirmed' — that is the review list, not test data.
 */
SET @has_marker := (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees'
                      AND COLUMN_NAME = '_from_user_id');
SET @sql := IF(@has_marker = 0,
               'ALTER TABLE `employees` ADD COLUMN `_from_user_id` int(11) NULL',
               'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

INSERT INTO `employees` (`full_name`, `designation`, `department`, `manager_id`, `_from_user_id`)
SELECT su.`full_name`,
       'To be confirmed',            -- HR sets the real designation on the People screen
       NULL, NULL,
       su.`id`
FROM `sales_users` su
JOIN `_hr_keep` k ON k.`employee_id` = su.`employee_id`
WHERE su.`id` <> k.`user_id`;        -- idempotent: after a successful run there are no extras

UPDATE `sales_users` su
JOIN `employees` e ON e.`_from_user_id` = su.`id`
SET su.`employee_id` = e.`id`;

UPDATE `employees` SET `_from_user_id` = NULL WHERE `_from_user_id` IS NOT NULL;
ALTER TABLE `employees` DROP COLUMN `_from_user_id`;
DROP TEMPORARY TABLE IF EXISTS `_hr_keep`;

/* 3c. Lock it in: one login per person, forever.
 *     Deactivation flips is_active and keeps the row (people_login_for() finds it either
 *     way), so this constraint never blocks re-enabling someone.
 */
SET @has_uq := (SELECT COUNT(*) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sales_users'
                  AND INDEX_NAME = 'uq_employee');
SET @sql := IF(@has_uq = 0,
               'ALTER TABLE `sales_users` ADD UNIQUE KEY `uq_employee` (`employee_id`)',
               'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
