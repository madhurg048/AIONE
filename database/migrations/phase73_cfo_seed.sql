-- ============================================================================
-- Phase 73 — CFO cockpit seed: the real Finance Operations content.
--   * 12 maker-checker form templates (the codes the reference app uses)
--   * statutory + internal obligations with their cadences
--   * the 16 Finance Operations risks (FR-01 … FR-16) with inherent/residual
--   * KPI/KRI definitions with RAG thresholds
--   * review cycles for the next 12 months + this quarter
-- Tasks are NOT seeded — the scheduler expands obligations into dated tasks,
-- so "overdue" is always computed from the calendar rather than typed in.
-- Idempotent; safe to re-run.
-- ============================================================================

/* ---------------- form templates ---------------- */
INSERT INTO `cfo_form_templates` (`code`,`title`,`cadence`,`approver_role`,`checker_role`,`fields_json`)
SELECT * FROM (
  SELECT 'MC-01-MAKER-RECON' code, "Maker's Reconciliation Worksheet" title, 'daily' cad, 'CFO' apr, 'Finance Manager' chk,
         '{"fields":[{"k":"txn_date","l":"Transaction date","t":"date"},{"k":"pg_total","l":"PG total (INR)","t":"number"},{"k":"bank_total","l":"Bank credit (INR)","t":"number"},{"k":"variance","l":"Variance","t":"calc","f":"pg_total-bank_total"},{"k":"remarks","l":"Remarks","t":"textarea"}],"table":{"cols":["Merchant","PG amount","Bank amount","Break","Reason"]}}' fj
  UNION ALL SELECT 'MC-02-BREAK-REGISTER', 'Break Register', 'monthly', 'CFO', 'Finance Manager',
         '{"fields":[{"k":"period","l":"Period","t":"text"},{"k":"opening_breaks","l":"Opening breaks","t":"number"},{"k":"new_breaks","l":"New breaks","t":"number"},{"k":"closed_breaks","l":"Closed breaks","t":"number"},{"k":"closing","l":"Closing","t":"calc","f":"opening_breaks+new_breaks-closed_breaks"}],"table":{"cols":["Break ref","Date","Amount","Ageing (d)","Owner","Status"]}}'
  UNION ALL SELECT 'MC-03-MERCHANT-SETTLEMENT', 'Merchant-wise Settlement Working', 'daily', 'CFO', 'Settlement Ops Lead',
         '{"fields":[{"k":"cycle_date","l":"Settlement cycle","t":"date"},{"k":"merchants","l":"Merchants settled","t":"number"},{"k":"gross","l":"Gross (INR)","t":"number"},{"k":"mdr","l":"MDR (INR)","t":"number"},{"k":"net","l":"Net payable","t":"calc","f":"gross-mdr"}],"table":{"cols":["Merchant","Gross","MDR","Tax","Net","UTR"]}}'
  UNION ALL SELECT 'MC-04-MAKER-CHECKLIST', "Maker's Daily Checklist", 'daily', 'Finance Manager', 'Settlement Ops Lead',
         '{"fields":[{"k":"date","l":"Date","t":"date"},{"k":"pg_file","l":"PG file received","t":"bool"},{"k":"bank_file","l":"Bank file received","t":"bool"},{"k":"recon_done","l":"Recon completed","t":"bool"},{"k":"exceptions","l":"Exceptions raised","t":"number"}]}'
  UNION ALL SELECT 'MC-05-CHECKER-REPERFORMANCE', "Checker's Independent Re-performance Worksheet", 'daily', 'CFO', 'Finance Manager',
         '{"fields":[{"k":"sample_size","l":"Sample size","t":"number"},{"k":"errors_found","l":"Errors found","t":"number"},{"k":"error_rate","l":"Error rate %","t":"calc","f":"errors_found/sample_size*100"},{"k":"conclusion","l":"Conclusion","t":"textarea"}]}'
  UNION ALL SELECT 'MC-06-MC-LOG', 'Maker-Checker Log (master batch register)', 'monthly', 'CFO', 'Finance Manager',
         '{"table":{"cols":["Batch","Date","Maker","Checker","Approver","Status","Exceptions"]}}'
  UNION ALL SELECT 'MC-07-EXCEPTION-REGISTER', 'Exception Register', 'on_demand', 'CFO', 'Finance Manager',
         '{"fields":[{"k":"raised_on","l":"Raised on","t":"date"},{"k":"category","l":"Category","t":"select","o":["Settlement","Recon","Escrow","Tax","Access","Other"]},{"k":"detail","l":"Detail","t":"textarea"},{"k":"amount","l":"Amount at risk (INR)","t":"number"}]}'
  UNION ALL SELECT 'FIN-08-NETWORTH', 'Quarterly Net-Worth Working', 'quarterly', 'CFO', 'Finance Manager',
         '{"fields":[{"k":"quarter","l":"Quarter","t":"text"},{"k":"paid_up","l":"Paid-up capital (INR)","t":"number"},{"k":"reserves","l":"Free reserves (INR)","t":"number"},{"k":"intangibles","l":"Less: intangibles","t":"number"},{"k":"net_worth","l":"Net worth","t":"calc","f":"paid_up+reserves-intangibles"},{"k":"threshold","l":"Regulatory minimum","t":"number"}]}'
  UNION ALL SELECT 'FIN-09-RBI-ANNEXURE-II', 'RBI Annexure-II Return', 'per_rbi_cadence', 'CFO', 'Finance Manager',
         '{"fields":[{"k":"period","l":"Return period","t":"text"},{"k":"escrow_balance","l":"Escrow balance (INR)","t":"number"},{"k":"outstanding","l":"Outstanding to merchants (INR)","t":"number"},{"k":"cover_ratio","l":"Cover ratio","t":"calc","f":"escrow_balance/outstanding"},{"k":"filed_on","l":"Filed on","t":"date"}]}'
  UNION ALL SELECT 'FIN-10-KPI-KRI', 'Monthly KPI / KRI Data Sheet', 'monthly', 'CFO', 'Finance Manager',
         '{"table":{"cols":["Indicator","Target","Actual","RAG","Comment"]}}'
  UNION ALL SELECT 'GEN-11-INCIDENT-NC', 'Incident / Nonconformity Report', 'on_demand', 'CFO', 'Finance Manager',
         '{"fields":[{"k":"occurred_on","l":"Occurred on","t":"date"},{"k":"severity","l":"Severity","t":"select","o":["Critical","High","Medium","Low"]},{"k":"what_happened","l":"What happened","t":"textarea"},{"k":"root_cause","l":"Root cause","t":"textarea"},{"k":"immediate_action","l":"Immediate action","t":"textarea"}]}'
  UNION ALL SELECT 'GEN-12-CHANGE-REQUEST', 'Change Request', 'on_demand', 'CFO', 'Finance Manager',
         '{"fields":[{"k":"change_title","l":"Change","t":"text"},{"k":"reason","l":"Reason","t":"textarea"},{"k":"impact","l":"Impact assessment","t":"textarea"},{"k":"rollback","l":"Rollback plan","t":"textarea"}]}'
) t WHERE NOT EXISTS (SELECT 1 FROM `cfo_form_templates` x WHERE x.code = t.code);

