<?php
/**
 * Configurable dashboard KPIs (Sales + Service dashboards).
 *
 *   GET    dashboard-kpis.php?action=catalog&vertical=service  -> metric catalog (for the builder UI)
 *   GET    dashboard-kpis.php?action=list&vertical=service     -> active KPIs + computed values (viewer-scoped)
 *   GET    dashboard-kpis.php?action=manage&vertical=service   -> all KPIs incl. inactive (manager/admin, for editing)
 *   POST   dashboard-kpis.php   {vertical,title,metric_key,display,period,target_value,direction,sort_order}
 *   PATCH  dashboard-kpis.php?id=<n>   {title?,metric_key?,display?,period?,target_value?,direction?,sort_order?,is_active?}
 *   DELETE dashboard-kpis.php?id=<n>
 *
 * Rules
 *   • Reading values: any logged-in user, but only for their own department's dashboard
 *     (a sales-vertical user can't read Service KPIs and vice versa; admin reads both).
 *     Values are row-level scoped exactly like the dashboards (an exec sees own rows).
 *   • Defining/editing: admin for any department; a department manager (manager /
 *     service_manager) ONLY for their own department. Execs can never write.
 */
require_once __DIR__ . '/_bootstrap.php';
require_login();
$db = db();

$me     = (int)current_employee_id();

/**
 * Whether to scope every metric below to the caller's own rows.
 *
 * A KPI governor (Admin, HR, Sales Head — capability 'kpi_govern') defines the tiles the
 * whole company is measured on, so they must be able to READ those tiles company-wide.
 * Without the can_govern_kpi() exemption an HR user fell through to the exec branch and
 * every tile computed against owner_id = their own employee id — HR owns no leads or deals,
 * so a correctly-configured dashboard rendered as a wall of zeros.
 *
 * This widens AGGREGATES only. Record-level visibility everywhere else still comes from
 * visible_owner_ids() / owner_scope(), which are untouched: HR reads the totals, not the rows.
 */
// Row scope for the tiles: null = whole company (dept head/admin/governor); otherwise the
// caller's own records + (for a manager) their reporting-line subtree. A single $me test used
// to under-count a manager to just their own rows — now every tile is scoped to the same id set.
$vis    = visible_owner_ids();                       // null=all, [self,...team]=scoped
$scoped = $vis !== null && !can_govern_kpi();
$idlist = $scoped ? implode(',', array_map('intval', $vis)) : '';
$sc = fn(string $col) => $scoped ? "$col IN ($idlist)" : null;   // "col IN (…)" or null (unscoped)

