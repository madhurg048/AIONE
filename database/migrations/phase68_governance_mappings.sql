-- ============================================================================
-- Phase 68 — A worked set of governance MAPPINGS, so the map shows a complete
-- chain rather than isolated codes:
--
--   POL  governs  ->  SLA        the policy that demands the service standard
--   SLA  escalates_via -> ESC    who picks it up when the TAT is missed
--   KPI  measured_by -> SLA      the indicator proven by that TAT
--   KRA  measured_by -> KPI      the outcome the indicator measures
--   KPA  delivers   -> KRA       the area that delivers the outcome
--   KPA  implements -> POL       the area that carries a regulatory obligation
--   KRA  depends_on -> ESC       an outcome that relies on the escalation path
--
-- All inserts are guarded and pick real rows, so re-running changes nothing.
-- ============================================================================

-- 1) Every active TAT policy is governed by the organisation's top compliance item.
INSERT INTO `governance_links` (`from_type`,`from_id`,`to_type`,`to_id`,`relation`,`note`)
SELECT 'sla', p.id, 'policy', c.id, 'governed_by', 'Service standard required by this regulatory item'
  FROM `sla_policies` p
  JOIN (SELECT id FROM `compliance_items` WHERE status='active' ORDER BY id LIMIT 1) c
 WHERE p.is_active = 1
   AND NOT EXISTS (SELECT 1 FROM `governance_links` l
                    WHERE l.from_type='sla' AND l.from_id=p.id AND l.to_type='policy' AND l.to_id=c.id)
 LIMIT 6;

-- 2) Each ticket TAT policy routes through the first-level escalation rules.
INSERT INTO `governance_links` (`from_type`,`from_id`,`to_type`,`to_id`,`relation`,`note`)
SELECT 'sla', p.id, 'esc', m.id, 'escalates_via', 'A breach of this TAT is routed by this rule'
  FROM (SELECT id FROM `sla_policies` WHERE is_active=1 AND entity='ticket' ORDER BY tat_hours LIMIT 2) p
  JOIN (SELECT id FROM `escalation_matrix` WHERE level=1 ORDER BY id LIMIT 5) m
 WHERE NOT EXISTS (SELECT 1 FROM `governance_links` l
                    WHERE l.from_type='sla' AND l.from_id=p.id AND l.to_type='esc' AND l.to_id=m.id);

-- 3) Service-side indicators are evidenced by the tightest ticket TAT.
INSERT INTO `governance_links` (`from_type`,`from_id`,`to_type`,`to_id`,`relation`,`note`)
SELECT 'kpi', k.id, 'sla', p.id, 'measured_by', 'This indicator is evidenced by ticket TAT performance'
  FROM (SELECT id FROM `kpa_goals`
         WHERE is_active=1 AND target_value IS NOT NULL AND target_value <> ''
           AND (org_group='Service' OR title LIKE '%Resolution%' OR title LIKE '%Response%')
         ORDER BY id LIMIT 3) k
  JOIN (SELECT id FROM `sla_policies` WHERE is_active=1 AND entity='ticket' ORDER BY tat_hours LIMIT 1) p
 WHERE NOT EXISTS (SELECT 1 FROM `governance_links` l
                    WHERE l.from_type='kpi' AND l.from_id=k.id AND l.to_type='sla' AND l.to_id=p.id);

-- 4) Sales indicators are evidenced by the task-side TAT (follow-ups, KYC…).
INSERT INTO `governance_links` (`from_type`,`from_id`,`to_type`,`to_id`,`relation`,`note`)
SELECT 'kpi', k.id, 'sla', p.id, 'measured_by', 'Indicator evidenced by task turnaround'
  FROM (SELECT id FROM `kpa_goals`
         WHERE is_active=1 AND target_value IS NOT NULL AND target_value <> '' AND org_group='Sales'
         ORDER BY id LIMIT 3) k
  JOIN (SELECT id FROM `sla_policies` WHERE is_active=1 AND entity='task' ORDER BY tat_hours LIMIT 1) p
 WHERE NOT EXISTS (SELECT 1 FROM `governance_links` l
                    WHERE l.from_type='kpi' AND l.from_id=k.id AND l.to_type='sla' AND l.to_id=p.id);

-- 5) A compliance-flavoured focus area implements the licence/certification.
INSERT INTO `governance_links` (`from_type`,`from_id`,`to_type`,`to_id`,`relation`,`note`)
SELECT 'kpa', k.id, 'policy', c.id, 'implements', 'This focus area carries the obligation in that item'
  FROM (SELECT id FROM `kpa_goals`
         WHERE is_active=1 AND (title LIKE '%Compliance%' OR title LIKE '%Risk%' OR org_group='Service')
         ORDER BY id LIMIT 2) k
  JOIN (SELECT id FROM `compliance_items` WHERE status='active' ORDER BY id LIMIT 2) c
 WHERE NOT EXISTS (SELECT 1 FROM `governance_links` l
                    WHERE l.from_type='kpa' AND l.from_id=k.id AND l.to_type='policy' AND l.to_id=c.id);

-- 6) An outcome that depends on the escalation path actually working.
INSERT INTO `governance_links` (`from_type`,`from_id`,`to_type`,`to_id`,`relation`,`note`)
SELECT 'kra', k.id, 'esc', m.id, 'depends_on', 'Hitting this outcome depends on escalations being picked up'
  FROM (SELECT id FROM `kpa_goals` WHERE is_active=1 AND kra IS NOT NULL AND kra <> ''
         AND (org_group='Service' OR title LIKE '%Resolution%') ORDER BY id LIMIT 2) k
  JOIN (SELECT id FROM `escalation_matrix` WHERE level=1 ORDER BY id LIMIT 2) m
 WHERE NOT EXISTS (SELECT 1 FROM `governance_links` l
                    WHERE l.from_type='kra' AND l.from_id=k.id AND l.to_type='esc' AND l.to_id=m.id);

-- record the mappings in the version history
INSERT INTO `governance_revisions` (`obj_type`,`obj_id`,`obj_code`,`version`,`action`,`summary`)
SELECT 'link', l.id, l.link_ref, 1, 'linked',
       CONCAT(UPPER(l.from_type), '-', LPAD(l.from_id,4,'0'), ' ', l.relation, ' ',
              UPPER(l.to_type), '-', LPAD(l.to_id,4,'0'), ' (sample mapping)')
  FROM `governance_links` l
 WHERE NOT EXISTS (SELECT 1 FROM `governance_revisions` r WHERE r.obj_type='link' AND r.obj_id=l.id);