/* ---------------- obligations ---------------- */
INSERT INTO `cfo_obligations` (`code`,`title`,`obligation_type`,`authority`,`cadence`,`due_day`,`owner_role`,`form_template_id`,`reference`)
SELECT t.* FROM (
  SELECT 'OB-RECON-D' code, 'Daily settlement reconciliation (T-1)' title, 'internal_review' ty, 'Internal' au, 'daily' cad, 1 dd, 'Settlement Ops Lead' own,
         (SELECT id FROM cfo_form_templates WHERE code='MC-01-MAKER-RECON') tpl, 'Internal control — daily recon' ref
  UNION ALL SELECT 'OB-CHECKER-D', "Checker's independent re-performance (T-1)", 'internal_review', 'Internal', 'daily', 1, 'Finance Manager',
         (SELECT id FROM cfo_form_templates WHERE code='MC-05-CHECKER-REPERFORMANCE'), 'Four-eyes control'
  UNION ALL SELECT 'OB-GSTR1-M', 'GST return GSTR-1 (monthly)', 'tax', 'GSTN', 'monthly', 11, 'Tax Lead', NULL, 'CGST Rules — Rule 59'
  UNION ALL SELECT 'OB-GSTR3B-M', 'GST return GSTR-3B (monthly)', 'tax', 'GSTN', 'monthly', 20, 'Tax Lead', NULL, 'CGST Rules — Rule 61'
  UNION ALL SELECT 'OB-TDS-M', 'TDS payment (monthly)', 'tax', 'Income Tax Dept', 'monthly', 7, 'Tax Lead', NULL, 'IT Act s.200'
  UNION ALL SELECT 'OB-PT-M', 'Professional Tax (monthly)', 'tax', 'State', 'monthly', 10, 'Finance Manager', NULL, 'State PT Act'
  UNION ALL SELECT 'OB-PF-M', 'Provident Fund (PF) remittance (monthly)', 'statutory', 'EPFO', 'monthly', 15, 'Finance Manager', NULL, 'EPF Act'
  UNION ALL SELECT 'OB-ESI-M', 'ESI remittance (monthly)', 'statutory', 'ESIC', 'monthly', 15, 'Finance Manager', NULL, 'ESI Act'
  UNION ALL SELECT 'OB-KPI-M', 'Monthly KPI/KRI capture (Review item M-01)', 'internal_review', 'Internal', 'monthly', 5, 'Finance Manager',
         (SELECT id FROM cfo_form_templates WHERE code='FIN-10-KPI-KRI'), 'Board pack input'
  UNION ALL SELECT 'OB-BREAK-M', 'Break register review (monthly)', 'internal_review', 'Internal', 'monthly', 7, 'Finance Manager',
         (SELECT id FROM cfo_form_templates WHERE code='MC-02-BREAK-REGISTER'), 'Internal control'
  UNION ALL SELECT 'OB-MCLOG-M', 'Maker-Checker log sign-off (monthly)', 'internal_review', 'Internal', 'monthly', 8, 'CFO',
         (SELECT id FROM cfo_form_templates WHERE code='MC-06-MC-LOG'), 'Internal control'
  UNION ALL SELECT 'OB-NETWORTH-Q', 'Quarterly net-worth certificate', 'rbi', 'RBI', 'quarterly', 30, 'CFO',
         (SELECT id FROM cfo_form_templates WHERE code='FIN-08-NETWORTH'), 'PA guidelines — net worth'
  UNION ALL SELECT 'OB-ANNEX2-Q', 'RBI Annexure-II return', 'rbi', 'RBI', 'per_rbi_cadence', 15, 'CFO',
         (SELECT id FROM cfo_form_templates WHERE code='FIN-09-RBI-ANNEXURE-II'), 'PA guidelines — escrow'
  UNION ALL SELECT 'OB-ESCROW-M', 'Escrow balance vs merchant liability check', 'rbi', 'RBI', 'monthly', 5, 'CFO', NULL, 'PA guidelines — escrow cover'
  UNION ALL SELECT 'OB-BOARD-Q', 'Board / Audit Committee compliance report', 'board', 'Board', 'quarterly', 20, 'CFO', NULL, 'Board charter'
  UNION ALL SELECT 'OB-ITR-Y', 'Income tax return (annual)', 'tax', 'Income Tax Dept', 'yearly', 31, 'Tax Lead', NULL, 'IT Act s.139'
) t WHERE NOT EXISTS (SELECT 1 FROM `cfo_obligations` x WHERE x.code = t.code);

