-- employees.primary_department_id was leftover schema from a departments/teams subsystem that was
-- never built (no departments table exists, no code references this column) -- same dead-legacy
-- pattern already documented in CLAUDE.md for the removed api/auth.php/includes/utils.php. Always NULL.
ALTER TABLE `employees` DROP COLUMN `primary_department_id`;
