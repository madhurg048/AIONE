-- Phase 40: employee org/contact fields (Department / Sub-department / Email / Phone).
--
-- Note: "department" here is a free-text org-structure label (e.g. "Sales", "Finance",
-- "Customer Support") for HR/org-chart display purposes -- distinct from the existing
-- "vertical" concept (sales/service) used for row-level pipeline scoping (phase29), and
-- distinct from the dead primary_department_id column dropped in phase28 (that referenced
-- a departments table that was never built). This is plain free-text on employees, no
-- separate lookup table -- picklists can be added later if needed.

ALTER TABLE `employees`
  ADD COLUMN IF NOT EXISTS `department`     VARCHAR(80)  NULL AFTER `designation`,
  ADD COLUMN IF NOT EXISTS `sub_department` VARCHAR(80)  NULL AFTER `department`,
  ADD COLUMN IF NOT EXISTS `email`          VARCHAR(150) NULL AFTER `sub_department`,
  ADD COLUMN IF NOT EXISTS `phone`          VARCHAR(30)  NULL AFTER `email`;
