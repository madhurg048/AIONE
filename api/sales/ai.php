<?php
/**
 * Sales AI — agentic layer over the existing processing service (/chat_v3).
 * Reuses SERVICE_CHAT_ENDPOINT + serviceIsUp() so it shares the same brain as
 * the support side. Results are cached in ai_insights to avoid re-calling.
 *
 * POST ai.php { action, ... }
 *   action=score_lead        { lead_id }        -> {score, band, reasons[], next_best_action}
 *   action=next_best_action  { deal_id }        -> {actions[], risk, talk_track}
 *   action=coach_agent       { employee_id }    -> {strengths[], gaps[], coaching[]}
 *   action=funnel_insights   {}                 -> {headline, risks[], recommendations[]}
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../service-health.php';
require_login();
// Release the session lock before the slow LLM call so it doesn't block other page requests.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$db = db();

if (method() !== 'POST') fail('POST required', 405);
$b = body();
$action = $b['action'] ?? '';

/** Call the agentic LLM service and return decoded JSON (best-effort). */
require_once __DIR__ . '/_ai.php';
function ai_call(string $prompt)
{
    // routes box -> cloud -> local (see _ai.php)
    $r = crm_ai_call($prompt, 'medium', 'chat_v3');
    if (!$r) return ['_down' => true];
    $clean = trim(preg_replace('/```(json)?/i', '', $r['response']));
    $parsed = json_decode($clean, true);
    if (json_last_error() !== JSON_ERROR_NONE && preg_match('/\{[\s\S]*\}/u', $clean, $m)) {
        $parsed = json_decode($m[0], true);
    }
    return (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) ? $parsed : ['raw' => $clean];
}

