-- ============================================================================
-- Phase 55 — SLAs on TICKETS (crm_tickets) alongside tasks, and escalation
-- that keeps climbing past the admin to the BOARD (full-access groups) at the
-- configured per-level intervals. Idempotent; safe to re-run.
-- ============================================================================

-- SLA state on tickets (mirrors the phase50 task columns)
ALTER TABLE `crm_tickets`
  ADD COLUMN IF NOT EXISTS `sla_policy_id` int(11) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `sla_due_at` timestamp NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `escalation_level` int(11) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `escalated_to` int(11) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `last_escalated_at` timestamp NULL DEFAULT NULL;

-- Policies now target an entity: 'task' (default) or 'ticket'.
-- For tickets, task_type matches crm_tickets.assist_type (service/transaction/...).
ALTER TABLE `sla_policies`
  ADD COLUMN IF NOT EXISTS `entity` enum('task','ticket') NOT NULL DEFAULT 'task';

-- Escalation log rows carry the entity + a Board-hop flag
ALTER TABLE `task_escalations`
  ADD COLUMN IF NOT EXISTS `entity` enum('task','ticket') NOT NULL DEFAULT 'task',
  ADD COLUMN IF NOT EXISTS `to_board` tinyint(1) NOT NULL DEFAULT 0;

-- Default ticket SLA policies (only when none exist yet)
INSERT INTO `sla_policies` (`name`,`entity`,`task_type`,`priority`,`tat_hours`,`escalate_every_hours`,`max_level`,`is_active`)
SELECT * FROM (
  SELECT 'Ticket urgent — 2h TAT' AS name, 'ticket' AS entity, NULL AS tt, 'urgent' AS pr, 2.0 AS tat, 2.0 AS esc, 10 AS ml, 1 AS act UNION ALL
  SELECT 'Ticket high — 8h TAT',    'ticket', NULL, 'high',  8.0, 4.0, 10, 1 UNION ALL
  SELECT 'Ticket standard — 24h TAT','ticket', NULL, NULL,  24.0, 12.0, 10, 1
) seed
WHERE NOT EXISTS (SELECT 1 FROM `sla_policies` WHERE `entity`='ticket');
