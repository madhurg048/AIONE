-- ---------------------------------------------------------------------------
-- phase45 — KPA / KRA + dashboard-KPI demo seed
--
-- Idempotent by design: every INSERT is guarded by a NOT EXISTS on the natural
-- key (scope + group + title for org goals, creator + owner + title for team
-- goals, vertical + metric_key + title for dashboard tiles). Re-running this
-- file changes nothing — unlike the phase2/phase3 seeds, which duplicate rows.
--
-- Nothing here is destructive: existing goals (id 1 "First Call Resolution",
-- id 5 "Total Sales") are left exactly as they are, so they remain available
-- as live edit/delete targets.
-- ---------------------------------------------------------------------------

/* ===================== ORG KPAs — owned by the HR Head ===================== */
/* created_by 21 = Latha Menon (hr_head); these are the company-wide goals
   every member of a group inherits automatically. */

INSERT INTO kpa_goals (scope, org_group, owner_employee_id, created_by, created_by_role, title, kra, weight, target_value, unit, period)
SELECT * FROM (SELECT
  'org' AS a,'HR' AS b,NULL AS c,21 AS d,'hr_head' AS e,
  'Talent Acquisition' AS f,
  'Close every approved requisition within 45 days; quality-of-hire rated >=4/5 by the hiring manager at 90 days' AS g,
  25.00 AS h,'30' AS i,'count' AS j,'FY26' AS k) t
WHERE NOT EXISTS (SELECT 1 FROM kpa_goals x WHERE x.scope='org' AND x.org_group='HR' AND x.title='Talent Acquisition');

INSERT INTO kpa_goals (scope, org_group, owner_employee_id, created_by, created_by_role, title, kra, weight, target_value, unit, period)
SELECT * FROM (SELECT 'org','HR',NULL,21,'hr_head',
  'Employee Retention',
  'Voluntary attrition held below 10% annualised; every exit interview logged with a coded reason',
  20.00,'90','pct','FY26') t
WHERE NOT EXISTS (SELECT 1 FROM kpa_goals x WHERE x.scope='org' AND x.org_group='HR' AND x.title='Employee Retention');

INSERT INTO kpa_goals (scope, org_group, owner_employee_id, created_by, created_by_role, title, kra, weight, target_value, unit, period)
SELECT * FROM (SELECT 'org','HR',NULL,21,'hr_head',
  'Timesheet & Attendance Compliance',
  'Percentage of employees submitting timesheets by the weekly cut-off, approved within 3 working days',
  15.00,'98','pct','FY26') t
WHERE NOT EXISTS (SELECT 1 FROM kpa_goals x WHERE x.scope='org' AND x.org_group='HR' AND x.title='Timesheet & Attendance Compliance');

INSERT INTO kpa_goals (scope, org_group, owner_employee_id, created_by, created_by_role, title, kra, weight, target_value, unit, period)
SELECT * FROM (SELECT 'org','HR',NULL,21,'hr_head',
  'Learning & Development',
  'Average certified training hours delivered per employee per year, tracked against the individual development plan',
  15.00,'16','count','FY26') t
WHERE NOT EXISTS (SELECT 1 FROM kpa_goals x WHERE x.scope='org' AND x.org_group='HR' AND x.title='Learning & Development');

INSERT INTO kpa_goals (scope, org_group, owner_employee_id, created_by, created_by_role, title, kra, weight, target_value, unit, period)
SELECT * FROM (SELECT 'org','HR',NULL,21,'hr_head',
  'Payroll Accuracy & Statutory Compliance',
  'Payroll processed error-free and on date; PF / ESI / TDS filings submitted before statutory due dates, zero notices',
  15.00,'100','pct','FY26') t
WHERE NOT EXISTS (SELECT 1 FROM kpa_goals x WHERE x.scope='org' AND x.org_group='HR' AND x.title='Payroll Accuracy & Statutory Compliance');

INSERT INTO kpa_goals (scope, org_group, owner_employee_id, created_by, created_by_role, title, kra, weight, target_value, unit, period)
SELECT * FROM (SELECT 'org','HR',NULL,21,'hr_head',
  'Employee Engagement',
  'Half-yearly engagement survey score out of 5, with a documented action plan per department scoring below 3.5',
  10.00,'4.2','text','FY26') t
