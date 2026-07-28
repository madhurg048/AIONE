-- Phase 35: waiting-queue timeout ladder + tracks whether the "agents are busy" nudge
-- (sent at 30s of a waiting chat with nobody assigned yet) has already gone out, so
-- chat_sweep_missed() in api/sales/_automation_engine.php doesn't re-push it on every poll.
ALTER TABLE `chat_sessions`
  ADD COLUMN `queue_warn_sent_at` DATETIME NULL DEFAULT NULL AFTER `last_activity_at`;
