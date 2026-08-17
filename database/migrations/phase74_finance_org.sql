-- ============================================================================
-- Phase 74 — the Finance department the CFO cockpit hangs off.
-- Obligations carry an owner_role ('CFO', 'Finance Manager', …); the scheduler
-- resolves those to real people, so the roles need real employees behind them.
-- Also gives the two new logins (cfo / finance_manager) a working nav, not just
-- the cockpit modules granted in phase72.
-- Idempotent.
-- ============================================================================

INSERT INTO `org_departments` (`department`,`sub_department`,`is_active`)
SELECT t.d, t.s, 1 FROM (
            SELECT 'Finance' d, NULL s
  UNION ALL SELECT 'Finance', 'Accounts'
  UNION ALL SELECT 'Finance', 'Treasury'
  UNION ALL SELECT 'Finance', 'Taxation'
  UNION ALL SELECT 'Finance', 'Settlement Ops'
  UNION ALL SELECT 'Finance', 'Internal Audit'
) t
WHERE NOT EXISTS (SELECT 1 FROM `org_departments` x
                   WHERE x.department = t.d AND (x.sub_department <=> t.s));

/* people behind the obligation owner roles */
INSERT INTO `employees` (`full_name`,`designation`,`department`,`sub_department`,`email`,`phone`,`emp_type`,`governance`)
SELECT t.* FROM (
            SELECT 'Ramesh Iyer' fn,'CFO' dg,'Finance' dp,NULL sd,'cfo@payg.in' em,'9900000101' ph,'employee' et,'executive' gv
  UNION ALL SELECT 'Deepa Nair','Finance Manager','Finance','Accounts','finance.manager@payg.in','9900000102','employee','execution'
  UNION ALL SELECT 'Suresh Bhat','Tax Lead','Finance','Taxation','tax.lead@payg.in','9900000103','employee','execution'
  UNION ALL SELECT 'Anita Joseph','Settlement Ops Lead','Finance','Settlement Ops','settlement.ops@payg.in','9900000104','employee','execution'
  UNION ALL SELECT 'Farid Khan','Compliance Lead','Finance','Internal Audit','compliance.lead@payg.in','9900000105','employee','execution'
) t WHERE NOT EXISTS (SELECT 1 FROM `employees` x WHERE x.email = t.em);

-- reporting line: Finance reports to the CFO
UPDATE `employees` SET `manager_id` = (SELECT id FROM (SELECT id FROM employees WHERE email='cfo@payg.in') z)
 WHERE department='Finance' AND email <> 'cfo@payg.in' AND manager_id IS NULL;

/* logins — password is the usual demo hash for 'admin123'; change before any
   external demo (same caveat as every other seeded account here). */
INSERT INTO `sales_users` (`username`,`password_hash`,`full_name`,`role`,`employee_id`,`is_active`)
SELECT t.u, t.h, t.n, t.r, (SELECT id FROM employees WHERE email = t.em), 1 FROM (
            SELECT 'cfo' u, '$2y$10$.8mYsWEvKHF6p6l9vRmSYO3vRYdYu1W/fFd6u1wE4MVCWSe1T5nbC' h,
                   'Ramesh Iyer' n, 'cfo' r, 'cfo@payg.in' em
  UNION ALL SELECT 'finance', '$2y$10$.8mYsWEvKHF6p6l9vRmSYO3vRYdYu1W/fFd6u1wE4MVCWSe1T5nbC',
                   'Deepa Nair', 'finance_manager', 'finance.manager@payg.in'
) t
WHERE NOT EXISTS (SELECT 1 FROM `sales_users` x WHERE x.username = t.u)
  AND (SELECT id FROM employees WHERE email = t.em) IS NOT NULL;

/* give the finance roles a usable nav beyond the cockpit */
INSERT INTO `role_access` (`role`,`module_key`,`can_access`)
SELECT r.role, m.mk, 1 FROM
  (SELECT 'cfo' role UNION ALL SELECT 'finance_manager') r,
  (SELECT 'dashboard' mk UNION ALL SELECT 'activity' UNION ALL SELECT 'timesheet'
   UNION ALL SELECT 'reports'   UNION ALL SELECT 'documents' UNION ALL SELECT 'orgchart'
   UNION ALL SELECT 'compliance'UNION ALL SELECT 'govmap'    UNION ALL SELECT 'performance'
   UNION ALL SELECT 'escalations' UNION ALL SELECT 'kra' UNION ALL SELECT 'kpi' UNION ALL SELECT 'kpa'
   UNION ALL SELECT 'scorecards' UNION ALL SELECT 'transactions' UNION ALL SELECT 'org') m
WHERE NOT EXISTS (SELECT 1 FROM `role_access` a WHERE a.role=r.role AND a.module_key=m.mk);

/* the CFO also sees the whole company's numbers, like a department head does */
UPDATE `cfo_obligations` o
   SET o.owner_employee_id = (SELECT e.id FROM employees e WHERE e.designation = o.owner_role LIMIT 1)
 WHERE o.owner_employee_id IS NULL;

UPDATE `cfo_risks` r
   SET r.owner_employee_id = (SELECT e.id FROM employees e WHERE e.designation = r.owner_role LIMIT 1)
 WHERE r.owner_employee_id IS NULL;

UPDATE `cfo_risks` SET `next_review` = DATE_ADD(CURDATE(), INTERVAL (id MOD 6) MONTH) WHERE next_review IS NULL;
