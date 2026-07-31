<?php
/**
 * Escalation matrix reports (admin) + escalation actions.
 *   GET  escalations.php?report=1&days=30      -> aggregates: by level, by employee, by type, worst breaches
 *   GET  escalations.php?list=1[&status=open]  -> escalation rows (managers: all; others: involving them)
 *   GET  escalations.php?ai=1[&refresh=1]      -> AI narrative summarising WHY things escalate (evidence-backed)
 *   POST escalations.php?action=run_sweep      -> run the SLA sweep now
 *   POST escalations.php?action=escalate       -> manual escalation {task_id, reason}
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_escalation_engine.php';
require_once __DIR__ . '/_ai.php';
require_login();
$db = db();
$me = current_employee_id();
$isManager = can_manage_all_records();

/* ---------------- evidence builder (shared by report + AI) ---------------- */
function esc_report(PDO $db, int $days): array
{
    $args = [$days];
    $tot = $db->prepare("SELECT COUNT(*) total,
                                SUM(status='open') open_now,
                                SUM(source='manual') manual_cnt,
                                ROUND(AVG(breached_by_hours),1) avg_breach_h,
                                ROUND(MAX(breached_by_hours),1) max_breach_h,
                                MAX(level) deepest_level
                           FROM task_escalations WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)");
    $tot->execute($args); $totals = $tot->fetch();

    $byLevel = $db->prepare("SELECT level, COUNT(*) cnt, SUM(status='open') open_cnt
                               FROM task_escalations WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                              GROUP BY level ORDER BY level");
    $byLevel->execute($args);

    // whose work escalates the most (the original assignee at the bottom of each chain)
    $byEmp = $db->prepare("SELECT t.assigned_to, e.full_name, e.designation, COUNT(DISTINCT te.task_id) tasks_escalated,
                                  COUNT(*) hops, ROUND(AVG(te.breached_by_hours),1) avg_breach_h
                             FROM task_escalations te JOIN tasks t ON t.id=te.task_id
                        LEFT JOIN employees e ON e.id=t.assigned_to
                            WHERE te.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                            GROUP BY t.assigned_to ORDER BY tasks_escalated DESC, hops DESC LIMIT 12");
    $byEmp->execute($args);

    // who is absorbing escalated work
    $byReceiver = $db->prepare("SELECT te.to_employee_id, e.full_name, COUNT(*) received, SUM(te.status='open') still_open
                                  FROM task_escalations te LEFT JOIN employees e ON e.id=te.to_employee_id
                                 WHERE te.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                                 GROUP BY te.to_employee_id ORDER BY received DESC LIMIT 12");
    $byReceiver->execute($args);

    $byType = $db->prepare("SELECT t.task_type, COUNT(*) cnt, ROUND(AVG(te.breached_by_hours),1) avg_breach_h
                              FROM task_escalations te JOIN tasks t ON t.id=te.task_id
                             WHERE te.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                             GROUP BY t.task_type ORDER BY cnt DESC");
    $byType->execute($args);

    $worst = $db->prepare("SELECT te.task_id, t.title, t.priority, t.task_type, te.level, te.breached_by_hours,
                                  te.reason, te.status, te.created_at,
                                  ea.full_name assignee_name, eb.full_name holder_name
                             FROM task_escalations te
                             JOIN tasks t ON t.id=te.task_id
                        LEFT JOIN employees ea ON ea.id=t.assigned_to
                        LEFT JOIN employees eb ON eb.id=te.to_employee_id
                            WHERE te.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                            ORDER BY te.breached_by_hours DESC LIMIT 10");
    $worst->execute($args);

    return [
        'window_days' => $days,
        'totals'      => $totals,
        'by_level'    => $byLevel->fetchAll(),
        'by_employee' => $byEmp->fetchAll(),
        'by_receiver' => $byReceiver->fetchAll(),
        'by_type'     => $byType->fetchAll(),
        'worst'       => $worst->fetchAll(),
    ];
}

if (method() === 'GET' && isset($_GET['report'])) {
    if (!$isManager) fail('Escalation reports are for managers and admins', 403);
    ok(esc_report($db, max(1, min(365, (int)($_GET['days'] ?? 30)))));
}

if (method() === 'GET' && isset($_GET['list'])) {
    $w = []; $args = [];
    if (!$isManager) { $w[] = "(te.to_employee_id=? OR t.assigned_to=?)"; $args[] = $me; $args[] = $me; }
    if (!empty($_GET['status']) && in_array($_GET['status'], ['open','resolved'], true)) { $w[] = "te.status=?"; $args[] = $_GET['status']; }
    $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
    $s = $db->prepare("SELECT te.*, t.title, t.priority, t.task_type, t.status AS task_status, t.sla_due_at,
                              ea.full_name assignee_name, eb.full_name to_name, ef.full_name from_name
                         FROM task_escalations te
                         JOIN tasks t ON t.id=te.task_id
                    LEFT JOIN employees ea ON ea.id=t.assigned_to
                    LEFT JOIN employees eb ON eb.id=te.to_employee_id
                    LEFT JOIN employees ef ON ef.id=te.from_employee_id
                        $where ORDER BY te.created_at DESC LIMIT 300");
    $s->execute($args);
    ok($s->fetchAll());
}

if (method() === 'GET' && isset($_GET['ai'])) {
    if (!$isManager) fail('Escalation AI summary is for managers and admins', 403);
    session_write_close();          // don't block other requests during the LLM call

    // serve a <30 min cached narrative unless refresh is forced
    if (empty($_GET['refresh'])) {
        $c = $db->query("SELECT payload, created_at FROM ai_insights
                          WHERE entity_type='funnel' AND insight_type='page_escalations'
                            AND created_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                          ORDER BY id DESC LIMIT 1")->fetch();
        if ($c) ok(['insight' => json_decode($c['payload'], true), 'cached' => true, 'as_of' => $c['created_at']]);
    }

    $ev = esc_report($db, 30);
    if (!(int)($ev['totals']['total'] ?? 0)) ok(['insight' => null, 'cached' => false, 'note' => 'No escalations in the last 30 days']);

    // Reasons are the payload the admin asked for — feed the actual reason strings.
    $reasons = array_map(fn($r) => [
        'task' => $r['title'], 'assignee' => $r['assignee_name'], 'level' => (int)$r['level'],
        'breached_by_h' => (float)$r['breached_by_hours'], 'reason' => $r['reason'], 'status' => $r['status'],
    ], $ev['worst']);

    $prompt = "You are analysing task-escalation data for a payments CRM. Every number you cite must come from the EVIDENCE. "
        . "Escalations happen when a task breaches its SLA turnaround time (TAT) and auto-moves up the manager chain toward the admin.\n\n"
        . "EVIDENCE (last 30 days):\n" . json_encode([
              'totals' => $ev['totals'], 'by_level' => $ev['by_level'], 'by_type' => $ev['by_type'],
              'worst_breaches_with_reasons' => $reasons,
              'most_escalated_employees' => $ev['by_employee'], 'receivers' => $ev['by_receiver'],
          ], JSON_UNESCAPED_UNICODE) . "\n\n"
        . "Return ONLY raw JSON (no markdown) with this exact shape:\n"
        . '{"summary":"2-3 sentence executive summary of WHY tasks are escalating","key_findings":["3-4 bullet facts with numbers"],'
        . '"root_causes":["2-3 likely root causes inferred from the reasons and patterns"],"actions":["2-3 concrete actions for the admin"]}';

    $r = crm_ai_call($prompt, 'medium');
    if (!$r) ok(['insight' => null, 'cached' => false, 'ai_status' => crm_ai_status(), 'evidence' => $ev]);

    $raw = trim($r['response']);
    $raw = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $raw);
    $j = json_decode($raw, true);
    if (!is_array($j)) $j = ['summary' => mb_substr($raw, 0, 1200), 'key_findings' => [], 'root_causes' => [], 'actions' => []];
    $j['_source'] = $r['source'];

    try {
        $db->prepare("INSERT INTO ai_insights (entity_type, entity_id, insight_type, payload, generated_by)
                      VALUES ('funnel', NULL, 'page_escalations', ?, ?)")
           ->execute([json_encode($j, JSON_UNESCAPED_UNICODE), $r['source']]);
    } catch (Throwable $e) {}
    ok(['insight' => $j, 'cached' => false]);
}

if (method() === 'POST' && ($_GET['action'] ?? '') === 'run_sweep') {
    if (!$isManager) fail('Not authorized', 403);
    ok(run_escalation_sweep($db));
}

if (method() === 'POST' && ($_GET['action'] ?? '') === 'escalate') {
    $b = body();
    $tid = (int)($b['task_id'] ?? 0);
    if (!$tid) fail('task_id required');
    $t = $db->prepare("SELECT * FROM tasks WHERE id=?"); $t->execute([$tid]);
    $task = $t->fetch();
    if (!$task) fail('Task not found', 404);
    // the assignee, current holder, or a manager may push it up manually
    if (!$isManager && (int)$task['assigned_to'] !== $me && (int)($task['escalated_to'] ?? 0) !== $me) {
        fail('Not authorized to escalate this task', 403);
    }
    if (in_array($task['status'], ['done','cancelled'], true)) fail('Task is already closed');
    $reason = trim((string)($b['reason'] ?? ''));
    $logId = escalate_task($db, $task, 'manual', $reason !== '' ? $reason : null, $me);
    if (!$logId) fail('Already at the top of the escalation chain (admin panel)');
    ok(['escalation_id' => $logId]);
}

fail('Unsupported request', 405);
