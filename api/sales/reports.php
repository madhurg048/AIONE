<?php
/**
 * Reports/Analytics aggregates for sales-reports.php. All from live data.
 *   GET reports.php  -> { funnel, monthly, source_roi, stage_value, cycle, agents, win_rate }
 */
require_once __DIR__ . '/_bootstrap.php';
require_login();
/* Company-wide analytics (agent performance, incentive totals, source ROI) — not
   scoped per-record like leads/deals. Gated via role_access ('reports' module_key),
   which already seeds admin/manager/support_manager=1 and sales_exec/service_exec=0. */
require_module('reports');
$db = db();
$q = fn($s) => $db->query($s);

// Row scope: a dept head/admin sees everything (fragments become '1'); a dept manager is
// restricted to their own + their reporting-line's records (owner_scope_inline -> IN (...)).
$S_lead  = owner_scope_inline('owner_id');       // leads (unaliased)
$S_leadL = owner_scope_inline('l.owner_id');     // leads l
$S_deal  = owner_scope_inline('d.owner_id');     // deals d
$S_merch = owner_scope_inline('m.owner_id');     // merchants m
$S_merU  = owner_scope_inline('owner_id');       // merchants (unaliased)
$S_emp   = owner_scope_inline('e.id');           // employees e (leaderboards)
$S_ledg  = owner_scope_inline('employee_id');    // incentive_ledger / timesheets

$out = [];

/* Funnel counts (for a funnel chart) */
$out['funnel'] = [
    'leads'      => (int)$q("SELECT COUNT(*) FROM leads WHERE $S_lead")->fetchColumn(),
    'qualified'  => (int)$q("SELECT COUNT(*) FROM leads WHERE lead_status IN('qualified','converted') AND $S_lead")->fetchColumn(),
    'deals'      => (int)$q("SELECT COUNT(*) FROM deals d WHERE $S_deal")->fetchColumn(),
    'won'        => (int)$q("SELECT COUNT(*) FROM deals d JOIN deal_stages s ON s.id=d.stage_id WHERE s.stage_type='won' AND $S_deal")->fetchColumn(),
    'activated'  => (int)$q("SELECT COUNT(*) FROM merchants WHERE onboarding_stage='activated' AND $S_merU")->fetchColumn(),
];

