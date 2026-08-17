<?php
/**
 * CFO cockpit — the finance compliance product.
 *
 * One question runs through every screen: are we compliant right now, what is
 * due or overdue, and where is the evidence? Nothing here is typed in as a
 * status: the compliance calendar is EXPANDED from obligations by the scheduler,
 * "overdue" is derived from due dates, risk ratings are likelihood x impact
 * (virtual columns), and RAG is computed from the KRI thresholds. That way the
 * numbers cannot drift from the facts behind them.
 *
 *   GET  cfo.php?meta=1
 *   GET  cfo.php?area=dashboard|calendar|reviews|forms|capa|risk|kri|evidence
 *                     |reports|audit|settings
 *   GET  cfo.php?task=N | risk=N | submission=N | review=N | obligation=N
 *   GET  cfo.php?export=risk|capa|calendar|documents|kri|audit       -> CSV
 *   POST cfo.php?action=scheduler                                    -> expand calendar
 *   POST cfo.php?action=task_status|task_assign|task_split
 *   POST cfo.php?action=submission_create|submission_save|submission_transition
 *   POST cfo.php?action=review_sign|review_status
 *   POST cfo.php?action=capa_save|risk_save|kri_reading|kri_save
 *   POST cfo.php?action=evidence_link|evidence_delete
 *   POST cfo.php?action=template_save|obligation_save|settings_save
 *   POST cfo.php?ai=<area>                                           -> AI analysis
 *
 * Writes are appended to cfo_audit, which has no UPDATE or DELETE path anywhere
 * in this file — that is what makes the Audit Trail screen trustworthy.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_ai.php';
require_login();
require_module('cfo_dashboard');          // entry to the product
$db = db();

/* ------------------------------------------------------------------ *
 *  Per-screen access
 * ------------------------------------------------------------------ *
 * Each screen has its own module key in the role matrix, and groups are granted
 * a subset (the Board and Compliance & Legal see the dashboard, risk, KPI,
 * reports and the audit trail — not the working queues). Gating the file once on
 * cfo_dashboard would make every one of those grants mean "everything", so each
 * request is checked against the module of the area it is actually asking for.
 */
const CFO_AREA_MODULE = [
    'dashboard' => 'cfo_dashboard', 'calendar' => 'cfo_calendar', 'reviews'  => 'cfo_reviews',
    'forms'     => 'cfo_forms',     'capa'     => 'cfo_capa',     'risk'     => 'cfo_risk',
    'kri'       => 'cfo_kri',       'evidence' => 'cfo_evidence', 'reports'  => 'cfo_reports',
    'audit'     => 'cfo_audit',     'settings' => 'cfo_settings',
];
const CFO_ACTION_MODULE = [
    'scheduler'   => 'cfo_calendar', 'task_status' => 'cfo_calendar', 'task_assign' => 'cfo_calendar',
    'task_split'  => 'cfo_calendar',
    'submission_create' => 'cfo_forms', 'submission_save' => 'cfo_forms', 'submission_transition' => 'cfo_forms',
    'review_sign' => 'cfo_reviews', 'review_status' => 'cfo_reviews',
    'capa_save'   => 'cfo_capa',    'risk_save' => 'cfo_risk',
    'kri_reading' => 'cfo_kri',     'kri_save'  => 'cfo_kri',
    'evidence_link' => 'cfo_evidence', 'evidence_delete' => 'cfo_evidence',
    'template_save' => 'cfo_settings', 'obligation_save' => 'cfo_settings', 'settings_save' => 'cfo_settings',
];

/** Gate this request on the module of the screen it belongs to. */
function cfo_gate(string $module): void { require_module($module); }

if (method() === 'GET') {
    if (isset($_GET['area']))       cfo_gate(CFO_AREA_MODULE[(string)$_GET['area']] ?? 'cfo_dashboard');
    elseif (isset($_GET['task']))   cfo_gate('cfo_calendar');
    elseif (isset($_GET['risk']))   cfo_gate('cfo_risk');
    elseif (isset($_GET['submission'])) cfo_gate('cfo_forms');
    elseif (isset($_GET['review'])) cfo_gate('cfo_reviews');
    elseif (isset($_GET['export'])) cfo_gate((string)$_GET['export'] === 'audit' ? 'cfo_audit' : 'cfo_reports');
    elseif (!isset($_GET['meta']))  cfo_gate('cfo_dashboard');
} else {
    if (isset($_GET['ai']))     cfo_gate(CFO_AREA_MODULE[(string)$_GET['ai']] ?? 'cfo_dashboard');
    elseif (isset($_GET['action'])) cfo_gate(CFO_ACTION_MODULE[(string)$_GET['action']] ?? 'cfo_dashboard');
}

/* ------------------------------------------------------------------ *
 *  Authority
 * ------------------------------------------------------------------ */
/** Prepare/edit records (maker + checker tiers). */
function cfo_can_write(): bool
{
    return can_manage_all_records()
        || in_array(current_role(), ['cfo', 'finance_manager', 'admin'], true);
}
/** Sign off reviews, approve submissions, change obligations/templates/settings. */
function cfo_can_sign(): bool
{
    return current_role() === 'admin' || current_role() === 'cfo'
        || payg_role_can(current_role(), 'rbac');
}
function cfo_role_label(): string { return payg_role_label(current_role()); }

