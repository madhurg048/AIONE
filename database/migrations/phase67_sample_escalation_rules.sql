-- ============================================================================
-- Phase 67 — Sample escalation rules (ESC-nnnn).
--
-- The escalation matrix OVERRIDES the default manager chain for a given person
-- and level. These samples are derived from the real reporting lines that exist
-- in the data (no hard-coded ids), so the same migration produces sensible
-- rules on any deployment:
--
--   L1  ->  the person's own manager                     (TAT 4h)
--   L2  ->  their manager's manager                      (TAT 8h)
--   L3  ->  the admin / top of their chain               (TAT 24h)
--
-- Every row gets its ESC-nnnn code automatically (phase64 virtual column) and
-- an approval record, so it appears in the Governance Map like any other
-- governed object. Idempotent; safe to re-run.
-- ============================================================================

-- L1: escalate to my manager (only for people who actually have one)
INSERT INTO `escalation_matrix` (`employee_id`, `level`, `escalate_to`, `tat_hours`)
SELECT e.id, 1, e.manager_id, 4.0
  FROM `employees` e
  JOIN `sales_users` su ON su.employee_id = e.id AND su.is_active = 1
 WHERE e.manager_id IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `escalation_matrix` m WHERE m.employee_id = e.id AND m.level = 1)
 LIMIT 12;

-- L2: escalate to my manager's manager
INSERT INTO `escalation_matrix` (`employee_id`, `level`, `escalate_to`, `tat_hours`)
SELECT e.id, 2, m1.manager_id, 8.0
  FROM `employees` e
  JOIN `employees` m1 ON m1.id = e.manager_id
  JOIN `sales_users` su ON su.employee_id = e.id AND su.is_active = 1
 WHERE m1.manager_id IS NOT NULL
   AND m1.manager_id <> e.id
   AND NOT EXISTS (SELECT 1 FROM `escalation_matrix` m WHERE m.employee_id = e.id AND m.level = 2)
 LIMIT 12;

-- L3: anything still open goes to the Admin account
INSERT INTO `escalation_matrix` (`employee_id`, `level`, `escalate_to`, `tat_hours`)
SELECT e.id, 3, a.employee_id, 24.0
  FROM `employees` e
  JOIN `sales_users` su ON su.employee_id = e.id AND su.is_active = 1
  JOIN (SELECT employee_id FROM `sales_users` WHERE role = 'admin' AND is_active = 1
         ORDER BY id LIMIT 1) a
 WHERE e.manager_id IS NOT NULL
   AND a.employee_id IS NOT NULL AND a.employee_id <> e.id
   AND NOT EXISTS (SELECT 1 FROM `escalation_matrix` m WHERE m.employee_id = e.id AND m.level = 3)
 LIMIT 12;

-- every rule is a governed object: give it an approval record
INSERT INTO `governance_approvals` (`obj_type`,`obj_id`,`status`,`version`,`approved_at`)
SELECT 'esc', m.id, 'approved', m.version, m.created_at FROM `escalation_matrix` m
 WHERE NOT EXISTS (SELECT 1 FROM `governance_approvals` a WHERE a.obj_type='esc' AND a.obj_id=m.id);

-- and record their creation in the version history so the trail is complete
INSERT INTO `governance_revisions` (`obj_type`,`obj_id`,`obj_code`,`version`,`action`,`summary`)
SELECT 'esc', m.id, m.esc_ref, m.version, 'created',
       CONCAT('Sample rule: L', m.level, ' escalation, TAT ', m.tat_hours, 'h')
  FROM `escalation_matrix` m
 WHERE NOT EXISTS (SELECT 1 FROM `governance_revisions` r WHERE r.obj_type='esc' AND r.obj_id=m.id);

-- link the tightest urgent TAT policy to the L1 rules, so a breach traces
-- SLA-nnnn -> ESC-nnnn on the governance map
INSERT INTO `governance_links` (`from_type`,`from_id`,`to_type`,`to_id`,`relation`,`note`)
SELECT 'sla', p.id, 'esc', m.id, 'escalates_via', 'Sample: an urgent breach routes through this first-level rule'
  FROM (SELECT id FROM `sla_policies` WHERE is_active = 1 ORDER BY tat_hours LIMIT 1) p
  JOIN (SELECT id FROM `escalation_matrix` WHERE level = 1 ORDER BY id LIMIT 4) m
 WHERE NOT EXISTS (SELECT 1 FROM `governance_links` l
                    WHERE l.from_type='sla' AND l.from_id=p.id AND l.to_type='esc' AND l.to_id=m.id);
