-- ============================================================================
-- Phase 75 — the cockpit's own settings (organisation & calendar).
-- A tiny key/value store rather than a wide row, so adding a knob later is a
-- seed line and not an ALTER.
-- ============================================================================
CREATE TABLE IF NOT EXISTS `cfo_settings` (
  `skey`       VARCHAR(60) NOT NULL,
  `sval`       VARCHAR(2000) NOT NULL DEFAULT '',
  `updated_by` INT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`skey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `cfo_settings` (`skey`,`sval`)
SELECT t.k, t.v FROM (
            SELECT 'org_name' k, 'PayG' v
  UNION ALL SELECT 'fy_start_month', '4'
  UNION ALL SELECT 'work_days', 'Mon,Tue,Wed,Thu,Fri,Sat'
  UNION ALL SELECT 'timezone', 'Asia/Kolkata (IST)'
  UNION ALL SELECT 'holidays', ''
  UNION ALL SELECT 'scheduler_back_days', '30'
  UNION ALL SELECT 'scheduler_fwd_days', '45'
  UNION ALL SELECT 'reminder_days_before', '3'
  UNION ALL SELECT 'digest_recipients', ''
) t WHERE NOT EXISTS (SELECT 1 FROM `cfo_settings` x WHERE x.skey = t.k);