WHERE NOT EXISTS (SELECT 1 FROM kpa_goals x WHERE x.scope='org' AND x.org_group='HR' AND x.title='Employee Engagement');

/* ---------------------------- Sales org KPAs ---------------------------- */
/* NB: 'Total Sales' (id 5) already exists and is deliberately left untouched. */

INSERT INTO kpa_goals (scope, org_group, owner_employee_id, created_by, created_by_role, title, kra, weight, target_value, unit, period)
SELECT * FROM (SELECT 'org','Sales',NULL,21,'hr_head',
  'New Merchant Acquisition',
  'Merchants onboarded AND activated in the quarter — a signed deal alone does not count towards this goal',
  25.00,'20','count','FY26') t
WHERE NOT EXISTS (SELECT 1 FROM kpa_goals x WHERE x.scope='org' AND x.org_group='Sales' AND x.title='New Merchant Acquisition');

INSERT INTO kpa_goals (scope, org_group, owner_employee_id, created_by, created_by_role, title, kra, weight, target_value, unit, period)
SELECT * FROM (SELECT 'org','Sales',NULL,21,'hr_head',
  'Lead Conversion Rate',
  'Qualified leads converted to deals as a percentage of total assigned leads, measured monthly',
  20.00,'35','pct','FY26') t
WHERE NOT EXISTS (SELECT 1 FROM kpa_goals x WHERE x.scope='org' AND x.org_group='Sales' AND x.title='Lead Conversion Rate');

INSERT INTO kpa_goals (scope, org_group, owner_employee_id, created_by, created_by_role, title, kra, weight, target_value, unit, period)
SELECT * FROM (SELECT 'org','Sales',NULL,21,'hr_head',
  'Pipeline Health',
  'Open pipeline value maintained at >=3x the monthly target, with no deal untouched for more than 14 days',
  20.00,'20000000','inr','FY26') t
WHERE NOT EXISTS (SELECT 1 FROM kpa_goals x WHERE x.scope='org' AND x.org_group='Sales' AND x.title='Pipeline Health');

INSERT INTO kpa_goals (scope, org_group, owner_employee_id, created_by, created_by_role, title, kra, weight, target_value, unit, period)
SELECT * FROM (SELECT 'org','Sales',NULL,21,'hr_head',
  'CRM Data Hygiene',
  'Every lead carries a source, owner and next-follow-up date; no stage change without a logged activity',
  15.00,'95','pct','FY26') t
WHERE NOT EXISTS (SELECT 1 FROM kpa_goals x WHERE x.scope='org' AND x.org_group='Sales' AND x.title='CRM Data Hygiene');

/* --------------------------- Service org KPAs --------------------------- */
/* NB: 'First Call Resolution' (id 1) already exists and is left untouched. */

INSERT INTO kpa_goals (scope, org_group, owner_employee_id, created_by, created_by_role, title, kra, weight, target_value, unit, period)
SELECT * FROM (SELECT 'org','Service',NULL,21,'hr_head',
  'Customer Satisfaction (CSAT)',
  'Post-interaction CSAT rating out of 5, collected on chat and call closure',
  25.00,'4.5','text','FY26') t
WHERE NOT EXISTS (SELECT 1 FROM kpa_goals x WHERE x.scope='org' AND x.org_group='Service' AND x.title='Customer Satisfaction (CSAT)');

INSERT INTO kpa_goals (scope, org_group, owner_employee_id, created_by, created_by_role, title, kra, weight, target_value, unit, period)
SELECT * FROM (SELECT 'org','Service',NULL,21,'hr_head',
  'Average Response Time',
  'Seconds from chat/call arrival to agent pickup, measured across the shift',
  20.00,'120','count','FY26') t
WHERE NOT EXISTS (SELECT 1 FROM kpa_goals x WHERE x.scope='org' AND x.org_group='Service' AND x.title='Average Response Time');

INSERT INTO kpa_goals (scope, org_group, owner_employee_id, created_by, created_by_role, title, kra, weight, target_value, unit, period)
SELECT * FROM (SELECT 'org','Service',NULL,21,'hr_head',
  'Ticket Backlog Control',
  'Open tickets older than 48 hours held under 10 at any point in the month',
  15.00,'10','count','FY26') t