/* Monthly trend (last 6 months): new leads, activated merchants, incentive cost */
$out['monthly'] = $q("
    SELECT m.ym,
      (SELECT COUNT(*) FROM leads     l WHERE DATE_FORMAT(l.created_at,'%Y-%m')=m.ym AND $S_leadL) AS leads,
      (SELECT COUNT(*) FROM merchants x WHERE DATE_FORMAT(x.activated_at,'%Y-%m')=m.ym AND " . owner_scope_inline('x.owner_id') . ") AS activated,
      (SELECT COALESCE(SUM(amount),0) FROM incentive_ledger il WHERE il.period_month=m.ym AND " . owner_scope_inline('il.employee_id') . ") AS incentive
    FROM (
      SELECT DATE_FORMAT(CURDATE()-INTERVAL n MONTH,'%Y-%m') ym
      FROM (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5) t
    ) m ORDER BY m.ym
")->fetchAll();

/* Source ROI: leads -> activated merchants, attributed via the lead→deal→merchant chain
   (merchant.source_lead_id may be null on older rows, so we walk converted_deal_id → deal → merchant) */
// Each activated merchant is attributed to EXACTLY ONE source lead (source_lead_id first,
// else the lead that owns its deal) so activations are not double-counted across sources.
$out['source_roi'] = $q("
    SELECT s.source,
           COALESCE(ls.leads,0)     AS leads,
           COALESCE(ms.activated,0) AS activated
    FROM (SELECT DISTINCT COALESCE(lead_source,'unknown') AS source FROM leads WHERE $S_lead) s
    LEFT JOIN (
        SELECT COALESCE(lead_source,'unknown') AS source, COUNT(*) AS leads
        FROM leads WHERE $S_lead GROUP BY 1
    ) ls ON ls.source = s.source
    LEFT JOIN (
        SELECT src AS source, COUNT(*) AS activated FROM (
            SELECT m.id, COALESCE(MIN(sl.lead_source), MIN(dl.lead_source), 'unknown') AS src
            FROM merchants m
            LEFT JOIN leads sl ON sl.id = m.source_lead_id
            LEFT JOIN leads dl ON dl.converted_deal_id = m.deal_id
            WHERE m.onboarding_stage='activated' AND $S_merch
            GROUP BY m.id
        ) mm GROUP BY src
    ) ms ON ms.source = s.source
    ORDER BY leads DESC LIMIT 10
")->fetchAll();

/* Pipeline value by stage */
$out['stage_value'] = $q("
    SELECT st.name, st.stage_type, COUNT(d.id) c, COALESCE(SUM(d.amount),0) value
    FROM deal_stages st LEFT JOIN deals d ON d.stage_id=st.id AND $S_deal
    GROUP BY st.id ORDER BY st.sort_order
")->fetchAll();

/* Sales cycle: avg days lead.created_at -> merchant.activated_at */
$out['cycle'] = [
    'avg_days_to_activate' => round((float)$q("
        SELECT COALESCE(AVG(DATEDIFF(m.activated_at, l.created_at)),0)
        FROM merchants m JOIN leads l ON l.id=m.source_lead_id
        WHERE m.activated_at IS NOT NULL AND $S_merch")->fetchColumn(), 1),
    'avg_kyc_days' => round((float)$q("
        SELECT COALESCE(AVG(DATEDIFF(activated_at, created_at)),0)
        FROM merchants WHERE activated_at IS NOT NULL AND $S_merU")->fetchColumn(), 1),
];

/* Win rate (won vs lost, closed) */
$won  = (int)$q("SELECT COUNT(*) FROM deals d JOIN deal_stages s ON s.id=d.stage_id WHERE s.stage_type='won' AND $S_deal")->fetchColumn();
$lost = (int)$q("SELECT COUNT(*) FROM deals d JOIN deal_stages s ON s.id=d.stage_id WHERE s.stage_type='lost' AND $S_deal")->fetchColumn();
$out['win_rate'] = ['won'=>$won, 'lost'=>$lost, 'rate_pct'=>($won+$lost)?round($won*100/($won+$lost),1):0];

/* Agent performance */
// merchants/incentive via correlated subqueries (no leads×merchants cross-join fan-out)
$out['agents'] = $q("
    SELECT e.full_name,
      COUNT(DISTINCT l.id) leads,
      (SELECT COUNT(*) FROM merchants mr WHERE mr.owner_id=e.id AND mr.onboarding_stage='activated') merchants,
      COALESCE((SELECT SUM(amount) FROM incentive_ledger il WHERE il.employee_id=e.id),0) incentive
    FROM employees e
    LEFT JOIN leads l ON l.owner_id=e.id
    WHERE $S_emp
    GROUP BY e.id HAVING leads>0 OR merchants>0
    ORDER BY merchants DESC LIMIT 12
")->fetchAll();

/* ---- NEW analytics (data-driven) ---- */

/* GMV & fee-income leaderboard from live transactions (each merchant → one owner, no fan-out) */
$out['gmv_leaderboard'] = $q("
    SELECT e.full_name,
       COALESCE(SUM(t.amount),0) AS gmv,
       COALESCE(SUM(t.amount*COALESCE(m.mdr_percent,0)/100),0) AS fee,
       COUNT(DISTINCT t.merchant_id) AS active_merchants
    FROM employees e
    JOIN merchants m ON m.owner_id=e.id
    JOIN merchant_transactions t ON t.merchant_id=m.id AND t.status='success'
    WHERE $S_emp
    GROUP BY e.id HAVING gmv>0 ORDER BY gmv DESC LIMIT 12")->fetchAll();

/* Dormant merchants: activated but no successful transaction in 30+ days (churn risk) */
$out['dormant'] = $q("
    SELECT m.merchant_code, m.legal_name, e.full_name AS owner,
       m.last_txn_at, DATEDIFF(NOW(), COALESCE(m.last_txn_at, m.activated_at)) AS days_idle
    FROM merchants m LEFT JOIN employees e ON e.id=m.owner_id
    WHERE m.onboarding_stage='activated' AND $S_merch
      AND (m.last_txn_at IS NULL OR m.last_txn_at < NOW() - INTERVAL 30 DAY)
    ORDER BY days_idle DESC LIMIT 15")->fetchAll();

/* Timesheet compliance for the current week */
$tc = $q("SELECT
      COALESCE(SUM(status='submitted'),0) submitted,
      COALESCE(SUM(status='approved'),0) approved,
      COALESCE(SUM(status='draft'),0) draft,
      COUNT(DISTINCT employee_id) contributors
    FROM timesheets WHERE entry_date >= CURDATE() - INTERVAL WEEKDAY(CURDATE()) DAY AND $S_ledg")->fetch();
$out['timesheet_compliance'] = $tc ?: [];

/* Incentive liability = unpaid booked incentive (accrued + approved) */
$out['incentive_liability'] = [
   'accrued'  => (float)$q("SELECT COALESCE(SUM(amount),0) FROM incentive_ledger WHERE status='accrued' AND $S_ledg")->fetchColumn(),
   'approved' => (float)$q("SELECT COALESCE(SUM(amount),0) FROM incentive_ledger WHERE status='approved' AND $S_ledg")->fetchColumn(),
];

ok($out);