/* ---------- attribution fragments (shared with service-overview.php's logic) ---------- */
$svcIds = array_map('intval', $db->query(
    "SELECT employee_id FROM sales_users WHERE is_active=1 AND employee_id IS NOT NULL
     AND role IN ('service_exec','service_manager')")->fetchAll(PDO::FETCH_COLUMN) ?: []);
$inSvc = $svcIds ? ('IN (' . implode(',', $svcIds) . ')') : 'IN (-1)';

$CS = "cs.vertical='service'" . ($scoped ? " AND ({$sc('cs.agent_id')} OR EXISTS(SELECT 1 FROM leads lx WHERE lx.id=cs.lead_id AND {$sc('lx.owner_id')}))" : '');
$CL = "(EXISTS(SELECT 1 FROM leads lx WHERE lx.id=cl.lead_id AND lx.vertical='service') OR cl.agent_id $inSvc)"
    . ($scoped ? " AND {$sc('cl.agent_id')}" : '');
$WT = "(EXISTS(SELECT 1 FROM leads lx WHERE lx.id=wt.lead_id AND lx.vertical='service') OR wt.agent_id $inSvc)"
    . ($scoped ? " AND ({$sc('wt.agent_id')} OR EXISTS(SELECT 1 FROM leads lx WHERE lx.id=wt.lead_id AND {$sc('lx.owner_id')}))" : '');
$TKs = "t.vertical='service'" . ($scoped ? " AND {$sc('t.owner_id')}" : '');
$LDs = "l.vertical='service'" . ($scoped ? " AND {$sc('l.owner_id')}" : '');
$LDx = "l.vertical='sales'"   . ($scoped ? " AND {$sc('l.owner_id')}" : '');
$OWN = $scoped ? $sc('owner_id')   : '1';
$DOW = $scoped ? $sc('d.owner_id') : '1';
$EMP = $scoped ? $sc('employee_id'): '1';

/**
 * Metric catalog. Each metric:
 *   label, unit (count|sec|inr|pct), vertical (sales|service),
 *   from  — FROM/JOIN clause, agg — aggregate expression,
 *   where — base filter (already vertical- and viewer-scoped),
 *   date  — timestamp column for period filters & monthly series (null => snapshot metric,
 *           period is ignored and trend charts are unavailable).
 */
$CATALOG = [
    /* ---------------- Service ---------------- */
    'svc_chats'             => ['label' => 'Live chats received',        'unit' => 'count', 'vertical' => 'service',
        'from' => 'chat_sessions cs', 'agg' => 'COUNT(*)', 'where' => $CS, 'date' => 'cs.started_at'],
    'svc_chats_handled'     => ['label' => 'Live chats handled (picked up)', 'unit' => 'count', 'vertical' => 'service',
        'from' => 'chat_sessions cs', 'agg' => 'COUNT(*)', 'where' => "$CS AND cs.picked_at IS NOT NULL", 'date' => 'cs.started_at'],
    'svc_chats_missed'      => ['label' => 'Live chats missed',          'unit' => 'count', 'vertical' => 'service',
        'from' => 'chat_sessions cs', 'agg' => 'COUNT(*)', 'where' => "$CS AND cs.status='missed'", 'date' => 'cs.started_at'],
    'svc_avg_pickup_sec'    => ['label' => 'Avg chat pickup time (sec)', 'unit' => 'sec', 'vertical' => 'service',
        'from' => 'chat_sessions cs', 'agg' => 'ROUND(AVG(TIMESTAMPDIFF(SECOND,cs.started_at,cs.picked_at)))',
        'where' => "$CS AND cs.picked_at IS NOT NULL", 'date' => 'cs.started_at'],
    'svc_avg_chat_duration' => ['label' => 'Avg chat duration (sec)',    'unit' => 'sec', 'vertical' => 'service',
        'from' => 'chat_sessions cs', 'agg' => 'ROUND(AVG(cs.duration_sec))', 'where' => "$CS AND cs.duration_sec>0", 'date' => 'cs.started_at'],
    'svc_calls'             => ['label' => 'Phone calls',                'unit' => 'count', 'vertical' => 'service',
        'from' => 'call_logs cl', 'agg' => 'COUNT(*)', 'where' => $CL, 'date' => 'cl.started_at'],
    'svc_calls_connected'   => ['label' => 'Phone calls connected',      'unit' => 'count', 'vertical' => 'service',
        'from' => 'call_logs cl', 'agg' => 'COUNT(*)', 'where' => "$CL AND cl.duration_sec>0", 'date' => 'cl.started_at'],
    'svc_avg_call_duration' => ['label' => 'Avg call duration (sec)',    'unit' => 'sec', 'vertical' => 'service',
        'from' => 'call_logs cl', 'agg' => 'ROUND(AVG(cl.duration_sec))', 'where' => "$CL AND cl.duration_sec>0", 'date' => 'cl.started_at'],
    'svc_wa_msgs'           => ['label' => 'WhatsApp messages',          'unit' => 'count', 'vertical' => 'service',
        'from' => 'whatsapp_messages wm JOIN whatsapp_threads wt ON wt.id=wm.thread_id', 'agg' => 'COUNT(*)', 'where' => $WT, 'date' => 'wm.created_at'],
    'svc_wa_inbound'        => ['label' => 'WhatsApp inbound messages',  'unit' => 'count', 'vertical' => 'service',
        'from' => 'whatsapp_messages wm JOIN whatsapp_threads wt ON wt.id=wm.thread_id', 'agg' => 'COUNT(*)',
        'where' => "$WT AND wm.direction='inbound'", 'date' => 'wm.created_at'],
    'svc_wa_threads_open'   => ['label' => 'WhatsApp threads open (now)', 'unit' => 'count', 'vertical' => 'service',
        'from' => 'whatsapp_threads wt', 'agg' => 'COUNT(*)', 'where' => "$WT AND wt.status='open'", 'date' => null],
    'svc_tickets_new'       => ['label' => 'Tickets raised',             'unit' => 'count', 'vertical' => 'service',
        'from' => 'crm_tickets t', 'agg' => 'COUNT(*)', 'where' => $TKs, 'date' => 't.created_at'],
    'svc_tickets_resolved'  => ['label' => 'Tickets resolved',           'unit' => 'count', 'vertical' => 'service',
        'from' => 'crm_tickets t', 'agg' => 'COUNT(*)', 'where' => "$TKs AND t.status IN('closed','resolved')", 'date' => 't.updated_at'],
    'svc_tickets_open'      => ['label' => 'Tickets open (now)',         'unit' => 'count', 'vertical' => 'service',
        'from' => 'crm_tickets t', 'agg' => 'COUNT(*)', 'where' => "$TKs AND (t.status NOT IN('closed','resolved') OR t.status IS NULL)", 'date' => null],
    'svc_callbacks_pending' => ['label' => 'Callbacks pending (now)',    'unit' => 'count', 'vertical' => 'service',
        'from' => 'crm_tickets t', 'agg' => 'COUNT(*)',
        'where' => "$TKs AND t.assist_type='callback' AND (t.status NOT IN('closed','resolved') OR t.status IS NULL)", 'date' => null],
    'svc_transfers_out'     => ['label' => 'Chats transferred out',      'unit' => 'count', 'vertical' => 'service',
        'from' => 'chat_transfers ct', 'agg' => 'COUNT(*)',
        'where' => "ct.action='transfer' AND ct.from_vertical='service'" . ($scoped ? " AND {$sc('ct.from_agent_id')}" : ''), 'date' => 'ct.created_at'],
    'svc_transfers_in'      => ['label' => 'Chats transferred in',       'unit' => 'count', 'vertical' => 'service',
        'from' => 'chat_transfers ct', 'agg' => 'COUNT(*)',
        'where' => "ct.action='transfer' AND ct.to_vertical='service'" . ($scoped ? " AND {$sc('ct.to_agent_id')}" : ''), 'date' => 'ct.created_at'],
    'svc_leads'             => ['label' => 'New service leads',          'unit' => 'count', 'vertical' => 'service',
        'from' => 'leads l', 'agg' => 'COUNT(*)', 'where' => $LDs, 'date' => 'l.created_at'],

    /* ---- Calls workspace (phase41) ---- */
    'svc_calls_inbound'     => ['label' => 'Calls received (inbound)',   'unit' => 'count', 'vertical' => 'service',
        'from' => 'call_logs cl', 'agg' => 'COUNT(*)', 'where' => "$CL AND cl.direction='inbound'", 'date' => 'cl.started_at'],
    'svc_calls_outbound'    => ['label' => 'Calls made (outbound)',      'unit' => 'count', 'vertical' => 'service',
        'from' => 'call_logs cl', 'agg' => 'COUNT(*)', 'where' => "$CL AND cl.direction='outbound'", 'date' => 'cl.started_at'],
    'svc_calls_missed'      => ['label' => 'Calls missed',               'unit' => 'count', 'vertical' => 'service',
        'from' => 'call_logs cl', 'agg' => 'COUNT(*)',
        'where' => "$CL AND cl.direction='inbound' AND cl.status IN ('missed','no-answer','busy','failed')",
        'date' => 'cl.started_at'],
    'svc_calls_answered'    => ['label' => 'Calls answered',             'unit' => 'count', 'vertical' => 'service',
        'from' => 'call_logs cl', 'agg' => 'COUNT(*)', 'where' => "$CL AND cl.direction='inbound' AND cl.claimed_by IS NOT NULL", 'date' => 'cl.started_at'],
    'svc_call_answer_rate'  => ['label' => 'Call answer rate (%)',       'unit' => 'pct', 'vertical' => 'service',
        'from' => 'call_logs cl', 'agg' => "ROUND(100 * SUM(CASE WHEN cl.claimed_by IS NOT NULL THEN 1 ELSE 0 END) / GREATEST(COUNT(*),1))",
        'where' => "$CL AND cl.direction='inbound'", 'date' => 'cl.started_at'],
    'svc_call_avg_wait_sec' => ['label' => 'Avg wait-to-answer (sec)',   'unit' => 'sec', 'vertical' => 'service',
        'from' => 'call_logs cl', 'agg' => 'ROUND(AVG(TIMESTAMPDIFF(SECOND,cl.started_at,cl.claimed_at)))',
        'where' => "$CL AND cl.direction='inbound' AND cl.claimed_at IS NOT NULL", 'date' => 'cl.started_at'],
    'svc_call_avg_handle_sec' => ['label' => 'Avg call handle time (sec)', 'unit' => 'sec', 'vertical' => 'service',
        'from' => 'call_logs cl', 'agg' => 'ROUND(AVG(cl.duration_sec))', 'where' => "$CL AND cl.duration_sec>0", 'date' => 'cl.started_at'],

    /* ---------------- Sales ---------------- */
    'sls_leads'             => ['label' => 'New leads',                  'unit' => 'count', 'vertical' => 'sales',
        'from' => 'leads l', 'agg' => 'COUNT(*)', 'where' => $LDx, 'date' => 'l.created_at'],
    'sls_leads_converted'   => ['label' => 'Leads converted',            'unit' => 'count', 'vertical' => 'sales',
        'from' => 'leads l', 'agg' => 'COUNT(*)', 'where' => "$LDx AND l.is_converted=1", 'date' => 'l.created_at'],
    'sls_followups_due'     => ['label' => 'Follow-ups due (now)',       'unit' => 'count', 'vertical' => 'sales',
        'from' => 'leads l', 'agg' => 'COUNT(*)',
        'where' => "$LDx AND l.next_followup_at IS NOT NULL AND l.next_followup_at<=NOW() AND l.is_converted=0", 'date' => null],
    'sls_deals_open'        => ['label' => 'Open deals (now)',           'unit' => 'count', 'vertical' => 'sales',
        'from' => 'deals d JOIN deal_stages s ON s.id=d.stage_id', 'agg' => 'COUNT(*)', 'where' => "s.stage_type='open' AND $DOW", 'date' => null],
    'sls_pipeline_value'    => ['label' => 'Open pipeline value (now)',  'unit' => 'inr', 'vertical' => 'sales',
        'from' => 'deals d JOIN deal_stages s ON s.id=d.stage_id', 'agg' => 'COALESCE(SUM(d.amount),0)', 'where' => "s.stage_type='open' AND $DOW", 'date' => null],
    'sls_deals_won'         => ['label' => 'Deals won',                  'unit' => 'count', 'vertical' => 'sales',
        'from' => 'deals d JOIN deal_stages s ON s.id=d.stage_id', 'agg' => 'COUNT(*)', 'where' => "s.stage_type='won' AND $DOW", 'date' => 'd.closed_at'],
    'sls_won_value'         => ['label' => 'Won deal value',             'unit' => 'inr', 'vertical' => 'sales',
        'from' => 'deals d JOIN deal_stages s ON s.id=d.stage_id', 'agg' => 'COALESCE(SUM(d.amount),0)', 'where' => "s.stage_type='won' AND $DOW", 'date' => 'd.closed_at'],
    'sls_merchants_activated' => ['label' => 'Merchants activated',      'unit' => 'count', 'vertical' => 'sales',
        'from' => 'merchants m', 'agg' => 'COUNT(*)', 'where' => ($scoped ? $sc('m.owner_id') : '1'), 'date' => 'm.activated_at'],
    'sls_txn_value'         => ['label' => 'Transaction value (success)', 'unit' => 'inr', 'vertical' => 'sales',
        'from' => 'merchant_transactions mt' . ($scoped ? " JOIN merchants m ON m.id=mt.merchant_id AND {$sc('m.owner_id')}" : ''),
        'agg' => 'COALESCE(SUM(mt.amount),0)', 'where' => "mt.status='success'", 'date' => 'mt.txn_date'],
    'sls_incentives'        => ['label' => 'Incentives booked',          'unit' => 'inr', 'vertical' => 'sales',
        'from' => 'incentive_ledger il', 'agg' => 'COALESCE(SUM(il.amount),0)', 'where' => ($scoped ? $sc('il.employee_id') : '1'), 'date' => 'il.created_at'],
];

const DK_DISPLAYS = ['kpi', 'bar', 'line', 'donut'];
const DK_PERIODS  = ['today', '7d', 'month', 'quarter', 'all'];
// Money/payout metrics hidden from anyone who doesn't manage incentives (execs).
const DK_INCENTIVE_METRICS = ['sls_incentives'];

/* ---------- vertical permission helpers ---------- */
function dk_read_vertical(string $v): void
{
    if (!in_array($v, ['sales', 'service'], true)) fail('vertical must be sales or service');
    $mine = current_vertical();                       // null for admin/hr/it
    if ($mine !== null && $mine !== $v && current_role() !== 'admin') {
        fail("Not authorized for the $v dashboard", 403);
    }
}
/**
 * The vertical(s) the caller may DEFINE KPIs for; null = none.
 *
 * A KPI governor (Admin, HR, Sales Head — capability 'kpi_govern', _roles.php) may define
 * tiles for any department: "who sets the numbers this company is measured on" is one
 * question, and it should not have three different answers depending on whether you open
 * KPA/KRA, the dashboard tiles or the scorecards. A department manager stays scoped to their
 * own vertical.
 */
function dk_manage_vertical(): ?string
{
    if (can_govern_kpi()) return 'both';
    switch (current_role()) {
        case 'manager':         return 'sales';
        case 'service_manager': return 'service';
        default:                return null;
    }
}
function dk_assert_can_manage(string $v): void
{
    $m = dk_manage_vertical();
    if ($m === null) fail('Only the admin, HR or the department manager can define dashboard KPIs', 403);
    if ($m !== 'both' && $m !== $v) fail('You can only manage KPIs for your own department', 403);
}

/* ---------- metric computation ---------- */
function dk_period_where(?string $dateCol, string $period): string
{
    if ($dateCol === null || $period === 'all') return '1';
    switch ($period) {
        case 'today':   return "DATE($dateCol)=CURDATE()";
        case '7d':      return "$dateCol >= CURDATE()-INTERVAL 6 DAY";
        case 'quarter': return "QUARTER($dateCol)=QUARTER(CURDATE()) AND YEAR($dateCol)=YEAR(CURDATE())";
        case 'month':
        default:        return "DATE_FORMAT($dateCol,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m')";
    }
}
function dk_value(PDO $db, array $m, string $period): float
{
    try {
        $sql = "SELECT {$m['agg']} FROM {$m['from']} WHERE {$m['where']} AND " . dk_period_where($m['date'], $period);
        return (float)($db->query($sql)->fetchColumn() ?: 0);
    } catch (Throwable $e) { return 0.0; }
}
/** 6-month series [{ym, v}] for trend displays; null for snapshot metrics. */
function dk_series(PDO $db, array $m): ?array
{
    if ($m['date'] === null) return null;
    try {
        $rows = $db->query("
            SELECT mm.ym, (SELECT {$m['agg']} FROM {$m['from']}
                           WHERE {$m['where']} AND DATE_FORMAT({$m['date']},'%Y-%m')=mm.ym) v
            FROM (SELECT DATE_FORMAT(CURDATE()-INTERVAL n MONTH,'%Y-%m') ym
                  FROM (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5) t) mm
            ORDER BY mm.ym")->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn($r) => ['ym' => $r['ym'], 'v' => (float)($r['v'] ?: 0)], $rows);
    } catch (Throwable $e) { return null; }
}
function dk_decorate(PDO $db, array $row, array $CATALOG, bool $withSeries): array
{
    $m = $CATALOG[$row['metric_key']] ?? null;
    $out = [
        'id' => (int)$row['id'], 'vertical' => $row['vertical'], 'title' => $row['title'],
        'metric_key' => $row['metric_key'], 'display' => $row['display'], 'period' => $row['period'],
        'target_value' => $row['target_value'] !== null ? (float)$row['target_value'] : null,
        'direction' => $row['direction'], 'sort_order' => (int)$row['sort_order'],
        'col_span' => max(1, min(4, (int)($row['col_span'] ?? 1))),
        'is_active' => (int)$row['is_active'], 'created_by' => (int)$row['created_by'],
        'created_by_role' => $row['created_by_role'],
        'unit' => $m['unit'] ?? 'count', 'metric_label' => $m['label'] ?? $row['metric_key'],
        'snapshot' => $m ? ($m['date'] === null) : true,
        'value' => $m ? dk_value($db, $m, $row['period']) : 0.0,
        'series' => null,
    ];
    if ($withSeries && $m && in_array($row['display'], ['bar', 'line'], true)) {
        $out['series'] = dk_series($db, $m);
    }
    return $out;
}

/* ============================ GET ============================ */
if (method() === 'GET') {
    $action = $_GET['action'] ?? 'list';
    $v = $_GET['vertical'] ?? (current_vertical() ?: 'sales');
    dk_read_vertical($v);

    if ($action === 'catalog') {
        $items = [];
        foreach ($CATALOG as $key => $m) {
            if ($m['vertical'] !== $v) continue;
            $items[] = ['key' => $key, 'label' => $m['label'], 'unit' => $m['unit'], 'snapshot' => $m['date'] === null];
        }
        ok(['vertical' => $v, 'metrics' => $items,
            'can_manage' => dk_manage_vertical() === 'both' || dk_manage_vertical() === $v,
            'manage_vertical' => dk_manage_vertical()]);
    }

    if ($action === 'manage') {
        dk_assert_can_manage($v);
        $s = $db->prepare("SELECT * FROM dashboard_kpis WHERE vertical=? ORDER BY sort_order, id");
        $s->execute([$v]);
        ok(['vertical' => $v, 'kpis' => array_map(fn($r) => dk_decorate($db, $r, $CATALOG, false), $s->fetchAll(PDO::FETCH_ASSOC))]);
    }

    // default: active KPIs with computed values + trend series (what the dashboard renders)
    $s = $db->prepare("SELECT * FROM dashboard_kpis WHERE vertical=? AND is_active=1 ORDER BY sort_order, id");
    $s->execute([$v]);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    // Incentive/payout figures are management-only: a sales_exec is measured on their KPIs, not
    // shown how much they'll be paid. Drop money-metric tiles unless the viewer manages incentives.
    if (!can_manage_incentives()) {
        $rows = array_filter($rows, fn($r) => !in_array($r['metric_key'], DK_INCENTIVE_METRICS, true));
    }
    ok(['vertical' => $v,
        'kpis' => array_map(fn($r) => dk_decorate($db, $r, $CATALOG, true), array_values($rows)),
        'can_manage' => dk_manage_vertical() === 'both' || dk_manage_vertical() === $v,
        'manage_vertical' => dk_manage_vertical()]);   // 'both' => governor may manage any dept
}

/* ============================ POST (create) ============================ */
if (method() === 'POST') {
    $b = body();
    $v = $b['vertical'] ?? '';
    if (!in_array($v, ['sales', 'service'], true)) fail('vertical must be sales or service');
    dk_assert_can_manage($v);

    $key = (string)($b['metric_key'] ?? '');
    if (!isset($CATALOG[$key]) || $CATALOG[$key]['vertical'] !== $v) fail('Unknown metric for this department');
    $title = trim((string)($b['title'] ?? '')) ?: $CATALOG[$key]['label'];
    $display = in_array($b['display'] ?? '', DK_DISPLAYS, true) ? $b['display'] : 'kpi';
    if ($CATALOG[$key]['date'] === null && in_array($display, ['bar', 'line'], true)) {
        fail('This metric is a point-in-time snapshot — trend charts (bar/line) are not available for it');
    }
    $period = in_array($b['period'] ?? '', DK_PERIODS, true) ? $b['period'] : 'month';
    $target = ($b['target_value'] ?? '') === '' || $b['target_value'] === null ? null : (float)$b['target_value'];
    $direction = ($b['direction'] ?? '') === 'lower' ? 'lower' : 'higher';
    $colSpan = max(1, min(4, (int)($b['col_span'] ?? 1)));

    $db->prepare("INSERT INTO dashboard_kpis
        (vertical, title, metric_key, display, col_span, period, target_value, direction, sort_order, created_by, created_by_role)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)")
       ->execute([$v, $title, $key, $display, $colSpan, $period, $target, $direction,
                  (int)($b['sort_order'] ?? 0), $me, current_role()]);
    ok(['id' => (int)$db->lastInsertId()]);
}

/* ============================ PATCH / DELETE ============================ */
if (method() === 'PATCH' || method() === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) fail('Missing id');
    $s = $db->prepare("SELECT * FROM dashboard_kpis WHERE id=?");
    $s->execute([$id]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if (!$row) fail('KPI not found', 404);
    dk_assert_can_manage($row['vertical']);

    if (method() === 'DELETE') {
        $db->prepare("DELETE FROM dashboard_kpis WHERE id=?")->execute([$id]);
        ok(['deleted' => $id]);
    }

    $b = body();
    $fields = []; $args = [];
    if (array_key_exists('title', $b)) { $fields[] = 'title=?'; $args[] = trim((string)$b['title']) ?: $row['title']; }
    if (array_key_exists('metric_key', $b)) {
        $key = (string)$b['metric_key'];
        if (!isset($CATALOG[$key]) || $CATALOG[$key]['vertical'] !== $row['vertical']) fail('Unknown metric for this department');
        $fields[] = 'metric_key=?'; $args[] = $key;
    }
    if (array_key_exists('display', $b) && in_array($b['display'], DK_DISPLAYS, true)) {
        $newKey = $b['metric_key'] ?? $row['metric_key'];
        if (isset($CATALOG[$newKey]) && $CATALOG[$newKey]['date'] === null && in_array($b['display'], ['bar', 'line'], true)) {
            fail('This metric is a point-in-time snapshot — trend charts (bar/line) are not available for it');
        }
        $fields[] = 'display=?'; $args[] = $b['display'];
    }
    if (array_key_exists('col_span', $b)) { $fields[] = 'col_span=?'; $args[] = max(1, min(4, (int)$b['col_span'])); }
    if (array_key_exists('period', $b) && in_array($b['period'], DK_PERIODS, true)) { $fields[] = 'period=?'; $args[] = $b['period']; }
    if (array_key_exists('target_value', $b)) {
        $fields[] = 'target_value=?';
        $args[] = ($b['target_value'] === '' || $b['target_value'] === null) ? null : (float)$b['target_value'];
    }
    if (array_key_exists('direction', $b)) { $fields[] = 'direction=?'; $args[] = $b['direction'] === 'lower' ? 'lower' : 'higher'; }
    if (array_key_exists('sort_order', $b)) { $fields[] = 'sort_order=?'; $args[] = (int)$b['sort_order']; }
    if (array_key_exists('is_active', $b)) { $fields[] = 'is_active=?'; $args[] = ((int)$b['is_active']) ? 1 : 0; }
    if (!$fields) fail('Nothing to update');
    $args[] = $id;
    $db->prepare("UPDATE dashboard_kpis SET " . implode(',', $fields) . " WHERE id=?")->execute($args);
    ok(['id' => $id]);
}

fail('Unsupported method', 405);
