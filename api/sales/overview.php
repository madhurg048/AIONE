<?php
/**
 * Executive overview — a high-level snapshot of the system in one call
 * (leads → pipeline → merchants → incentives → transactions → tickets → timesheets).
 * Row-level scoped: admins/managers see everything; a rep sees only their own figures.
 *   GET overview.php
 */
require_once __DIR__ . '/_bootstrap.php';
require_login();
$db = db();
$q  = fn($s) => (function () use ($db, $s) { try { return $db->query($s); } catch (Throwable $e) { return null; } })();
$one = fn($s, $d = 0) => ($r = $q($s)) ? $r->fetchColumn() : $d;

// scope fragments (evaluate to "1" for admin/manager)
$L  = owner_scope_inline('owner_id');            // leads / merchants (both have owner_id)
$D  = owner_scope_inline('d.owner_id');           // deals (aliased d)
$E  = owner_scope_inline('employee_id');          // incentive_ledger / timesheets
$scoped = visible_owner_ids() !== null;
$MSUB = $scoped ? "merchant_id IN (SELECT id FROM merchants WHERE " . owner_scope_inline('owner_id') . ")" : '1';

$funnel = [
    'leads'      => (int)$one("SELECT COUNT(*) FROM leads WHERE $L"),
    'qualified'  => (int)$one("SELECT COUNT(*) FROM leads WHERE lead_status IN('qualified','converted') AND $L"),
    'converted'  => (int)$one("SELECT COUNT(*) FROM leads WHERE is_converted=1 AND $L"),
    'open_deals' => (int)$one("SELECT COUNT(*) FROM deals d JOIN deal_stages s ON s.id=d.stage_id WHERE s.stage_type='open' AND $D"),
    'won'        => (int)$one("SELECT COUNT(*) FROM deals d JOIN deal_stages s ON s.id=d.stage_id WHERE s.stage_type='won' AND $D"),
    'merchants'  => (int)$one("SELECT COUNT(*) FROM merchants WHERE onboarding_stage='activated' AND $L"),
];

