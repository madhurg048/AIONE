-- ============================================================================
-- Phase 19 — Sales reimbursements (travel / stay / food …).
-- A claim MUST be linked to a lead that has NOT yet become a merchant
-- (i.e. cost of pursuing an unconverted lead). Idempotent.
-- ============================================================================
CREATE TABLE IF NOT EXISTS reimbursements (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  employee_id   INT NOT NULL,                         -- the sales rep claiming
  lead_id       INT NOT NULL,                         -- the (not-yet-merchant) lead pursued
  category      ENUM('travel','stay','food','fuel','other') NOT NULL DEFAULT 'travel',
  amount        DECIMAL(12,2) NOT NULL DEFAULT 0,
  expense_date  DATE NOT NULL,
  description   VARCHAR(500) DEFAULT NULL,
  receipt_ref   VARCHAR(255) DEFAULT NULL,            -- optional receipt no. / link
  status        ENUM('submitted','approved','rejected','paid') NOT NULL DEFAULT 'submitted',
  approved_by   INT DEFAULT NULL,
  approved_at   TIMESTAMP NULL DEFAULT NULL,
  paid_at       TIMESTAMP NULL DEFAULT NULL,
  remarks       VARCHAR(500) DEFAULT NULL,            -- approver note / reject reason
  created_at    TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  updated_at    TIMESTAMP NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  KEY idx_emp (employee_id), KEY idx_lead (lead_id), KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- module access: reps submit & see own; managers/admin approve & pay
INSERT IGNORE INTO role_access (role, module_key, can_access) VALUES
 ('admin','reimbursements',1),
 ('manager','reimbursements',1),
 ('sales_exec','reimbursements',1);
