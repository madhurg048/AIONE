-- ============================================================
-- PayG CRM — Phase 48: freeze the Service→Sales handoff onto crm_tickets
--
-- Bug: the "Generated Leads" page (sales-genleads.php) showed the lead's LIVE owner_id for
-- "assigned to" — so if Sales later reassigned the lead to someone else, Service's page
-- silently changed too, misrepresenting who it was actually handed to at transfer time.
-- Service needs a frozen record of the handoff: who on their side sent it, and who on
-- Sales' side it landed on at that exact moment — not whatever the record says today.
--
-- Fix: snapshot both ends of the handoff onto crm_tickets when service-lead.php's action=send
-- runs (both the create-and-send and send-an-existing-draft paths), and read the snapshot
-- instead of the live leads.owner_id join.
-- ============================================================

ALTER TABLE `crm_tickets`
  ADD COLUMN IF NOT EXISTS `transferred_by_id` int(11) DEFAULT NULL AFTER `customer_mobile`,
  ADD COLUMN IF NOT EXISTS `transferred_by_name` varchar(160) DEFAULT NULL AFTER `transferred_by_id`,
  ADD COLUMN IF NOT EXISTS `transferred_to_id` int(11) DEFAULT NULL AFTER `transferred_by_name`,
  ADD COLUMN IF NOT EXISTS `transferred_to_name` varchar(160) DEFAULT NULL AFTER `transferred_to_id`,
  ADD COLUMN IF NOT EXISTS `transferred_at` datetime DEFAULT NULL AFTER `transferred_to_name`;

-- Best-effort backfill for leads already sent before this migration: freeze whatever the
-- lead currently shows. Can't recover the true historical assignee (it was never stored),
-- but stops any FUTURE reassignment on the Sales side from moving these old rows around.
UPDATE `crm_tickets` ct
JOIN `leads` l ON l.id = ct.lead_id
LEFT JOIN `employees` e ON e.id = l.owner_id
SET ct.transferred_to_id   = l.owner_id,
    ct.transferred_to_name = e.full_name,
    ct.transferred_at      = COALESCE(ct.transferred_at, ct.created_at)
WHERE ct.source = 'service_lead' AND l.vertical = 'sales' AND ct.transferred_to_id IS NULL;

-- Grant the new 'genleads' module (sales-genleads.php — the "Generated Leads" page) to the
-- roles that actually need it: Service (who generates these leads) and admin. Sales has no
-- equivalent need (see sales_groups() in includes/sales-layout.php). Idempotent via the
-- unique (role,module_key).
INSERT INTO `role_access` (`role`, `module_key`, `can_access`) VALUES
  ('admin',            'genleads', 1),
  ('service_exec',     'genleads', 1),
  ('service_manager',  'genleads', 1)
ON DUPLICATE KEY UPDATE `can_access` = VALUES(`can_access`);
