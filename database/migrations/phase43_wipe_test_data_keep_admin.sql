-- ============================================================================
-- Phase 43 — Full test-data wipe, keep ONLY the `admin` login
-- ============================================================================
-- Use this instead of phase42 when you want a clean slate: every business/
-- transactional row created during testing is removed from EVERY table that
-- was exercised while testing, but:
--   - all schema stays
--   - all config/keys stay untouched: role_access, custom_fields (defs),
--     automation_rules (defs), incentive_plans/incentive_rules (defs),
--     pipelines/deal_stages (defs), email_settings, whatsapp_settings,
--     telephony_settings, ai_settings, transcription_settings, chat_settings,
--     email_mailboxes, integration_tokens, sales_email_templates, web_lead_sources
--   - KPI / KRA / AI configuration & insight history stay: kpi_definitions,
--     dashboard_kpis, kpa_goals (org-scope rows only — team-scope rows tied to
--     wiped test employees are removed), ai_settings, ai_insights, ai_documents
--   - exactly ONE login survives: the `admin` account in sales_users (its
--     employee_id is detached to NULL since the employees table is wiped —
--     admin/manager roles see all rows regardless of employee link)
--
-- After this runs: create your real employees + sales_users accounts, then
-- reset admin's password (see production-wipe-keep-admin.php, which can do
-- that for you — plain SQL can't compute a PHP password_hash()).
--
-- No FOREIGN KEY constraints exist anywhere in this schema (verified against
-- every phase*.sql — all relations are app-level `KEY` indexes only), so
-- TRUNCATE order below is for readability, not correctness.
-- ============================================================================

START TRANSACTION;
SET FOREIGN_KEY_CHECKS = 0;

-- ---- Funnel / pipeline data ----
TRUNCATE TABLE deal_stage_history;
TRUNCATE TABLE deals;
TRUNCATE TABLE lead_followups;
TRUNCATE TABLE leads;
TRUNCATE TABLE contacts;
TRUNCATE TABLE accounts;

-- ---- PIMS / merchants ----
TRUNCATE TABLE merchant_transactions;
TRUNCATE TABLE merchant_documents;
TRUNCATE TABLE merchants;

-- ---- Incentives (ledger only — plan/rule DEFINITIONS are kept) ----
TRUNCATE TABLE incentive_ledger;

-- ---- Tickets / service desk ----
TRUNCATE TABLE crm_tickets;

-- ---- Tasks / activities ----
TRUNCATE TABLE tasks;
TRUNCATE TABLE activities;

-- ---- Live chat / calls / WhatsApp ----
TRUNCATE TABLE chat_messages;
TRUNCATE TABLE chat_transfers;
TRUNCATE TABLE chat_sessions;
TRUNCATE TABLE agent_call_presence;
TRUNCATE TABLE call_logs;
TRUNCATE TABLE whatsapp_messages;
TRUNCATE TABLE whatsapp_threads;

-- ---- Timesheets / HR-ish transactional data ----
TRUNCATE TABLE timesheets;
TRUNCATE TABLE reimbursements;
TRUNCATE TABLE sales_targets;

-- ---- Scorecards (per-employee assignments — KPI catalogue definitions kept) ----
TRUNCATE TABLE scorecard_lines;
TRUNCATE TABLE scorecards;

-- ---- KPA/KRA team-level goals tied to specific (now-wiped) test employees ----
--      (org-level goal definitions for whole departments are left alone)
DELETE FROM kpa_goals WHERE scope = 'team';

-- ---- Custom field VALUES (definitions in custom_fields are kept) ----
TRUNCATE TABLE custom_field_values;

-- ---- Automation execution logs (rule DEFINITIONS in automation_rules are kept) ----
TRUNCATE TABLE automation_logs;
TRUNCATE TABLE assignment_state;

-- ---- Misc runtime/test artifacts ----
TRUNCATE TABLE notifications;
TRUNCATE TABLE otp_rate_limits;
TRUNCATE TABLE ai_documents;
TRUNCATE TABLE inbox_emails;
-- auth_login_attempts is created ad-hoc at runtime (api/sales/auth.php); if this
-- errors "table doesn't exist" on a fresh DB that's expected — safe to ignore.
TRUNCATE TABLE auth_login_attempts;

-- ---- Logins: keep ONLY admin ----
TRUNCATE TABLE employees;
DELETE FROM sales_users WHERE username <> 'admin';
UPDATE sales_users SET employee_id = NULL WHERE username = 'admin';

SET FOREIGN_KEY_CHECKS = 1;
COMMIT;

-- ============================================================================
-- NOT automated here:
--   1. Reset admin's password (it is currently the well-known dev value
--      "admin123" — use production-wipe-keep-admin.php's --set-admin-password
--      option, since SQL can't compute PHP's password_hash()).
--   2. Set PAYG_ENABLE_SAMPLE_LOGIN=false in the production environment.
--   3. Rotate the integration_tokens row (demo placeholder).
--   4. Delete dev seed scripts from the webroot: sales-setup.php,
--      create-users.php, create-service-users.php, create-dept-managers.php,
--      create-service-sample-data.php, seed-chat.php, reset-dev-passwords.php.
--   5. Log in as admin, go to Org/Users admin, and create real employees +
--      sales_users accounts under their real names.
-- ============================================================================
