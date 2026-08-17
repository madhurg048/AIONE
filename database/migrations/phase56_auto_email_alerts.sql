-- ============================================================================
-- Phase 56 — Auto-email alerts (manager always looped in):
--   * timesheet not filled (daily), SLA/TAT breach escalations (per hop),
--     sales target attainment below threshold (weekly).
--   * alert_settings drives it all (Settings → Auto-email alerts);
--     alert_log throttles (one email per person/type/ref/day) and audits.
-- Idempotent; safe to re-run.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `alert_settings` (
  `id` tinyint(1) NOT NULL DEFAULT 1,
  `alerts_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `cc_manager` tinyint(1) NOT NULL DEFAULT 1,
  `timesheet_alert` tinyint(1) NOT NULL DEFAULT 1,
  `sla_alert` tinyint(1) NOT NULL DEFAULT 1,
  `target_alert` tinyint(1) NOT NULL DEFAULT 1,
  `target_threshold_pct` int(11) NOT NULL DEFAULT 70,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `alert_settings` (`id`) SELECT 1 WHERE NOT EXISTS (SELECT 1 FROM `alert_settings` WHERE id=1);

CREATE TABLE IF NOT EXISTS `alert_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `alert_type` varchar(30) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `ref` varchar(60) DEFAULT NULL,
  `sent_to` varchar(255) DEFAULT NULL,
  `cc_manager_to` varchar(255) DEFAULT NULL,
  `status` enum('sent','failed','skipped') NOT NULL DEFAULT 'sent',
  `detail` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_throttle` (`alert_type`,`employee_id`,`ref`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
