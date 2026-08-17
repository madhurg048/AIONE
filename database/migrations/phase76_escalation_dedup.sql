-- ============================================================================
-- Phase 76 — SLA escalation: database-level duplicate protection
--
-- One escalation hop = one (entity, task_id, level). Until now that was only an
-- application-level assumption: run_escalation_sweep() read escalation_level, decided a
-- hop was due, and inserted. Two sweeps overlapping (cron running long + someone pressing
-- "Run sweep now", or two cron nodes) both read the same pre-hop level and both inserted —
-- duplicate escalation rows, duplicate notifications and duplicate SLA emails to the same
-- manager. The engine now claims the hop with a conditional UPDATE, and this key makes the
-- guarantee structural rather than a matter of timing.
--
-- Idempotent: safe to re-run.
-- ============================================================================

-- 1. Collapse any duplicates already written, keeping the earliest row of each hop.
--    (The unique key below cannot be created while duplicates are present.)
DELETE te FROM `task_escalations` te
  JOIN `task_escalations` keep
    ON keep.`entity`  = te.`entity`
   AND keep.`task_id` = te.`task_id`
   AND keep.`level`   = te.`level`
   AND keep.`id`      < te.`id`;

-- 2. One row per (entity, item, level) — for good.
ALTER TABLE `task_escalations`
  ADD UNIQUE KEY IF NOT EXISTS `uq_esc_hop` (`entity`,`task_id`,`level`);