WHERE NOT EXISTS (SELECT 1 FROM kpa_goals x WHERE x.scope='org' AND x.org_group='Service' AND x.title='Ticket Backlog Control');

INSERT INTO kpa_goals (scope, org_group, owner_employee_id, created_by, created_by_role, title, kra, weight, target_value, unit, period)
SELECT * FROM (SELECT 'org','Service',NULL,21,'hr_head',
  'Callback Adherence',
  'Promised callbacks completed within the committed window',
  10.00,'95','pct','FY26') t
WHERE NOT EXISTS (SELECT 1 FROM kpa_goals x WHERE x.scope='org' AND x.org_group='Service' AND x.title='Callback Adherence');

/* ------------------------------ IT org KPAs ----------------------------- */

INSERT INTO kpa_goals (scope, org_group, owner_employee_id, created_by, created_by_role, title, kra, weight, target_value, unit, period)
SELECT * FROM (SELECT 'org','IT',NULL,21,'hr_head',
  'System Uptime',
  'Availability of the CRM, telephony and payment gateway during business hours, excluding planned windows',
  35.00,'99.5','pct','FY26') t
WHERE NOT EXISTS (SELECT 1 FROM kpa_goals x WHERE x.scope='org' AND x.org_group='IT' AND x.title='System Uptime');

INSERT INTO kpa_goals (scope, org_group, owner_employee_id, created_by, created_by_role, title, kra, weight, target_value, unit, period)
SELECT * FROM (SELECT 'org','IT',NULL,21,'hr_head',
  'Internal Support SLA',
  'Internal IT tickets resolved within the agreed SLA by priority band',
  25.00,'90','pct','FY26') t
WHERE NOT EXISTS (SELECT 1 FROM kpa_goals x WHERE x.scope='org' AND x.org_group='IT' AND x.title='Internal Support SLA');

INSERT INTO kpa_goals (scope, org_group, owner_employee_id, created_by, created_by_role, title, kra, weight, target_value, unit, period)
SELECT * FROM (SELECT 'org','IT',NULL,21,'hr_head',
  'Security & Access Review',
  'Quarterly review of user roles, API tokens and privileged access; every exception documented and closed',
  20.00,'4','count','FY26') t
WHERE NOT EXISTS (SELECT 1 FROM kpa_goals x WHERE x.scope='org' AND x.org_group='IT' AND x.title='Security & Access Review');

INSERT INTO kpa_goals (scope, org_group, owner_employee_id, created_by, created_by_role, title, kra, weight, target_value, unit, period)
SELECT * FROM (SELECT 'org','IT',NULL,21,'hr_head',
  'Asset Inventory Accuracy',
  'Hardware and licence register reconciled against actual allocation each quarter',
  20.00,'98','pct','FY26') t
WHERE NOT EXISTS (SELECT 1 FROM kpa_goals x WHERE x.scope='org' AND x.org_group='IT' AND x.title='Asset Inventory Accuracy');

/* ---------------------------- Support org KPAs -------------------------- */

INSERT INTO kpa_goals (scope, org_group, owner_employee_id, created_by, created_by_role, title, kra, weight, target_value, unit, period)
SELECT * FROM (SELECT 'org','Support',NULL,21,'hr_head',
  'First Response Time',
  'Minutes from ticket creation to first substantive agent response',
  25.00,'15','count','FY26') t
WHERE NOT EXISTS (SELECT 1 FROM kpa_goals x WHERE x.scope='org' AND x.org_group='Support' AND x.title='First Response Time');

INSERT INTO kpa_goals (scope, org_group, owner_employee_id, created_by, created_by_role, title, kra, weight, target_value, unit, period)
SELECT * FROM (SELECT 'org','Support',NULL,21,'hr_head',
  'Ticket Resolution Rate',
  'Tickets closed within the month as a percentage of tickets raised in that month',
  30.00,'92','pct','FY26') t
WHERE NOT EXISTS (SELECT 1 FROM kpa_goals x WHERE x.scope='org' AND x.org_group='Support' AND x.title='Ticket Resolution Rate');