$out = [
    'funnel' => $funnel,
    'conversion_rate' => $funnel['leads'] ? round($funnel['converted'] * 100 / $funnel['leads'], 1) : 0,
    'leads' => [
        'total'        => $funnel['leads'],
        'new_month'    => (int)$one("SELECT COUNT(*) FROM leads WHERE DATE_FORMAT(created_at,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m') AND $L"),
        'due_followups'=> (int)$one("SELECT COUNT(*) FROM leads WHERE next_followup_at IS NOT NULL AND next_followup_at<=NOW() AND is_converted=0 AND $L"),
    ],
    'pipeline' => [
        'open_count' => $funnel['open_deals'],
        'open_value' => (float)$one("SELECT COALESCE(SUM(d.amount),0) FROM deals d JOIN deal_stages s ON s.id=d.stage_id WHERE s.stage_type='open' AND $D"),
        'won_value'  => (float)$one("SELECT COALESCE(SUM(d.amount),0) FROM deals d JOIN deal_stages s ON s.id=d.stage_id WHERE s.stage_type='won' AND $D"),
    ],
    'merchants' => [
        'total'          => (int)$one("SELECT COUNT(*) FROM merchants WHERE $L"),
        'activated'      => $funnel['merchants'],
        'kyc_pending'    => (int)$one("SELECT COUNT(*) FROM merchants WHERE kyc_status IN('pending','partial') AND $L"),
        'volume_expected'=> (float)$one("SELECT COALESCE(SUM(expected_monthly_volume),0) FROM merchants WHERE onboarding_stage='activated' AND $L"),
        'volume_actual'  => (float)$one("SELECT COALESCE(SUM(actual_monthly_volume),0) FROM merchants WHERE onboarding_stage='activated' AND $L"),
    ],
    'incentives' => [
        'total_booked'    => (float)$one("SELECT COALESCE(SUM(amount),0) FROM incentive_ledger WHERE $E"),
        'this_month'      => (float)$one("SELECT COALESCE(SUM(amount),0) FROM incentive_ledger WHERE period_month=DATE_FORMAT(CURDATE(),'%Y-%m') AND $E"),
        'pending_approval'=> (float)$one("SELECT COALESCE(SUM(amount),0) FROM incentive_ledger WHERE status='accrued' AND $E"),
        'paid'            => (float)$one("SELECT COALESCE(SUM(amount),0) FROM incentive_ledger WHERE status='paid' AND $E"),
    ],
    'transactions' => [
        'today_count' => (int)$one("SELECT COUNT(*) FROM merchant_transactions WHERE DATE(txn_date)=CURDATE() AND status='success' AND $MSUB"),
        'today_value' => (float)$one("SELECT COALESCE(SUM(amount),0) FROM merchant_transactions WHERE DATE(txn_date)=CURDATE() AND status='success' AND $MSUB"),
        'month_value' => (float)$one("SELECT COALESCE(SUM(amount),0) FROM merchant_transactions WHERE DATE_FORMAT(txn_date,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m') AND status='success' AND $MSUB"),
    ],
    'tickets' => [
        'total'      => (int)$one("SELECT COUNT(*) FROM crm_tickets WHERE $MSUB"),
        'open'       => (int)$one("SELECT COUNT(*) FROM crm_tickets WHERE (status NOT IN('closed','resolved') OR status IS NULL) AND $MSUB"),
        'waiting_us' => (int)$one("SELECT COUNT(*) FROM crm_tickets WHERE waiting_on='us' AND $MSUB"),
    ],
    'timesheet' => [
        'pending'     => (int)$one("SELECT COUNT(*) FROM timesheets WHERE status='submitted' AND $E"),
        'hours_today' => (float)$one("SELECT COALESCE(SUM(hours),0) FROM timesheets WHERE entry_date=CURDATE() AND $E"),
    ],
    'automation' => [
        'rules' => (int)$one("SELECT COUNT(*) FROM automation_rules WHERE is_active=1"),
        'runs'  => (int)$one("SELECT COALESCE(SUM(run_count),0) FROM automation_rules"),
    ],
];

// top agents (scoped: a rep only sees themselves)
$scE = owner_scope_inline('e.id');
if ($r = $q("SELECT e.full_name,
        (SELECT COUNT(*) FROM merchants m WHERE m.owner_id=e.id AND m.onboarding_stage='activated') merchants,
        (SELECT COALESCE(SUM(amount),0) FROM incentive_ledger il WHERE il.employee_id=e.id) incentive
      FROM employees e WHERE $scE HAVING merchants>0 OR incentive>0 ORDER BY merchants DESC, incentive DESC LIMIT 6")) {
    $out['top_agents'] = $r->fetchAll();
} else $out['top_agents'] = [];

$out['recent_transactions'] = ($r = $q("SELECT t.txn_ref,t.amount,t.status,t.txn_date,m.legal_name
    FROM merchant_transactions t LEFT JOIN merchants m ON m.id=t.merchant_id WHERE $MSUB ORDER BY t.id DESC LIMIT 6")) ? $r->fetchAll() : [];
$out['recent_tickets'] = ($r = $q("SELECT ticket_ref,subject,status,assigned_to_name,waiting_on FROM crm_tickets WHERE $MSUB ORDER BY updated_at DESC LIMIT 6")) ? $r->fetchAll() : [];

$out['monthly'] = ($r = $q("
    SELECT m.ym,
      (SELECT COUNT(*) FROM leads l WHERE DATE_FORMAT(l.created_at,'%Y-%m')=m.ym AND " . owner_scope_inline('l.owner_id') . ") leads,
      (SELECT COUNT(*) FROM merchants x WHERE DATE_FORMAT(x.activated_at,'%Y-%m')=m.ym AND " . owner_scope_inline('x.owner_id') . ") activated
    FROM (SELECT DATE_FORMAT(CURDATE()-INTERVAL n MONTH,'%Y-%m') ym
          FROM (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5) t) m
    ORDER BY m.ym")) ? $r->fetchAll() : [];

ok($out);
