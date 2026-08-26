<?php
/**
 * Person performance — SLA/TAT adherence and escalations feed a person's record
 * AUTOMATICALLY, alongside the KPA/KRA goals they own.
 *
 *   GET performance.php?employee_id=N   -> one person's scorecard (default: me)
 *   GET performance.php?team=1          -> everyone the caller may see, ranked
 *
 * Visibility follows the standard ladder (admin/Board = all, head = department,
 * team lead = their line, everyone else = themselves).
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_governance.php';
require_login();
$db = db();
$me = (int)(current_employee_id() ?: 0);
$days = max(7, min(365, (int)($_GET['days'] ?? 90)));

/** Who may this caller look at? */
function perf_visible_ids(PDO $db): ?array { return visible_owner_ids(); }

/** The automatic half of a person's performance: TAT/SLA + escalations + goals. */
function perf_for(PDO $db, int $empId, int $days): array
{
    $q = function (string $sql, array $a = []) use ($db) {
        $s = $db->prepare($sql); $s->execute($a); return $s->fetch(PDO::FETCH_ASSOC) ?: [];
    };

    // --- TAT / SLA on tasks (the assignee owns the TAT) ---
    $tasks = $q("SELECT COUNT(*) total,
                        SUM(t.sla_due_at IS NOT NULL) with_sla,
                        SUM(t.sla_due_at IS NOT NULL AND t.status IN('done','cancelled')
                            AND t.updated_at <= t.sla_due_at) met,
                        SUM(t.sla_due_at IS NOT NULL AND (
                              (t.status IN('done','cancelled') AND t.updated_at > t.sla_due_at)
                           OR (t.status IN('open','in_progress') AND t.sla_due_at < NOW()))) breached
                   FROM tasks t
                  WHERE t.assigned_to = ? AND t.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)", [$empId, $days]);

    // --- TAT / SLA on tickets they own ---
    $tickets = $q("SELECT COUNT(*) total,
                          SUM(ct.sla_due_at IS NOT NULL) with_sla,
                          SUM(ct.sla_due_at IS NOT NULL AND ct.status IN('closed','resolved')
                              AND ct.updated_at <= ct.sla_due_at) met,
                          SUM(ct.sla_due_at IS NOT NULL AND (
                                (ct.status IN('closed','resolved') AND ct.updated_at > ct.sla_due_at)
                             OR (ct.status NOT IN('closed','resolved') AND ct.sla_due_at < NOW()))) breached
                     FROM crm_tickets ct
                    WHERE ct.owner_id = ? AND ct.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)", [$empId, $days]);

    // --- escalations raised on their work (matrix + SLA codes carried through) ---
    $esc = $q("SELECT COUNT(*) hops,
                      COUNT(DISTINCT CONCAT(te.entity, te.task_id)) items,
                      SUM(te.status='open') still_open,
                      MAX(te.level) deepest_level,
                      SUM(te.to_board=1) to_board,
                      ROUND(AVG(te.breached_by_hours),1) avg_breach_h
                 FROM task_escalations te
                 LEFT JOIN tasks t ON te.entity='task' AND t.id = te.task_id
                 LEFT JOIN crm_tickets ct ON te.entity='ticket' AND ct.id = te.task_id
                WHERE COALESCE(t.assigned_to, ct.owner_id) = ?
                  AND te.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)", [$empId, $days]);

    // which SLA policies and matrix rules were involved — the audit trail, by code
    $trail = $db->prepare("SELECT te.esl_ref, te.level, te.status, te.breached_by_hours, te.created_at,
                                  p.sla_ref, p.name AS sla_name, m.esc_ref,
                                  COALESCE(t.title, ct.subject) AS subject
                             FROM task_escalations te
                             LEFT JOIN tasks t ON te.entity='task' AND t.id = te.task_id
                             LEFT JOIN crm_tickets ct ON te.entity='ticket' AND ct.id = te.task_id
                             LEFT JOIN sla_policies p ON p.id = te.sla_policy_id
                             LEFT JOIN escalation_matrix m ON m.id = te.matrix_id
                            WHERE COALESCE(t.assigned_to, ct.owner_id) = ?
                              AND te.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                            ORDER BY te.id DESC LIMIT 20");
    $trail->execute([$empId, $days]);

    // --- the KPA/KRA goals they own, with any SLA that measures them ---
    $goals = $db->prepare("SELECT k.id, k.kpa_ref, k.kra_ref, k.title, k.kra, k.weight, k.target_value, k.unit,
                                  k.period, p.sla_ref, p.name AS sla_name, p.tat_hours,
                                  COALESCE(a.status,'draft') AS approval_status
                             FROM kpa_goals k
                             LEFT JOIN sla_policies p ON p.id = k.sla_policy_id
                             LEFT JOIN governance_approvals a ON a.obj_type='kpa' AND a.obj_id=k.id
                            WHERE k.owner_employee_id = ? AND k.is_active = 1
                            ORDER BY k.id");
    $goals->execute([$empId]);

    $withSla   = (int)$tasks['with_sla'] + (int)$tickets['with_sla'];
    $met       = (int)$tasks['met'] + (int)$tickets['met'];
    $breached  = (int)$tasks['breached'] + (int)$tickets['breached'];
    $adherence = $withSla > 0 ? round($met * 100 / max(1, $met + $breached), 1) : null;

    // Performance impact: start at 100, lose ground for breaches and escalations.
    // Deliberately simple and explainable — the evidence is all above it.
    $score = 100;
    if ($adherence !== null) $score -= (100 - $adherence) * 0.6;
    $score -= min(20, (int)$esc['items'] * 3);
    $score -= min(10, (int)$esc['to_board'] * 5);
    $score = max(0, min(100, round($score)));

    return [
        'employee_id' => $empId,
        'window_days' => $days,
        'tat' => [
            'tasks' => $tasks, 'tickets' => $tickets,
            'with_sla' => $withSla, 'met' => $met, 'breached' => $breached,
            'adherence_pct' => $adherence,
        ],
        'escalations' => $esc,
        'escalation_trail' => $trail->fetchAll(PDO::FETCH_ASSOC),
        'goals' => $goals->fetchAll(PDO::FETCH_ASSOC),
        'performance_score' => $score,
        'band' => $score >= 85 ? 'on track' : ($score >= 65 ? 'watch' : 'at risk'),
    ];
}

if (method() === 'GET' && isset($_GET['team'])) {
    $ids = perf_visible_ids($db);
    $where = $ids === null ? '1' : 'e.id IN (' . (implode(',', array_map('intval', $ids)) ?: '-1') . ')';
    $people = $db->query("SELECT e.id, e.full_name, e.designation, e.department, e.sub_department, e.manager_id,
                                 m.full_name AS manager_name
                            FROM employees e
                            JOIN sales_users su ON su.employee_id=e.id AND su.is_active=1
                            LEFT JOIN employees m ON m.id = e.manager_id
                           WHERE $where ORDER BY e.full_name")->fetchAll(PDO::FETCH_ASSOC);
    $rows = [];
    foreach ($people as $p) {
        $m = perf_for($db, (int)$p['id'], $days);
        $rows[] = $p + [
            'adherence_pct'   => $m['tat']['adherence_pct'],
            'with_sla'        => $m['tat']['with_sla'],
            'met'             => $m['tat']['met'],
            'breached'        => $m['tat']['breached'],
            'escalated_items' => (int)$m['escalations']['items'],
            'still_open'      => (int)$m['escalations']['still_open'],
            'to_board'        => (int)$m['escalations']['to_board'],
            'deepest_level'   => (int)$m['escalations']['deepest_level'],
            'goals'           => count($m['goals']),
            'performance_score' => $m['performance_score'],
            'band'            => $m['band'],
        ];
    }
    usort($rows, fn($a, $b) => $a['performance_score'] <=> $b['performance_score']);   // weakest first

    // ---- aggregate roll-up: the whole scope, then by department and by manager ----
    $agg = function (array $set): array {
        $n = count($set);
        $withSla = array_sum(array_column($set, 'with_sla'));
        $met = array_sum(array_column($set, 'met'));
        $bre = array_sum(array_column($set, 'breached'));
        $scores = array_column($set, 'performance_score');
        return [
            'people'        => $n,
            'with_sla'      => $withSla,
            'met'           => $met,
            'breached'      => $bre,
            'adherence_pct' => ($met + $bre) > 0 ? round($met * 100 / ($met + $bre), 1) : null,
            'escalated_items' => array_sum(array_column($set, 'escalated_items')),
            'still_open'    => array_sum(array_column($set, 'still_open')),
            'to_board'      => array_sum(array_column($set, 'to_board')),
            'avg_score'     => $n ? round(array_sum($scores) / $n) : null,
            'at_risk'       => count(array_filter($set, fn($r) => $r['band'] === 'at risk')),
            'watch'         => count(array_filter($set, fn($r) => $r['band'] === 'watch')),
            'on_track'      => count(array_filter($set, fn($r) => $r['band'] === 'on track')),
        ];
    };
    $byDept = [];
    foreach ($rows as $r) $byDept[$r['department'] ?: 'Unassigned'][] = $r;
    $depts = [];
    foreach ($byDept as $d => $set) $depts[] = ['department' => $d] + $agg($set);
    usort($depts, fn($a, $b) => ($a['avg_score'] ?? 100) <=> ($b['avg_score'] ?? 100));

    $byMgr = [];
    foreach ($rows as $r) if ($r['manager_id']) $byMgr[$r['manager_name'] ?: ('#' . $r['manager_id'])][] = $r;
    $teams = [];
    foreach ($byMgr as $mn => $set) $teams[] = ['manager' => $mn] + $agg($set);
    usort($teams, fn($a, $b) => ($a['avg_score'] ?? 100) <=> ($b['avg_score'] ?? 100));

    // what level of aggregation the caller is entitled to see
    $role = current_role();
    $level = $role === 'admin' || $ids === null ? 'organisation'
           : (payg_role_tier($role) >= PAYG_TIER_HEAD ? 'department' : 'team');

    ok([
        'window_days' => $days,
        'level'       => $level,
        'summary'     => $agg($rows),
        'by_department' => $depts,
        'by_team'     => $teams,
        'people'      => $rows,
    ]);
}

if (method() === 'GET') {
    $empId = (int)($_GET['employee_id'] ?? 0) ?: $me;
    if ($empId !== $me) assert_can_see($empId);      // ladder: self, team, dept, all
    ok(perf_for($db, $empId, $days));
}

fail('Unsupported request', 405);
