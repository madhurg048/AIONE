-- ============================================================================
-- Phase 14 — KPA/KRA Scorecards (weighted quarterly KPI framework)
-- Managers define KPAs/KRAs for their teams; incentive eligibility is linked to
-- measurable, gated KPIs. Also corrects a few role_access grants.
-- Idempotent: safe to re-run.
-- ============================================================================

-- ---- KPI catalogue (the 5 framework KPIs; weights are defaults, tunable per card)
CREATE TABLE IF NOT EXISTS kpi_definitions (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  code            VARCHAR(40)  NOT NULL UNIQUE,
  name            VARCHAR(120) NOT NULL,
  description     VARCHAR(400) DEFAULT NULL,
  measurement_basis VARCHAR(400) DEFAULT NULL,
  default_weight  DECIMAL(5,2) NOT NULL DEFAULT 0,
  unit            ENUM('inr','pct','count','flag') NOT NULL DEFAULT 'inr',
  is_gate         TINYINT(1)   NOT NULL DEFAULT 0,   -- primary eligibility gate (GMV)
  direction       ENUM('higher','lower') NOT NULL DEFAULT 'higher',
  auto_source     VARCHAR(40)  DEFAULT NULL,         -- how actuals auto-fill: gmv|fee_income|active_merchants|segment_mix|NULL(manual)
  sort_order      INT NOT NULL DEFAULT 0,
  is_active       TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO kpi_definitions (code,name,description,measurement_basis,default_weight,unit,is_gate,direction,auto_source,sort_order) VALUES
 ('gmv','GMV Achievement',
  'Average monthly GMV achieved during the quarter against assigned target. Primary eligibility gate — below 100% no GMV incentive is payable; above 110% qualifies for accelerator.',
  'Avg monthly GMV vs assigned target',50.00,'inr',1,'higher','gmv',1),
 ('fee_income','Fee Income Achievement',
  'Net fee income from eligible active merchants. Min Rs 1L for eligibility; higher slabs at Rs 2L and Rs 5L. Paid over and above GMV incentives.',
  'Net fee income from active merchants',20.00,'inr',0,'higher','fee_income',2),
 ('segment_mix','High-Yield Segment Mix',
  'Share of GMV from FI, NBFC, Insurance, Education and Enterprise categories. Minimum 70% contribution expected.',
  '% GMV from high-yield categories',15.00,'pct',0,'higher','segment_mix',3),
 ('active_merchants','Active Transacting Merchants',
  'Count of merchants transacting during the quarter. Eligibility control — only active, transacting merchants qualify for GMV and fee computation.',
  'No. of active transacting merchants',10.00,'count',0,'higher','active_merchants',4),
 ('governance','Governance & Validation Compliance',
  'Timely submission of validated sales, merchant, GMV and fee data for Finance review. 100% validation and management approval mandatory before payout release.',
  '% data validated & approved',5.00,'pct',0,'higher',NULL,5);

-- ---- A scorecard = one employee, one quarter, at a role level
CREATE TABLE IF NOT EXISTS scorecards (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  employee_id  INT NOT NULL,
  period       CHAR(7) NOT NULL,              -- e.g. '2026-Q3'
  role_level   VARCHAR(40) NOT NULL DEFAULT 'Senior Sales Executive',
  assigned_by  INT DEFAULT NULL,             -- manager/admin employee id
  status       ENUM('draft','published','acknowledged','locked') NOT NULL DEFAULT 'draft',
  gmv_target   DECIMAL(18,2) NOT NULL DEFAULT 0,  -- convenience mirror of the gmv line target
  notes        VARCHAR(600) DEFAULT NULL,
  acknowledged_at TIMESTAMP NULL DEFAULT NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  updated_at   TIMESTAMP NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  UNIQUE KEY uq_emp_period (employee_id, period),
  KEY idx_period (period),
  KEY idx_assigned_by (assigned_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Each KPI line on a scorecard (weight/target/threshold/actual)
CREATE TABLE IF NOT EXISTS scorecard_lines (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  scorecard_id  INT NOT NULL,
  kpi_code      VARCHAR(40) NOT NULL,
  weight        DECIMAL(5,2) NOT NULL DEFAULT 0,
  target_value  DECIMAL(18,2) NOT NULL DEFAULT 0,
  threshold_value DECIMAL(18,2) NOT NULL DEFAULT 0,
  actual_value  DECIMAL(18,2) NOT NULL DEFAULT 0,
  actual_source ENUM('auto','manual') NOT NULL DEFAULT 'manual',
  is_gate       TINYINT(1) NOT NULL DEFAULT 0,
  notes         VARCHAR(255) DEFAULT NULL,
  UNIQUE KEY uq_card_kpi (scorecard_id, kpi_code),
  KEY idx_card (scorecard_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- role_access corrections
-- ============================================================================
-- Sales Manager should not see the org-wide HR compliance dashboard.
DELETE FROM role_access WHERE role='manager' AND module_key='hr';
-- IT Manager should not configure sales incentive plans.
DELETE FROM role_access WHERE role='it_manager' AND module_key='admin';

-- New 'scorecards' module: admin + manager manage; sales_exec sees own (My Scorecard).
INSERT IGNORE INTO role_access (role, module_key, can_access) VALUES
 ('admin','scorecards',1),
 ('manager','scorecards',1),
 ('sales_exec','scorecards',1);