/* ---------------- the 16 Finance Operations risks ---------------- */
INSERT INTO `cfo_risks` (`ref`,`sub_process`,`description`,`inherent_likelihood`,`inherent_impact`,`controls`,`residual_likelihood`,`residual_impact`,`owner_role`,`status`)
SELECT t.* FROM (
  SELECT 'FR-01' ref,'PG Settlement & Recon' sp,'Breach of RBI T+1 settlement timeline due to PG file delay, bank file mismatch or manual handoffs' d,3 il,4 ii,'Daily maker-checker recon; automated file ingestion alerts' c,2 rl,4 ri,'Finance Manager' o,'open' s
  UNION ALL SELECT 'FR-02','Escrow Management','Commingling of escrow funds with operating account, or movement from escrow without eligible purpose',2,5,'Segregated escrow account; dual authorisation on movements',1,5,'CFO','open'
  UNION ALL SELECT 'FR-03','Escrow Management','Escrow balance falls short of aggregate merchant liability at any cut-off',2,5,'Daily cover check; monthly Annexure-II reconciliation',1,4,'CFO','open'
  UNION ALL SELECT 'FR-04','Merchant Onboarding','Onboarding a prohibited or inadequately KYC-verified merchant',3,4,'KYC checklist; sanctions screening; four-eyes approval',2,3,'Compliance Lead','open'
  UNION ALL SELECT 'FR-05','Chargebacks & Disputes','Chargeback losses absorbed by the company due to late merchant recovery',3,3,'Chargeback register; recovery from settlement per agreement',2,3,'Finance Manager','open'
  UNION ALL SELECT 'FR-06','Reconciliation','Unresolved reconciliation breaks ageing beyond policy, masking real loss',4,3,'Break register with ageing; monthly review by CFO',2,3,'Finance Manager','open'
  UNION ALL SELECT 'FR-07','Tax Compliance','Late or incorrect GST / TDS filing attracting interest and penalty',3,3,'Compliance calendar with reminders; maker-checker on returns',2,2,'Tax Lead','open'
  UNION ALL SELECT 'FR-08','Payouts & Vendor Payments','Fraudulent or duplicate vendor payout through compromised master data',2,4,'Vendor master change control; payment approval matrix',1,4,'Finance Manager','open'
  UNION ALL SELECT 'FR-09','Access & Segregation','Excessive or stale access to finance systems breaking segregation of duties',3,4,'Quarterly access review; role-based access; joiner-leaver process',2,3,'IT Manager','open'
  UNION ALL SELECT 'FR-10','Financial Reporting','Misstatement in management accounts due to unposted or wrongly classified entries',3,3,'Month-end close checklist; review of journals above threshold',2,2,'Finance Manager','open'
  UNION ALL SELECT 'FR-11','Regulatory Reporting','Incorrect or late RBI return (Annexure-II, net worth) submission',2,4,'Return calendar; CFO sign-off before filing',1,4,'CFO','open'
  UNION ALL SELECT 'FR-12','Net Worth & Capital','Net worth falling below the regulatory minimum for the licence',2,5,'Quarterly net-worth working; board-level capital monitoring',1,5,'CFO','open'
  UNION ALL SELECT 'FR-13','Business Continuity','Loss of settlement capability from bank/PG outage without a tested fallback',2,4,'Documented BCP; secondary bank rail; annual DR test',2,3,'IT Manager','open'
  UNION ALL SELECT 'FR-14','Data Privacy','Exposure of merchant or customer personal data from finance extracts',2,4,'Masked extracts; access logging; retention policy',1,3,'Compliance Lead','open'
  UNION ALL SELECT 'FR-15','Third-party / Outsourcing','Outsourced processing partner failing controls without visibility',3,3,'Annual partner due diligence; SLA-backed contracts',2,3,'CFO','open'
  UNION ALL SELECT 'FR-16','Fraud & Whistleblowing','Internal fraud in settlement or refunds going undetected',2,4,'Surprise re-performance; whistleblower channel; anomaly review',1,3,'CFO','open'
) t WHERE NOT EXISTS (SELECT 1 FROM `cfo_risks` x WHERE x.ref = t.ref);

