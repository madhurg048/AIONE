-- ============================================================================
-- Phase 66 — KPI becomes a first-class code, completing the standard hierarchy:
--
--   KPA  (Key Performance Area)   the AREA you focus on          -> "where"
--   KRA  (Key Result Area)        the OUTCOME you must deliver   -> "what"
--   KPI  (Key Performance Indic.) the MEASURE of success         -> "how measured"
--
-- One kpa_goals row already carries all three (title = area, kra = outcome,
-- target_value/unit = measure), so KPI-0007 is the measure of KRA-0007 inside
-- KPA-0007 — same number, three faces. Links between them are then explicit in
-- governance_links so the chain reads: KPA delivers KRA, KRA measured_by KPI,
-- KPI measured_by SLA (the TAT that proves it), SLA escalates_via ESC,
-- SLA governed_by POL.
-- Idempotent; safe to re-run.
-- ============================================================================

ALTER TABLE `kpa_goals`
  ADD COLUMN IF NOT EXISTS `kpi_ref` varchar(20)
      AS (CONCAT('KPI-', LPAD(`id`, 4, '0'))) VIRTUAL;

ALTER TABLE `governance_links`
  MODIFY `from_type` enum('kpa','kra','kpi','sla','esc','policy') NOT NULL,
  MODIFY `to_type`   enum('kpa','kra','kpi','sla','esc','policy') NOT NULL;

-- ---------------------------------------------------------------------------
-- Worked example so the model is self-explanatory in the UI. Each insert is
-- guarded, picks real rows, and does nothing if the pieces don't exist.
-- ---------------------------------------------------------------------------

-- 1) KPA delivers KRA, and KRA is measured by KPI — for every active goal that
--    has both an outcome and a target (the textbook chain, on real records).
INSERT INTO `governance_links` (`from_type`,`from_id`,`to_type`,`to_id`,`relation`,`note`)
SELECT 'kpa', k.id, 'kra', k.id, 'delivers', 'The focus area delivers this outcome'
  FROM `kpa_goals` k
 WHERE k.is_active = 1 AND k.kra IS NOT NULL AND k.kra <> ''
   AND NOT EXISTS (SELECT 1 FROM `governance_links` l
                    WHERE l.from_type='kpa' AND l.from_id=k.id AND l.to_type='kra' AND l.to_id=k.id AND l.relation='delivers')
 LIMIT 40;

INSERT INTO `governance_links` (`from_type`,`from_id`,`to_type`,`to_id`,`relation`,`note`)
SELECT 'kra', k.id, 'kpi', k.id, 'measured_by', 'The outcome is measured by this indicator'
  FROM `kpa_goals` k
 WHERE k.is_active = 1 AND k.target_value IS NOT NULL AND k.target_value <> ''
   AND NOT EXISTS (SELECT 1 FROM `governance_links` l
                    WHERE l.from_type='kra' AND l.from_id=k.id AND l.to_type='kpi' AND l.to_id=k.id AND l.relation='measured_by')
 LIMIT 40;

-- 2) A service KPI is proven by a TAT policy: link the first service-side goal
--    to the tightest active ticket SLA.
INSERT INTO `governance_links` (`from_type`,`from_id`,`to_type`,`to_id`,`relation`,`note`)
SELECT 'kpi', k.id, 'sla', p.id, 'measured_by', 'Sample: this indicator is evidenced by the ticket TAT policy'
  FROM (SELECT id FROM `kpa_goals` WHERE is_active=1 AND (org_group='Service' OR title LIKE '%Resolution%') ORDER BY id LIMIT 1) k
  JOIN (SELECT id FROM `sla_policies` WHERE is_active=1 AND entity='ticket' ORDER BY tat_hours LIMIT 1) p
 WHERE NOT EXISTS (SELECT 1 FROM `governance_links` l
                    WHERE l.from_type='kpi' AND l.from_id=k.id AND l.to_type='sla' AND l.to_id=p.id);

-- 3) That TAT policy escalates through the escalation matrix.
INSERT INTO `governance_links` (`from_type`,`from_id`,`to_type`,`to_id`,`relation`,`note`)
SELECT 'sla', p.id, 'esc', m.id, 'escalates_via', 'Sample: a breach of this TAT routes through this escalation rule'
  FROM (SELECT id FROM `sla_policies` WHERE is_active=1 ORDER BY tat_hours LIMIT 1) p
  JOIN (SELECT id FROM `escalation_matrix` ORDER BY id LIMIT 1) m
 WHERE NOT EXISTS (SELECT 1 FROM `governance_links` l
                    WHERE l.from_type='sla' AND l.from_id=p.id AND l.to_type='esc' AND l.to_id=m.id);

-- 4) And the TAT policy exists because a policy/licence demands it.
INSERT INTO `governance_links` (`from_type`,`from_id`,`to_type`,`to_id`,`relation`,`note`)
SELECT 'sla', p.id, 'policy', c.id, 'governed_by', 'Sample: the regulatory item this service standard answers to'
  FROM (SELECT id FROM `sla_policies` WHERE is_active=1 ORDER BY tat_hours LIMIT 1) p
  JOIN (SELECT id FROM `compliance_items` WHERE status='active' ORDER BY id LIMIT 1) c
 WHERE NOT EXISTS (SELECT 1 FROM `governance_links` l
                    WHERE l.from_type='sla' AND l.from_id=p.id AND l.to_type='policy' AND l.to_id=c.id);