INSERT INTO kpa_goals (scope, org_group, owner_employee_id, created_by, created_by_role, title, kra, weight, target_value, unit, period)
SELECT * FROM (SELECT 'org','Support',NULL,21,'hr_head',
  'Escalation Rate',
  'Percentage of tickets escalated to L2 or to the product team — lower is better',
  20.00,'5','pct','FY26') t
WHERE NOT EXISTS (SELECT 1 FROM kpa_goals x WHERE x.scope='org' AND x.org_group='Support' AND x.title='Escalation Rate');

INSERT INTO kpa_goals (scope, org_group, owner_employee_id, created_by, created_by_role, title, kra, weight, target_value, unit, period)
SELECT * FROM (SELECT 'org','Support',NULL,21,'hr_head',
  'Knowledge Base Contribution',
  'New or updated KB articles published per agent per quarter from real resolved tickets',
  25.00,'12','count','FY26') t
WHERE NOT EXISTS (SELECT 1 FROM kpa_goals x WHERE x.scope='org' AND x.org_group='Support' AND x.title='Knowledge Base Contribution');


/* ============ TEAM KPAs — set by department managers, not HR ============ */
/* These prove the inheritance story: a member sees org goals (from HR) PLUS
   the goals their own manager set. HR can see and override all of them. */

-- Meena Manager (3, Sales Manager) -> Asha Sales (1)
INSERT INTO kpa_goals (scope, org_group, owner_employee_id, created_by, created_by_role, title, kra, weight, target_value, unit, period)
SELECT * FROM (SELECT 'team',NULL,1,3,'manager',
  'Daily Outbound Call Quota',
  'Minimum connected outbound calls logged per working day in the CRM',
  15.00,'25','count','monthly') t
WHERE NOT EXISTS (SELECT 1 FROM kpa_goals x WHERE x.scope='team' AND x.created_by=3 AND x.owner_employee_id=1 AND x.title='Daily Outbound Call Quota');

INSERT INTO kpa_goals (scope, org_group, owner_employee_id, created_by, created_by_role, title, kra, weight, target_value, unit, period)
SELECT * FROM (SELECT 'team',NULL,1,3,'manager',
  'Merchant Activation Follow-through',
  'Won deals carried all the way to activated status within 21 days of closure',
  20.00,'85','pct','monthly') t
WHERE NOT EXISTS (SELECT 1 FROM kpa_goals x WHERE x.scope='team' AND x.created_by=3 AND x.owner_employee_id=1 AND x.title='Merchant Activation Follow-through');

-- Meena Manager (3) -> Ravi Sales (2)
INSERT INTO kpa_goals (scope, org_group, owner_employee_id, created_by, created_by_role, title, kra, weight, target_value, unit, period)
SELECT * FROM (SELECT 'team',NULL,2,3,'manager',
  'Upsell to Existing Merchants',
  'Additional product attached to already-active merchants in the assigned book',
  20.00,'8','count','monthly') t
WHERE NOT EXISTS (SELECT 1 FROM kpa_goals x WHERE x.scope='team' AND x.created_by=3 AND x.owner_employee_id=2 AND x.title='Upsell to Existing Merchants');

-- Meena Manager (3) -> whole Sales team (owner NULL)
INSERT INTO kpa_goals (scope, org_group, owner_employee_id, created_by, created_by_role, title, kra, weight, target_value, unit, period)
SELECT * FROM (SELECT 'team' AS a,NULL AS b,NULL AS c,3 AS d,'manager' AS e,
  'Weekly Pipeline Review Participation' AS f,
  'Attend the Monday pipeline review with every open deal updated beforehand',
  10.00,'100','pct','monthly') t
WHERE NOT EXISTS (SELECT 1 FROM kpa_goals x WHERE x.scope='team' AND x.created_by=3 AND x.owner_employee_id IS NULL AND x.title='Weekly Pipeline Review Participation');

-- Neha Kapoor (9, Service Manager) -> Priya Service (7)
INSERT INTO kpa_goals (scope, org_group, owner_employee_id, created_by, created_by_role, title, kra, weight, target_value, unit, period)
SELECT * FROM (SELECT 'team',NULL,7,9,'service_manager',
  'Chat Pickup Speed',
  'Personal average seconds to pick up a waiting chat during the assigned shift',
  20.00,'45','count','monthly') t
