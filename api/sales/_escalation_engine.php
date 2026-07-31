<?php
/**
 * SLA / escalation engine.
 *
 * TAT model: every task gets an SLA policy on creation (most specific active
 * match on type/priority wins) which stamps tasks.sla_due_at. Once the TAT is
 * breached and the task is still open, it escalates one level at a time:
 *
 *   level 1 = the assignee's manager (employees.manager_id), level 2 = the
 *   manager's manager, … until the chain runs out — then the ADMIN panel.
 *   A row in escalation_matrix (employee_id, level) overrides any hop, so a
 *   custom path can be defined per employee.
 *
 * Re-escalation cadence: matrix row tat_hours for the level just breached,
 * else the policy's escalate_every_hours, else its tat_hours.
 *
 * Non-fatal by design (like _automation_engine.php): callers wrap in
 * try/catch; a broken escalation must never break task CRUD or the cron.
 */

/** Most specific active SLA policy for a task (type+priority > type > priority > any). */
function sla_match_policy(PDO $db, ?string $taskType, ?string $priority): ?array
{
    $s = $db->prepare(
        "SELECT * FROM sla_policies
          WHERE is_active=1
            AND (task_type IS NULL OR task_type=?)
            AND (priority  IS NULL OR priority=?)
          ORDER BY (task_type IS NOT NULL) + (priority IS NOT NULL) DESC, tat_hours ASC
          LIMIT 1");
    $s->execute([$taskType ?: '', $priority ?: '']);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Stamp a freshly created task with its SLA policy + due time. */
function sla_stamp_task(PDO $db, int $taskId): void
{
    $t = $db->prepare("SELECT id, task_type, priority, created_at, due_at FROM tasks WHERE id=?");
    $t->execute([$taskId]);
    $task = $t->fetch(PDO::FETCH_ASSOC);
    if (!$task) return;
    $p = sla_match_policy($db, $task['task_type'], $task['priority']);
    if (!$p) return;
    // SLA clock starts at creation; an explicit earlier due_at tightens it.
    $slaDue = date('Y-m-d H:i:s', strtotime($task['created_at']) + (int)round((float)$p['tat_hours'] * 3600));
    if (!empty($task['due_at']) && strtotime($task['due_at']) < strtotime($slaDue)) {
        $slaDue = date('Y-m-d H:i:s', strtotime($task['due_at']));
    }
    $db->prepare("UPDATE tasks SET sla_policy_id=?, sla_due_at=? WHERE id=?")
       ->execute([(int)$p['id'], $slaDue, $taskId]);
}

/** The admin panel's employee id (final escalation stop). 0 if none configured. */
function escalation_admin_employee(PDO $db): int
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $v = $db->query("SELECT employee_id FROM sales_users
                      WHERE role='admin' AND is_active=1 AND employee_id IS NOT NULL
                      ORDER BY id LIMIT 1")->fetchColumn();
    return $cache = (int)$v;
}

/**
 * Who receives escalation level $level for work owned by $assigneeId.
 * Custom matrix row first, else the $level-th manager up the reporting chain,
 * else the admin. Returns 0 when there is genuinely nobody (no admin either).
 */
function escalation_target(PDO $db, int $assigneeId, int $level): int
{
    $m = $db->prepare("SELECT escalate_to FROM escalation_matrix WHERE employee_id=? AND level=?");
    $m->execute([$assigneeId, $level]);
    $custom = (int)$m->fetchColumn();
    if ($custom) return $custom;

    // walk the manager chain $level hops up, guarding against cycles
    $cur = $assigneeId; $seen = [$assigneeId => true];
    for ($i = 0; $i < $level; $i++) {
        $q = $db->prepare("SELECT manager_id FROM employees WHERE id=?");
        $q->execute([$cur]);
        $mgr = (int)$q->fetchColumn();
        if (!$mgr || isset($seen[$mgr])) return escalation_admin_employee($db);
        $seen[$mgr] = true;
        $cur = $mgr;
    }
    return $cur ?: escalation_admin_employee($db);
}

/** Full escalation chain preview for one employee (for the matrix UI). */
function escalation_chain(PDO $db, int $assigneeId, int $maxLevel = 6): array
{
    $admin = escalation_admin_employee($db);
    $chain = [];
    for ($lvl = 1; $lvl <= $maxLevel; $lvl++) {
        $to = escalation_target($db, $assigneeId, $lvl);
        if (!$to) break;
        $m = $db->prepare("SELECT escalate_to, tat_hours FROM escalation_matrix WHERE employee_id=? AND level=?");
        $m->execute([$assigneeId, $lvl]);
        $custom = $m->fetch(PDO::FETCH_ASSOC);
        $chain[] = ['level' => $lvl, 'employee_id' => $to,
                    'custom' => (bool)$custom, 'tat_hours' => $custom['tat_hours'] ?? null,
                    'is_admin' => $to === $admin];
        if ($to === $admin) break;                       // chain terminates at the admin panel
        if (count($chain) > 1 && $chain[count($chain)-1]['employee_id'] === $chain[count($chain)-2]['employee_id']) break;
    }
    return $chain;
}

/**
 * Escalate one task one level. Returns the log-row id or 0 if there was
 * nowhere left to go. $reason overrides the auto-generated one (manual path).
 */
function escalate_task(PDO $db, array $task, string $source = 'auto', ?string $reason = null, ?int $actorId = null): int
{
    $assignee = (int)$task['assigned_to'];
    $level    = (int)($task['escalation_level'] ?? 0) + 1;
    $admin    = escalation_admin_employee($db);
    $current  = (int)($task['escalated_to'] ?: 0);
    if ($current && $current === $admin) return 0;       // already at the admin panel

    $to = escalation_target($db, $assignee, $level);
    if (!$to || $to === $assignee) $to = $admin;
    if (!$to) return 0;
    // if this level resolves to whoever already holds it, jump to the admin
    if ($current && $to === $current) $to = $admin;
    if (!$to || ($current && $to === $current)) return 0;

    $pol = null;
    if (!empty($task['sla_policy_id'])) {
        $q = $db->prepare("SELECT * FROM sla_policies WHERE id=?");
        $q->execute([(int)$task['sla_policy_id']]);
        $pol = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $tat = $pol ? (float)$pol['tat_hours'] : null;
    $breachedBy = !empty($task['sla_due_at'])
        ? round(max(0, time() - strtotime($task['sla_due_at'])) / 3600, 1) : null;

    if ($reason === null) {
        $names = $db->prepare("SELECT id, full_name FROM employees WHERE id IN (?,?)");
        $names->execute([$assignee, $to]);
        $nm = array_column($names->fetchAll(PDO::FETCH_ASSOC), 'full_name', 'id');
        $reason = sprintf("TAT breached: '%s' (%s priority, assigned to %s) exceeded its %sh SLA by %sh and is still %s.",
            $task['title'], $task['priority'], $nm[$assignee] ?? ('#'.$assignee),
            $tat !== null ? rtrim(rtrim(number_format($tat, 1), '0'), '.') : '?',
            $breachedBy !== null ? $breachedBy : '?', $task['status']);
    }

    $db->prepare("INSERT INTO task_escalations
                    (task_id, level, from_employee_id, to_employee_id, sla_policy_id, tat_hours, breached_by_hours, reason, source)
                  VALUES (?,?,?,?,?,?,?,?,?)")
       ->execute([(int)$task['id'], $level, $current ?: $assignee, $to,
                  $task['sla_policy_id'] ?: null, $tat, $breachedBy, mb_substr($reason, 0, 500), $source]);
    $logId = (int)$db->lastInsertId();

    $db->prepare("UPDATE tasks SET escalation_level=?, escalated_to=?, last_escalated_at=NOW() WHERE id=?")
       ->execute([$level, $to, (int)$task['id']]);

    // notify the receiver + the original assignee
    try {
        $ins = $db->prepare("INSERT INTO notifications (user_id,type,title,message,link) VALUES (?,?,?,?,?)");
        $ins->execute([$to, 'task_escalated', 'Escalated to you (L'.$level.'): '.$task['title'],
                       mb_substr($reason, 0, 500), 'sales-escalations.php']);
        $ins->execute([$assignee, 'task_escalated', 'Your task was escalated (L'.$level.'): '.$task['title'],
                       'It breached its TAT and has been raised to your reporting line.', 'sales-dashboard.php']);
    } catch (Throwable $e) {}
    return $logId;
}

/**
 * The sweep the cron (and the "Run now" button) executes:
 *  1. backfill SLA stamps on open tasks that pre-date the SLA feature,
 *  2. escalate every open task past its TAT / re-escalation window,
 *  3. close escalation log rows whose task got done/cancelled.
 */
function run_escalation_sweep(PDO $db): array
{
    $stats = ['stamped' => 0, 'escalated' => 0, 'resolved' => 0, 'errors' => 0];

    // 1. backfill
    foreach ($db->query("SELECT id FROM tasks
                          WHERE status IN ('open','in_progress') AND sla_due_at IS NULL")
                ->fetchAll(PDO::FETCH_COLUMN) as $tid) {
        try { sla_stamp_task($db, (int)$tid); $stats['stamped']++; }
        catch (Throwable $e) { $stats['errors']++; }
    }

    // 2. escalate breaches. First hop when past sla_due_at; later hops when past
    //    last_escalated_at + the level's cadence (matrix tat > policy escalate_every > policy tat).
    $rows = $db->query(
        "SELECT t.*, p.escalate_every_hours, p.max_level, em.tat_hours AS level_tat
           FROM tasks t
           JOIN sla_policies p ON p.id = t.sla_policy_id
      LEFT JOIN escalation_matrix em ON em.employee_id = t.assigned_to AND em.level = t.escalation_level
          WHERE t.status IN ('open','in_progress')
            AND t.sla_due_at IS NOT NULL AND t.sla_due_at < NOW()")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $t) {
        try {
            if ((int)$t['escalation_level'] >= (int)$t['max_level']) continue;
            if ((int)$t['escalation_level'] > 0) {
                $cadence = (float)($t['level_tat'] ?: ($t['escalate_every_hours'] ?: $t['tat_hours'] ?: 24));
                $nextAt = strtotime($t['last_escalated_at']) + (int)round($cadence * 3600);
                if (time() < $nextAt) continue;
            }
            if (escalate_task($db, $t, 'auto')) $stats['escalated']++;
        } catch (Throwable $e) { $stats['errors']++; }
    }

    // 3. resolve log rows for finished tasks
    $stats['resolved'] = $db->exec(
        "UPDATE task_escalations te JOIN tasks t ON t.id = te.task_id
            SET te.status='resolved', te.resolved_at=NOW()
          WHERE te.status='open' AND t.status IN ('done','cancelled')") ?: 0;

    return $stats;
}