/** Append-only audit. Every write in this file goes through here. */
function cfo_log(PDO $db, string $action, string $entity, $entityId, string $summary): void
{
    try {
        $db->prepare("INSERT INTO cfo_audit (employee_id,user_name,action,entity,entity_id,summary)
                      VALUES (?,?,?,?,?,?)")
           ->execute([current_employee_id() ?: null,
                      (string)($_SESSION['user']['data']['full_name'] ?? ($_SESSION['user']['username'] ?? 'system')),
                      $action, $entity, (string)$entityId, mb_substr($summary, 0, 400)]);
    } catch (Throwable $e) { /* auditing must never break the action it records */ }
}

function cfo_setting(PDO $db, string $key, string $default = ''): string
{
    static $all = null;
    if ($all === null) {
        $all = [];
        try {
            foreach ($db->query("SELECT skey,sval FROM cfo_settings")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $all[$r['skey']] = $r['sval'];
            }
        } catch (Throwable $e) { $all = []; }
    }
    return $all[$key] ?? $default;
}

/* ------------------------------------------------------------------ *
 *  The scheduler — obligations become dated tasks
 * ------------------------------------------------------------------ */
/**
 * Periods for one obligation inside the rolling window, with the date each is
 * due. A period's due date is always AFTER the period it covers (you file
 * September's return in October), which is what makes "overdue" meaningful.
 */
function cfo_periods(string $cadence, int $dueDay, int $backDays, int $fwdDays): array
{
    $today = new DateTimeImmutable('today');
    $from  = $today->modify('-' . max(1, $backDays) . ' days');
    $to    = $today->modify('+' . max(1, $fwdDays) . ' days');
    $dueDay = max(1, $dueDay ?: 1);
    $out = [];

    $afterEnd = function (DateTimeImmutable $end, int $addMonths = 0) use ($dueDay) {
        // first day of the month following the period, plus (dueDay - 1) days,
        // clamped inside that month
        $m = $end->modify('first day of next month')->modify('+' . $addMonths . ' months');
        $last = (int)$m->format('t');
        return $m->setDate((int)$m->format('Y'), (int)$m->format('n'), min($dueDay, $last));
    };

    switch ($cadence) {
        case 'daily':
            for ($d = $from; $d <= $today; $d = $d->modify('+1 day')) {
                if ($d->format('N') === '7') continue;              // no Sunday cycle
                $out[] = ['period' => $d->format('Y-m-d'),
                          'label'  => $d->format('d M Y'),
                          'due_on' => $d->modify('+' . $dueDay . ' days')->format('Y-m-d')];
            }
            break;
        case 'weekly':
            $w = $from->modify('monday this week');
            while ($w <= $to) {
                $end = $w->modify('+6 days');
                $out[] = ['period' => $w->format('o-\WW'),
                          'label'  => 'Week ' . $w->format('W, o'),
                          'due_on' => $end->modify('+' . $dueDay . ' days')->format('Y-m-d')];
                $w = $w->modify('+7 days');
            }
            break;
        case 'monthly':
        case 'per_rbi_cadence':
            $m = $from->modify('first day of this month');
            while ($m <= $to) {
                $end = $m->modify('last day of this month');
                $out[] = ['period' => $m->format('Y-m'), 'label' => $m->format('M Y'),
                          'due_on' => $afterEnd($end)->format('Y-m-d')];
                $m = $m->modify('+1 month');
            }
            break;
        case 'quarterly':
        case 'half_yearly':
            $step  = $cadence === 'quarterly' ? 3 : 6;
            $start = $from->modify('first day of this month');
            $q     = (int)floor(((int)$start->format('n') - 1) / $step) * $step + 1;
            $cur   = $start->setDate((int)$start->format('Y'), $q, 1);
            while ($cur <= $to) {
                $end = $cur->modify('+' . ($step - 1) . ' months')->modify('last day of this month');
                $out[] = ['period' => $cur->format('Y') . '-' .
                            ($step === 3 ? 'Q' . (int)ceil((int)$cur->format('n') / 3)
                                         : 'H' . (int)ceil((int)$cur->format('n') / 6)),
                          'label'  => ($step === 3 ? 'Q' . (int)ceil((int)$cur->format('n') / 3) : 'H' . (int)ceil((int)$cur->format('n') / 6))
                                      . ' ' . $cur->format('Y') . ' (' . $cur->format('M') . '–' . $end->format('M') . ')',
                          'due_on' => $afterEnd($end)->format('Y-m-d')];
                $cur = $cur->modify('+' . $step . ' months');
            }
            break;
        case 'yearly':
            // Indian financial year, Apr–Mar; the annual return follows six months later
            $fy = (int)$from->format('n') >= 4 ? (int)$from->format('Y') : (int)$from->format('Y') - 1;
            for ($y = $fy; $y <= (int)$to->format('Y'); $y++) {
                $end = (new DateTimeImmutable())->setDate($y + 1, 3, 31);
                $out[] = ['period' => 'FY' . $y . '-' . substr((string)($y + 1), 2),
                          'label'  => 'FY ' . $y . '-' . substr((string)($y + 1), 2),
                          'due_on' => $afterEnd($end, 5)->format('Y-m-d')];
            }
            break;
        default: /* on_demand -> no calendar entries */ break;
    }
    return $out;
}

/** Expand every active obligation into tasks. Idempotent: UNIQUE(obligation_id, period). */
function cfo_run_scheduler(PDO $db): array
{
    $back = (int)cfo_setting($db, 'scheduler_back_days', '30');
    $fwd  = (int)cfo_setting($db, 'scheduler_fwd_days', '45');
    $obl  = $db->query("SELECT * FROM cfo_obligations WHERE is_active=1")->fetchAll(PDO::FETCH_ASSOC);
    $ins  = $db->prepare("INSERT IGNORE INTO cfo_tasks
              (obligation_id,title,task_type,period,owner_role,owner_employee_id,due_on,status)
              VALUES (?,?,?,?,?,?,?,'open')");
    $created = 0;
    foreach ($obl as $o) {
        foreach (cfo_periods((string)$o['cadence'], (int)$o['due_day'], $back, $fwd) as $p) {
            $ins->execute([$o['id'], $o['title'] . ' — ' . $p['label'], $o['obligation_type'],
                           $p['period'], $o['owner_role'], $o['owner_employee_id'] ?: null, $p['due_on']]);
            $created += $ins->rowCount();
        }
    }
    // derived, not typed: anything still open past its due date is overdue
    $db->exec("UPDATE cfo_tasks SET status='overdue' WHERE status='open' AND due_on < CURDATE()");
    $db->exec("UPDATE cfo_tasks SET status='open' WHERE status='overdue' AND due_on >= CURDATE()");
    $db->exec("UPDATE cfo_form_submissions SET status='overdue'
                WHERE status IN('draft') AND due_on < CURDATE()");
    return ['created' => $created, 'obligations' => count($obl)];
}

/* ------------------------------------------------------------------ *
 *  Shared readers
 * ------------------------------------------------------------------ */
function cfo_band(int $score): string
{
    return $score >= 15 ? 'Critical' : ($score >= 10 ? 'High' : ($score >= 5 ? 'Medium' : 'Low'));
}

function cfo_rag(array $k, $value): string
{
    if ($value === null || $value === '') return 'no_data';
    $v = (float)$value; $g = (float)$k['green_from']; $a = (float)$k['amber_from'];
    if ($k['direction'] === 'lower_better') return $v <= $g ? 'green' : ($v <= $a ? 'amber' : 'red');
    return $v >= $g ? 'green' : ($v >= $a ? 'amber' : 'red');
}

/** KRIs with their most recent reading and computed RAG. */
function cfo_kri_rows(PDO $db): array
{
    $rows = $db->query("
        SELECT k.*, r.period AS last_period, r.value AS last_value, r.captured_at, r.note,
               e.full_name AS captured_by_name, g.title AS kpa_title
          FROM cfo_kri k
          LEFT JOIN cfo_kri_readings r
                 ON r.id = (SELECT r2.id FROM cfo_kri_readings r2 WHERE r2.kri_id=k.id
                             ORDER BY r2.period DESC, r2.id DESC LIMIT 1)
          LEFT JOIN employees e ON e.id = r.captured_by
          LEFT JOIN kpa_goals g ON g.id = k.kpa_goal_id
         WHERE k.is_active=1 ORDER BY k.code")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['rag'] = cfo_rag($r, $r['last_value']);
        $r['trend'] = $db->query("SELECT period,value FROM cfo_kri_readings
                                   WHERE kri_id=" . (int)$r['id'] . " ORDER BY period DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
        $r['trend'] = array_reverse($r['trend']);
    }
    return $rows;
}

/**
 * The forms picture: every expected filing (from the calendar) plus every
 * submission that exists, in one list. A period with no submission row yet is
 * "not started" rather than missing from the screen.
 */
function cfo_form_rows(PDO $db): array
{
    $rows = $db->query("
        SELECT t.id AS task_id, t.period, t.due_on, t.owner_role, t.owner_employee_id,
               o.code AS obligation_code, o.title AS obligation_title,
               tpl.id AS template_id, tpl.code AS form_code, tpl.title AS form_title,
               tpl.cadence, tpl.approver_role, tpl.checker_role, tpl.version,
               s.id AS submission_id, s.status AS sub_status, s.sub_ref,
               s.prepared_by, s.reviewed_by, s.approved_by, s.updated_at,
               pe.full_name AS prepared_by_name, re.full_name AS reviewed_by_name,
               ae.full_name AS approved_by_name, oe.full_name AS owner_name
          FROM cfo_tasks t
          JOIN cfo_obligations o ON o.id = t.obligation_id
          JOIN cfo_form_templates tpl ON tpl.id = o.form_template_id
          LEFT JOIN cfo_form_submissions s ON s.template_id = tpl.id AND s.period = t.period
          LEFT JOIN employees pe ON pe.id = s.prepared_by
          LEFT JOIN employees re ON re.id = s.reviewed_by
          LEFT JOIN employees ae ON ae.id = s.approved_by
          LEFT JOIN employees oe ON oe.id = t.owner_employee_id
         ORDER BY t.due_on DESC, tpl.code")->fetchAll(PDO::FETCH_ASSOC);

    // on-demand templates have no calendar, so their submissions are listed directly
    $extra = $db->query("
        SELECT NULL AS task_id, s.period, s.due_on, tpl.approver_role AS owner_role,
               NULL AS owner_employee_id, NULL AS obligation_code, tpl.title AS obligation_title,
               tpl.id AS template_id, tpl.code AS form_code, tpl.title AS form_title,
               tpl.cadence, tpl.approver_role, tpl.checker_role, tpl.version,
               s.id AS submission_id, s.status AS sub_status, s.sub_ref,
               s.prepared_by, s.reviewed_by, s.approved_by, s.updated_at,
               pe.full_name AS prepared_by_name, re.full_name AS reviewed_by_name,
               ae.full_name AS approved_by_name, pe.full_name AS owner_name
          FROM cfo_form_submissions s
          JOIN cfo_form_templates tpl ON tpl.id = s.template_id
          LEFT JOIN employees pe ON pe.id = s.prepared_by
          LEFT JOIN employees re ON re.id = s.reviewed_by
          LEFT JOIN employees ae ON ae.id = s.approved_by
         WHERE tpl.cadence='on_demand'
         ORDER BY s.id DESC")->fetchAll(PDO::FETCH_ASSOC);

    $all = array_merge($extra, $rows);
    $today = date('Y-m-d');
    foreach ($all as &$r) {
        $r['status'] = $r['sub_status'] ?: 'not_started';
        $r['is_overdue'] = ($r['due_on'] && $r['due_on'] < $today
                            && !in_array($r['status'], ['approved', 'reviewed'], true));
        if ($r['is_overdue'] && $r['status'] === 'not_started') $r['status'] = 'overdue';
    }
    return $all;
}

/* ------------------------------------------------------------------ *
 *  GET — meta
 * ------------------------------------------------------------------ */
if (method() === 'GET' && isset($_GET['meta'])) {
    $me = current_employee_id();
    ok([
        'can_write' => cfo_can_write(),
        'can_sign'  => cfo_can_sign(),
        'my_role'   => current_role(),
        'my_role_label' => cfo_role_label(),
        'my_employee_id' => $me,
        'owners'    => $db->query("SELECT id, full_name, designation, department FROM employees
                                    ORDER BY (department='Finance') DESC, full_name")->fetchAll(PDO::FETCH_ASSOC),
        'owner_roles' => $db->query("SELECT DISTINCT owner_role FROM cfo_obligations
                                      UNION SELECT DISTINCT owner_role FROM cfo_risks
                                      ORDER BY owner_role")->fetchAll(PDO::FETCH_COLUMN),
        'templates' => $db->query("SELECT id,code,title,cadence,version,approver_role,checker_role,is_active
                                     FROM cfo_form_templates ORDER BY code")->fetchAll(PDO::FETCH_ASSOC),
        'obligations' => $db->query("SELECT id,code,obl_ref,title,obligation_type,authority,cadence,due_day,
                                            owner_role,form_template_id,reference,is_active
                                       FROM cfo_obligations ORDER BY obligation_type, code")->fetchAll(PDO::FETCH_ASSOC),
        'kris'      => $db->query("SELECT id,code,name,indicator_kind,unit FROM cfo_kri WHERE is_active=1 ORDER BY code")->fetchAll(PDO::FETCH_ASSOC),
        'kpa_goals' => $db->query("SELECT id, kpi_ref, title FROM kpa_goals WHERE status='active' ORDER BY id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC),
        'doc_folders' => $db->query("SELECT id,name FROM doc_folders ORDER BY name")->fetchAll(PDO::FETCH_ASSOC),
        'cadences'  => ['daily','weekly','monthly','quarterly','half_yearly','yearly','per_rbi_cadence','on_demand'],
        'org'       => ['name' => cfo_setting($db, 'org_name', 'PayG'),
                        'timezone' => cfo_setting($db, 'timezone', 'Asia/Kolkata (IST)'),
                        'work_days' => cfo_setting($db, 'work_days'),
                        'fy_start_month' => (int)cfo_setting($db, 'fy_start_month', '4')],
    ]);
}

/* ------------------------------------------------------------------ *
 *  GET — single records (drill-down)
 * ------------------------------------------------------------------ */
if (method() === 'GET' && isset($_GET['task'])) {
    $id = (int)$_GET['task'];
    $s = $db->prepare("SELECT t.*, o.code AS obligation_code, o.obl_ref, o.title AS obligation_title,
                              o.authority, o.reference, o.cadence, o.form_template_id,
                              tpl.code AS form_code, tpl.title AS form_title,
                              e.full_name AS owner_name, e.designation AS owner_designation,
                              ce.full_name AS completed_by_name
                         FROM cfo_tasks t
                         JOIN cfo_obligations o ON o.id=t.obligation_id
                         LEFT JOIN cfo_form_templates tpl ON tpl.id=o.form_template_id
                         LEFT JOIN employees e ON e.id=t.owner_employee_id
                         LEFT JOIN employees ce ON ce.id=t.completed_by
                        WHERE t.id=?");
    $s->execute([$id]);
    $t = $s->fetch(PDO::FETCH_ASSOC);
    if (!$t) fail('Task not found', 404);
    $sub = null;
    if ($t['form_template_id']) {
        $q = $db->prepare("SELECT * FROM cfo_form_submissions WHERE template_id=? AND period=?");
        $q->execute([$t['form_template_id'], $t['period']]);
        $sub = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $ev = $db->prepare("SELECT * FROM cfo_evidence WHERE entity_type='task' AND entity_id=? ORDER BY id DESC");
    $ev->execute([$id]);
    $au = $db->prepare("SELECT * FROM cfo_audit WHERE entity='task' AND entity_id=? ORDER BY id DESC LIMIT 30");
    $au->execute([(string)$id]);
    ok(['task' => $t, 'submission' => $sub, 'evidence' => $ev->fetchAll(PDO::FETCH_ASSOC),
        'history' => $au->fetchAll(PDO::FETCH_ASSOC)]);
}

if (method() === 'GET' && isset($_GET['risk'])) {
    $id = (int)$_GET['risk'];
    $s = $db->prepare("SELECT r.*, e.full_name AS owner_name FROM cfo_risks r
                        LEFT JOIN employees e ON e.id=r.owner_employee_id WHERE r.id=?");
    $s->execute([$id]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if (!$r) fail('Risk not found', 404);
    $r['inherent_band'] = cfo_band((int)$r['inherent_score']);
    $r['residual_band'] = cfo_band((int)$r['residual_score']);
    $a = $db->prepare("SELECT * FROM cfo_actions WHERE source_type='risk' AND source_ref=? ORDER BY id DESC");
    $a->execute([$r['ref']]);
    $ev = $db->prepare("SELECT * FROM cfo_evidence WHERE entity_type='risk' AND entity_id=? ORDER BY id DESC");
    $ev->execute([$id]);
    $au = $db->prepare("SELECT * FROM cfo_audit WHERE entity='risk' AND entity_id=? ORDER BY id DESC LIMIT 50");
    $au->execute([(string)$id]);
    ok(['risk' => $r, 'actions' => $a->fetchAll(PDO::FETCH_ASSOC),
        'evidence' => $ev->fetchAll(PDO::FETCH_ASSOC), 'history' => $au->fetchAll(PDO::FETCH_ASSOC)]);
}

if (method() === 'GET' && isset($_GET['submission'])) {
    $id = (int)$_GET['submission'];
    $s = $db->prepare("SELECT s.*, tpl.code AS form_code, tpl.title AS form_title, tpl.fields_json,
                              tpl.cadence, tpl.checker_role, tpl.approver_role, tpl.version,
                              pe.full_name AS prepared_by_name, re.full_name AS reviewed_by_name,
                              ae.full_name AS approved_by_name
                         FROM cfo_form_submissions s
                         JOIN cfo_form_templates tpl ON tpl.id=s.template_id
                         LEFT JOIN employees pe ON pe.id=s.prepared_by
                         LEFT JOIN employees re ON re.id=s.reviewed_by
                         LEFT JOIN employees ae ON ae.id=s.approved_by
                        WHERE s.id=?");
    $s->execute([$id]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if (!$row) fail('Submission not found', 404);
    $row['fields']  = json_decode((string)$row['fields_json'], true) ?: [];
    $row['data']    = json_decode((string)$row['data_json'], true) ?: [];
    $ev = $db->prepare("SELECT * FROM cfo_evidence WHERE entity_type='submission' AND entity_id=? ORDER BY id DESC");
    $ev->execute([$id]);
    $au = $db->prepare("SELECT * FROM cfo_audit WHERE entity='submission' AND entity_id=? ORDER BY id DESC LIMIT 40");
    $au->execute([(string)$id]);
    ok(['submission' => $row, 'evidence' => $ev->fetchAll(PDO::FETCH_ASSOC),
        'history' => $au->fetchAll(PDO::FETCH_ASSOC)]);
}

if (method() === 'GET' && isset($_GET['review'])) {
    $id = (int)$_GET['review'];
    $s = $db->prepare("SELECT r.*, e.full_name AS signed_by_name FROM cfo_reviews r
                        LEFT JOIN employees e ON e.id=r.signed_by WHERE r.id=?");
    $s->execute([$id]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if (!$r) fail('Review not found', 404);
    $ev = $db->prepare("SELECT * FROM cfo_evidence WHERE entity_type='review' AND entity_id=? ORDER BY id DESC");
    $ev->execute([$id]);
    // what the meeting has to look at: this period's overdue work and red indicators
    $open = $db->query("SELECT COUNT(*) FROM cfo_tasks WHERE status='overdue'")->fetchColumn();
    $capa = $db->query("SELECT COUNT(*) FROM cfo_actions WHERE status NOT IN('closed','verified')")->fetchColumn();
    $reds = array_values(array_filter(cfo_kri_rows($db), fn($k) => $k['rag'] === 'red'));
    ok(['review' => $r, 'evidence' => $ev->fetchAll(PDO::FETCH_ASSOC),
        'pack' => ['overdue_tasks' => (int)$open, 'open_actions' => (int)$capa, 'red_indicators' => $reds]]);
}

/* ------------------------------------------------------------------ *
 *  GET — CSV exports (the Reports screen's registers)
 * ------------------------------------------------------------------ */
if (method() === 'GET' && isset($_GET['export'])) {
    $what = (string)$_GET['export'];
    $sets = [
        'risk' => ['risk-register', "SELECT r.ref AS 'Ref', r.sub_process AS 'Sub-process', r.description AS 'Description',
                        r.inherent_likelihood AS 'Inh. likelihood', r.inherent_impact AS 'Inh. impact', r.inherent_score AS 'Inherent score',
                        r.controls AS 'Controls', r.residual_likelihood AS 'Res. likelihood', r.residual_impact AS 'Res. impact',
                        r.residual_score AS 'Residual score', r.owner_role AS 'Owner role', e.full_name AS 'Owner',
                        r.next_review AS 'Next review', r.status AS 'Status', r.version AS 'Version'
                     FROM cfo_risks r LEFT JOIN employees e ON e.id=r.owner_employee_id ORDER BY r.ref"],
        'capa' => ['capa-register', "SELECT a.ref AS 'Ref', a.title AS 'Title', a.action_kind AS 'Kind', a.source_type AS 'Source',
                        a.source_ref AS 'Source ref', a.owner_role AS 'Owner role', e.full_name AS 'Owner', a.due_on AS 'Due',
                        a.priority AS 'Priority', a.status AS 'Status', a.closure_evidence AS 'Closure evidence', a.closed_at AS 'Closed at'
                     FROM cfo_actions a LEFT JOIN employees e ON e.id=a.owner_employee_id ORDER BY a.id"],
        'calendar' => ['compliance-calendar', "SELECT t.task_ref AS 'Ref', o.code AS 'Obligation', t.title AS 'Task',
                        t.task_type AS 'Type', t.period AS 'Period', t.due_on AS 'Due', t.owner_role AS 'Owner role',
                        e.full_name AS 'Owner', t.status AS 'Status', o.authority AS 'Authority', o.reference AS 'Reference'
                     FROM cfo_tasks t JOIN cfo_obligations o ON o.id=t.obligation_id
                     LEFT JOIN employees e ON e.id=t.owner_employee_id ORDER BY t.due_on DESC"],
        'documents' => ['document-registry', "SELECT tpl.code AS 'Form code', tpl.title AS 'Title', tpl.cadence AS 'Cadence',
                        tpl.version AS 'Version', tpl.checker_role AS 'Checker', tpl.approver_role AS 'Approver',
                        (SELECT COUNT(*) FROM cfo_form_submissions s WHERE s.template_id=tpl.id) AS 'Submissions',
                        (SELECT COUNT(*) FROM cfo_form_submissions s WHERE s.template_id=tpl.id AND s.status='approved') AS 'Approved'
                     FROM cfo_form_templates tpl ORDER BY tpl.code"],
        'kri' => ['kpi-kri-register', "SELECT k.code AS 'Code', k.name AS 'Indicator', k.indicator_kind AS 'Kind',
                        k.unit AS 'Unit', k.direction AS 'Direction', k.target_value AS 'Target', k.green_from AS 'Green from',
                        k.amber_from AS 'Amber from', k.frequency AS 'Frequency', k.owner_role AS 'Owner',
                        r.period AS 'Last period', r.value AS 'Last value'
                     FROM cfo_kri k LEFT JOIN cfo_kri_readings r ON r.id=(SELECT r2.id FROM cfo_kri_readings r2
                        WHERE r2.kri_id=k.id ORDER BY r2.period DESC, r2.id DESC LIMIT 1) ORDER BY k.code"],
        'audit' => ['audit-trail', "SELECT a.created_at AS 'When (IST)', a.user_name AS 'User', a.action AS 'Action',
                        a.entity AS 'Entity', a.entity_id AS 'Entity id', a.summary AS 'Summary'
                     FROM cfo_audit a ORDER BY a.id DESC LIMIT 5000"],
    ];
    if (!isset($sets[$what])) fail('Unknown export');
    [$name, $sql] = $sets[$what];
    $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    cfo_log($db, 'export', 'report', $what, 'exported ' . $name . ' (' . count($rows) . ' rows)');
    header_remove('Content-Type');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");                       // Excel opens UTF-8 correctly
    if ($rows) fputcsv($out, array_keys($rows[0]));
    foreach ($rows as $r) fputcsv($out, array_values($r));
    fclose($out);
    exit;
}

/* ------------------------------------------------------------------ *
 *  GET — areas
 * ------------------------------------------------------------------ */
if (method() === 'GET') {
    $area = (string)($_GET['area'] ?? 'dashboard');
    $me   = current_employee_id();
    $today = date('Y-m-d');

    if ($area === 'dashboard') {
        $t = $db->query("SELECT
              COUNT(*) total,
              SUM(due_on <= CURDATE()) due_to_date,
              SUM(due_on <= CURDATE() AND status IN('done','waived')) done_to_date,
              SUM(status='overdue') overdue,
              SUM(status IN('open','in_progress') AND due_on BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)) due_week,
              SUM(task_type='statutory' AND status='overdue') statutory_overdue,
              SUM(task_type='rbi' AND status='overdue') rbi_overdue,
              SUM(task_type='tax' AND status='overdue') tax_overdue
            FROM cfo_tasks")->fetch(PDO::FETCH_ASSOC);
        $forms = cfo_form_rows($db);
        $overdueForms = count(array_filter($forms, fn($f) => $f['is_overdue']));
        $health = (int)$t['due_to_date'] > 0
            ? round(((int)$t['done_to_date'] / (int)$t['due_to_date']) * 100) : 100;

        $risks = $db->query("SELECT residual_score FROM cfo_risks WHERE status IN('open','accepted')")->fetchAll(PDO::FETCH_COLUMN);
        $profile = ['Critical' => 0, 'High' => 0, 'Medium' => 0, 'Low' => 0];
        foreach ($risks as $sc) $profile[cfo_band((int)$sc)]++;

        $kris = cfo_kri_rows($db);
        $rag = ['green' => 0, 'amber' => 0, 'red' => 0, 'no_data' => 0];
        foreach ($kris as $k) $rag[$k['rag']]++;

        ok([
            'tiles' => [
                'health_pct'   => $health,
                'due_to_date'  => (int)$t['due_to_date'],
                'done_to_date' => (int)$t['done_to_date'],
                'total_tasks'  => (int)$t['total'],
                'overdue'      => (int)$t['overdue'],
                'due_week'     => (int)$t['due_week'],
                'open_actions' => (int)$db->query("SELECT COUNT(*) FROM cfo_actions WHERE status NOT IN('closed','verified')")->fetchColumn(),
                'overdue_forms'=> $overdueForms,
                'overdue_reviews' => (int)$db->query("SELECT COUNT(*) FROM cfo_reviews
                                        WHERE status IN('scheduled','in_progress') AND scheduled_on < CURDATE()")->fetchColumn(),
                // shares the governance Compliance register rather than keeping a second
                // copy of the policy list
                'policies_to_review' => (int)$db->query("SELECT COUNT(*) FROM compliance_items
                                        WHERE valid_till IS NOT NULL
                                          AND valid_till <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                                          AND status <> 'archived'")->fetchColumn(),
                'statutory_overdue' => (int)$t['statutory_overdue'],
                'rbi_overdue'  => (int)$t['rbi_overdue'],
                'tax_overdue'  => (int)$t['tax_overdue'],
            ],
            'risk_profile' => $profile,
            'kri_rag'      => $rag,
            'upcoming_reviews' => $db->query("SELECT r.*, e.full_name AS signed_by_name FROM cfo_reviews r
                                    LEFT JOIN employees e ON e.id=r.signed_by
                                   WHERE r.scheduled_on BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                                   ORDER BY r.scheduled_on LIMIT 10")->fetchAll(PDO::FETCH_ASSOC),
            'overdue_top'  => $db->query("SELECT t.id, t.task_ref, t.title, t.task_type, t.period, t.due_on,
                                        t.owner_role, e.full_name AS owner_name,
                                        DATEDIFF(CURDATE(), t.due_on) AS days_late
                                   FROM cfo_tasks t LEFT JOIN employees e ON e.id=t.owner_employee_id
                                  WHERE t.status='overdue' ORDER BY t.due_on LIMIT 12")->fetchAll(PDO::FETCH_ASSOC),
            'by_type'      => $db->query("SELECT task_type,
                                        COUNT(*) total, SUM(status='overdue') overdue,
                                        SUM(status IN('done','waived')) done
                                   FROM cfo_tasks GROUP BY task_type ORDER BY overdue DESC")->fetchAll(PDO::FETCH_ASSOC),
            'as_of' => date('d M Y H:i'),
        ]);
    }

    if ($area === 'calendar') {
        $w = ['1']; $args = [];
        if (($_GET['scope'] ?? '') === 'mine') {
            $w[] = '(t.owner_employee_id = ? OR (t.owner_employee_id IS NULL AND t.owner_role = ?))';
            $args[] = $me; $args[] = cfo_role_label();
        }
        if (!empty($_GET['status']))  { $w[] = 't.status=?';    $args[] = $_GET['status']; }
        if (!empty($_GET['type']))    { $w[] = 't.task_type=?'; $args[] = $_GET['type']; }
        if (!empty($_GET['owner']))   { $w[] = 't.owner_employee_id=?'; $args[] = (int)$_GET['owner']; }
        if (!empty($_GET['period']))  { $w[] = 't.period LIKE ?'; $args[] = $_GET['period'] . '%'; }
        if (!empty($_GET['q'])) {
            $w[] = '(t.title LIKE ? OR o.code LIKE ? OR o.authority LIKE ?)';
            $like = '%' . $_GET['q'] . '%'; array_push($args, $like, $like, $like);
        }
        $s = $db->prepare("SELECT t.*, o.code AS obligation_code, o.authority, o.cadence, o.reference,
                                  o.form_template_id, tpl.code AS form_code,
                                  e.full_name AS owner_name, e.designation AS owner_designation,
                                  s.id AS submission_id, s.status AS submission_status,
                                  DATEDIFF(CURDATE(), t.due_on) AS days_late
                             FROM cfo_tasks t
                             JOIN cfo_obligations o ON o.id=t.obligation_id
                             LEFT JOIN cfo_form_templates tpl ON tpl.id=o.form_template_id
                             LEFT JOIN cfo_form_submissions s ON s.template_id=o.form_template_id AND s.period=t.period
                             LEFT JOIN employees e ON e.id=t.owner_employee_id
                            WHERE " . implode(' AND ', $w) . "
                            ORDER BY (t.status='overdue') DESC, t.due_on ASC LIMIT 500");
        $s->execute($args);
        $tot = $db->query("SELECT COUNT(*) total, SUM(status='overdue') overdue,
                                  SUM(status IN('open','in_progress') AND due_on BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY)) due_week,
                                  SUM(status IN('done','waived')) done,
                                  SUM(status='in_progress') in_progress
                             FROM cfo_tasks")->fetch(PDO::FETCH_ASSOC);
        ok(['rows' => $s->fetchAll(PDO::FETCH_ASSOC), 'totals' => $tot,
            'types' => $db->query("SELECT DISTINCT task_type FROM cfo_tasks ORDER BY task_type")->fetchAll(PDO::FETCH_COLUMN)]);
    }

    if ($area === 'reviews') {
        $w = ['1']; $args = [];
        if (!empty($_GET['status'])) { $w[] = 'r.status=?'; $args[] = $_GET['status']; }
        if (!empty($_GET['type']))   { $w[] = 'r.review_type=?'; $args[] = $_GET['type']; }
        $s = $db->prepare("SELECT r.*, e.full_name AS signed_by_name,
                                  (SELECT COUNT(*) FROM cfo_evidence v WHERE v.entity_type='review' AND v.entity_id=r.id) AS evidence_count
                             FROM cfo_reviews r LEFT JOIN employees e ON e.id=r.signed_by
                            WHERE " . implode(' AND ', $w) . " ORDER BY r.scheduled_on DESC");
        $s->execute($args);
        $tot = $db->query("SELECT COUNT(*) total, SUM(status='signed') signed_cnt,
                                  SUM(status IN('scheduled','in_progress') AND scheduled_on < CURDATE()) overdue_cnt,
                                  SUM(status IN('scheduled','in_progress') AND scheduled_on >= CURDATE()) upcoming_cnt
                             FROM cfo_reviews")->fetch(PDO::FETCH_ASSOC);
        ok(['rows' => $s->fetchAll(PDO::FETCH_ASSOC), 'totals' => $tot, 'can_sign' => cfo_can_sign()]);
    }

    if ($area === 'forms') {
        $rows = cfo_form_rows($db);
        $roleLabel = cfo_role_label();
        $mine = array_values(array_filter($rows, fn($r) =>
            (((int)$r['owner_employee_id'] === $me && $me) || $r['owner_role'] === $roleLabel)
            && in_array($r['status'], ['not_started', 'draft', 'rejected', 'overdue'], true)));
        $awaiting = array_values(array_filter($rows, fn($r) =>
            ($r['status'] === 'submitted' && $r['checker_role'] === $roleLabel)
            || ($r['status'] === 'reviewed' && ($r['approver_role'] === $roleLabel || cfo_can_sign()))
            || ($r['status'] === 'submitted' && cfo_can_sign() && $r['checker_role'] === null)));
        $tot = ['total' => count($rows),
                'approved' => count(array_filter($rows, fn($r) => $r['status'] === 'approved')),
                'pending'  => count(array_filter($rows, fn($r) => in_array($r['status'], ['draft','submitted','reviewed'], true))),
                'overdue'  => count(array_filter($rows, fn($r) => $r['is_overdue']))];
        ok(['rows' => $rows, 'to_prepare' => $mine, 'awaiting' => $awaiting, 'totals' => $tot,
            'templates' => $db->query("SELECT tpl.*,
                                (SELECT COUNT(*) FROM cfo_form_submissions s WHERE s.template_id=tpl.id) AS submissions
                              FROM cfo_form_templates tpl ORDER BY tpl.code")->fetchAll(PDO::FETCH_ASSOC),
            'on_demand' => $db->query("SELECT id,code,title FROM cfo_form_templates
                                        WHERE cadence='on_demand' AND is_active=1 ORDER BY code")->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($area === 'capa') {
        $w = ['1']; $args = [];
        foreach (['status' => 'a.status', 'priority' => 'a.priority', 'source_type' => 'a.source_type',
                  'action_kind' => 'a.action_kind'] as $k => $col) {
            if (!empty($_GET[$k])) { $w[] = "$col=?"; $args[] = $_GET[$k]; }
        }
        if (($_GET['bucket'] ?? '') !== '') {
            $b = $_GET['bucket'];
            $w[] = "a.status NOT IN('closed','verified')";
            if ($b === '30')   $w[] = 'DATEDIFF(CURDATE(), a.due_on) <= 30';
            if ($b === '60')   $w[] = 'DATEDIFF(CURDATE(), a.due_on) BETWEEN 31 AND 60';
            if ($b === 'over') $w[] = 'DATEDIFF(CURDATE(), a.due_on) > 60';
        }
        if (!empty($_GET['q'])) {
            $w[] = '(a.title LIKE ? OR a.ref LIKE ? OR a.description LIKE ? OR a.source_ref LIKE ?)';
            $like = '%' . $_GET['q'] . '%'; array_push($args, $like, $like, $like, $like);
        }
        $s = $db->prepare("SELECT a.*, e.full_name AS owner_name,
                                  DATEDIFF(CURDATE(), a.due_on) AS age_days
                             FROM cfo_actions a LEFT JOIN employees e ON e.id=a.owner_employee_id
                            WHERE " . implode(' AND ', $w) . "
                            ORDER BY FIELD(a.priority,'critical','high','medium','low'), a.due_on");
        $s->execute($args);
        $tot = $db->query("SELECT COUNT(*) total,
                              SUM(status NOT IN('closed','verified')) open_cnt,
                              SUM(status NOT IN('closed','verified') AND DATEDIFF(CURDATE(),due_on) <= 30) b30,
                              SUM(status NOT IN('closed','verified') AND DATEDIFF(CURDATE(),due_on) BETWEEN 31 AND 60) b60,
                              SUM(status NOT IN('closed','verified') AND DATEDIFF(CURDATE(),due_on) > 60) bover,
                              SUM(status IN('closed','verified')) closed_cnt
                            FROM cfo_actions")->fetch(PDO::FETCH_ASSOC);
        ok(['rows' => $s->fetchAll(PDO::FETCH_ASSOC), 'totals' => $tot,
            'risk_refs' => $db->query("SELECT ref, sub_process FROM cfo_risks ORDER BY ref")->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($area === 'risk') {
        $w = ['1']; $args = [];
        if (!empty($_GET['status'])) { $w[] = 'r.status=?'; $args[] = $_GET['status']; }
        if (!empty($_GET['sub_process'])) { $w[] = 'r.sub_process=?'; $args[] = $_GET['sub_process']; }
        if (!empty($_GET['q'])) {
            $w[] = '(r.ref LIKE ? OR r.description LIKE ? OR r.sub_process LIKE ? OR r.controls LIKE ?)';
            $like = '%' . $_GET['q'] . '%'; array_push($args, $like, $like, $like, $like);
        }
        $s = $db->prepare("SELECT r.*, e.full_name AS owner_name,
                                  (SELECT COUNT(*) FROM cfo_actions a WHERE a.source_type='risk' AND a.source_ref=r.ref) AS action_count
                             FROM cfo_risks r LEFT JOIN employees e ON e.id=r.owner_employee_id
                            WHERE " . implode(' AND ', $w) . " ORDER BY r.ref");
        $s->execute($args);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['inherent_band'] = cfo_band((int)$r['inherent_score']);
            $r['residual_band'] = cfo_band((int)$r['residual_score']);
        }
        unset($r);
        // 5x5 heatmap of RESIDUAL exposure — likelihood (rows) x impact (cols)
        $heat = [];
        for ($l = 5; $l >= 1; $l--) for ($i = 1; $i <= 5; $i++) $heat[$l][$i] = [];
        $band = ['Critical' => 0, 'High' => 0, 'Medium' => 0, 'Low' => 0];
        foreach ($rows as $r) {
            $l = max(1, min(5, (int)$r['residual_likelihood']));
            $i = max(1, min(5, (int)$r['residual_impact']));
            $heat[$l][$i][] = $r['ref'];
            $band[$r['residual_band']]++;
        }
        ok(['rows' => $rows, 'heat' => $heat, 'bands' => $band,
            'sub_processes' => $db->query("SELECT DISTINCT sub_process FROM cfo_risks ORDER BY sub_process")->fetchAll(PDO::FETCH_COLUMN),
            'totals' => ['total' => count($rows)] + $band]);
    }

    if ($area === 'kri') {
        $rows = cfo_kri_rows($db);
        $rag = ['green' => 0, 'amber' => 0, 'red' => 0, 'no_data' => 0];
        foreach ($rows as $r) $rag[$r['rag']]++;
        ok(['rows' => $rows, 'rag' => $rag, 'totals' => ['total' => count($rows)] + $rag,
            'periods' => $db->query("SELECT DISTINCT period FROM cfo_kri_readings ORDER BY period DESC LIMIT 24")->fetchAll(PDO::FETCH_COLUMN)]);
    }

    if ($area === 'evidence') {
        $w = ['1']; $args = [];
        if (!empty($_GET['entity_type'])) { $w[] = 'v.entity_type=?'; $args[] = $_GET['entity_type']; }
        if (!empty($_GET['q'])) {
            $w[] = '(v.title LIKE ? OR v.file_name LIKE ? OR v.note LIKE ?)';
            $like = '%' . $_GET['q'] . '%'; array_push($args, $like, $like, $like);
        }
        $s = $db->prepare("SELECT v.*, e.full_name AS uploaded_by_name, f.orig_name AS doc_name,
                                  fo.name AS folder_name
                             FROM cfo_evidence v
                             LEFT JOIN employees e ON e.id=v.uploaded_by
                             LEFT JOIN doc_files f ON f.id=v.doc_id
                             LEFT JOIN doc_folders fo ON fo.id=f.folder_id
                            WHERE " . implode(' AND ', $w) . " ORDER BY v.id DESC LIMIT 500");
        $s->execute($args);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        $byType = $db->query("SELECT entity_type, COUNT(*) n FROM cfo_evidence GROUP BY entity_type")->fetchAll(PDO::FETCH_ASSOC);
        // what still has no evidence at all — the honest gap list
        $gaps = $db->query("SELECT t.id, t.task_ref, t.title, t.due_on FROM cfo_tasks t
                             WHERE t.status IN('done','overdue')
                               AND NOT EXISTS (SELECT 1 FROM cfo_evidence v WHERE v.entity_type='task' AND v.entity_id=t.id)
                             ORDER BY t.due_on DESC LIMIT 25")->fetchAll(PDO::FETCH_ASSOC);
        ok(['rows' => $rows, 'by_type' => $byType, 'gaps' => $gaps,
            'totals' => ['total' => count($rows),
                         'linked_docs' => (int)$db->query("SELECT COUNT(*) FROM cfo_evidence WHERE doc_id IS NOT NULL")->fetchColumn(),
                         'gaps' => (int)$db->query("SELECT COUNT(*) FROM cfo_tasks t WHERE t.status IN('done','overdue')
                                        AND NOT EXISTS (SELECT 1 FROM cfo_evidence v WHERE v.entity_type='task' AND v.entity_id=t.id)")->fetchColumn(),
                         'files' => (int)$db->query("SELECT COUNT(*) FROM doc_files WHERE is_deleted=0")->fetchColumn()],
            'doc_files' => $db->query("SELECT f.id, f.orig_name, fo.name AS folder FROM doc_files f
                                        LEFT JOIN doc_folders fo ON fo.id=f.folder_id
                                       WHERE f.is_deleted=0 ORDER BY f.id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($area === 'reports') {
        ok([
            'registers' => [
                ['key' => 'risk',      'title' => 'Risk Register',              'rows' => (int)$db->query("SELECT COUNT(*) FROM cfo_risks")->fetchColumn()],
                ['key' => 'capa',      'title' => 'CAPA Register',              'rows' => (int)$db->query("SELECT COUNT(*) FROM cfo_actions")->fetchColumn()],
                ['key' => 'calendar',  'title' => 'Compliance Calendar status', 'rows' => (int)$db->query("SELECT COUNT(*) FROM cfo_tasks")->fetchColumn()],
                ['key' => 'documents', 'title' => 'Document Registry',          'rows' => (int)$db->query("SELECT COUNT(*) FROM cfo_form_templates")->fetchColumn()],
                ['key' => 'kri',       'title' => 'KPI / KRI Register',         'rows' => (int)$db->query("SELECT COUNT(*) FROM cfo_kri")->fetchColumn()],
                ['key' => 'audit',     'title' => 'Audit Trail',                'rows' => (int)$db->query("SELECT COUNT(*) FROM cfo_audit")->fetchColumn()],
            ],
            'signed_reviews' => $db->query("SELECT r.*, e.full_name AS signed_by_name FROM cfo_reviews r
                                            LEFT JOIN employees e ON e.id=r.signed_by
                                           WHERE r.status='signed' ORDER BY r.signed_at DESC LIMIT 24")->fetchAll(PDO::FETCH_ASSOC),
            'pack' => [
                'period'      => date('M Y'),
                'tasks'       => (int)$db->query("SELECT COUNT(*) FROM cfo_tasks")->fetchColumn(),
                'overdue'     => (int)$db->query("SELECT COUNT(*) FROM cfo_tasks WHERE status='overdue'")->fetchColumn(),
                'submissions' => (int)$db->query("SELECT COUNT(*) FROM cfo_form_submissions")->fetchColumn(),
                'approved'    => (int)$db->query("SELECT COUNT(*) FROM cfo_form_submissions WHERE status='approved'")->fetchColumn(),
                'risks'       => (int)$db->query("SELECT COUNT(*) FROM cfo_risks")->fetchColumn(),
                'actions'     => (int)$db->query("SELECT COUNT(*) FROM cfo_actions")->fetchColumn(),
                'evidence'    => (int)$db->query("SELECT COUNT(*) FROM cfo_evidence")->fetchColumn(),
            ],
        ]);
    }

    if ($area === 'audit') {
        $w = ['1']; $args = [];
        if (!empty($_GET['entity'])) { $w[] = 'a.entity=?'; $args[] = $_GET['entity']; }
        if (!empty($_GET['action'])) { $w[] = 'a.action=?'; $args[] = $_GET['action']; }
        if (!empty($_GET['q'])) {
            $w[] = '(a.summary LIKE ? OR a.user_name LIKE ? OR a.entity_id LIKE ?)';
            $like = '%' . $_GET['q'] . '%'; array_push($args, $like, $like, $like);
        }
        $s = $db->prepare("SELECT a.* FROM cfo_audit a WHERE " . implode(' AND ', $w) .
                          " ORDER BY a.id DESC LIMIT " . max(50, min(2000, (int)($_GET['limit'] ?? 300))));
        $s->execute($args);
        ok(['rows' => $s->fetchAll(PDO::FETCH_ASSOC),
            'entities' => $db->query("SELECT DISTINCT entity FROM cfo_audit ORDER BY entity")->fetchAll(PDO::FETCH_COLUMN),
            'actions'  => $db->query("SELECT DISTINCT action FROM cfo_audit ORDER BY action")->fetchAll(PDO::FETCH_COLUMN),
            'totals'   => $db->query("SELECT COUNT(*) total,
                                        SUM(created_at >= CURDATE()) today_cnt,
                                        SUM(created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)) week_cnt,
                                        COUNT(DISTINCT user_name) users
                                      FROM cfo_audit")->fetch(PDO::FETCH_ASSOC)]);
    }

    if ($area === 'settings') {
        $set = [];
        foreach ($db->query("SELECT skey,sval,updated_at FROM cfo_settings ORDER BY skey")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $set[$r['skey']] = $r['sval'];
        }
        ok([
            'settings' => $set,
            'templates' => $db->query("SELECT tpl.*,
                            (SELECT COUNT(*) FROM cfo_form_submissions s WHERE s.template_id=tpl.id) AS submissions
                          FROM cfo_form_templates tpl ORDER BY tpl.code")->fetchAll(PDO::FETCH_ASSOC),
            'obligations' => $db->query("SELECT o.*, tpl.code AS form_code, e.full_name AS owner_name,
                            (SELECT COUNT(*) FROM cfo_tasks t WHERE t.obligation_id=o.id) AS task_count
                          FROM cfo_obligations o
                          LEFT JOIN cfo_form_templates tpl ON tpl.id=o.form_template_id
                          LEFT JOIN employees e ON e.id=o.owner_employee_id
                          ORDER BY o.obligation_type, o.code")->fetchAll(PDO::FETCH_ASSOC),
            'users' => $db->query("SELECT u.username, u.role, u.is_active, u.last_login_at,
                                          e.full_name, e.email, e.designation, e.department
                                     FROM sales_users u LEFT JOIN employees e ON e.id=u.employee_id
                                    WHERE u.role IN('admin','cfo','finance_manager')
                                       OR e.department='Finance' ORDER BY u.role, u.username")->fetchAll(PDO::FETCH_ASSOC),
            'can_sign' => cfo_can_sign(),
        ]);
    }

    fail('Unknown area', 404);
}

/* ------------------------------------------------------------------ *
 *  POST — AI analysis (evidence first: the model only interprets)
 * ------------------------------------------------------------------ */
if (method() === 'POST' && isset($_GET['ai'])) {
    $area = (string)$_GET['ai'];
    $ev = [];
    $q = fn($sql) => $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    if ($area === 'dashboard' || $area === 'calendar') {
        $t = $db->query("SELECT COUNT(*) total, SUM(status='overdue') overdue,
                                SUM(due_on <= CURDATE()) due_to_date,
                                SUM(due_on <= CURDATE() AND status IN('done','waived')) done_to_date
                           FROM cfo_tasks")->fetch(PDO::FETCH_ASSOC);
        $ev['tasks'] = $t;
        $ev['overdue_by_type'] = $q("SELECT task_type, COUNT(*) n FROM cfo_tasks WHERE status='overdue' GROUP BY task_type");
        $ev['overdue_by_owner'] = $q("SELECT COALESCE(owner_role,'unassigned') owner, COUNT(*) n FROM cfo_tasks
                                       WHERE status='overdue' GROUP BY owner_role ORDER BY n DESC LIMIT 8");
        $ev['worst'] = $q("SELECT title, due_on, DATEDIFF(CURDATE(),due_on) days_late FROM cfo_tasks
                            WHERE status='overdue' ORDER BY due_on LIMIT 8");
    } elseif ($area === 'risk') {
        $ev['by_band'] = $q("SELECT CASE WHEN residual_score>=15 THEN 'Critical' WHEN residual_score>=10 THEN 'High'
                                         WHEN residual_score>=5 THEN 'Medium' ELSE 'Low' END band, COUNT(*) n
                               FROM cfo_risks GROUP BY band");
        $ev['top'] = $q("SELECT ref, sub_process, description, inherent_score, residual_score, controls, owner_role, next_review
                           FROM cfo_risks ORDER BY residual_score DESC LIMIT 8");
        $ev['no_action'] = $q("SELECT ref, description FROM cfo_risks r WHERE residual_score >= 9
                                AND NOT EXISTS (SELECT 1 FROM cfo_actions a WHERE a.source_type='risk' AND a.source_ref=r.ref)");
        $ev['review_overdue'] = $q("SELECT ref, next_review FROM cfo_risks WHERE next_review < CURDATE()");
    } elseif ($area === 'capa') {
        $ev['by_status'] = $q("SELECT status, COUNT(*) n FROM cfo_actions GROUP BY status");
        $ev['ageing'] = $q("SELECT priority, COUNT(*) n, AVG(DATEDIFF(CURDATE(),due_on)) avg_age FROM cfo_actions
                             WHERE status NOT IN('closed','verified') GROUP BY priority");
        $ev['oldest'] = $q("SELECT ref, title, source_type, due_on, DATEDIFF(CURDATE(),due_on) age FROM cfo_actions
                             WHERE status NOT IN('closed','verified') ORDER BY due_on LIMIT 8");
    } elseif ($area === 'kri') {
        foreach (cfo_kri_rows($db) as $k) {
            $ev['indicators'][] = ['code' => $k['code'], 'name' => $k['name'], 'kind' => $k['indicator_kind'],
                'target' => $k['target_value'], 'last' => $k['last_value'], 'period' => $k['last_period'],
                'rag' => $k['rag'], 'direction' => $k['direction']];
        }
    } elseif ($area === 'forms') {
        $rows = cfo_form_rows($db);
        $agg = [];
        foreach ($rows as $r) {
            $k = $r['form_code'];
            $agg[$k] = $agg[$k] ?? ['form' => $k, 'total' => 0, 'approved' => 0, 'overdue' => 0, 'not_started' => 0];
            $agg[$k]['total']++;
            if ($r['status'] === 'approved') $agg[$k]['approved']++;
            if ($r['is_overdue']) $agg[$k]['overdue']++;
            if ($r['status'] === 'not_started') $agg[$k]['not_started']++;
        }
        $ev['forms'] = array_values($agg);
    } elseif ($area === 'reviews') {
        $ev['reviews'] = $q("SELECT period, review_type, scheduled_on, status FROM cfo_reviews ORDER BY scheduled_on DESC LIMIT 14");
        $ev['overdue'] = (int)$db->query("SELECT COUNT(*) FROM cfo_reviews WHERE status IN('scheduled','in_progress') AND scheduled_on < CURDATE()")->fetchColumn();
    } elseif ($area === 'evidence') {
        $ev['by_type'] = $q("SELECT entity_type, COUNT(*) n FROM cfo_evidence GROUP BY entity_type");
        $ev['gap_count'] = (int)$db->query("SELECT COUNT(*) FROM cfo_tasks t WHERE t.status IN('done','overdue')
                              AND NOT EXISTS (SELECT 1 FROM cfo_evidence v WHERE v.entity_type='task' AND v.entity_id=t.id)")->fetchColumn();
    } elseif ($area === 'audit') {
        $ev['by_action'] = $q("SELECT action, COUNT(*) n FROM cfo_audit GROUP BY action ORDER BY n DESC");
        $ev['by_user'] = $q("SELECT user_name, COUNT(*) n, MAX(created_at) last_seen FROM cfo_audit GROUP BY user_name ORDER BY n DESC LIMIT 10");
    } else {
        $ev['tasks'] = $db->query("SELECT COUNT(*) total, SUM(status='overdue') overdue FROM cfo_tasks")->fetch(PDO::FETCH_ASSOC);
        $ev['risks'] = $db->query("SELECT COUNT(*) total, SUM(residual_score>=10) high_plus FROM cfo_risks")->fetch(PDO::FETCH_ASSOC);
    }

    $ask = [
        'dashboard' => 'Assess the overall compliance position. Where is the biggest exposure right now, what should be cleared first, and what does the pattern of misses say about the process?',
        'calendar'  => 'Which obligations are slipping and why might that be? Who is carrying too much? What would you re-sequence this week?',
        'risk'      => 'Comment on the residual risk profile: which risks are under-controlled, where is the control narrative weak, and which need re-rating or an action?',
        'capa'      => 'Assess the corrective/preventive action backlog: ageing, priority mix, and which items look stalled or wrongly rated.',
        'kri'       => 'Interpret the indicator set: which are breaching, which are trending the wrong way, and which have no data (a control gap in itself).',
        'forms'     => 'Assess the maker-checker filing discipline per form: which forms are chronically late or never started, and what that implies.',
        'reviews'   => 'Assess the review cadence: are sign-offs happening on time, and what is the risk of the gaps?',
        'evidence'  => 'Assess evidence coverage: where would an auditor find nothing, and which gaps matter most?',
        'audit'     => 'Assess the activity pattern in the audit trail: concentration on one user, unusual action mixes, or quiet periods.',
    ][$area] ?? 'Assess this compliance data and state the top risks and actions.';

    $prompt = "You are the compliance analyst for " . cfo_setting($db, 'org_name', 'PayG')
        . ", a payment aggregator regulated by the RBI. Today is " . date('d M Y') . ".\n"
        . "Below is REAL data from the finance compliance cockpit. Use ONLY these numbers — never invent any.\n\n"
        . json_encode($ev, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n"
        . $ask . "\n"
        . "Answer in at most 6 short bullet points, each starting with a bold 3-6 word label. "
        . "Quote the actual figures you rely on. End with one line beginning 'Do next:' naming the single highest-value action.";

    $r = crm_ai_call($prompt, 'medium');
    ok(['analysis' => $r['response'] ?? null, 'source' => $r['source'] ?? null,
        'evidence' => $ev, 'as_of' => date('d M Y H:i')]);
}

/* ------------------------------------------------------------------ *
 *  POST — actions
 * ------------------------------------------------------------------ */
if (method() !== 'POST' && method() !== 'PATCH') fail('Unsupported request', 405);
$action = (string)($_GET['action'] ?? '');
$b = body();
if (!cfo_can_write()) fail('You are not authorized to change compliance records', 403);

/* ---- the scheduler ---- */
if ($action === 'scheduler') {
    $res = cfo_run_scheduler($db);
    cfo_log($db, 'run', 'scheduler', 'calendar',
            'scheduler run: ' . $res['created'] . ' new tasks from ' . $res['obligations'] . ' obligations');
    ok($res + ['message' => $res['created'] . ' new task(s) created']);
}

/* ---- calendar & tasks ---- */
if ($action === 'task_status') {
    $id = (int)($b['id'] ?? 0);
    $to = (string)($b['status'] ?? '');
    if (!$id || !in_array($to, ['open', 'in_progress', 'done', 'waived'], true)) fail('id and a valid status are required');
    $s = $db->prepare("SELECT * FROM cfo_tasks WHERE id=?"); $s->execute([$id]);
    $t = $s->fetch(PDO::FETCH_ASSOC); if (!$t) fail('Task not found', 404);
    if ($to === 'waived' && !cfo_can_sign()) fail('Only the CFO can waive an obligation', 403);
    $done = in_array($to, ['done', 'waived'], true);
    $db->prepare("UPDATE cfo_tasks SET status=?, completed_at=?, completed_by=? WHERE id=?")
       ->execute([$to, $done ? date('Y-m-d H:i:s') : null, $done ? (current_employee_id() ?: null) : null, $id]);
    if (!$done && $t['due_on'] < date('Y-m-d') && $to === 'open') {
        $db->prepare("UPDATE cfo_tasks SET status='overdue' WHERE id=?")->execute([$id]);
    }
    cfo_log($db, 'update', 'task', $id, $t['title'] . ': ' . $t['status'] . ' -> ' . $to);
    ok(['id' => $id, 'status' => $to]);
}

if ($action === 'task_assign') {
    $ids = array_map('intval', (array)($b['ids'] ?? []));
    $owner = (int)($b['owner_employee_id'] ?? 0);
    if (!$ids) fail('Select at least one task');
    $name = 'unassigned';
    if ($owner) {
        $s = $db->prepare("SELECT full_name, designation FROM employees WHERE id=?"); $s->execute([$owner]);
        $e = $s->fetch(PDO::FETCH_ASSOC); if (!$e) fail('Unknown employee');
        $name = $e['full_name'];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $db->prepare("UPDATE cfo_tasks SET owner_employee_id=? WHERE id IN ($in)")
       ->execute(array_merge([$owner ?: null], $ids));
    cfo_log($db, 'assign', 'task', implode(',', $ids), count($ids) . ' task(s) assigned to ' . $name);
    ok(['assigned' => count($ids), 'owner' => $name]);
}

if ($action === 'task_split') {
    // Split one period's work across several people. The extra parts carry the
    // same obligation and period with a #n suffix, so the calendar still ties
    // back to one obligation and the UNIQUE(obligation_id, period) key holds.
    $id = (int)($b['id'] ?? 0);
    $owners = array_values(array_filter(array_map('intval', (array)($b['owners'] ?? []))));
    if (!$id || count($owners) < 2) fail('Pick a task and at least two owners');
    $s = $db->prepare("SELECT * FROM cfo_tasks WHERE id=?"); $s->execute([$id]);
    $t = $s->fetch(PDO::FETCH_ASSOC); if (!$t) fail('Task not found', 404);
    if (strpos((string)$t['period'], '#') !== false) {
        fail('That is already one part of a split — split the parent task instead', 409);
    }
    // strip any existing "(part n of m)" first, so re-splitting relabels rather than stacking
    $base = preg_replace('/ \(part \d+ of \d+\)$/', '', $t['title']);
    // and drop parts left over from a previous, wider split
    $db->prepare("DELETE FROM cfo_tasks
                   WHERE obligation_id=? AND period LIKE ?
                     AND CAST(SUBSTRING_INDEX(period,'#',-1) AS UNSIGNED) > ?")
       ->execute([$t['obligation_id'], $t['period'] . '#%', count($owners)]);
    $db->prepare("UPDATE cfo_tasks SET owner_employee_id=?, title=? WHERE id=?")
       ->execute([$owners[0], $base . ' (part 1 of ' . count($owners) . ')', $id]);
    $ins = $db->prepare("INSERT INTO cfo_tasks
              (obligation_id,title,task_type,period,owner_role,owner_employee_id,due_on,status)
              VALUES (?,?,?,?,?,?,?,?)
              ON DUPLICATE KEY UPDATE title=VALUES(title), owner_employee_id=VALUES(owner_employee_id)");
    foreach (array_slice($owners, 1) as $i => $o) {
        $ins->execute([$t['obligation_id'],
                       $base . ' (part ' . ($i + 2) . ' of ' . count($owners) . ')',
                       $t['task_type'], $t['period'] . '#' . ($i + 2), $t['owner_role'], $o, $t['due_on'], $t['status']]);
    }
    cfo_log($db, 'split', 'task', $id, $base . ': split across ' . count($owners) . ' owners');
    ok(['parts' => count($owners)]);
}

/* ---- forms & submissions (maker -> checker -> approver) ---- */
if ($action === 'submission_create') {
    $tplId  = (int)($b['template_id'] ?? 0);
    $period = trim((string)($b['period'] ?? ''));
    if (!$tplId) fail('Pick a form');
    $s = $db->prepare("SELECT * FROM cfo_form_templates WHERE id=?"); $s->execute([$tplId]);
    $tpl = $s->fetch(PDO::FETCH_ASSOC); if (!$tpl) fail('Form not found', 404);
    if ($period === '') $period = $tpl['cadence'] === 'daily' ? date('Y-m-d') : date('Y-m');
    $due = (string)($b['due_on'] ?? '') ?: date('Y-m-d', strtotime('+7 days'));
    $chk = $db->prepare("SELECT id FROM cfo_form_submissions WHERE template_id=? AND period=?");
    $chk->execute([$tplId, $period]);
    if ($ex = $chk->fetchColumn()) ok(['id' => (int)$ex, 'existing' => true]);
    $db->prepare("INSERT INTO cfo_form_submissions (template_id,period,due_on,status,data_json,prepared_by,prepared_at)
                  VALUES (?,?,?,'draft','{}',?,NOW())")
       ->execute([$tplId, $period, $due, current_employee_id() ?: null]);
    $id = (int)$db->lastInsertId();
    cfo_log($db, 'create', 'submission', $id, $tpl['code'] . ' ' . $period . ' started');
    ok(['id' => $id]);
}

if ($action === 'submission_save') {
    $id = (int)($b['id'] ?? 0); if (!$id) fail('id required');
    $s = $db->prepare("SELECT s.*, tpl.code FROM cfo_form_submissions s JOIN cfo_form_templates tpl ON tpl.id=s.template_id WHERE s.id=?");
    $s->execute([$id]); $row = $s->fetch(PDO::FETCH_ASSOC); if (!$row) fail('Submission not found', 404);
    // Maker-checker only means something if the maker cannot keep editing after handing
    // over: once submitted, the working paper is frozen until the checker returns it.
    if ($row['status'] === 'approved') fail('An approved submission cannot be edited — raise a change request', 409);
    if (in_array($row['status'], ['submitted', 'reviewed'], true)) {
        fail('This filing is with the checker. It has to be returned for correction before it can be edited.', 409);
    }
    $data = is_array($b['data'] ?? null) ? $b['data'] : [];
    $db->prepare("UPDATE cfo_form_submissions SET data_json=?, remarks=?, prepared_by=COALESCE(prepared_by,?),
                         prepared_at=COALESCE(prepared_at,NOW()), status=IF(status='rejected','draft',status) WHERE id=?")
       ->execute([json_encode($data), ($b['remarks'] ?? null) ?: null, current_employee_id() ?: null, $id]);
    cfo_log($db, 'update', 'submission', $id, $row['code'] . ' ' . $row['period'] . ' data saved');
    ok(['id' => $id]);
}

if ($action === 'submission_transition') {
    $id = (int)($b['id'] ?? 0);
    $to = (string)($b['to'] ?? '');
    if (!$id || !in_array($to, ['submitted', 'reviewed', 'approved', 'rejected'], true)) fail('id and a valid target state are required');
    $s = $db->prepare("SELECT s.*, tpl.code, tpl.checker_role, tpl.approver_role
                         FROM cfo_form_submissions s JOIN cfo_form_templates tpl ON tpl.id=s.template_id WHERE s.id=?");
    $s->execute([$id]); $row = $s->fetch(PDO::FETCH_ASSOC); if (!$row) fail('Submission not found', 404);
    $me = current_employee_id();

    // four eyes: the same person cannot make, check and approve the same filing
    if ($to === 'reviewed' && (int)$row['prepared_by'] === $me && $me) {
        fail('The checker must be someone other than the person who prepared it', 403);
    }
    if ($to === 'approved') {
        if (!cfo_can_sign() && payg_role_label(current_role()) !== $row['approver_role']) {
            fail('Only ' . ($row['approver_role'] ?: 'the CFO') . ' can approve this form', 403);
        }
        if ((int)$row['prepared_by'] === $me && $me) fail('You prepared this filing, so you cannot approve it', 403);
        if ($row['status'] !== 'reviewed' && $row['status'] !== 'submitted') fail('It has to be submitted and checked first', 409);
    }
    if ($to === 'submitted' && !in_array($row['status'], ['draft', 'rejected', 'overdue'], true)) {
        fail('Only a draft can be submitted', 409);
    }

    $set = "status=?"; $args = [$to];
    if ($to === 'reviewed')  { $set .= ", reviewed_by=?, reviewed_at=NOW()"; $args[] = $me ?: null; }
    if ($to === 'approved')  { $set .= ", approved_by=?, approved_at=NOW()"; $args[] = $me ?: null; }
    if ($to === 'rejected')  { $set .= ", reviewed_by=?, reviewed_at=NOW(), remarks=?"; $args[] = $me ?: null; $args[] = (string)($b['remarks'] ?? 'Returned for correction'); }
    $args[] = $id;
    $db->prepare("UPDATE cfo_form_submissions SET $set WHERE id=?")->execute($args);

    // an approved filing closes the calendar task it belongs to
    if ($to === 'approved') {
        $db->prepare("UPDATE cfo_tasks t JOIN cfo_obligations o ON o.id=t.obligation_id
                         SET t.status='done', t.submission_id=?, t.completed_at=NOW(), t.completed_by=?
                       WHERE o.form_template_id=? AND t.period=?")
           ->execute([$id, $me ?: null, $row['template_id'], $row['period']]);
    }
    // If the same person checked and approved, say so in the trail. Four eyes on the
    // preparer is enforced; a small team doubling up on check + approve is allowed but
    // must be visible rather than implied.
    $note = '';
    if ($to === 'approved' && (int)$row['reviewed_by'] === $me && $me) {
        $note = ' (checked and approved by the same person)';
    }
    cfo_log($db, $to, 'submission', $id, $row['code'] . ' ' . $row['period'] . ': ' . $row['status'] . ' -> ' . $to . $note);
    ok(['id' => $id, 'status' => $to]);
}

/* ---- reviews ---- */
if ($action === 'review_sign') {
    if (!cfo_can_sign()) fail('Only the CFO signs off a review', 403);
    $id = (int)($b['id'] ?? 0); if (!$id) fail('id required');
    $s = $db->prepare("SELECT * FROM cfo_reviews WHERE id=?"); $s->execute([$id]);
    $r = $s->fetch(PDO::FETCH_ASSOC); if (!$r) fail('Review not found', 404);
    $db->prepare("UPDATE cfo_reviews SET status='signed', findings=?, signature_note=?, signed_by=?, signed_at=NOW() WHERE id=?")
       ->execute([(string)($b['findings'] ?? ''), (string)($b['note'] ?? ''), current_employee_id() ?: null, $id]);
    cfo_log($db, 'sign', 'review', $id, $r['review_type'] . ' review ' . $r['period'] . ' signed off');
    ok(['id' => $id, 'status' => 'signed']);
}

if ($action === 'review_status') {
    $id = (int)($b['id'] ?? 0);
    $to = (string)($b['status'] ?? '');
    if (!$id || !in_array($to, ['scheduled', 'in_progress', 'skipped'], true)) fail('id and a valid status are required');
    if ($to === 'skipped' && !cfo_can_sign()) fail('Only the CFO can skip a review', 403);
    $db->prepare("UPDATE cfo_reviews SET status=?, findings=COALESCE(?,findings) WHERE id=?")
       ->execute([$to, $b['findings'] ?? null, $id]);
    cfo_log($db, 'update', 'review', $id, 'status -> ' . $to);
    ok(['id' => $id, 'status' => $to]);
}

/* ---- CAPA ---- */
if ($action === 'capa_save') {
    $id = (int)($b['id'] ?? 0);
    $title = trim((string)($b['title'] ?? ''));
    if (!$id && $title === '') fail('A title is required');
    $en = fn($v, $list, $def) => in_array($v ?? '', $list, true) ? $v : $def;
    $fields = [
        'title'       => $title ?: null,
        'description' => ($b['description'] ?? null) ?: null,
        'source_type' => $en($b['source_type'] ?? null, ['review','audit','incident','risk','kpi_breach','regulator','other'], 'other'),
        'source_ref'  => ($b['source_ref'] ?? null) ?: null,
        'action_kind' => $en($b['action_kind'] ?? null, ['corrective','preventive'], 'corrective'),
        'owner_role'  => ($b['owner_role'] ?? null) ?: null,
        'owner_employee_id' => ($b['owner_employee_id'] ?? null) ?: null,
        'due_on'      => ($b['due_on'] ?? null) ?: null,
        'priority'    => $en($b['priority'] ?? null, ['critical','high','medium','low'], 'medium'),
        'status'      => $en($b['status'] ?? null, ['open','in_progress','done','verified','closed','overdue'], 'open'),
        'closure_evidence' => ($b['closure_evidence'] ?? null) ?: null,
    ];
    if ($id) {
        $set = []; $args = [];
        foreach ($fields as $k => $v) { if (array_key_exists($k, $b)) { $set[] = "$k=?"; $args[] = $v; } }
        if (!$set) fail('Nothing to update');
        if (in_array($fields['status'], ['closed', 'verified'], true) && array_key_exists('status', $b)) {
            $set[] = 'closed_at=NOW()';
        }
        $args[] = $id;
        $db->prepare("UPDATE cfo_actions SET " . implode(',', $set) . " WHERE id=?")->execute($args);
        cfo_log($db, 'update', 'action', $id, 'CAPA edited: ' . implode(', ', array_keys(array_intersect_key($fields, $b))));
        ok(['id' => $id]);
    }
    $db->prepare("INSERT INTO cfo_actions (ref,title,description,source_type,source_ref,action_kind,owner_role,
                        owner_employee_id,due_on,priority,status,closure_evidence)
                  VALUES ('',?,?,?,?,?,?,?,?,?,?,?)")
       ->execute([$fields['title'], $fields['description'], $fields['source_type'], $fields['source_ref'],
                  $fields['action_kind'], $fields['owner_role'], $fields['owner_employee_id'],
                  $fields['due_on'], $fields['priority'], $fields['status'], $fields['closure_evidence']]);
    $id = (int)$db->lastInsertId();
    $ref = 'CAPA-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
    $db->prepare("UPDATE cfo_actions SET ref=? WHERE id=?")->execute([$ref, $id]);
    cfo_log($db, 'create', 'action', $id, $ref . ' raised: ' . $fields['title']);
    ok(['id' => $id, 'ref' => $ref]);
}

/* ---- risk register ---- */
if ($action === 'risk_save') {
    $id = (int)($b['id'] ?? 0);
    $cl = fn($v) => max(1, min(5, (int)$v));
    $fields = [
        'sub_process' => ($b['sub_process'] ?? null) ?: null,
        'description' => ($b['description'] ?? null) ?: null,
        'inherent_likelihood' => $cl($b['inherent_likelihood'] ?? 3),
        'inherent_impact'     => $cl($b['inherent_impact'] ?? 3),
        'controls'    => ($b['controls'] ?? null) ?: null,
        'residual_likelihood' => $cl($b['residual_likelihood'] ?? 2),
        'residual_impact'     => $cl($b['residual_impact'] ?? 3),
        'owner_role'  => ($b['owner_role'] ?? null) ?: null,
        'owner_employee_id' => ($b['owner_employee_id'] ?? null) ?: null,
        'next_review' => ($b['next_review'] ?? null) ?: null,
        'status'      => in_array($b['status'] ?? '', ['open','mitigated','accepted','closed'], true) ? $b['status'] : 'open',
    ];
    if ($id) {
        $s = $db->prepare("SELECT * FROM cfo_risks WHERE id=?"); $s->execute([$id]);
        $old = $s->fetch(PDO::FETCH_ASSOC); if (!$old) fail('Risk not found', 404);
        $set = []; $args = [];
        foreach ($fields as $k => $v) { if (array_key_exists($k, $b)) { $set[] = "$k=?"; $args[] = $v; } }
        if (!$set) fail('Nothing to update');
        $set[] = 'version=version+1';                          // every edit is versioned
        $args[] = $id;
        $db->prepare("UPDATE cfo_risks SET " . implode(',', $set) . " WHERE id=?")->execute($args);
        $changed = [];
        foreach ($fields as $k => $v) {
            if (array_key_exists($k, $b) && (string)$old[$k] !== (string)$v) $changed[] = $k . ': ' . $old[$k] . ' -> ' . $v;
        }
        cfo_log($db, 'update', 'risk', $id, $old['ref'] . ' v' . ((int)$old['version'] + 1) . ' — ' . (implode('; ', $changed) ?: 'no value change'));
        ok(['id' => $id, 'version' => (int)$old['version'] + 1]);
    }
    if (!$fields['description']) fail('Describe the risk');
    $next = (int)$db->query("SELECT COALESCE(MAX(CAST(SUBSTRING(ref,4) AS UNSIGNED)),0)+1 FROM cfo_risks WHERE ref LIKE 'FR-%'")->fetchColumn();
    $ref = 'FR-' . str_pad((string)$next, 2, '0', STR_PAD_LEFT);
    $db->prepare("INSERT INTO cfo_risks (ref,sub_process,description,inherent_likelihood,inherent_impact,controls,
                        residual_likelihood,residual_impact,owner_role,owner_employee_id,next_review,status,version)
                  VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1)")
       ->execute([$ref, $fields['sub_process'], $fields['description'], $fields['inherent_likelihood'],
                  $fields['inherent_impact'], $fields['controls'], $fields['residual_likelihood'],
                  $fields['residual_impact'], $fields['owner_role'], $fields['owner_employee_id'],
                  $fields['next_review'], $fields['status']]);
    $id = (int)$db->lastInsertId();
    cfo_log($db, 'create', 'risk', $id, $ref . ' added to the register');
    ok(['id' => $id, 'ref' => $ref]);
}

/* ---- KPI / KRI ---- */
if ($action === 'kri_reading') {
    $kriId = (int)($b['kri_id'] ?? 0);
    $period = trim((string)($b['period'] ?? date('Y-m')));
    if (!$kriId || $period === '') fail('Indicator and period are required');
    if (($b['value'] ?? '') === '') fail('A value is required');
    $s = $db->prepare("SELECT * FROM cfo_kri WHERE id=?"); $s->execute([$kriId]);
    $k = $s->fetch(PDO::FETCH_ASSOC); if (!$k) fail('Indicator not found', 404);
    $val = (float)$b['value'];
    $rag = cfo_rag($k, $val);
    $db->prepare("INSERT INTO cfo_kri_readings (kri_id,period,value,rag,note,captured_by)
                  VALUES (?,?,?,?,?,?)
                  ON DUPLICATE KEY UPDATE value=VALUES(value), rag=VALUES(rag), note=VALUES(note),
                                          captured_by=VALUES(captured_by), captured_at=NOW()")
       ->execute([$kriId, $period, $val, $rag, ($b['note'] ?? null) ?: null, current_employee_id() ?: null]);
    cfo_log($db, 'update', 'kri', $kriId, $k['code'] . ' ' . $period . ' = ' . $val . ' (' . strtoupper($rag) . ')');

    // a red reading raises a CAPA automatically — a breach with no action is how
    // indicators quietly become decoration
    if ($rag === 'red') {
        $exists = $db->prepare("SELECT id FROM cfo_actions WHERE source_type='kpi_breach' AND source_ref=? AND status NOT IN('closed','verified')");
        $exists->execute([$k['code'] . ' ' . $period]);
        if (!$exists->fetchColumn()) {
            $db->prepare("INSERT INTO cfo_actions (ref,title,description,source_type,source_ref,action_kind,
                                owner_role,due_on,priority,status)
                          VALUES ('',?,?,'kpi_breach',?, 'corrective',?,DATE_ADD(CURDATE(), INTERVAL 14 DAY),'high','open')")
               ->execute([$k['code'] . ' breach — ' . $k['name'],
                          'Auto-raised: ' . $k['name'] . ' read ' . $val . ' for ' . $period
                          . ' against a target of ' . $k['target_value'] . ' (' . $k['direction'] . ').',
                          $k['code'] . ' ' . $period, $k['owner_role']]);
            $aid = (int)$db->lastInsertId();
            $db->prepare("UPDATE cfo_actions SET ref=? WHERE id=?")
               ->execute(['CAPA-' . str_pad((string)$aid, 4, '0', STR_PAD_LEFT), $aid]);
            cfo_log($db, 'create', 'action', $aid, 'auto-raised from ' . $k['code'] . ' red reading');
        }
    }
    ok(['rag' => $rag, 'period' => $period]);
}

if ($action === 'kri_save') {
    if (!cfo_can_sign()) fail('Only the CFO defines indicators', 403);
    $id = (int)($b['id'] ?? 0);
    $en = fn($v, $l, $d) => in_array($v ?? '', $l, true) ? $v : $d;
    $f = [
        'code' => trim((string)($b['code'] ?? '')),
        'name' => trim((string)($b['name'] ?? '')),
        'indicator_kind' => $en($b['indicator_kind'] ?? null, ['kpi','kri'], 'kri'),
        'unit' => ($b['unit'] ?? 'count') ?: 'count',
        'direction' => $en($b['direction'] ?? null, ['higher_better','lower_better'], 'higher_better'),
        'target_value' => (float)($b['target_value'] ?? 0),
        'green_from' => (float)($b['green_from'] ?? 0),
        'amber_from' => (float)($b['amber_from'] ?? 0),
        'frequency' => $en($b['frequency'] ?? null, ['daily','weekly','monthly','quarterly'], 'monthly'),
        'owner_role' => ($b['owner_role'] ?? null) ?: null,
        'kpa_goal_id' => ($b['kpa_goal_id'] ?? null) ?: null,
        'is_active' => (int)($b['is_active'] ?? 1) ? 1 : 0,
    ];
    if ($id) {
        $set = []; $args = [];
        foreach ($f as $k => $v) { if (array_key_exists($k, $b)) { $set[] = "$k=?"; $args[] = $v; } }
        if (!$set) fail('Nothing to update');
        $args[] = $id;
        $db->prepare("UPDATE cfo_kri SET " . implode(',', $set) . " WHERE id=?")->execute($args);
        cfo_log($db, 'update', 'kri', $id, 'indicator definition edited');
        ok(['id' => $id]);
    }
    if ($f['code'] === '' || $f['name'] === '') fail('Code and name are required');
    $db->prepare("INSERT INTO cfo_kri (code,name,indicator_kind,unit,direction,target_value,green_from,amber_from,
                        frequency,owner_role,kpa_goal_id,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
       ->execute(array_values($f));
    $id = (int)$db->lastInsertId();
    cfo_log($db, 'create', 'kri', $id, $f['code'] . ' ' . $f['name'] . ' defined');
    ok(['id' => $id]);
}

/* ---- evidence ---- */
if ($action === 'evidence_link') {
    $type = (string)($b['entity_type'] ?? '');
    $eid  = (int)($b['entity_id'] ?? 0);
    if (!in_array($type, ['task','submission','review','risk','action','kri','obligation'], true) || !$eid) {
        fail('Pick what the evidence belongs to');
    }
    $title = trim((string)($b['title'] ?? ''));
    if ($title === '') fail('A title is required');
    $docId = (int)($b['doc_id'] ?? 0) ?: null;
    $fileName = null;
    if ($docId) {
        $s = $db->prepare("SELECT orig_name FROM doc_files WHERE id=? AND is_deleted=0"); $s->execute([$docId]);
        $fileName = $s->fetchColumn() ?: null;
        if (!$fileName) fail('That document is not in the library');
    }
    $db->prepare("INSERT INTO cfo_evidence (title,entity_type,entity_id,doc_id,file_name,url,note,uploaded_by)
                  VALUES (?,?,?,?,?,?,?,?)")
       ->execute([$title, $type, $eid, $docId, $fileName, ($b['url'] ?? null) ?: null,
                  ($b['note'] ?? null) ?: null, current_employee_id() ?: null]);
    $id = (int)$db->lastInsertId();
    cfo_log($db, 'create', 'evidence', $id, 'evidence "' . $title . '" attached to ' . $type . ' #' . $eid);
    ok(['id' => $id]);
}

if ($action === 'evidence_delete') {
    if (!cfo_can_sign()) fail('Only the CFO can detach evidence', 403);
    $id = (int)($b['id'] ?? 0); if (!$id) fail('id required');
    $s = $db->prepare("SELECT * FROM cfo_evidence WHERE id=?"); $s->execute([$id]);
    $e = $s->fetch(PDO::FETCH_ASSOC); if (!$e) fail('Not found', 404);
    $db->prepare("DELETE FROM cfo_evidence WHERE id=?")->execute([$id]);
    cfo_log($db, 'delete', 'evidence', $id, 'detached "' . $e['title'] . '" from ' . $e['entity_type'] . ' #' . $e['entity_id']);
    ok(['id' => $id]);
}

/* ---- admin: templates, obligations, settings ---- */
if ($action === 'template_save') {
    if (!cfo_can_sign()) fail('Only the CFO changes form templates', 403);
    $id = (int)($b['id'] ?? 0);
    $fields = json_encode(is_array($b['fields'] ?? null)
        ? $b['fields']
        : (json_decode((string)($b['fields_json'] ?? '{}'), true) ?: []));
    if ($id) {
        $s = $db->prepare("SELECT * FROM cfo_form_templates WHERE id=?"); $s->execute([$id]);
        $old = $s->fetch(PDO::FETCH_ASSOC); if (!$old) fail('Template not found', 404);
        // a changed field set is a new version of the form, not a silent edit
        $bump = array_key_exists('fields', $b) || array_key_exists('fields_json', $b);
        $db->prepare("UPDATE cfo_form_templates SET title=?, cadence=?, approver_role=?, checker_role=?,
                             fields_json=?, is_active=?, version=version+? WHERE id=?")
           ->execute([(string)($b['title'] ?? $old['title']), (string)($b['cadence'] ?? $old['cadence']),
                      ($b['approver_role'] ?? $old['approver_role']) ?: null,
                      ($b['checker_role'] ?? $old['checker_role']) ?: null,
                      $bump ? $fields : $old['fields_json'],
                      (int)($b['is_active'] ?? $old['is_active']) ? 1 : 0, $bump ? 1 : 0, $id]);
        cfo_log($db, 'update', 'template', $id, $old['code'] . ($bump ? ' -> v' . ((int)$old['version'] + 1) : ' edited'));
        ok(['id' => $id, 'version' => (int)$old['version'] + ($bump ? 1 : 0)]);
    }
    $code = strtoupper(trim((string)($b['code'] ?? '')));
    $title = trim((string)($b['title'] ?? ''));
    if ($code === '' || $title === '') fail('Code and title are required');
    $db->prepare("INSERT INTO cfo_form_templates (code,title,cadence,version,approver_role,checker_role,fields_json,is_active)
                  VALUES (?,?,?,1,?,?,?,1)")
       ->execute([$code, $title,
                  in_array($b['cadence'] ?? '', ['daily','weekly','monthly','quarterly','half_yearly','yearly','per_rbi_cadence','on_demand'], true) ? $b['cadence'] : 'monthly',
                  ($b['approver_role'] ?? null) ?: null, ($b['checker_role'] ?? null) ?: null, $fields]);
    $id = (int)$db->lastInsertId();
    cfo_log($db, 'create', 'template', $id, $code . ' ' . $title . ' created');
    ok(['id' => $id]);
}

if ($action === 'obligation_save') {
    if (!cfo_can_sign()) fail('Only the CFO changes obligations and due dates', 403);
    $id = (int)($b['id'] ?? 0);
    $en = fn($v, $l, $d) => in_array($v ?? '', $l, true) ? $v : $d;
    $types = ['statutory','internal_review','rbi','tax','board'];
    $cads  = ['daily','weekly','monthly','quarterly','half_yearly','yearly','per_rbi_cadence','on_demand'];
    $f = [
        'title' => trim((string)($b['title'] ?? '')),
        'obligation_type' => $en($b['obligation_type'] ?? null, $types, 'internal_review'),
        'authority' => ($b['authority'] ?? null) ?: null,
        'cadence' => $en($b['cadence'] ?? null, $cads, 'monthly'),
        'due_day' => max(1, min(31, (int)($b['due_day'] ?? 1))),
        'owner_role' => ($b['owner_role'] ?? null) ?: null,
        'owner_employee_id' => ($b['owner_employee_id'] ?? null) ?: null,
        'form_template_id' => ($b['form_template_id'] ?? null) ?: null,
        'reference' => ($b['reference'] ?? null) ?: null,
        'is_active' => (int)($b['is_active'] ?? 1) ? 1 : 0,
    ];
    if ($id) {
        $s = $db->prepare("SELECT * FROM cfo_obligations WHERE id=?"); $s->execute([$id]);
        $old = $s->fetch(PDO::FETCH_ASSOC); if (!$old) fail('Obligation not found', 404);
        $set = []; $args = [];
        foreach ($f as $k => $v) { if (array_key_exists($k, $b)) { $set[] = "$k=?"; $args[] = $v; } }
        if (!$set) fail('Nothing to update');
        $args[] = $id;
        $db->prepare("UPDATE cfo_obligations SET " . implode(',', $set) . " WHERE id=?")->execute($args);
        cfo_log($db, 'update', 'obligation', $id, $old['code'] . ' edited (' . implode(', ', array_keys(array_intersect_key($f, $b))) . ')');
        ok(['id' => $id]);
    }
    $code = strtoupper(trim((string)($b['code'] ?? '')));
    if ($code === '' || $f['title'] === '') fail('Code and title are required');
    $db->prepare("INSERT INTO cfo_obligations (code,title,obligation_type,authority,cadence,due_day,owner_role,
                        owner_employee_id,form_template_id,reference,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
       ->execute([$code, $f['title'], $f['obligation_type'], $f['authority'], $f['cadence'], $f['due_day'],
                  $f['owner_role'], $f['owner_employee_id'], $f['form_template_id'], $f['reference'], $f['is_active']]);
    $id = (int)$db->lastInsertId();
    cfo_log($db, 'create', 'obligation', $id, $code . ' ' . $f['title'] . ' added');
    ok(['id' => $id]);
}

if ($action === 'settings_save') {
    if (!cfo_can_sign()) fail('Only the CFO changes cockpit settings', 403);
    $allowed = ['org_name','fy_start_month','work_days','timezone','holidays',
                'scheduler_back_days','scheduler_fwd_days','reminder_days_before','digest_recipients'];
    $up = $db->prepare("INSERT INTO cfo_settings (skey,sval,updated_by) VALUES (?,?,?)
                        ON DUPLICATE KEY UPDATE sval=VALUES(sval), updated_by=VALUES(updated_by)");
    $n = 0;
    foreach ($allowed as $k) {
        if (array_key_exists($k, $b)) { $up->execute([$k, mb_substr((string)$b[$k], 0, 2000), current_employee_id() ?: null]); $n++; }
    }
    if (!$n) fail('Nothing to save');
    cfo_log($db, 'update', 'settings', 'cockpit', $n . ' setting(s) changed');
    ok(['saved' => $n]);
}

fail('Unknown action', 404);
