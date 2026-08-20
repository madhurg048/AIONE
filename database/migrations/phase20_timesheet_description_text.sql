-- Phase 20: timesheet description overflow + missing activity type
--
-- Bug 1 (data loss): `description` was varchar(500). The Timesheet UI now supports
-- pasting Excel/Teams tables into the description, which serialise to well over 500
-- chars of HTML. Because this server's sql_mode does NOT include STRICT_TRANS_TABLES,
-- MySQL silently truncated the value at 500 chars and still reported success — so any
-- text typed after a pasted table was discarded with no error shown to the rep or the
-- approving manager. Widen to TEXT (64KB).
--
-- Bug 2: 'reports' was added to the Type dropdown in the UI but never to the enum,
-- so choosing it could not be stored.

ALTER TABLE `timesheets`
  MODIFY COLUMN `description` TEXT DEFAULT NULL;

ALTER TABLE `timesheets`
  MODIFY COLUMN `activity_type` enum(
    'call','meeting','follow_up','onboarding','admin','travel',
    'training','reports','chat','whatsapp','other'
  ) DEFAULT 'other';

-- Manager rejection remarks can also carry pasted context; 255 is tight.
ALTER TABLE `timesheets`
  MODIFY COLUMN `remarks` TEXT DEFAULT NULL;