WHERE NOT EXISTS (SELECT 1 FROM kpa_goals x WHERE x.scope='team' AND x.created_by=9 AND x.owner_employee_id=7 AND x.title='Chat Pickup Speed');

-- Neha Kapoor (9) -> whole Service team
INSERT INTO kpa_goals (scope, org_group, owner_employee_id, created_by, created_by_role, title, kra, weight, target_value, unit, period)
SELECT * FROM (SELECT 'team' AS a,NULL AS b,NULL AS c,9 AS d,'service_manager' AS e,
  'Ticket Documentation Quality' AS f,
  'Every closed ticket carries a root cause and resolution note fit for the knowledge base',
  15.00,'90','pct','monthly') t
WHERE NOT EXISTS (SELECT 1 FROM kpa_goals x WHERE x.scope='team' AND x.created_by=9 AND x.owner_employee_id IS NULL AND x.title='Ticket Documentation Quality');

-- Sneha Iyer (23, HR Manager) -> Kavya Rao (4, HR Executive)
INSERT INTO kpa_goals (scope, org_group, owner_employee_id, created_by, created_by_role, title, kra, weight, target_value, unit, period)
SELECT * FROM (SELECT 'team',NULL,4,23,'hr_manager',
  'New Joiner Onboarding Experience',
  'Day-1 kit, system access and buddy assigned before the joining date; 30-day check-in completed',
  20.00,'100','pct','monthly') t
WHERE NOT EXISTS (SELECT 1 FROM kpa_goals x WHERE x.scope='team' AND x.created_by=23 AND x.owner_employee_id=4 AND x.title='New Joiner Onboarding Experience');


/* ================= DASHBOARD KPI TILES (sales vertical) ================= */
/* The sales dashboard had exactly one tile. metric_key values below are all
   present in the $CATALOG in api/sales/dashboard-kpis.php. */

INSERT INTO dashboard_kpis (vertical,title,metric_key,display,period,target_value,direction,sort_order,created_by,created_by_role)
SELECT * FROM (SELECT 'sales','New leads','sls_leads','kpi','month',200.00,'higher',1,21,'hr_head') t
WHERE NOT EXISTS (SELECT 1 FROM dashboard_kpis x WHERE x.vertical='sales' AND x.metric_key='sls_leads' AND x.title='New leads');

INSERT INTO dashboard_kpis (vertical,title,metric_key,display,period,target_value,direction,sort_order,created_by,created_by_role)
SELECT * FROM (SELECT 'sales','Leads converted','sls_leads_converted','kpi','month',60.00,'higher',2,21,'hr_head') t
WHERE NOT EXISTS (SELECT 1 FROM dashboard_kpis x WHERE x.vertical='sales' AND x.metric_key='sls_leads_converted' AND x.title='Leads converted');

INSERT INTO dashboard_kpis (vertical,title,metric_key,display,period,target_value,direction,sort_order,created_by,created_by_role)
SELECT * FROM (SELECT 'sales','Deals won','sls_deals_won','kpi','month',25.00,'higher',3,21,'hr_head') t
WHERE NOT EXISTS (SELECT 1 FROM dashboard_kpis x WHERE x.vertical='sales' AND x.metric_key='sls_deals_won' AND x.title='Deals won');

INSERT INTO dashboard_kpis (vertical,title,metric_key,display,period,target_value,direction,sort_order,created_by,created_by_role)
SELECT * FROM (SELECT 'sales','Won deal value','sls_won_value','kpi','month',5000000.00,'higher',4,21,'hr_head') t
WHERE NOT EXISTS (SELECT 1 FROM dashboard_kpis x WHERE x.vertical='sales' AND x.metric_key='sls_won_value' AND x.title='Won deal value');

INSERT INTO dashboard_kpis (vertical,title,metric_key,display,period,target_value,direction,sort_order,created_by,created_by_role)
SELECT * FROM (SELECT 'sales','Merchants activated','sls_merchants_activated','kpi','month',20.00,'higher',5,21,'hr_head') t
WHERE NOT EXISTS (SELECT 1 FROM dashboard_kpis x WHERE x.vertical='sales' AND x.metric_key='sls_merchants_activated' AND x.title='Merchants activated');

