-- Phase 39: Service dashboard + configurable dashboard KPIs.
--
-- 1. chat_transfers — a dedicated log of every live-chat handoff (cross-dept transfer
--    or in-dept assign/reassign). Before this, a transfer only left a system message in
--    chat_messages and mutated chat/lead vertical+owner, so "how many chats did we
--    transfer, to whom, to which department" was not answerable. Written by
--    api/sales/chat.php (?action=transfer and ?action=assign), read by the Service
--    dashboard's "Transfers" KPIs. Rows before this migration are not backfilled.
--
-- 2. dashboard_kpis — admin/department-manager-defined custom KPIs rendered on the
--    Sales and Service dashboards (metric from a fixed catalog in
--    api/sales/dashboard-kpis.php, chart type, period, target). Admin may define for
--    any department; a manager only for their own department (enforced in the API).

CREATE TABLE IF NOT EXISTS `chat_transfers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` int(11) NOT NULL,
  `lead_id` int(11) DEFAULT NULL,
  `action` enum('transfer','assign') NOT NULL DEFAULT 'transfer',   -- cross-dept handoff vs in-dept (re)assign
  `from_agent_id` int(11) DEFAULT NULL,                             -- NULL = chat was unassigned (waiting)
  `to_agent_id` int(11) NOT NULL,
  `from_vertical` enum('sales','service') DEFAULT NULL,
  `to_vertical` enum('sales','service') DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,                                -- employee who performed the action
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ct_session` (`session_id`),
  KEY `idx_ct_from` (`from_agent_id`),
  KEY `idx_ct_to` (`to_agent_id`),
  KEY `idx_ct_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `dashboard_kpis` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `vertical` enum('sales','service') NOT NULL,
  `title` varchar(120) NOT NULL,
  `metric_key` varchar(60) NOT NULL,                                -- key in the catalog (api/sales/dashboard-kpis.php)
  `display` enum('kpi','bar','line','donut') NOT NULL DEFAULT 'kpi',-- kpi tile / 6-mo bar / 6-mo line / vs-target donut
  `period` enum('today','7d','month','quarter','all') NOT NULL DEFAULT 'month',
  `target_value` decimal(14,2) DEFAULT NULL,                        -- NULL = no target (informational)
  `direction` enum('higher','lower') NOT NULL DEFAULT 'higher',     -- lower = e.g. missed chats, response time
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_by_role` varchar(40) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_dk_vertical` (`vertical`,`is_active`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
