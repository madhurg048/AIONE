-- ============================================================================
-- Phase 15 — Support vs Sales separation
-- Live chat is a SUPPORT function; Sales works leads and reaches out via
-- WhatsApp / call. Encodes that distinction in the role_access control panel.
-- Idempotent.
-- ============================================================================

-- 1) allow the two new roles on the login table
ALTER TABLE sales_users MODIFY COLUMN role
  ENUM('admin','manager','sales_exec','hr','it','hr_manager','it_manager','support','support_manager') NOT NULL;

-- 2) the Live Chat console moves OUT of sales roles (Support owns it)
DELETE FROM role_access WHERE role IN ('sales_exec','manager') AND module_key='chat';

-- 3) grant the Support roles their modules
--    support         = front-line: man the live chat, AI inbox, own timesheet/activity
--    support_manager = + omni Live Monitor oversight, reports, manage support users
INSERT IGNORE INTO role_access (role, module_key, can_access) VALUES
 ('support','chat',1),
 ('support','conversations',1),
 ('support','inbox',1),
 ('support','activity',1),
 ('support','timesheet',1),
 ('support_manager','chat',1),
 ('support_manager','conversations',1),
 ('support_manager','inbox',1),
 ('support_manager','activity',1),
 ('support_manager','timesheet',1),
 ('support_manager','reports',1),
 ('support_manager','users',1);

-- 4) reclassify the Application/Customer Support Executive as Support
UPDATE sales_users SET role='support' WHERE username='saitejashinde@payg.in';