INSERT INTO dashboard_kpis (vertical,title,metric_key,display,period,target_value,direction,sort_order,created_by,created_by_role)
SELECT * FROM (SELECT 'sales','Follow-ups due now','sls_followups_due','kpi','all',NULL,'lower',6,21,'hr_head') t
WHERE NOT EXISTS (SELECT 1 FROM dashboard_kpis x WHERE x.vertical='sales' AND x.metric_key='sls_followups_due' AND x.title='Follow-ups due now');

INSERT INTO dashboard_kpis (vertical,title,metric_key,display,period,target_value,direction,sort_order,created_by,created_by_role)
SELECT * FROM (SELECT 'sales','Deals won — monthly trend','sls_deals_won','bar','month',NULL,'higher',7,21,'hr_head') t
WHERE NOT EXISTS (SELECT 1 FROM dashboard_kpis x WHERE x.vertical='sales' AND x.metric_key='sls_deals_won' AND x.title='Deals won — monthly trend');


/* ============ TASK -> KPA ATTRIBUTION (drives the "Hours" column) ============ */
/* Deterministic by assignee, so re-running converges instead of double-tagging.
   These UPDATEs are what make the chevron drill-down on sales-kpa.php show
   "who spent how much" against each goal. */

SET @g_acq  := (SELECT id FROM kpa_goals WHERE scope='org' AND org_group='Sales'   AND title='New Merchant Acquisition' LIMIT 1);
SET @g_conv := (SELECT id FROM kpa_goals WHERE scope='org' AND org_group='Sales'   AND title='Lead Conversion Rate'     LIMIT 1);
SET @g_resp := (SELECT id FROM kpa_goals WHERE scope='org' AND org_group='Service' AND title='Average Response Time'    LIMIT 1);
SET @g_call := (SELECT id FROM kpa_goals WHERE scope='team' AND created_by=3 AND owner_employee_id=1 AND title='Daily Outbound Call Quota' LIMIT 1);

UPDATE tasks SET kpa_goal_id=@g_call, hours_spent=IF(hours_spent>0,hours_spent,2.50) WHERE assigned_to=1 AND id IN (32,33);
UPDATE tasks SET kpa_goal_id=@g_acq,  hours_spent=IF(hours_spent>0,hours_spent,3.25) WHERE assigned_to=1 AND id IN (28,35);
UPDATE tasks SET kpa_goal_id=@g_conv, hours_spent=IF(hours_spent>0,hours_spent,1.75) WHERE assigned_to=2 AND id IN (34,36);
UPDATE tasks SET kpa_goal_id=@g_acq,  hours_spent=IF(hours_spent>0,hours_spent,4.00) WHERE assigned_to=6 AND id IN (37,39);
UPDATE tasks SET kpa_goal_id=@g_resp, hours_spent=IF(hours_spent>0,hours_spent,2.00) WHERE assigned_to=7 AND id IN (27,29);
UPDATE tasks SET kpa_goal_id=@g_resp, hours_spent=IF(hours_spent>0,hours_spent,1.50) WHERE assigned_to=8 AND id IN (38);


/* ============ ONE PENDING HR CHANGE REQUEST (maker-checker demo) ============ */
/* Raised by Sneha Iyer (23, hr_manager) so the HR Head has something live to
   approve or reject. Payload shape must match hr_apply_kpa_update(). */

INSERT INTO hr_change_requests (change_type,summary,target_label,payload,status,requested_by,requested_by_role)
SELECT * FROM (SELECT
  'kpa.update' AS a,
  'Edit Sales org KPA: Lead Conversion Rate' AS b,
  'Lead Conversion Rate' AS c,
  CONCAT('{"id":', @g_conv, ',"fields":{"target_value":"40","weight":25}}') AS d,
  'pending' AS e, 23 AS f, 'hr_manager' AS g) t
WHERE NOT EXISTS (SELECT 1 FROM hr_change_requests x WHERE x.status='pending' AND x.change_type='kpa.update' AND x.target_label='Lead Conversion Rate');
