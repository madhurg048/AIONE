-- Phase 24: Department-scoped managers.
--
-- The existing 'manager' role becomes the Sales Manager (dept-scoped to Sales —
-- sees all sales_exec employees + sales-vertical leads/tickets/chats, but not Service).
-- A new 'service_manager' role is added as its Service-side counterpart. Only 'admin'
-- keeps unrestricted cross-dept visibility.

ALTER TABLE sales_users MODIFY role
    ENUM('admin','manager','sales_exec','service_exec','service_manager','hr','it') NOT NULL DEFAULT 'sales_exec';

-- service_manager gets the same module access as manager (dashboard/leads/pipeline/etc.),
-- row/vertical scoping is enforced at the data layer (current_vertical()), not here —
-- except reports.php/incentives.php, which assume a Sales deal/merchant pipeline that
-- Service doesn't have and carry zero vertical scoping of their own; left enabled they'd
-- leak Sales agents' performance/payout figures to the Service Manager, so they stay off.
INSERT INTO role_access (role, module_key, can_access)
SELECT 'service_manager', module_key, can_access FROM role_access WHERE role = 'manager'
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);

UPDATE role_access SET can_access = 0 WHERE role = 'service_manager' AND module_key IN ('reports', 'incentives');
