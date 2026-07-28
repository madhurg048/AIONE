-- ============================================================================
-- Phase 42 — Production cutover cleanup
-- ============================================================================
-- Purpose: strip DEV/QA/demo seed data before go-live while KEEPING:
--   - all schema (every CREATE TABLE from phase1..phase41)
--   - all configuration & keys: email_settings, whatsapp_settings, telephony_settings,
--     ai_settings, transcription_settings, chat_settings, integration_tokens,
--     role_access, custom_fields (definitions), automation_rules (definitions),
--     incentive_plans/incentive_rules (definitions), pipelines, deal_stages,
--     sales_email_templates, web_lead_sources
--   - all KPI / KRA / AI configuration & history: kpi_definitions, dashboard_kpis,
--     kpa_goals (scope='org' rows), ai_settings, ai_insights, ai_documents
--
-- It deletes only rows created by known dev/QA seed scripts:
--   create-users.php, create-service-users.php, create-dept-managers.php,
--   create-service-sample-data.php, sales-setup.php (auto-seed-if-empty block),
--   seed-chat.php, database/seed_calls_sample.sql, and the auth.php/​_devlogin.php
--   auto-seeded sample accounts (admin/manager/asha/ravi/... — see PAYG_ENABLE_SAMPLE_LOGIN).
--
-- SAFE TO RE-RUN: every DELETE is scoped to specific, known demo identifiers
-- (usernames, emails, provider_call_id markers, employee full_names). Running
-- it twice is a no-op the second time.
--
-- >>> BEFORE RUNNING ON A REAL LIVE DB: run the SELECT preview block at the
-- >>> bottom of this file first (or via production-cutover.php --dry-run) and
-- >>> confirm the row counts match what you expect to remove. If your team has
-- >>> since created real accounts named e.g. "asha" or real leads at the same
-- >>> demo domains, adjust the WHERE clauses below before running.
-- ============================================================================

START TRANSACTION;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- 1. Demo employee IDs used throughout (resolved once for reuse below)
-- ----------------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS tmp_demo_employee_ids;
CREATE TEMPORARY TABLE tmp_demo_employee_ids AS
SELECT id FROM employees WHERE full_name IN (
  'Asha Sales','Ravi Sales','Meena Manager',
  'Kavya Rao','Vikram Singh','Rohan Das','Latha Menon','Ramesh Iyer',
  'Priya Service','Zoya Service','Neha Kapoor'
);

DROP TEMPORARY TABLE IF EXISTS tmp_demo_lead_ids;
CREATE TEMPORARY TABLE tmp_demo_lead_ids AS
SELECT id FROM leads WHERE
  email IN (
    'ravi@kumarele.in','anita@shahtex.in','imran@sheikhfoods.in','priya@nairpharma.in','vikram@raomotors.in',
    'farhan@aligeneral.in','sneha@kulkarniprov.in','manoj@yadavkirana.in','kavita@bhattsweets.in',
    'rajesh@menontraders.in','divya@pillaibakery.in','suresh@reddyhw.in','anjali@deshmukhmob.in'
  )
  OR company IN ('QA Corp','Site Co','Zenith Retail')
  OR email LIKE 'qa@%test%' OR email LIKE '%@test.com'
  OR first_name IN ('QA','Test','Auto Test','Manual Test');

-- ----------------------------------------------------------------------------
-- 2. Seeded chats / calls / WhatsApp (create-service-sample-data.php, seed-chat.php, seed_calls_sample.sql)
-- ----------------------------------------------------------------------------
DELETE FROM chat_messages WHERE session_id IN (
  SELECT id FROM chat_sessions WHERE visitor_name IN (
    'Farhan Ali','Web Visitor','Kavita Bhatt','Rajesh Menon','Suresh Reddy',
    'Rohit Sharma','Priya Menon','Aditya Kulkarni','Sneha Nair','Karan Bhatia',
    'Vikas Gupta','Deepa Iyer','Karan Singh','Meera Joshi','Sanjay Rao','Anita Desai'
  )
);
DELETE FROM chat_sessions WHERE visitor_name IN (
  'Farhan Ali','Web Visitor','Kavita Bhatt','Rajesh Menon','Suresh Reddy',
  'Rohit Sharma','Priya Menon','Aditya Kulkarni','Sneha Nair','Karan Bhatia',
  'Vikas Gupta','Deepa Iyer','Karan Singh','Meera Joshi','Sanjay Rao','Anita Desai'
);

DELETE FROM call_logs WHERE
  provider_call_id LIKE 'SAMPLE-%'
  OR provider_call_id LIKE 'demo%'
  OR customer_name IN ('Farhan Ali','Sneha Kulkarni','Manoj Yadav','Rajesh Menon','Live demo caller');

DELETE FROM agent_call_presence WHERE agent_id IN (SELECT id FROM tmp_demo_employee_ids);

DELETE FROM whatsapp_messages WHERE
  lead_id IN (SELECT id FROM tmp_demo_lead_ids)
  OR phone IN ('919811100011','919822200022','919833300033','919844400044');

