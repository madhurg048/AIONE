-- Phase 21: explicit overtime rows on timesheets
--
-- A workday expects 8 standard hours. Work beyond that is logged as a separate
-- row flagged is_overtime=1 for the same date, so "standard hours logged" and
-- "overtime" are countable independently instead of being inferred purely from
-- the day's running total.

ALTER TABLE `timesheets`
  ADD COLUMN `is_overtime` TINYINT(1) NOT NULL DEFAULT 0 AFTER `source`;
