<?php
/**
 * SLA / escalation engine — TASKS and TICKETS.
 *
 * TAT model: every task/ticket gets an SLA policy (most specific active match
 * on entity + type/priority) which stamps sla_due_at. Once breached and still
 * open, it escalates one level at a time, at each level's configured interval:
 *
 *   assignee's manager -> manager's manager -> … -> ADMIN -> THE BOARD (all
 *   members of active FULL-ACCESS groups), where the chain terminates.
 *   escalation_matrix rows override any hop per employee; a matrix row's
 *   tat_hours (else the policy's escalate_every_hours, else its tat_hours)
 *   is the interval before the NEXT hop.
 *
 *   Unowned tickets (queue items nobody claimed) escalate to their vertical's
 *   department manager first, then admin, then the Board.
 *
 * escalated_to = -1 is the "Board reached" sentinel — the top of the chain.
 * Non-fatal by design: callers wrap in try/catch; escalation must never break
 * CRUD or the cron.
 */

/**
 * Most specific active SLA policy. NULL columns are wildcards; specificity weights
 * make department-scoped policies beat generic ones and sub-department beat both
 * (sub_dept 3 + dept 2 + type 1 + priority 1).
 */
/** The login role and active group ids of an employee (for role/group targeting). */
function esc_person_context(PDO $db, ?int $empId): array
{
    static $cache = [];
    if (!$empId) return ['role' => null, 'groups' => []];
    if (isset($cache[$empId])) return $cache[$empId];
    $r = $db->prepare("SELECT role FROM sales_users WHERE employee_id=? AND is_active=1 LIMIT 1");
    $r->execute([$empId]);
    $g = $db->prepare("SELECT m.group_id FROM emp_group_members m JOIN emp_groups gg ON gg.id=m.group_id
                        WHERE m.employee_id=? AND gg.is_active=1");
    $g->execute([$empId]);
    return $cache[$empId] = ['role' => $r->fetchColumn() ?: null,
                             'groups' => array_map('intval', $g->fetchAll(PDO::FETCH_COLUMN))];
}

/**
 * Most specific active SLA policy. NULL columns are wildcards; specificity
 * weights decide the winner:
 *   person (8) > group (6) > role (5) > sub-department (3) > department (2)
 *   > task type (1) + priority (1)
 */
function sla_match_policy(PDO $db, ?string $taskType, ?string $priority, string $entity = 'task',
                          ?string $dept = null, ?string $subDept = null, ?int $empId = null): ?array
{
    $ctx = esc_person_context($db, $empId);
    $groupIn = $ctx['groups'] ? implode(',', $ctx['groups']) : '-1';
    $s = $db->prepare(
        "SELECT * FROM sla_policies
          WHERE is_active=1 AND status='active' AND entity=?
            AND (task_type IS NULL OR task_type=?)
            AND (priority  IS NULL OR priority=?)
            AND (department IS NULL OR department=?)
            AND (sub_department IS NULL OR sub_department=?)
            AND (applies_to_type='all'
                 OR (applies_to_type='employee' AND applies_to_id=?)
                 OR (applies_to_type='role'     AND applies_to_role=?)
                 OR (applies_to_type='group'    AND applies_to_id IN ($groupIn)))
          ORDER BY (task_type IS NOT NULL) + (priority IS NOT NULL)
                 + 2*(department IS NOT NULL) + 3*(sub_department IS NOT NULL)
                 + 8*(applies_to_type='employee') + 6*(applies_to_type='group')
                 + 5*(applies_to_type='role') DESC,
                   tat_hours ASC
          LIMIT 1");
    $s->execute([$entity, $taskType ?: '', $priority ?: '', $dept ?: '', $subDept ?: '',
                 $empId ?: 0, $ctx['role'] ?: '']);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/* ==================================================================== *
 *  TAT arithmetic (phase70)
 *  A deadline can be stated the way the business states it:
 *    hours          — plain elapsed hours (the original behaviour)
 *    business_hours — only hours inside the policy's working window
 *    business_days  — N working days, landing at the end of the work day
 *    calendar_days  — N days, clock unchanged
 *    clock          — "T+n by 11:00" (settlement/reconciliation style)
 *  Working hours mean a Friday-evening breach clock doesn't run all weekend.
 * ==================================================================== */

/** Days the policy works, as ISO day numbers (1=Mon … 7=Sun). */
function sla_work_days(array $p): array
{
    if (!empty($p['is_24x7'])) return [1, 2, 3, 4, 5, 6, 7];
    $map = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];
    $out = [];
    foreach (explode(',', (string)($p['work_days'] ?? 'Mon,Tue,Wed,Thu,Fri')) as $d) {
        $k = strtolower(substr(trim($d), 0, 3));
        if (isset($map[$k])) $out[] = $map[$k];
    }
    return $out ?: [1, 2, 3, 4, 5];
}

/** Deadline for a policy, starting from $startTs (unix). Returns unix seconds. */
function sla_due_from(array $p, int $startTs): int
{
    $basis = $p['tat_basis'] ?? 'hours';
    $value = (float)($p['tat_value'] ?? $p['tat_hours'] ?? 0);
    $days  = sla_work_days($p);
    $open  = !empty($p['is_24x7']) ? '00:00:00' : ($p['work_start'] ?? '09:00:00');
    $close = !empty($p['is_24x7']) ? '23:59:59' : ($p['work_end'] ?? '18:00:00');
    $secOpen  = strtotime('1970-01-01 ' . $open . ' UTC');
    $secClose = strtotime('1970-01-01 ' . $close . ' UTC');
    $dayLen   = max(1, $secClose - $secOpen);

    $isWork = fn(int $ts) => in_array((int)date('N', $ts), $days, true);
    $atTime = fn(int $ts, int $secs) => strtotime(date('Y-m-d', $ts) . ' 00:00:00') + $secs;

    switch ($basis) {
        case 'calendar_days':
            return $startTs + (int)round($value * 86400);

        case 'clock':   // T+n by HH:MM, skipping non-working days
            $offset = max(0, (int)($p['tat_offset_days'] ?? 1));
            $clock  = $p['tat_clock'] ?: '11:00:00';
            $t = $startTs;
            for ($i = 0; $i < $offset; $i++) {
                do { $t = strtotime('+1 day', $t); } while (!$isWork($t));
            }
            if ($offset === 0) while (!$isWork($t)) $t = strtotime('+1 day', $t);
            return $atTime($t, strtotime('1970-01-01 ' . $clock . ' UTC'));

        case 'business_days':
            $t = $startTs;
            $whole = (int)floor($value);
            for ($i = 0; $i < $whole; $i++) {
                do { $t = strtotime('+1 day', $t); } while (!$isWork($t));
            }
            while (!$isWork($t)) $t = strtotime('+1 day', $t);
            return $atTime($t, $secClose);          // due by close of that working day

        case 'business_hours':
            $remaining = $value * 3600;
            $t = $startTs;
            $guard = 0;
            while ($remaining > 0 && $guard++ < 800) {
                if (!$isWork($t)) { $t = $atTime(strtotime('+1 day', $t), $secOpen); continue; }
                $dayOpen  = $atTime($t, $secOpen);
                $dayClose = $atTime($t, $secClose);
                if ($t < $dayOpen)  { $t = $dayOpen; continue; }
                if ($t >= $dayClose) { $t = $atTime(strtotime('+1 day', $t), $secOpen); continue; }
                $left = $dayClose - $t;
                if ($remaining <= $left) return (int)($t + $remaining);
                $remaining -= $left;
                $t = $atTime(strtotime('+1 day', $t), $secOpen);
            }
            return (int)$t;

        default:        // plain hours
            return $startTs + (int)round(($value ?: (float)($p['tat_hours'] ?? 0)) * 3600);
    }
}

/** The business-facing deadline label, e.g. "3 Business Days", "T+1 by 11:00 AM". */
function sla_tat_label(array $p): string
{
    $v = rtrim(rtrim(number_format((float)($p['tat_value'] ?? $p['tat_hours'] ?? 0), 1, '.', ''), '0'), '.');
    switch ($p['tat_basis'] ?? 'hours') {
        case 'business_days':  return $v . ' Business Day' . ($v == 1 ? '' : 's');
        case 'calendar_days':  return $v . ' Calendar Day' . ($v == 1 ? '' : 's');
        case 'business_hours': return $v . ' Working Hours';
        case 'clock':
            $n = (int)($p['tat_offset_days'] ?? 1);
            return 'T+' . $n . ' by ' . date('g:i A', strtotime('1970-01-01 ' . ($p['tat_clock'] ?: '11:00:00')));
        default:               return $v . ' Hours';
    }
}

/** "Mon–Fri (9 AM – 6 PM)" / "24x7" for the table. */
function sla_hours_label(array $p): string
{
    if (!empty($p['is_24x7'])) return '24x7';
    $d = array_map('trim', explode(',', (string)($p['work_days'] ?? '')));
    $span = count($d) > 1 ? $d[0] . '–' . end($d) : ($d[0] ?? '');
    $f = fn($t) => date('g A', strtotime('1970-01-01 ' . $t));
    return $span . ' (' . $f($p['work_start'] ?? '09:00:00') . ' – ' . $f($p['work_end'] ?? '18:00:00') . ')';
}

/** Department / sub-department of an employee (for SLA policy scoping). */
function sla_employee_dept(PDO $db, ?int $empId): array
{
    if (!$empId) return [null, null];
    $s = $db->prepare("SELECT department, sub_department FROM employees WHERE id=?");
    $s->execute([$empId]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    return [$r['department'] ?? null, $r['sub_department'] ?? null];
}

/** Stamp a freshly created task with its SLA policy + due time. */
function sla_stamp_task(PDO $db, int $taskId): void
{
    $t = $db->prepare("SELECT id, task_type, priority, created_at, due_at, assigned_to FROM tasks WHERE id=?");
    $t->execute([$taskId]);
    $task = $t->fetch(PDO::FETCH_ASSOC);
    if (!$task) return;
    [$dept, $subDept] = sla_employee_dept($db, (int)($task['assigned_to'] ?? 0));
    $p = sla_match_policy($db, $task['task_type'], $task['priority'], 'task', $dept, $subDept, (int)($task['assigned_to'] ?? 0));
    if (!$p) return;
    $slaDue = date('Y-m-d H:i:s', sla_due_from($p, strtotime($task['created_at'])));
    if (!empty($task['due_at']) && strtotime($task['due_at']) < strtotime($slaDue)) {
        $slaDue = date('Y-m-d H:i:s', strtotime($task['due_at']));
    }
    $db->prepare("UPDATE tasks SET sla_policy_id=?, sla_due_at=? WHERE id=?")
       ->execute([(int)$p['id'], $slaDue, $taskId]);
}

/** Stamp a ticket. Ticket "type" for policy matching is crm_tickets.assist_type. */
function sla_stamp_ticket(PDO $db, int $ticketId): void
{
    $t = $db->prepare("SELECT id, assist_type, priority, created_at, owner_id, vertical FROM crm_tickets WHERE id=?");
    $t->execute([$ticketId]);
    $tk = $t->fetch(PDO::FETCH_ASSOC);
    if (!$tk) return;
    // Dept comes from the ticket's owner; an unowned ticket falls back to its
    // vertical's department name (Sales / Service) so dept policies still apply.
    [$dept, $subDept] = sla_employee_dept($db, (int)($tk['owner_id'] ?? 0));
    if ($dept === null && !empty($tk['vertical'])) $dept = ucfirst($tk['vertical']);
    $p = sla_match_policy($db, $tk['assist_type'], $tk['priority'], 'ticket', $dept, $subDept, (int)($tk['owner_id'] ?? 0));
    if (!$p) return;
    $slaDue = date('Y-m-d H:i:s', sla_due_from($p, strtotime($tk['created_at'])));
    $db->prepare("UPDATE crm_tickets SET sla_policy_id=?, sla_due_at=? WHERE id=?")
       ->execute([(int)$p['id'], $slaDue, $ticketId]);
}

/** The admin panel's employee id. 0 if none configured. */
function escalation_admin_employee(PDO $db): int
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $v = $db->query("SELECT employee_id FROM sales_users
                      WHERE role='admin' AND is_active=1 AND employee_id IS NOT NULL
                      ORDER BY id LIMIT 1")->fetchColumn();
    return $cache = (int)$v;
}

/** Every member of an active FULL-ACCESS group — "the Board". */
function escalation_board_ids(PDO $db): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        return $cache = array_map('intval', $db->query(
            "SELECT DISTINCT m.employee_id FROM emp_group_members m
               JOIN emp_groups g ON g.id=m.group_id
              WHERE g.is_active=1 AND g.grant_all=1")->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) { return $cache = []; }
}

/** First dept manager (with an active login) for a ticket vertical — the L1 stop for unowned tickets. */
function escalation_dept_manager(PDO $db, ?string $vertical): int
{
    $role = $vertical === 'service' ? 'service_manager' : 'manager';
    $v = $db->query("SELECT employee_id FROM sales_users
                      WHERE role=" . $db->quote($role) . " AND is_active=1 AND employee_id IS NOT NULL
                      ORDER BY id LIMIT 1")->fetchColumn();
    return (int)$v;
}

/** Who receives level $level for work owned by $assigneeId (matrix > manager chain > admin). */
/**
 * The matrix rule that governs this person at this level: their own rule first,
 * then a rule for any group they belong to, then one for their role.
 */
function escalation_matrix_rule(PDO $db, int $assigneeId, int $level): ?array
{
    $ctx = esc_person_context($db, $assigneeId);
    $groupIn = $ctx['groups'] ? implode(',', $ctx['groups']) : '-1';
    $s = $db->prepare("SELECT * FROM escalation_matrix
                        WHERE level=?
                          AND ((subject_type='employee' AND employee_id=?)
                            OR (subject_type='group'    AND subject_group_id IN ($groupIn))
                            OR (subject_type='role'     AND subject_role=?))
                        ORDER BY (subject_type='employee') DESC, (subject_type='group') DESC, id
                        LIMIT 1");
    $s->execute([$level, $assigneeId, $ctx['role'] ?: '']);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Resolve a rule's target to a real person. A role or group target picks the
 * member carrying the fewest open escalations, so a group rule always lands on
 * someone specific instead of nobody — and spreads the load.
 */
function escalation_resolve_target(PDO $db, array $rule, int $assigneeId): int
{
    $type = $rule['target_type'] ?? 'employee';
    if ($type === 'employee') return (int)($rule['escalate_to'] ?? 0);

    if ($type === 'group') {
        $s = $db->prepare("SELECT e.id,
                                  (SELECT COUNT(*) FROM task_escalations te
                                    WHERE te.to_employee_id=e.id AND te.status='open') AS load_now
                             FROM emp_group_members m
                             JOIN employees e ON e.id=m.employee_id
                             JOIN sales_users su ON su.employee_id=e.id AND su.is_active=1
                            WHERE m.group_id=? AND e.id<>?
                            ORDER BY load_now ASC, e.id ASC LIMIT 1");
        $s->execute([(int)($rule['target_group_id'] ?? 0), $assigneeId]);
        return (int)$s->fetchColumn();
    }
    // role
    $s = $db->prepare("SELECT e.id,
                              (SELECT COUNT(*) FROM task_escalations te
                                WHERE te.to_employee_id=e.id AND te.status='open') AS load_now
                         FROM employees e
                         JOIN sales_users su ON su.employee_id=e.id AND su.is_active=1
                        WHERE su.role=? AND e.id<>?
                        ORDER BY load_now ASC, e.id ASC LIMIT 1");
    $s->execute([(string)($rule['target_role'] ?? ''), $assigneeId]);
    return (int)$s->fetchColumn();
}

function escalation_target(PDO $db, int $assigneeId, int $level): int
{
    $rule = escalation_matrix_rule($db, $assigneeId, $level);
    if ($rule) {
        $custom = escalation_resolve_target($db, $rule, $assigneeId);
        if ($custom) return $custom;
    }

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

/** Full chain preview for one employee (matrix UI) — now terminates at the Board. */
function escalation_chain(PDO $db, int $assigneeId, int $maxLevel = 6): array
{
    $admin = escalation_admin_employee($db);
    $chain = [];
    for ($lvl = 1; $lvl <= $maxLevel; $lvl++) {
        $to = escalation_target($db, $assigneeId, $lvl);
        if (!$to) break;
        $custom = escalation_matrix_rule($db, $assigneeId, $lvl);
        $chain[] = ['level' => $lvl, 'employee_id' => $to,
                    'custom' => (bool)$custom, 'tat_hours' => $custom['tat_hours'] ?? null,
                    'is_admin' => $to === $admin, 'is_board' => false];
        if ($to === $admin) break;
        if (count($chain) > 1 && $chain[count($chain)-1]['employee_id'] === $chain[count($chain)-2]['employee_id']) break;
    }
    if (escalation_board_ids($db)) {
        $chain[] = ['level' => (end($chain)['level'] ?? 0) + 1, 'employee_id' => 0,
                    'custom' => false, 'tat_hours' => null, 'is_admin' => false, 'is_board' => true];
    }
    return $chain;
}

/** Normalise a tasks row for the generic escalator. */
function esc_norm_task(array $t): array
{
    return ['entity' => 'task', 'table' => 'tasks', 'id' => (int)$t['id'],
            'title' => $t['title'], 'priority' => $t['priority'], 'status' => $t['status'],
            'assignee' => (int)$t['assigned_to'], 'vertical' => null,
            'sla_policy_id' => $t['sla_policy_id'] ?? null, 'sla_due_at' => $t['sla_due_at'] ?? null,
            'escalation_level' => (int)($t['escalation_level'] ?? 0),
            'escalated_to' => (int)($t['escalated_to'] ?? 0)];
}

/** Normalise a crm_tickets row (assignee = owner, may be 0 for queue items). */
function esc_norm_ticket(array $t): array
{
    return ['entity' => 'ticket', 'table' => 'crm_tickets', 'id' => (int)$t['id'],
            'title' => trim(($t['ticket_ref'] ?? '') . ' ' . ($t['subject'] ?? '')) ?: ('ticket #' . $t['id']),
            'priority' => $t['priority'] ?: 'medium', 'status' => $t['status'],
            'assignee' => (int)($t['owner_id'] ?? 0), 'vertical' => $t['vertical'] ?? null,
            'sla_policy_id' => $t['sla_policy_id'] ?? null, 'sla_due_at' => $t['sla_due_at'] ?? null,
            'escalation_level' => (int)($t['escalation_level'] ?? 0),
            'escalated_to' => (int)($t['escalated_to'] ?? 0)];
}

/**
 * Escalate one item one level. Returns the log-row id, or 0 when the Board has
 * already been reached (escalated_to = -1) / there is nowhere left to go.
 */
function escalate_item(PDO $db, array $it, string $source = 'auto', ?string $reason = null): int
{
    $admin = escalation_admin_employee($db);
    $board = escalation_board_ids($db);
    $current = $it['escalated_to'];
    if ($current === -1) return 0;                             // Board reached — top of the chain
    $level = $it['escalation_level'] + 1;

    // resolve the next stop
    $toBoard = false; $to = 0;
    if ($current && $current === $admin) {
        if (!$board) return 0;                                 // no Board defined — admin is final
        $toBoard = true;
    } elseif (!$it['assignee']) {                              // unowned ticket queue item
        $to = $level <= 1 ? escalation_dept_manager($db, $it['vertical']) : $admin;
        if (!$to) { $to = $admin; }
        if ($current && $to === $current) $to = $admin;
        if ($current && $to === $current) { if (!$board) return 0; $toBoard = true; }
    } else {
        $to = escalation_target($db, $it['assignee'], $level);
        if (!$to || $to === $it['assignee']) $to = $admin;
        if ($current && $to === $current) $to = $admin;
        if (!$to) return 0;
        if ($current && $to === $current) { if (!$board) return 0; $toBoard = true; }
    }

    $pol = null;
    if (!empty($it['sla_policy_id'])) {
        $q = $db->prepare("SELECT * FROM sla_policies WHERE id=?");
        $q->execute([(int)$it['sla_policy_id']]);
        $pol = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $tat = $pol ? (float)$pol['tat_hours'] : null;
    $breachedBy = !empty($it['sla_due_at'])
        ? round(max(0, time() - strtotime($it['sla_due_at'])) / 3600, 1) : null;

    if ($reason === null) {
        $who = 'unassigned (' . ($it['vertical'] ?: 'queue') . ')';
        if ($it['assignee']) {
            $n = $db->prepare("SELECT full_name FROM employees WHERE id=?");
            $n->execute([$it['assignee']]);
            $who = $n->fetchColumn() ?: ('#' . $it['assignee']);
        }
        $reason = sprintf("TAT breached: %s '%s' (%s priority, %s) exceeded its %sh SLA by %sh and is still %s.%s",
            $it['entity'], $it['title'], $it['priority'], $who,
            $tat !== null ? rtrim(rtrim(number_format($tat, 1), '0'), '.') : '?',
            $breachedBy !== null ? $breachedBy : '?', $it['status'],
            $toBoard ? ' Raised to the BOARD.' : '');
    }

    $logTo = $toBoard ? ($board[0] ?? $admin) : $to;
    // Which escalation-matrix rule routed this hop (NULL = plain manager chain).
    // Stored so an escalation can be traced to both codes: SLA-nnnn and ESC-nnnn.
    $matrixId = null;
    if (!empty($it['assignee'])) {
        $rule = escalation_matrix_rule($db, (int)$it['assignee'], $level);
        $matrixId = $rule['id'] ?? null;
    }
    $newHolder = $toBoard ? -1 : $to;

    // CLAIM the hop before logging it. Two sweeps can overlap (a long cron run plus a manual
    // "Run sweep now", or two cron nodes) and both read the same pre-hop escalation_level, so
    // the level check in the sweep alone is not enough — it is a read, then a decision, then a
    // write. This UPDATE is conditional on the level we based the decision on: exactly one
    // caller can move the row off it, and the loser returns 0 instead of writing a second
    // escalation row, a second notification and a second SLA email for the same hop.
    // task_escalations also carries UNIQUE(entity, task_id, level) — phase76 — as a backstop.
    $ownTxn = !$db->inTransaction();
    if ($ownTxn) $db->beginTransaction();
    try {
        $claim = $db->prepare("UPDATE {$it['table']}
                                  SET escalation_level=?, escalated_to=?, last_escalated_at=NOW()
                                WHERE id=? AND escalation_level=?");
        $claim->execute([$level, $newHolder, $it['id'], $it['escalation_level']]);
        if ($claim->rowCount() === 0) {                        // someone else already took this hop
            if ($ownTxn) $db->rollBack();
            return 0;
        }
        $db->prepare("INSERT INTO task_escalations
                        (entity, task_id, level, from_employee_id, to_employee_id, to_board, sla_policy_id, matrix_id, tat_hours, breached_by_hours, reason, source)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$it['entity'], $it['id'], $level, $current ?: ($it['assignee'] ?: null), $logTo,
                      $toBoard ? 1 : 0, $it['sla_policy_id'] ?: null, $matrixId, $tat, $breachedBy,
                      mb_substr($reason, 0, 500), $source]);
        $logId = (int)$db->lastInsertId();
        if ($ownTxn) $db->commit();
    } catch (Throwable $e) {
        // Never leave the item advanced without a log row (or vice versa).
        if ($ownTxn && $db->inTransaction()) $db->rollBack();
        if (stripos($e->getMessage(), 'Duplicate') !== false) return 0;   // uq_esc_hop backstop
        throw $e;
    }

    // notify: the receiver(s) + the original assignee
    $targets = $toBoard ? $board : [$to];
    try {
        $ins = $db->prepare("INSERT INTO notifications (user_id,type,title,message,link) VALUES (?,?,?,?,?)");
        foreach ($targets as $emp) {
            $ins->execute([(int)$emp, 'task_escalated',
                ($toBoard ? '🔴 BOARD escalation (L' . $level . '): ' : 'Escalated to you (L' . $level . '): ') . $it['title'],
                mb_substr($reason, 0, 500), 'sales-escalations.php']);
        }
        if ($it['assignee']) {
            $ins->execute([$it['assignee'], 'task_escalated',
                'Your ' . $it['entity'] . ' was escalated (L' . $level . '): ' . $it['title'],
                'It breached its TAT and has been raised ' . ($toBoard ? 'to the Board.' : 'up your reporting line.'),
                'sales-dashboard.php']);
        }
    } catch (Throwable $e) {}
    // auto-EMAIL the assignee + receivers, each with their manager looped in (alert_settings)
    try {
        require_once __DIR__ . '/_alert_engine.php';
        alert_sla_email($db, $it, $level, $targets, $reason, $toBoard);
    } catch (Throwable $e) {}
    return $logId;
}

/** Back-compat wrapper (manual escalation of a tasks row from escalations.php). */
function escalate_task(PDO $db, array $task, string $source = 'auto', ?string $reason = null, ?int $actorId = null): int
{
    return escalate_item($db, esc_norm_task($task), $source, $reason);
}

/**
 * The sweep the cron (and "Run sweep now") executes, for BOTH entities:
 *  1. backfill SLA stamps on open tasks/tickets that pre-date their policies,
 *  2. escalate everything past its TAT / re-escalation interval,
 *  3. close log rows whose task/ticket got finished.
 */
function run_escalation_sweep(PDO $db): array
{
    $stats = ['stamped' => 0, 'escalated' => 0, 'resolved' => 0, 'errors' => 0];

    // 1. backfill
    foreach ($db->query("SELECT id FROM tasks WHERE status IN ('open','in_progress') AND sla_due_at IS NULL")
                ->fetchAll(PDO::FETCH_COLUMN) as $tid) {
        try { sla_stamp_task($db, (int)$tid); $stats['stamped']++; } catch (Throwable $e) { $stats['errors']++; }
    }
    foreach ($db->query("SELECT id FROM crm_tickets WHERE status NOT IN ('resolved','closed') AND sla_due_at IS NULL")
                ->fetchAll(PDO::FETCH_COLUMN) as $tid) {
        try { sla_stamp_ticket($db, (int)$tid); $stats['stamped']++; } catch (Throwable $e) { $stats['errors']++; }
    }

    // 2. escalate breaches — first hop when past sla_due_at; later hops when past
    //    last_escalated_at + the level's interval (matrix tat > policy escalate_every > policy tat)
    $sweepOne = function (array $row, string $entity) use ($db, &$stats) {
        $it = $entity === 'task' ? esc_norm_task($row) : esc_norm_ticket($row);
        if ($it['escalation_level'] >= (int)($row['max_level'] ?? 10)) return;
        if ($it['escalation_level'] > 0) {
            $cadence = (float)(($row['level_tat'] ?? null) ?: ($row['escalate_every_hours'] ?? null) ?: ($row['tat_hours'] ?? 24));
            if (time() < strtotime($row['last_escalated_at']) + (int)round($cadence * 3600)) return;
        }
        if (escalate_item($db, $it, 'auto')) $stats['escalated']++;
    };

    $tasks = $db->query(
        "SELECT t.*, p.tat_hours, p.escalate_every_hours, p.max_level, em.tat_hours AS level_tat
           FROM tasks t
           JOIN sla_policies p ON p.id = t.sla_policy_id
      LEFT JOIN escalation_matrix em ON em.employee_id = t.assigned_to AND em.level = t.escalation_level
          WHERE t.status IN ('open','in_progress')
            AND t.sla_due_at IS NOT NULL AND t.sla_due_at < NOW()")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($tasks as $row) { try { $sweepOne($row, 'task'); } catch (Throwable $e) { $stats['errors']++; } }

    $tickets = $db->query(
        "SELECT ct.*, p.tat_hours, p.escalate_every_hours, p.max_level, em.tat_hours AS level_tat
           FROM crm_tickets ct
           JOIN sla_policies p ON p.id = ct.sla_policy_id
      LEFT JOIN escalation_matrix em ON em.employee_id = ct.owner_id AND em.level = ct.escalation_level
          WHERE ct.status NOT IN ('resolved','closed')
            AND ct.sla_due_at IS NOT NULL AND ct.sla_due_at < NOW()")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($tickets as $row) { try { $sweepOne($row, 'ticket'); } catch (Throwable $e) { $stats['errors']++; } }

    // 3. resolve log rows for finished work
    $stats['resolved'] = (int)$db->exec(
        "UPDATE task_escalations te JOIN tasks t ON t.id = te.task_id AND te.entity='task'
            SET te.status='resolved', te.resolved_at=NOW()
          WHERE te.status='open' AND t.status IN ('done','cancelled')");
    $stats['resolved'] += (int)$db->exec(
        "UPDATE task_escalations te JOIN crm_tickets ct ON ct.id = te.task_id AND te.entity='ticket'
            SET te.status='resolved', te.resolved_at=NOW()
          WHERE te.status='open' AND ct.status IN ('resolved','closed')");

    return $stats;
}
