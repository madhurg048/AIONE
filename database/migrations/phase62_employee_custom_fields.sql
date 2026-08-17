-- ============================================================================
-- Phase 62 — Admin-defined additional fields for USERS / EMPLOYEES:
--   custom_fields.entity was an enum without 'employee', so defining a people
--   field failed (and surfaced as a bogus "duplicate" error). Values table was
--   already varchar. Widening the enum keeps existing rows untouched.
-- Idempotent; safe to re-run.
-- ============================================================================

ALTER TABLE `custom_fields`
  MODIFY `entity` enum('lead','account','contact','deal','merchant','employee') NOT NULL;