/* ---------------- KPI / KRI with RAG thresholds ---------------- */
INSERT INTO `cfo_kri` (`code`,`name`,`indicator_kind`,`unit`,`direction`,`target_value`,`green_from`,`amber_from`,`frequency`,`owner_role`)
SELECT t.* FROM (
  SELECT 'KRI-01' code,'Settlements completed within T+1' n,'kpi' k,'pct' u,'higher_better' dir,99.50 tgt,99.00 g,97.00 a,'daily' f,'Settlement Ops Lead' o
  UNION ALL SELECT 'KRI-02','Reconciliation breaks open > 7 days','kri','count','lower_better',0,0,5,'daily','Finance Manager'
  UNION ALL SELECT 'KRI-03','Escrow cover ratio','kri','pct','higher_better',105.00,102.00,100.00,'daily','CFO'
  UNION ALL SELECT 'KRI-04','Statutory filings on time','kpi','pct','higher_better',100.00,100.00,95.00,'monthly','Tax Lead'
  UNION ALL SELECT 'KRI-05','Chargeback ratio','kri','pct','lower_better',0.50,0.50,0.90,'monthly','Finance Manager'
  UNION ALL SELECT 'KRI-06','Open CAPA beyond due date','kri','count','lower_better',0,0,3,'monthly','CFO'
  UNION ALL SELECT 'KRI-07','Access review completion','kpi','pct','higher_better',100.00,100.00,90.00,'quarterly','IT Manager'
  UNION ALL SELECT 'KRI-08','Month-end close (working days)','kpi','days','lower_better',5,5,7,'monthly','Finance Manager'
) t WHERE NOT EXISTS (SELECT 1 FROM `cfo_kri` x WHERE x.code = t.code);

