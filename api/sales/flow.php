<?php
/**
 * End-to-end conversion flow: Lead → Conversion (incentives) → Merchant (PIMS).
 * Stage counts, stage-to-stage conversion %, and useful metrics per phase.
 * Row-level scoped (admins/managers = all; a rep = their own).
 *   GET flow.php
 */
require_once __DIR__ . '/_bootstrap.php';
require_login();
$db = db();
$one = function ($sql) use ($db) { try { return $db->query($sql)->fetchColumn(); } catch (Throwable $e) { return 0; } };

$L  = owner_scope_inline('owner_id');       // leads / merchants
$Lm = owner_scope_inline('m.owner_id');      // merchants (aliased m)
$D  = owner_scope_inline('d.owner_id');      // deals
$E  = owner_scope_inline('employee_id');     // incentive_ledger

// ---- stage counts (the pipeline) ----
$leads     = (int)$one("SELECT COUNT(*) FROM leads WHERE $L");
// qualified must be a SUPERSET of converted (a converted lead is, by definition, qualified) so
// stage-to-stage conversion can never exceed 100%.
$qualified = (int)$one("SELECT COUNT(*) FROM leads WHERE (lead_status IN('qualified','converted') OR is_converted=1) AND $L");
$converted = (int)$one("SELECT COUNT(*) FROM leads WHERE is_converted=1 AND $L");
$deals     = (int)$one("SELECT COUNT(*) FROM deals d WHERE $D");
$won       = (int)$one("SELECT COUNT(*) FROM deals d JOIN deal_stages s ON s.id=d.stage_id WHERE s.stage_type='won' AND $D");
$lost      = (int)$one("SELECT COUNT(*) FROM deals d JOIN deal_stages s ON s.id=d.stage_id WHERE s.stage_type='lost' AND $D");
$merch     = (int)$one("SELECT COUNT(*) FROM merchants WHERE $L");
$activated = (int)$one("SELECT COUNT(*) FROM merchants WHERE onboarding_stage='activated' AND $L");

$pct = fn($n, $d) => $d > 0 ? round($n * 100 / $d, 1) : 0;

// ---- the flow: 6 stages grouped into 3 phases, each with conversion-from-previous ----
$stages = [
    ['key'=>'leads',     'label'=>'Leads',       'phase'=>'lead',       'count'=>$leads,     'conv'=>null],
    ['key'=>'qualified', 'label'=>'Qualified',   'phase'=>'lead',       'count'=>$qualified, 'conv'=>$pct($qualified,$leads)],
    ['key'=>'converted', 'label'=>'Converted',   'phase'=>'conversion', 'count'=>$converted, 'conv'=>$pct($converted,$qualified)],
    ['key'=>'won',       'label'=>'Deals Won',   'phase'=>'conversion', 'count'=>$won,       'conv'=>$pct($won,$converted)],
    ['key'=>'merchants', 'label'=>'Merchants',   'phase'=>'merchant',   'count'=>$merch,     'conv'=>$pct($merch,$won)],
    ['key'=>'activated', 'label'=>'Activated',   'phase'=>'merchant',   'count'=>$activated, 'conv'=>$pct($activated,$merch)],
];

// ---- per-phase metrics ----
$wonValue   = (float)$one("SELECT COALESCE(SUM(d.amount),0) FROM deals d JOIN deal_stages s ON s.id=d.stage_id WHERE s.stage_type='won' AND $D");
$openValue  = (float)$one("SELECT COALESCE(SUM(d.amount),0) FROM deals d JOIN deal_stages s ON s.id=d.stage_id WHERE s.stage_type='open' AND $D");
$incAccrued = (float)$one("SELECT COALESCE(SUM(amount),0) FROM incentive_ledger WHERE status='accrued' AND $E");
$incApprov  = (float)$one("SELECT COALESCE(SUM(amount),0) FROM incentive_ledger WHERE status='approved' AND $E");
$incPaid    = (float)$one("SELECT COALESCE(SUM(amount),0) FROM incentive_ledger WHERE status='paid' AND $E");
$incTotal   = $incAccrued + $incApprov + $incPaid;
$volExpected= (float)$one("SELECT COALESCE(SUM(expected_monthly_volume),0) FROM merchants WHERE onboarding_stage='activated' AND $L");
$volActual  = (float)$one("SELECT COALESCE(SUM(actual_monthly_volume),0) FROM merchants WHERE onboarding_stage='activated' AND $L");
$kycPending = (int)$one("SELECT COUNT(*) FROM merchants WHERE kyc_status IN('pending','partial') AND $L");

