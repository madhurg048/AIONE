-- Phase 23: Service vertical (parallel to Sales) — new role, ticket routing, live-chat split.
--
-- A "service_exec" logs in and sees the same nav (Leads/Pipeline/Merchants/Incentives/Reports/
-- Timesheet/Automation/Settings) as a sales_exec, just scoped to service-vertical records and
-- rebranded "Service" instead of "Sales". Chatbot tickets route by assist_type:
--   sales                -> vertical=sales,   ticket only (no live chat)
--   service, transaction -> vertical=service, ticket + live chat session (waiting queue)
--   callback              -> vertical=service, ticket only (agent calls back)

ALTER TABLE sales_users MODIFY role
    ENUM('admin','manager','sales_exec','service_exec','hr','it') NOT NULL DEFAULT 'sales_exec';

ALTER TABLE leads
    ADD COLUMN vertical ENUM('sales','service') NOT NULL DEFAULT 'sales' AFTER lead_source,
    ADD KEY idx_leads_vertical (vertical);

ALTER TABLE crm_tickets
    ADD COLUMN vertical ENUM('sales','service') NOT NULL DEFAULT 'sales' AFTER source,
    ADD COLUMN assist_type VARCHAR(20) DEFAULT NULL AFTER vertical;

ALTER TABLE chat_sessions
    ADD COLUMN vertical ENUM('sales','service') NOT NULL DEFAULT 'sales' AFTER lead_id,
    ADD KEY idx_chat_vertical (vertical, status);

-- module access for the new role — mirrors sales_exec, minus conversations/users (manager/admin monitor only)
INSERT INTO role_access (role, module_key, can_access) VALUES
    ('service_exec','dashboard',1),
    ('service_exec','leads',1),
    ('service_exec','pipeline',1),
    ('service_exec','merchants',1),
    ('service_exec','incentives',0),
    ('service_exec','reports',0),
    ('service_exec','automation',0),
    ('service_exec','settings',0),
    ('service_exec','admin',0),
    ('service_exec','integrations',0),
    ('service_exec','timesheet',1),
    ('service_exec','chat',1),
    ('service_exec','conversations',0),
    ('service_exec','users',0),
    ('service_exec','inbox',1)
ON DUPLICATE KEY UPDATE can_access=VALUES(can_access);
