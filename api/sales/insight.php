<?php
/**
 * Data-grounded AI insight for main pages.
 *   POST insight.php { scope: leads|pipeline|merchants|incentives|dashboard }
 *
 * Pattern: build a deterministic EVIDENCE PACK from SQL (real numbers), hand it to
 * the LLM to INTERPRET only (summary, findings, decisions, watch). Returns both the
 * narrative and the evidence so the UI can show what each claim is based on.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../service-health.php';
require_login();
// Release the PHP session lock now — this endpoint is slow (LLM call) and read-only,
// so holding the lock would serialise every other request on the page behind it.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$db = db();

if (method() !== 'POST') fail('POST required', 405);
$in = body();
$scope = $in['scope'] ?? '';
$force = !empty($in['force']);
define('AI_CACHE_MINUTES', 30);

/* ---------- AUTHORIZATION (must run BEFORE the cache lookup — a cached snapshot is
   the same company-wide data, so serving it without the role check would let any
   logged-in user read manager-only aggregates) ---------- */
$VALID_SCOPES = ['leads', 'pipeline', 'merchants', 'incentives', 'dashboard', 'service_dashboard'];
if (!in_array($scope, $VALID_SCOPES, true)) fail('Unknown scope', 400);
// All evidence builders are company-wide aggregates (no owner scoping), and
// 'incentives'/'dashboard' surface per-agent payout figures — manager/admin only.
if (!can_manage_all_records()) fail('Not authorized', 403);
// Dept separation: the Service scope is for service_manager/admin; every sales scope is
// for the Sales manager/admin. A dept manager never reads the other dept's narrative.
if ($scope === 'service_dashboard') {
    if (current_vertical() === 'sales') fail('Not authorized', 403);
} elseif (current_vertical() === 'service') {
    fail('Not authorized', 403);
}

/* ---------- CACHE: serve a snapshot <30 min old unless Refresh forced it ----------
   The whole snapshot (narrative + evidence + timestamp) is stored, so a page reload
   within the window shows the cached read instantly without re-calling the LLM. */
