-- Phase 44: HR governs the KPA / KRA framework company-wide.
--
-- Senior management: HR owns performance management across every department.
--   • HR (hr_manager and hr) may define, edit and delete the global scope='org' KPAs for
--     ANY org group — the same authority the admin has.
--   • HR sees every KPA in the organisation, including the scope='team' goals each
--     department manager sets for their own people, and may modify those too.
--   • A department manager is unchanged: they add scope='team' KPAs for their own line of
--     control only, and can edit/delete only what they themselves created.
--
-- Enforced in api/sales/kpa.php via kpa_is_governor(); this migration only opens the nav
-- module to the plain 'hr' role ('hr_manager' already had it from phase 37).

INSERT INTO `role_access` (`role`, `module_key`, `can_access`) VALUES
  ('hr', 'kpa', 1)
ON DUPLICATE KEY UPDATE `can_access` = VALUES(`can_access`);
