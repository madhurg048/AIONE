-- phase47: one shared round-robin rotation for every sales enquiry (CHAT / SLS / CRM)
--
-- Problem this fixes:
--   Assignment happened in two places at once. The 'Round-robin assign new leads' rule (id 1)
--   assigned leads off its own private 'rule:1' pointer, while the chatbot assigned its ticket
--   off 'ticket:sales'. Each pointer walked the rep list independently and restarted at the
--   top, so a single new chatbot enquiry advanced BOTH — burning two rotation slots and
--   skipping reps — and the rule's pick was then overwritten by the chatbot's, making the work
--   pointless as well as skewing the split.
--
-- Fix (code side, api/sales/_automation_engine.php):
--   * automation_rr_scope() — every round-robin now shares one pointer per vertical.
--   * sales_assign_next()   — the single place a lead+ticket get their owner, called directly
--                             by every lead-creating path.
--
-- Fix (this file):
--   Rule 1 keeps its notify action but no longer assigns — assignment is now unconditional in
--   code, so it can't be silently switched off by toggling a rule in the UI. Rule 3 ('Reassign
--   stale leads') KEEPS its assign_owner: it's a genuine time-based reassignment with no code
--   path doing it, and it now draws from the shared pointer via automation_rr_scope().
--
-- Idempotent: the WHERE clause matches only the un-migrated actions blob, so re-running is a
-- no-op (these seeds are not idempotent by default — see phase2/phase3).

UPDATE automation_rules
SET actions = '[{"type":"notify","to":"owner","title":"New lead assigned","message":"A new lead has been assigned to you."}]'
WHERE id = 1
  AND entity = 'lead'
  AND trigger_event = 'on_create'
  AND actions LIKE '%assign_owner%';

-- Collapse the now-orphaned per-rule pointers into the shared one, keeping the rotation's
-- current position rather than resetting it to the top of the list (which would hand the next
-- several enquiries to whoever sorts first by employee id).
INSERT INTO assignment_state (scope, last_employee_id)
SELECT 'ticket:sales', last_employee_id FROM assignment_state WHERE scope = 'rule:1'
ON DUPLICATE KEY UPDATE last_employee_id = VALUES(last_employee_id);

DELETE FROM assignment_state WHERE scope IN ('rule:1', 'rule:3');