if (!$force && $scope) {
    $cs = $db->prepare("SELECT payload, created_at FROM ai_insights
                        WHERE entity_type='funnel' AND insight_type=?
                          AND created_at > NOW() - INTERVAL " . AI_CACHE_MINUTES . " MINUTE
                        ORDER BY id DESC LIMIT 1");
    $cs->execute(['page_' . $scope]);
    if ($row = $cs->fetch()) {
        $snap = json_decode($row['payload'], true);
        if (is_array($snap) && !empty($snap['ai_available'])) {
            $snap['cached'] = true;
            $snap['cached_at'] = $row['created_at'];
            ok($snap);
        }
    }
}

/* ---------- EVIDENCE BUILDERS (pure SQL, trustworthy) ---------- */
function ev_leads(PDO $db): array {
    $q = fn($s) => $db->query($s);
    $total = (int)$q("SELECT COUNT(*) FROM leads")->fetchColumn();
    return [
        'total_leads' => $total,
        'new_last_30d' => (int)$q("SELECT COUNT(*) FROM leads WHERE created_at >= NOW()-INTERVAL 30 DAY")->fetchColumn(),
        'converted' => (int)$q("SELECT COUNT(*) FROM leads WHERE is_converted=1")->fetchColumn(),
        'conversion_rate_pct' => $total ? round((int)$q("SELECT COUNT(*) FROM leads WHERE is_converted=1")->fetchColumn()*100/$total,1) : 0,
        'by_status' => $q("SELECT lead_status status, COUNT(*) c FROM leads GROUP BY lead_status")->fetchAll(),
        'by_source' => $q("SELECT COALESCE(lead_source,'unknown') source, COUNT(*) total,
                           SUM(is_converted) converted,
                           ROUND(SUM(is_converted)*100/COUNT(*),1) rate_pct
                           FROM leads GROUP BY source ORDER BY total DESC LIMIT 10")->fetchAll(),
        'avg_ai_score' => round((float)$q("SELECT COALESCE(AVG(score),0) FROM leads WHERE score>0")->fetchColumn(),1),
        'unscored_open' => (int)$q("SELECT COUNT(*) FROM leads WHERE (score IS NULL OR score=0) AND is_converted=0")->fetchColumn(),
        'stale_over_14d' => (int)$q("SELECT COUNT(*) FROM leads WHERE lead_status IN('new','contacted') AND updated_at < NOW()-INTERVAL 14 DAY")->fetchColumn(),
    ];
}
function ev_pipeline(PDO $db): array {
    $q = fn($s) => $db->query($s);
    return [
        'open_deals' => (int)$q("SELECT COUNT(*) FROM deals d JOIN deal_stages s ON s.id=d.stage_id WHERE s.stage_type='open'")->fetchColumn(),
        'open_value' => (float)$q("SELECT COALESCE(SUM(d.amount),0) FROM deals d JOIN deal_stages s ON s.id=d.stage_id WHERE s.stage_type='open'")->fetchColumn(),
        'avg_deal_size' => round((float)$q("SELECT COALESCE(AVG(amount),0) FROM deals")->fetchColumn(),0),
        'by_stage' => $q("SELECT st.name, st.stage_type, COUNT(d.id) c, COALESCE(SUM(d.amount),0) value,
                          ROUND(AVG(DATEDIFF(NOW(), d.updated_at)),1) avg_age_days
                          FROM deal_stages st LEFT JOIN deals d ON d.stage_id=st.id
                          GROUP BY st.id ORDER BY st.sort_order")->fetchAll(),
        'closing_next_30d' => (int)$q("SELECT COUNT(*) FROM deals d JOIN deal_stages s ON s.id=d.stage_id
                                       WHERE s.stage_type='open' AND d.expected_close_date BETWEEN CURDATE() AND CURDATE()+INTERVAL 30 DAY")->fetchColumn(),
        'stalled_over_21d' => (int)$q("SELECT COUNT(*) FROM deals d JOIN deal_stages s ON s.id=d.stage_id
                                       WHERE s.stage_type='open' AND d.updated_at < NOW()-INTERVAL 21 DAY")->fetchColumn(),
        'stalled_value' => (float)$q("SELECT COALESCE(SUM(d.amount),0) FROM deals d JOIN deal_stages s ON s.id=d.stage_id
                                      WHERE s.stage_type='open' AND d.updated_at < NOW()-INTERVAL 21 DAY")->fetchColumn(),
        'won_90d' => (int)$q("SELECT COUNT(*) FROM deals d JOIN deal_stages s ON s.id=d.stage_id WHERE s.stage_type='won' AND d.closed_at>=NOW()-INTERVAL 90 DAY")->fetchColumn(),
        'lost_90d' => (int)$q("SELECT COUNT(*) FROM deals d JOIN deal_stages s ON s.id=d.stage_id WHERE s.stage_type='lost' AND d.closed_at>=NOW()-INTERVAL 90 DAY")->fetchColumn(),
    ];
}
function ev_merchants(PDO $db): array {
    $q = fn($s) => $db->query($s);
    return [
        'total' => (int)$q("SELECT COUNT(*) FROM merchants")->fetchColumn(),
        'activated' => (int)$q("SELECT COUNT(*) FROM merchants WHERE onboarding_stage='activated'")->fetchColumn(),
        'activated_last_30d' => (int)$q("SELECT COUNT(*) FROM merchants WHERE activated_at>=NOW()-INTERVAL 30 DAY")->fetchColumn(),
        'by_stage' => $q("SELECT onboarding_stage stage, COUNT(*) c FROM merchants GROUP BY onboarding_stage")->fetchAll(),
        'kyc_pending' => (int)$q("SELECT COUNT(*) FROM merchants WHERE kyc_status IN('pending','partial')")->fetchColumn(),
        'kyc_stuck_over_7d' => (int)$q("SELECT COUNT(*) FROM merchants WHERE kyc_status IN('pending','partial') AND created_at<NOW()-INTERVAL 7 DAY")->fetchColumn(),
        'avg_activation_days' => round((float)$q("SELECT COALESCE(AVG(DATEDIFF(activated_at,created_at)),0) FROM merchants WHERE activated_at IS NOT NULL")->fetchColumn(),1),
        'live_monthly_volume' => (float)$q("SELECT COALESCE(SUM(expected_monthly_volume),0) FROM merchants WHERE onboarding_stage='activated'")->fetchColumn(),
    ];
}
function ev_incentives(PDO $db): array {
    $q = fn($s) => $db->query($s);
    $top = $q("SELECT e.full_name, COALESCE(SUM(il.amount),0) amt FROM incentive_ledger il
               JOIN employees e ON e.id=il.employee_id GROUP BY e.id ORDER BY amt DESC LIMIT 1")->fetch();
    return [
        'total_booked' => (float)$q("SELECT COALESCE(SUM(amount),0) FROM incentive_ledger")->fetchColumn(),
        'this_month' => (float)$q("SELECT COALESCE(SUM(amount),0) FROM incentive_ledger WHERE period_month=DATE_FORMAT(CURDATE(),'%Y-%m')")->fetchColumn(),
        'pending_approval' => (float)$q("SELECT COALESCE(SUM(amount),0) FROM incentive_ledger WHERE status='accrued'")->fetchColumn(),
        'approved_unpaid' => (float)$q("SELECT COALESCE(SUM(amount),0) FROM incentive_ledger WHERE status='approved'")->fetchColumn(),
        'paid_total' => (float)$q("SELECT COALESCE(SUM(amount),0) FROM incentive_ledger WHERE status='paid'")->fetchColumn(),
        'avg_per_merchant' => round((float)$q("SELECT COALESCE(AVG(amt),0) FROM (SELECT SUM(amount) amt FROM incentive_ledger WHERE merchant_id IS NOT NULL GROUP BY merchant_id) x")->fetchColumn(),0),
        'top_earner' => $top ? ($top['full_name'] . ' (₹' . number_format((float)$top['amt']) . ')') : 'n/a',
    ];
}
function ev_dashboard(PDO $db): array {
    return ['leads'=>ev_leads($db), 'pipeline'=>ev_pipeline($db),
            'merchants'=>ev_merchants($db), 'incentives'=>ev_incentives($db)];
}
function ev_service_dashboard(PDO $db): array {
    $q = fn($s) => $db->query($s);
    // calls / WhatsApp carry no vertical — attribute via the lead's vertical or a service-side agent
    $svc = $q("SELECT GROUP_CONCAT(employee_id) FROM sales_users
               WHERE is_active=1 AND employee_id IS NOT NULL AND role IN ('service_exec','service_manager')")->fetchColumn();
    $inSvc = $svc ? "IN ($svc)" : "IN (-1)";
    $CL = "(EXISTS(SELECT 1 FROM leads lx WHERE lx.id=cl.lead_id AND lx.vertical='service') OR cl.agent_id $inSvc)";
    $WT = "(EXISTS(SELECT 1 FROM leads lx WHERE lx.id=wt.lead_id AND lx.vertical='service') OR wt.agent_id $inSvc)";
    $mon = fn($c) => "DATE_FORMAT($c,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m')";
    return [
        'live_chats' => [
            'received_this_month' => (int)$q("SELECT COUNT(*) FROM chat_sessions cs WHERE cs.vertical='service' AND " . $mon('cs.started_at'))->fetchColumn(),
            'handled_this_month' => (int)$q("SELECT COUNT(*) FROM chat_sessions cs WHERE cs.vertical='service' AND cs.picked_at IS NOT NULL AND " . $mon('cs.started_at'))->fetchColumn(),
            'missed_this_month' => (int)$q("SELECT COUNT(*) FROM chat_sessions cs WHERE cs.vertical='service' AND cs.status='missed' AND " . $mon('cs.started_at'))->fetchColumn(),
            'waiting_right_now' => (int)$q("SELECT COUNT(*) FROM chat_sessions cs WHERE cs.vertical='service' AND cs.status='waiting'")->fetchColumn(),
            'avg_pickup_sec' => (int)round((float)$q("SELECT AVG(TIMESTAMPDIFF(SECOND,cs.started_at,cs.picked_at)) FROM chat_sessions cs
                                                      WHERE cs.vertical='service' AND cs.picked_at IS NOT NULL AND " . $mon('cs.started_at'))->fetchColumn()),
            'avg_duration_sec' => (int)round((float)$q("SELECT AVG(cs.duration_sec) FROM chat_sessions cs
                                                        WHERE cs.vertical='service' AND cs.duration_sec>0 AND " . $mon('cs.started_at'))->fetchColumn()),
        ],
        'phone_calls' => [
            'this_month' => (int)$q("SELECT COUNT(*) FROM call_logs cl WHERE $CL AND " . $mon('cl.started_at'))->fetchColumn(),
            'connected' => (int)$q("SELECT COUNT(*) FROM call_logs cl WHERE $CL AND cl.duration_sec>0 AND " . $mon('cl.started_at'))->fetchColumn(),
            'avg_duration_sec' => (int)round((float)$q("SELECT AVG(cl.duration_sec) FROM call_logs cl WHERE $CL AND cl.duration_sec>0 AND " . $mon('cl.started_at'))->fetchColumn()),
        ],
        'whatsapp' => [
            'messages_this_month' => (int)$q("SELECT COUNT(*) FROM whatsapp_messages wm JOIN whatsapp_threads wt ON wt.id=wm.thread_id WHERE $WT AND " . $mon('wm.created_at'))->fetchColumn(),
            'inbound_this_month' => (int)$q("SELECT COUNT(*) FROM whatsapp_messages wm JOIN whatsapp_threads wt ON wt.id=wm.thread_id WHERE $WT AND wm.direction='inbound' AND " . $mon('wm.created_at'))->fetchColumn(),
            'threads_open' => (int)$q("SELECT COUNT(*) FROM whatsapp_threads wt WHERE $WT AND wt.status='open'")->fetchColumn(),
            'unread' => (int)$q("SELECT COALESCE(SUM(wt.unread_count),0) FROM whatsapp_threads wt WHERE $WT AND wt.status='open'")->fetchColumn(),
        ],
        'tickets' => [
            'open' => (int)$q("SELECT COUNT(*) FROM crm_tickets t WHERE t.vertical='service' AND (t.status NOT IN('closed','resolved') OR t.status IS NULL)")->fetchColumn(),
            'unclaimed' => (int)$q("SELECT COUNT(*) FROM crm_tickets t WHERE t.vertical='service' AND t.owner_id IS NULL AND (t.status NOT IN('closed','resolved') OR t.status IS NULL)")->fetchColumn(),
            'new_this_month' => (int)$q("SELECT COUNT(*) FROM crm_tickets t WHERE t.vertical='service' AND " . $mon('t.created_at'))->fetchColumn(),
            'resolved_this_month' => (int)$q("SELECT COUNT(*) FROM crm_tickets t WHERE t.vertical='service' AND t.status IN('closed','resolved') AND " . $mon('t.updated_at'))->fetchColumn(),
            'callbacks_pending' => (int)$q("SELECT COUNT(*) FROM crm_tickets t WHERE t.vertical='service' AND t.assist_type='callback' AND (t.status NOT IN('closed','resolved') OR t.status IS NULL)")->fetchColumn(),
            'by_assist_type' => $q("SELECT COALESCE(NULLIF(t.assist_type,''),'other') type, COUNT(*) c FROM crm_tickets t
                                    WHERE t.vertical='service' AND " . $mon('t.created_at') . " GROUP BY type ORDER BY c DESC")->fetchAll(),
        ],
        'chat_transfers' => [
            'sent_to_other_dept_this_month' => (int)$q("SELECT COUNT(*) FROM chat_transfers ct WHERE ct.action='transfer' AND ct.from_vertical='service' AND " . $mon('ct.created_at'))->fetchColumn(),
            'received_from_other_dept_this_month' => (int)$q("SELECT COUNT(*) FROM chat_transfers ct WHERE ct.action='transfer' AND ct.to_vertical='service' AND " . $mon('ct.created_at'))->fetchColumn(),
        ],
        'service_leads_by_source_30d' => $q("SELECT COALESCE(NULLIF(l.lead_source,''),'unknown') source, COUNT(*) c FROM leads l
                                             WHERE l.vertical='service' AND l.created_at>=NOW()-INTERVAL 30 DAY GROUP BY source ORDER BY c DESC LIMIT 8")->fetchAll(),
        'agents_this_month' => $q("SELECT e.full_name,
              (SELECT COUNT(*) FROM chat_sessions cs WHERE cs.agent_id=e.id AND cs.vertical='service' AND cs.picked_at IS NOT NULL AND " . $mon('cs.started_at') . ") chats_handled,
              (SELECT COUNT(*) FROM crm_tickets t WHERE t.owner_id=e.id AND t.vertical='service' AND t.status IN('closed','resolved') AND " . $mon('t.updated_at') . ") tickets_closed
            FROM employees e JOIN sales_users su ON su.employee_id=e.id
            WHERE su.is_active=1 AND su.role IN ('service_exec','service_manager')
            HAVING chats_handled>0 OR tickets_closed>0 ORDER BY chats_handled DESC LIMIT 8")->fetchAll(),
    ];
}

$builders = ['leads'=>'ev_leads','pipeline'=>'ev_pipeline','merchants'=>'ev_merchants',
             'incentives'=>'ev_incentives','dashboard'=>'ev_dashboard',
             'service_dashboard'=>'ev_service_dashboard'];
// scope validity + role/vertical authorization already enforced above, before the cache
$evidence = $builders[$scope]($db);

/* ---------- LLM INTERPRETS the evidence (no number invention) ---------- */
$roleHint = [
    'leads'      => 'lead-generation quality and where to focus outreach',
    'pipeline'   => 'deal momentum, at-risk revenue and forecast',
    'merchants'  => 'onboarding/KYC throughput and activation speed',
    'incentives' => 'payout liability, fairness and cost efficiency',
    'dashboard'  => 'overall sales health across the whole funnel',
    'service_dashboard' => 'service-desk responsiveness (chat pickup/missed rates), channel volumes (live chat vs phone vs WhatsApp), ticket resolution throughput, callback backlog and chat-transfer patterns',
][$scope];

$persona = $scope === 'service_dashboard'
    ? 'customer-service operations analyst for a payment-gateway company supporting merchants in India'
    : 'sales operations analyst for a payment-gateway company acquiring merchants in India';
$prompt = 'Respond with ONLY raw JSON, no markdown/prose.
You are a ' . $persona . '.
Below is an EVIDENCE PACK of real figures from the CRM. Analyse ' . $roleHint . '.
Rules: use ONLY numbers present in the evidence — never invent figures. Every finding must cite a number.
Make decisions concrete and prioritised by impact.

EVIDENCE (JSON):
' . json_encode($evidence, JSON_PRETTY_PRINT) . '

Return exactly:
{"summary":"2-3 sentence executive read",
 "key_findings":["finding citing a specific number","..."],
 "decisions":["concrete action with expected impact","..."],
 "watch":["risk or threshold to monitor","..."]}';

require_once __DIR__ . '/_ai.php';
$r = crm_ai_call($prompt, 'medium', 'chat_v3');   // box -> cloud -> local
if (!$r) {
    $offline = crm_ai_status() === 'box_offline';
    ok(['ai_available'=>false, 'evidence'=>$evidence, 'ai_status'=>crm_ai_status(),
        'summary'=> $offline
            ? 'AI box is offline — showing raw evidence below. (The on-prem box is enabled in Settings but not reachable.)'
            : 'AI service offline — showing raw evidence below.']);
}
$clean = trim(preg_replace('/```(json)?/i','',$r['response']));
$parsed = json_decode($clean, true);
if (json_last_error() !== JSON_ERROR_NONE && preg_match('/\{[\s\S]*\}/u',$clean,$m)) $parsed = json_decode($m[0],true);
if (!is_array($parsed)) ok(['ai_available'=>false,'evidence'=>$evidence,'summary'=>'AI returned no structured result — evidence shown below.','ai_source'=>$r['source']]);

// Build the full snapshot (narrative + the evidence it was based on) and cache it.
// entity_type is ENUM(lead,deal,merchant,employee,funnel) — page insights are aggregate,
// stored as 'funnel' with the scope kept in insight_type.
$snapshot = array_merge(['ai_available'=>true, 'evidence'=>$evidence, 'generated_at'=>date('Y-m-d H:i:s')], $parsed);
try {
    $db->prepare("INSERT INTO ai_insights (entity_type,entity_id,insight_type,payload) VALUES ('funnel',NULL,?,?)")
       ->execute(['page_' . $scope, json_encode($snapshot)]);
} catch (Throwable $e) { /* caching is best-effort, never fail the response */ }

$snapshot['cached'] = false;
ok($snapshot);