function cache_insight(PDO $db, string $type, ?int $id, string $kind, $payload, ?int $score = null): void
{
    $db->prepare("INSERT INTO ai_insights (entity_type,entity_id,insight_type,payload,score)
                  VALUES (?,?,?,?,?)")
       ->execute([$type, $id, $kind, json_encode($payload), $score]);
}

/* -------------------- SCORE LEAD -------------------- */
if ($action === 'score_lead') {
    $id = (int)($b['lead_id'] ?? 0);
    $s = $db->prepare("SELECT * FROM leads WHERE id=?"); $s->execute([$id]);
    $lead = $s->fetch();
    if (!$lead) fail('Lead not found', 404);
    // Team-wide within a vertical, same as leads.php/followups.php — any exec can AI-score
    // any lead in their vertical, not just their own.
    assert_can_see_vertical($lead['vertical']);

    $prompt = 'Respond with ONLY raw JSON, no markdown/prose.
You are a B2B sales qualification AI for a payment-gateway company acquiring merchants in India.
Score this lead 0-100 for likelihood to become an activated merchant, and recommend the single next best action.
Consider: company presence, industry fit for online payments, contactability, stated need, source quality.

Lead:
- Name: ' . trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? '')) . '
- Company: ' . ($lead['company'] ?? 'N/A') . '
- Title: ' . ($lead['title'] ?? 'N/A') . '
- Industry: ' . ($lead['industry'] ?? 'N/A') . '
- Source: ' . ($lead['lead_source'] ?? 'N/A') . '
- Email: ' . ($lead['email'] ?? 'N/A') . ' / Phone: ' . ($lead['phone'] ?? 'N/A') . '
- Notes: ' . ($lead['description'] ?? 'none') . '

Return exactly:
{"score": <0-100 int>, "band": "hot|warm|cold", "reasons": ["..."], "next_best_action": "one concrete step"}';

    $res = ai_call($prompt);
    if (!empty($res['_down'])) fail('AI service unavailable', 503);

    $score = isset($res['score']) ? (int)$res['score'] : null;
    $band  = $res['band'] ?? ($score >= 70 ? 'hot' : ($score >= 40 ? 'warm' : 'cold'));
    // Persist score onto the lead so metrics/sorting can use it
    if ($score !== null) {
        $db->prepare("UPDATE leads SET score=?, rating=? WHERE id=?")->execute([$score, $band, $id]);
    }
    cache_insight($db, 'lead', $id, 'score', $res, $score);
    ok($res);
}

/* -------------------- NEXT BEST ACTION (deal) -------------------- */
if ($action === 'next_best_action') {
    $id = (int)($b['deal_id'] ?? 0);
    $s = $db->prepare("SELECT d.*, a.account_name, st.name AS stage_name
                       FROM deals d LEFT JOIN accounts a ON a.id=d.account_id
                       LEFT JOIN deal_stages st ON st.id=d.stage_id WHERE d.id=?");
    $s->execute([$id]); $deal = $s->fetch();
    if (!$deal) fail('Deal not found', 404);
    assert_can_see($deal['owner_id']);

    $prompt = 'Respond with ONLY raw JSON.
You are a sales coach AI for a payment-gateway merchant-acquisition deal.
Given the deal, propose the next best actions to move it toward "Closed Won" (merchant activation).

Deal: ' . $deal['deal_name'] . ' | Account: ' . ($deal['account_name'] ?? 'N/A') .
' | Stage: ' . ($deal['stage_name'] ?? 'N/A') . ' | Amount: ' . $deal['amount'] .
' | Probability: ' . $deal['probability'] . '% | Close: ' . ($deal['expected_close_date'] ?? 'N/A') . '

Return: {"actions":["step1","step2","step3"],"risk":"main risk","talk_track":"one persuasive line to use with the merchant"}';

    $res = ai_call($prompt);
    if (!empty($res['_down'])) fail('AI service unavailable', 503);
    cache_insight($db, 'deal', $id, 'next_best_action', $res);
    ok($res);
}

/* -------------------- COACH AGENT -------------------- */
if ($action === 'coach_agent') {
    $id = (int)($b['employee_id'] ?? 0);
    if (!can_manage_all_records() && $id !== current_employee_id()) fail('Not authorized to coach this employee', 403);
    $st = $db->prepare("SELECT
            (SELECT COUNT(*) FROM leads WHERE owner_id=e.id) AS leads,
            (SELECT COUNT(*) FROM leads WHERE owner_id=e.id AND is_converted=1) AS converted,
            (SELECT COUNT(*) FROM merchants WHERE owner_id=e.id AND onboarding_stage='activated') AS merchants,
            (SELECT COALESCE(SUM(amount),0) FROM incentive_ledger WHERE employee_id=e.id) AS incentives,
            e.full_name
        FROM employees e WHERE e.id=?");
    $st->execute([$id]); $m = $st->fetch();
    if (!$m) fail('Employee not found', 404);
    $conv = $m['leads'] > 0 ? round($m['converted'] * 100 / $m['leads'], 1) : 0;

    $prompt = 'Respond with ONLY raw JSON.
You are a sales performance coach. Analyse this agent\'s funnel numbers and give specific coaching.

Agent: ' . $m['full_name'] . '
Leads owned: ' . $m['leads'] . ' | Converted: ' . $m['converted'] . ' (' . $conv . '%) | Merchants activated: ' . $m['merchants'] . ' | Incentive earned: ₹' . $m['incentives'] . '

Return: {"headline":"one-line assessment","strengths":["..."],"gaps":["..."],"coaching":["actionable tip 1","tip 2"]}';

    $res = ai_call($prompt);
    if (!empty($res['_down'])) fail('AI service unavailable', 503);
    cache_insight($db, 'employee', $id, 'coaching', $res);
    ok($res);
}

/* -------------------- FUNNEL INSIGHTS (dashboard) -------------------- */
if ($action === 'funnel_insights') {
    // Company-wide (unscoped) figures, including incentive totals — manager/admin only.
    if (!can_manage_all_records()) fail('Not authorized', 403);
    // Serve a cached read <30 min old unless Refresh forced it.
    if (empty($b['force'])) {
        $cs = $db->prepare("SELECT payload, created_at FROM ai_insights
                            WHERE entity_type='funnel' AND insight_type='insights'
                              AND created_at > NOW() - INTERVAL 30 MINUTE ORDER BY id DESC LIMIT 1");
        $cs->execute();
        if ($row = $cs->fetch()) {
            $snap = json_decode($row['payload'], true);
            if (is_array($snap)) ok($snap, ['cached' => true, 'cached_at' => $row['created_at']]);
        }
    }
    $f = [
        'leads'     => (int)$db->query("SELECT COUNT(*) FROM leads")->fetchColumn(),
        'converted' => (int)$db->query("SELECT COUNT(*) FROM leads WHERE is_converted=1")->fetchColumn(),
        'open_deals'=> (int)$db->query("SELECT COUNT(*) FROM deals d JOIN deal_stages s ON s.id=d.stage_id WHERE s.stage_type='open'")->fetchColumn(),
        'won'       => (int)$db->query("SELECT COUNT(*) FROM deals d JOIN deal_stages s ON s.id=d.stage_id WHERE s.stage_type='won'")->fetchColumn(),
        'merchants' => (int)$db->query("SELECT COUNT(*) FROM merchants WHERE onboarding_stage='activated'")->fetchColumn(),
        'kyc_stuck' => (int)$db->query("SELECT COUNT(*) FROM merchants WHERE kyc_status IN('pending','partial')")->fetchColumn(),
        'incentive_month' => (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM incentive_ledger WHERE period_month=DATE_FORMAT(CURDATE(),'%Y-%m')")->fetchColumn(),
    ];
    $prompt = 'Respond with ONLY raw JSON.
You are a sales operations analyst for a payment-gateway company. Given the current funnel snapshot,
write a crisp executive read with the biggest risks and concrete recommendations.

Snapshot: ' . json_encode($f) . '

Return: {"headline":"one sentence","risks":["..."],"recommendations":["..."]}';

    $res = ai_call($prompt);
    if (!empty($res['_down'])) fail('AI service unavailable', 503);
    cache_insight($db, 'funnel', null, 'insights', $res);
    ok($res, ['snapshot' => $f]);
}

fail('Unknown action: ' . htmlspecialchars($action), 400);