/* ---------------- review cycles: 12 monthly + 4 quarterly ---------------- */
INSERT INTO `cfo_reviews` (`period`,`review_type`,`scheduled_on`,`status`,`agenda`)
SELECT t.period, t.rt, t.sd, 'scheduled',
       IF(t.rt='quarterly',
          'Risk register review · KRI trend · open CAPA · regulatory filings · net worth',
          'Recon breaks · KPI/KRI capture · statutory calendar · exceptions raised')
  FROM (
    SELECT DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL n MONTH), '%b %Y') period, 'monthly' rt,
           DATE_ADD(DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL n MONTH), '%Y-%m-01'), INTERVAL 9 DAY) sd
      FROM (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
            UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8
            UNION ALL SELECT 9 UNION ALL SELECT 10 UNION ALL SELECT 11) m
  ) t
 WHERE NOT EXISTS (SELECT 1 FROM `cfo_reviews` x WHERE x.period = t.period AND x.review_type = t.rt);

INSERT INTO `cfo_reviews` (`period`,`review_type`,`scheduled_on`,`status`,`agenda`)
SELECT t.period, 'quarterly', t.sd, 'scheduled',
       'Risk register review · KRI trend · open CAPA · regulatory filings · net worth'
  FROM (
    SELECT CONCAT('Q', QUARTER(DATE_ADD(CURDATE(), INTERVAL q*3 MONTH)), ' FY',
                  IF(MONTH(DATE_ADD(CURDATE(), INTERVAL q*3 MONTH)) >= 4,
                     YEAR(DATE_ADD(CURDATE(), INTERVAL q*3 MONTH)), YEAR(DATE_ADD(CURDATE(), INTERVAL q*3 MONTH))-1),
                  '-', RIGHT(IF(MONTH(DATE_ADD(CURDATE(), INTERVAL q*3 MONTH)) >= 4,
                     YEAR(DATE_ADD(CURDATE(), INTERVAL q*3 MONTH))+1, YEAR(DATE_ADD(CURDATE(), INTERVAL q*3 MONTH))), 2)) period,
           DATE_ADD(DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL q*3 MONTH), '%Y-%m-01'), INTERVAL 19 DAY) sd
      FROM (SELECT 0 q UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3) x
  ) t
 WHERE NOT EXISTS (SELECT 1 FROM `cfo_reviews` y WHERE y.period = t.period AND y.review_type = 'quarterly');
