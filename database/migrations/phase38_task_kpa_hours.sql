-- Phase 38: link tasks to a KPA/KRA goal + capture hours spent, so effort rolls up
-- per goal (who spent how much) for the admin (whole org) and each manager (their team).
--
--   tasks.kpa_goal_id  -> the KPA/KRA (kpa_goals.id) this task contributes to
--   tasks.hours_spent  -> hours the assignee logged against it
--
-- Aggregation: SUM(tasks.hours_spent) GROUP BY kpa_goal_id, assigned_to, scoped by the
-- viewer (admin = everyone; manager = their line of control).

ALTER TABLE `tasks`
  ADD COLUMN IF NOT EXISTS `kpa_goal_id` int(11) DEFAULT NULL AFTER `related_id`,
  ADD COLUMN IF NOT EXISTS `hours_spent` decimal(6,2) NOT NULL DEFAULT 0.00 AFTER `kpa_goal_id`;

ALTER TABLE `tasks`
  ADD INDEX IF NOT EXISTS `idx_task_kpa_goal` (`kpa_goal_id`);