-- ----------------------------------------------------------------------------
-- 3. Seeded leads / tickets / tasks / timesheets tied to demo leads or demo employees
-- ----------------------------------------------------------------------------
DELETE FROM tasks WHERE related_type='lead' AND related_id IN (SELECT id FROM tmp_demo_lead_ids);
DELETE FROM crm_tickets WHERE lead_id IN (SELECT id FROM tmp_demo_lead_ids) OR ticket_ref LIKE 'CHAT-%-000%';
DELETE FROM lead_followups WHERE lead_id IN (SELECT id FROM tmp_demo_lead_ids);
DELETE FROM deal_stage_history WHERE deal_id IN (SELECT id FROM deals WHERE lead_id IN (SELECT id FROM tmp_demo_lead_ids));
DELETE FROM deals WHERE lead_id IN (SELECT id FROM tmp_demo_lead_ids);
DELETE FROM leads WHERE id IN (SELECT id FROM tmp_demo_lead_ids);

DELETE FROM timesheets WHERE employee_id IN (SELECT id FROM tmp_demo_employee_ids);
DELETE FROM incentive_ledger WHERE employee_id IN (SELECT id FROM tmp_demo_employee_ids);

-- Per-employee KRA/scorecard assignments for demo staff (KPI *catalogue*/org-scope goals are untouched)
DELETE FROM scorecard_lines WHERE scorecard_id IN (
  SELECT id FROM scorecards WHERE employee_id IN (SELECT id FROM tmp_demo_employee_ids)
);
DELETE FROM scorecards WHERE employee_id IN (SELECT id FROM tmp_demo_employee_ids);
DELETE FROM kpa_goals WHERE scope='team' AND owner_employee_id IN (SELECT id FROM tmp_demo_employee_ids);

-- ----------------------------------------------------------------------------
-- 4. Seeded merchants / accounts / contacts / transactions (manual QA test records)
-- ----------------------------------------------------------------------------
DELETE FROM merchant_transactions WHERE txn_ref LIKE 'QATXN%' OR txn_ref IN ('TXN1001','TXN1002');
DELETE FROM merchant_documents WHERE merchant_id IN (
  SELECT id FROM merchants WHERE legal_name IN ('Patel Traders','Site Co','QA Corp','Zenith Retail')
     OR display_name IN ('Patel Traders','Site Co','QA Corp','Zenith Retail')
);
DELETE FROM merchants WHERE legal_name IN ('Patel Traders','Site Co','QA Corp','Zenith Retail')
   OR display_name IN ('Patel Traders','Site Co','QA Corp','Zenith Retail');
DELETE FROM contacts WHERE email LIKE '%qa@test.com%' OR (first_name='Test' AND last_name='Runner');
DELETE FROM accounts WHERE account_name IN ('Patel Traders','Site Co','QA Corp','Zenith Retail');

-- ----------------------------------------------------------------------------
-- 5. Demo/dev login accounts + their employee rows
--    (create-users.php, create-service-users.php, create-dept-managers.php,
--     and the auth.php/_devlogin.php auto-seed-on-empty-table accounts)
--    NOTE: role_access itself is NOT touched — those roles are real production roles.
-- ----------------------------------------------------------------------------
DELETE FROM sales_users WHERE username IN (
  'admin','manager','asha','ravi','neha','hr','it','rohan','hrmanager','itmanager','priya','zoya'
);
DELETE FROM employees WHERE id IN (SELECT id FROM tmp_demo_employee_ids);

DROP TEMPORARY TABLE IF EXISTS tmp_demo_employee_ids;
DROP TEMPORARY TABLE IF EXISTS tmp_demo_lead_ids;

SET FOREIGN_KEY_CHECKS = 1;
COMMIT;

-- ============================================================================
-- PREVIEW / VERIFY — run these SELECTs BEFORE the deletes above (comment the
-- DELETEs out, or just eyeball this file) to sanity-check what will be removed.
-- ============================================================================
-- SELECT COUNT(*) AS demo_employees FROM employees WHERE full_name IN (
--   'Asha Sales','Ravi Sales','Meena Manager','Kavya Rao','Vikram Singh','Rohan Das',
--   'Latha Menon','Ramesh Iyer','Priya Service','Zoya Service','Neha Kapoor');
-- SELECT COUNT(*) AS demo_logins FROM sales_users WHERE username IN (
--   'admin','manager','asha','ravi','neha','hr','it','rohan','hrmanager','itmanager','priya','zoya');
-- SELECT COUNT(*) AS demo_leads FROM leads WHERE email LIKE '%@aligeneral.in' OR company IN ('QA Corp','Site Co','Zenith Retail');
-- SELECT COUNT(*) AS sample_calls FROM call_logs WHERE provider_call_id LIKE 'SAMPLE-%' OR provider_call_id LIKE 'demo%';

-- ============================================================================
-- NOT automated here (do these manually before go-live):
--   1. Set PAYG_ENABLE_SAMPLE_LOGIN=false in the production environment
--      (disables auth.php's auto-seed-on-empty-sales_users AND api/sales/_devlogin.php's
--      no-password backdoor login).
--   2. Rotate the integration_tokens row seeded by phase7_integrations.sql
--      (originally 'payg_demo_0000000000000000') — generate a fresh token via
--      the Integrations admin screen; do NOT reuse a value that ever shipped in a repo.
--   3. Delete or move out of the webroot: sales-setup.php, create-users.php,
--      create-service-users.php, create-dept-managers.php,
--      create-service-sample-data.php, seed-chat.php, reset-dev-passwords.php.
--   4. Create real employee + sales_users accounts for actual staff — after this
--      script runs there are ZERO login accounts left. Do not go live without
--      creating at least one real admin account first.
-- ============================================================================
