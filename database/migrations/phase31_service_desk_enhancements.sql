-- Phase 31: Service desk enhancements
--   1. Presence: sales_users.last_heartbeat_at (drives "is a Service/Sales exec online" checks)
--   2. Ticket round-robin: crm_tickets.owner_id (FK-less, mirrors leads/merchants owner pattern)
--   3. RBAC: grant whatsapp/tickets modules to service_exec, tickets to service_manager
--      (whatsapp was already granted to service_manager; left untouched via INSERT IGNORE)

ALTER TABLE sales_users
  ADD COLUMN last_heartbeat_at TIMESTAMP NULL DEFAULT NULL AFTER last_login_at;

ALTER TABLE crm_tickets
  ADD COLUMN owner_id INT NULL DEFAULT NULL AFTER assigned_to_name,
  ADD KEY idx_ticket_owner (owner_id);

INSERT IGNORE INTO role_access (role, module_key, can_access) VALUES
  ('service_exec', 'whatsapp', 1),
  ('service_exec', 'tickets', 1),
  ('service_manager', 'tickets', 1);
