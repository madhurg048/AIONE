-- Phase 79: Sales reimbursement two-stage approval hierarchy.
--   Stage 1 (mgr_*): Sales Manager (for an Exec's claim) OR Sales Head (for anyone's claim
--                     in the Sales dept) — whichever acts first settles this stage.
--   Stage 2 (hr_*):   HR Head or HR Manager — sequential, only after stage 1 is approved.
--   Final `status` becomes 'approved' only once BOTH stages are 'approved'; either stage
--   rejecting flips it straight to 'rejected'. Admin remains read-only (enforced in code,
--   not schema — the app never lets 'admin' set mgr_*/hr_* columns).

ALTER TABLE reimbursements
  ADD COLUMN mgr_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending' AFTER status,
  ADD COLUMN mgr_by INT NULL DEFAULT NULL AFTER mgr_status,
  ADD COLUMN mgr_at TIMESTAMP NULL DEFAULT NULL AFTER mgr_by,
  ADD COLUMN hr_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending' AFTER mgr_at,
  ADD COLUMN hr_by INT NULL DEFAULT NULL AFTER hr_status,
  ADD COLUMN hr_at TIMESTAMP NULL DEFAULT NULL AFTER hr_by;

-- HR Head/Manager need module access to see + act on Sales reimbursements (Sales Head
-- already had a grant from phase19; HR never did).
INSERT IGNORE INTO role_access (role, module_key, can_access) VALUES
 ('hr_head','reimbursements',1),
 ('hr_manager','reimbursements',1);