// cycle times (avg days) — best-effort
$daysToConvert  = (float)$one("SELECT COALESCE(AVG(DATEDIFF(converted_at,created_at)),0) FROM leads WHERE is_converted=1 AND converted_at IS NOT NULL AND $L");
$daysToActivate = (float)$one("SELECT COALESCE(AVG(DATEDIFF(activated_at,created_at)),0) FROM merchants WHERE activated_at IS NOT NULL AND $L");

// Incentive/payout amounts are management-only — a rep is shown their funnel, not their pay.
$seeInc = can_manage_incentives();
$convMetrics = [
    ['Deals', $deals], ['Won / Lost', $won . ' / ' . $lost],
    ['Win rate', $pct($won, $won + $lost) . '%'], ['Avg deal size', $won ? round($wonValue / $won) : 0, 'money'],
    ['Open pipeline', $openValue, 'money'],
];
if ($seeInc) $convMetrics[] = ['Incentive booked', $incTotal, 'money'];
$merchMetrics = [
    ['Merchants', $merch], ['Activated', $activated . ' (' . $pct($activated,$merch) . '%)'],
    ['KYC pending', $kycPending], ['Avg days to activate', round($daysToActivate,1)],
    ['Live volume/mo', $volActual ?: $volExpected, 'money'],
];
if ($seeInc) $merchMetrics[] = ['Incentive / merchant', $activated ? round($incTotal / $activated) : 0, 'money'];

$phases = [
    'lead' => [
        'label' => 'Lead — acquisition & qualification', 'icon' => 'fa-user-plus', 'color' => 'indigo',
        'metrics' => [
            ['Total leads', $leads], ['Qualified', $qualified . ' (' . $pct($qualified,$leads) . '%)'],
            ['Converted', $converted], ['Avg days to convert', round($daysToConvert,1)],
        ],
    ],
    'conversion' => [
        'label' => $seeInc ? 'Conversion — deals & incentives' : 'Conversion — deals',
        'icon' => 'fa-handshake', 'color' => 'amber', 'metrics' => $convMetrics,
    ],
    'merchant' => [
        'label' => 'Merchant — PIMS onboarding & volume', 'icon' => 'fa-store', 'color' => 'green',
        'metrics' => $merchMetrics,
    ],
];

// source ROI: leads -> activated per source
$sourceRoi = [];
try {
    // each activated merchant attributed to exactly one source (no OR-join fan-out)
    $sourceRoi = $db->query("
        SELECT s.source, COALESCE(ls.leads,0) AS leads, COALESCE(ms.activated,0) AS activated
        FROM (SELECT DISTINCT COALESCE(lead_source,'unknown') AS source FROM leads WHERE $L) s
        LEFT JOIN (SELECT COALESCE(lead_source,'unknown') AS source, COUNT(*) AS leads FROM leads WHERE $L GROUP BY 1) ls
               ON ls.source = s.source
        LEFT JOIN (
            SELECT src AS source, COUNT(*) AS activated FROM (
                SELECT m.id, COALESCE(MIN(sl.lead_source), MIN(dl.lead_source), 'unknown') AS src
                FROM merchants m
                LEFT JOIN leads sl ON sl.id = m.source_lead_id
                LEFT JOIN leads dl ON dl.converted_deal_id = m.deal_id
                WHERE m.onboarding_stage='activated' AND $Lm
                GROUP BY m.id
            ) mm GROUP BY src
        ) ms ON ms.source = s.source
        ORDER BY leads DESC LIMIT 10")->fetchAll();
} catch (Throwable $e) {}

ok([
    'stages' => $stages,
    'phases' => $phases,
    'overall_conversion' => $pct($activated, $leads),   // lead -> live merchant
    'incentive' => $seeInc ? ['accrued'=>$incAccrued, 'approved'=>$incApprov, 'paid'=>$incPaid, 'total'=>$incTotal] : null,
    'volume' => ['expected'=>$volExpected, 'actual'=>$volActual],
    'source_roi' => $sourceRoi,
]);
