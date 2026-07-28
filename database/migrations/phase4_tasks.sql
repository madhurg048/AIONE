-- ============================================================
-- PayG CRM — Phase 4: Task assignment (My Tasks + delegate to subordinates)
-- ============================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `tasks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `task_type` enum('general','kyc_validation','document_collection','follow_up','callback','review','onboarding') DEFAULT 'general',
  `assigned_to` int(11) NOT NULL,          -- employee who must do it
  `assigned_by` int(11) DEFAULT NULL,      -- employee who assigned it
  `related_type` enum('lead','deal','merchant','none') DEFAULT 'none',
  `related_id` int(11) DEFAULT NULL,
  `priority` enum('low','medium','high','urgent') DEFAULT 'medium',
  `status` enum('open','in_progress','done','cancelled') DEFAULT 'open',
  `due_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_task_assignee` (`assigned_to`,`status`),
  KEY `idx_task_assigner` (`assigned_by`),
  KEY `idx_task_related` (`related_type`,`related_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- manager -> subordinate relationship (nullable; assignment falls back to all employees if unset)
ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `manager_id` int(11) DEFAULT NULL;
