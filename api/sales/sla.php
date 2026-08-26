<?php
/**
 * SLA policies + escalation matrix admin.
 *   GET  sla.php?policies=1                  -> SLA policy list
 *   POST sla.php?policy=1                    -> create policy
 *   PATCH sla.php?policy_id=N                -> update policy
 *   DELETE sla.php?policy_id=N               -> deactivate policy
 *   GET  sla.php?matrix=1[&employee_id=N]    -> matrix rows (+ effective chain per employee)
 *   POST sla.php?matrix=1                    -> upsert a matrix row {employee_id, level, escalate_to, tat_hours}
 *   DELETE sla.php?matrix_row=N              -> remove a matrix override row
 *   GET  sla.php?chain=EMPLOYEE_ID           -> effective escalation chain preview
 *
 * Reading is open to managers+; writing the org's TAT/escalation rules is
 * admin + HR (the same people who own reporting lines).
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_governance.php';
require_once __DIR__ . '/_escalation_engine.php';
require_login();
$db = db();

$canWrite = current_role() === 'admin' || payg_role_can(current_role(), 'people');

if (method() === 'GET' && isset($_GET['employees'])) {
    ok($db->query("SELECT id, full_name, designation FROM employees ORDER BY full_name")->fetchAll());
}

/* everything an SLA or escalation rule can be pointed AT: a person, a role or a group */
if (method() === 'GET' && isset($_GET['targets'])) {
    ok([
        'people' => $db->query("SELECT e.id, e.full_name, e.designation, su.role
                                  FROM employees e LEFT JOIN sales_users su ON su.employee_id=e.id AND su.is_active=1
                                 ORDER BY e.full_name")->fetchAll(),
        'roles'  => array_map(fn($k) => ['key' => $k, 'label' => payg_role_label($k)], array_keys(payg_roles())),
        'groups' => $db->query("SELECT g.id, g.name,
                                 (SELECT COUNT(*) FROM emp_group_members m
                                   JOIN sales_users su ON su.employee_id=m.employee_id AND su.is_active=1
                                  WHERE m.group_id=g.id) AS members
                                  FROM emp_groups g WHERE g.is_active=1 ORDER BY g.name")->fetchAll(),
    ]);
}

if (method() === 'GET' && isset($_GET['policies'])) {
    $w = ['1']; $args = [];
    if (!empty($_GET['status']))     { $w[] = 'p.status=?';     $args[] = $_GET['status']; }
    if (!empty($_GET['sla_type']))   { $w[] = 'p.sla_type=?';   $args[] = $_GET['sla_type']; }
    if (!empty($_GET['department'])) { $w[] = 'p.department=?'; $args[] = $_GET['department']; }
    if (!empty($_GET['q']))          { $w[] = '(p.name LIKE ? OR p.description LIKE ?)';
                                       $like = '%' . $_GET['q'] . '%'; $args[] = $like; $args[] = $like; }
    $where = implode(' AND ', $w);
    $st = $db->prepare("SELECT p.*,
                     (SELECT COUNT(*) FROM tasks t WHERE t.sla_policy_id=p.id AND t.status IN('open','in_progress'))
                   + (SELECT COUNT(*) FROM crm_tickets ct WHERE ct.sla_policy_id=p.id AND ct.status NOT IN('resolved','closed')) AS open_tasks,
                     (SELECT GROUP_CONCAT(m.esc_ref ORDER BY m.esc_ref SEPARATOR ', ')
                        FROM governance_links l JOIN escalation_matrix m ON m.id=l.to_id
                       WHERE l.from_type='sla' AND l.from_id=p.id AND l.to_type='esc') AS matrix_codes,
                     (SELECT r.created_at FROM governance_revisions r
                       WHERE r.obj_type='sla' AND r.obj_id=p.id ORDER BY r.id DESC LIMIT 1) AS last_change,
                     (SELECT emp.full_name FROM governance_revisions r LEFT JOIN employees emp ON emp.id=r.changed_by
                       WHERE r.obj_type='sla' AND r.obj_id=p.id ORDER BY r.id DESC LIMIT 1) AS last_change_by,
                     COALESCE(a.status,'draft') AS approval_status
                     FROM sla_policies p
                     LEFT JOIN governance_approvals a ON a.obj_type='sla' AND a.obj_id=p.id
                    WHERE $where ORDER BY FIELD(p.status,'active','draft','archived'), p.entity, p.tat_hours");
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['tat_label']   = sla_tat_label($r);
        $r['hours_label'] = sla_hours_label($r);
        if ($r['applies_to_type'] === 'role') {
            $r['applies_label'] = 'Role: ' . payg_role_label($r['applies_to_role']);
        } elseif ($r['applies_to_type'] === 'group') {
            $g = $db->prepare("SELECT name FROM emp_groups WHERE id=?"); $g->execute([(int)$r['applies_to_id']]);
            $r['applies_label'] = 'Group: ' . ($g->fetchColumn() ?: '?');
        } elseif ($r['applies_to_type'] === 'employee') {
            $e = $db->prepare("SELECT full_name FROM employees WHERE id=?"); $e->execute([(int)$r['applies_to_id']]);
            $r['applies_label'] = 'Person: ' . ($e->fetchColumn() ?: '?');
        } else {
            $r['applies_label'] = $r['department'] ?: 'Everyone';
        }
    }
    unset($r);
    $tot = $db->query("SELECT COUNT(*) total, SUM(status='active') active_cnt,
                              SUM(status='draft') draft_cnt, SUM(status='archived') archived_cnt
                         FROM sla_policies")->fetch(PDO::FETCH_ASSOC);
    ok(['rows' => $rows, 'totals' => $tot,
        'sla_types' => ['Service', 'Operational', 'Control', 'Compliance'],
        'tat_bases' => ['hours' => 'Hours', 'business_hours' => 'Working hours',
                        'business_days' => 'Business days', 'calendar_days' => 'Calendar days',
                        'clock' => 'By a clock time (T+n)']]);
}

if (method() === 'GET' && isset($_GET['chain'])) {
    $emp = (int)$_GET['chain'];
    $chain = escalation_chain($db, $emp);
    $ids = array_merge([$emp], array_column($chain, 'employee_id'));
    $in = implode(',', array_map('intval', array_unique($ids)));
    $names = $in ? $db->query("SELECT id, full_name, designation FROM employees WHERE id IN ($in)")->fetchAll(PDO::FETCH_UNIQUE) : [];
    ok(['chain' => $chain, 'people' => $names]);
}

/**
 * ONE escalation table: a row per SUBJECT (person / role / group) with every
 * level side by side (L1, L2, L3 …), the way the design shows a matrix — rather
 * than one row per level. Each cell keeps its own ESC code, because escalation
 * events reference the individual level rows.
 */
if (method() === 'GET' && isset($_GET['matrix_grid'])) {
    $rows = $db->query("
        SELECT m.*, e.full_name AS employee_name, e.department AS employee_dept, e.sub_department AS employee_subdept,
               x.full_name AS escalate_to_name, sg.name AS subject_group_name, tg.name AS target_group_name,
               a.status AS approval_status,
               (SELECT MAX(r.created_at) FROM governance_revisions r WHERE r.obj_type='esc' AND r.obj_id=m.id) AS last_change,
               (SELECT emp.full_name FROM governance_revisions r
                  LEFT JOIN employees emp ON emp.id=r.changed_by
                 WHERE r.obj_type='esc' AND r.obj_id=m.id ORDER BY r.id DESC LIMIT 1) AS last_change_by,
               (SELECT COUNT(*) FROM task_escalations te WHERE te.matrix_id=m.id) AS times_used
          FROM escalation_matrix m
          LEFT JOIN employees e ON e.id = m.employee_id
          LEFT JOIN employees x ON x.id = m.escalate_to
          LEFT JOIN emp_groups sg ON sg.id = m.subject_group_id
          LEFT JOIN emp_groups tg ON tg.id = m.target_group_id
          LEFT JOIN governance_approvals a ON a.obj_type='esc' AND a.obj_id=m.id
         ORDER BY m.subject_type, e.full_name, m.level")->fetchAll(PDO::FETCH_ASSOC);

    $subjects = [];
    $maxLevel = 1;
    foreach ($rows as $r) {
        $key = $r['subject_type'] . ':' . ($r['subject_type'] === 'employee' ? (int)$r['employee_id']
              : ($r['subject_type'] === 'role' ? $r['subject_role'] : (int)$r['subject_group_id']));
        if (!isset($subjects[$key])) {
            $subjects[$key] = [
                'key'          => $key,
                'subject_type' => $r['subject_type'],
                'employee_id'  => $r['employee_id'] ? (int)$r['employee_id'] : null,
                'subject_role' => $r['subject_role'],
                'subject_group_id' => $r['subject_group_id'] ? (int)$r['subject_group_id'] : null,
                'label'        => $r['subject_type'] === 'role'  ? payg_role_label($r['subject_role'])
                                : ($r['subject_type'] === 'group' ? ($r['subject_group_name'] ?? '?')
                                : ($r['employee_name'] ?? ('#' . $r['employee_id']))),
                'department'   => $r['subject_type'] === 'employee' ? $r['employee_dept'] : null,
                'sub_department' => $r['subject_type'] === 'employee' ? $r['employee_subdept'] : null,
                'levels'       => [],
                'codes'        => [],
                'times_used'   => 0,
                'last_change'  => null,
                'last_change_by' => null,
            ];
        }
        $lvl = (int)$r['level'];
        $maxLevel = max($maxLevel, $lvl);
        $subjects[$key]['levels'][$lvl] = [
            'id'          => (int)$r['id'],
            'esc_ref'     => $r['esc_ref'],
            'version'     => (int)$r['version'],
            'target_type' => $r['target_type'],
            'target_label'=> $r['target_type'] === 'role'  ? 'Role: ' . payg_role_label($r['target_role'])
                           : ($r['target_type'] === 'group' ? 'Group: ' . ($r['target_group_name'] ?? '?')
                           : ($r['escalate_to_name'] ?? ('#' . $r['escalate_to']))),
            'escalate_to' => $r['escalate_to'] ? (int)$r['escalate_to'] : null,
            'target_role' => $r['target_role'],
            'target_group_id' => $r['target_group_id'] ? (int)$r['target_group_id'] : null,
            'tat_hours'   => $r['tat_hours'],
            'status'      => $r['approval_status'] ?? 'draft',
        ];
        $subjects[$key]['codes'][] = $r['esc_ref'];
        $subjects[$key]['times_used'] += (int)$r['times_used'];
        if ($r['last_change'] && $r['last_change'] > (string)$subjects[$key]['last_change']) {
            $subjects[$key]['last_change'] = $r['last_change'];
            $subjects[$key]['last_change_by'] = $r['last_change_by'];
        }
    }
    ok(['max_level' => $maxLevel, 'subjects' => array_values($subjects)]);
}

if (method() === 'GET' && isset($_GET['matrix'])) {
    $w = ''; $args = [];
    if (!empty($_GET['employee_id'])) { $w = 'WHERE m.employee_id=?'; $args[] = (int)$_GET['employee_id']; }
    $s = $db->prepare("SELECT m.*, e.full_name AS employee_name, x.full_name AS escalate_to_name,
                              sg.name AS subject_group_name, tg.name AS target_group_name
                         FROM escalation_matrix m
                    LEFT JOIN employees e ON e.id=m.employee_id
                    LEFT JOIN employees x ON x.id=m.escalate_to
                    LEFT JOIN emp_groups sg ON sg.id=m.subject_group_id
                    LEFT JOIN emp_groups tg ON tg.id=m.target_group_id
                        $w ORDER BY m.subject_type, e.full_name, m.level");
    $s->execute($args);
    $rows = $s->fetchAll();
    foreach ($rows as &$r) {
        $r['subject_label'] = $r['subject_type'] === 'role'  ? 'Role: ' . payg_role_label($r['subject_role'])
                            : ($r['subject_type'] === 'group' ? 'Group: ' . ($r['subject_group_name'] ?? '?')
                            : ($r['employee_name'] ?? ('#' . $r['employee_id'])));
        $r['target_label']  = $r['target_type'] === 'role'   ? 'Role: ' . payg_role_label($r['target_role'])
                            : ($r['target_type'] === 'group'  ? 'Group: ' . ($r['target_group_name'] ?? '?')
                            : ($r['escalate_to_name'] ?? ('#' . $r['escalate_to'])));
    }
    unset($r);
    ok($rows);
}

if (method() === 'POST' && isset($_GET['policy'])) {
    if (!$canWrite) fail('Not authorized to define SLA policies', 403);
    $b = body();
    if (empty($b['name']) || empty($b['tat_hours'])) fail('name and tat_hours required');
    $entity = in_array($b['entity'] ?? '', ['task', 'ticket'], true) ? $b['entity'] : 'task';
    $at = in_array($b['applies_to_type'] ?? 'all', ['all','employee','role','group'], true) ? ($b['applies_to_type'] ?? 'all') : 'all';
    $atId = in_array($at, ['employee','group'], true) ? ((int)($b['applies_to_id'] ?? 0) ?: null) : null;
    $atRole = $at === 'role' ? (payg_role_exists((string)($b['applies_to_role'] ?? '')) ? $b['applies_to_role'] : null) : null;
    if ($at === 'employee' && !$atId) fail('Pick the person this TAT applies to');
    if ($at === 'group' && !$atId)    fail('Pick the group this TAT applies to');
    if ($at === 'role' && !$atRole)   fail('Pick the role this TAT applies to');
    $basis = in_array($b['tat_basis'] ?? 'hours', ['hours','business_hours','business_days','calendar_days','clock'], true) ? ($b['tat_basis'] ?? 'hours') : 'hours';
    $tatValue = (float)($b['tat_value'] ?? $b['tat_hours'] ?? 0);
    $status = in_array($b['status'] ?? 'active', ['active','draft','archived'], true) ? ($b['status'] ?? 'active') : 'active';
    $db->prepare("INSERT INTO sla_policies (name, entity, department, sub_department, applies_to_type, applies_to_id, applies_to_role, task_type, priority, tat_hours, escalate_every_hours, max_level, is_active, created_by,
                                            sla_type, description, status, tat_basis, tat_value, tat_clock, tat_offset_days, is_24x7, work_days, work_start, work_end)
                  VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?, ?,?,?,?,?,?,?,?,?,?,?)")
       ->execute([trim($b['name']), $entity,
                  ($b['department'] ?? null) ?: null, ($b['sub_department'] ?? null) ?: null,
                  $at, $atId, $atRole,
                  ($b['task_type'] ?? null) ?: null, ($b['priority'] ?? null) ?: null,
                  max(0.5, (float)$b['tat_hours']),
                  isset($b['escalate_every_hours']) && $b['escalate_every_hours'] !== '' ? max(0.5, (float)$b['escalate_every_hours']) : null,
                  max(1, (int)($b['max_level'] ?? 10)), $status === 'active' ? 1 : 0, current_employee_id() ?: null,
                  ($b['sla_type'] ?? null) ?: null, ($b['description'] ?? null) ?: null, $status,
                  $basis, $tatValue ?: null, ($b['tat_clock'] ?? null) ?: null,
                  max(0, (int)($b['tat_offset_days'] ?? 1)), (int)!empty($b['is_24x7']),
                  ($b['work_days'] ?? 'Mon,Tue,Wed,Thu,Fri'),
                  ($b['work_start'] ?? '09:00:00'), ($b['work_end'] ?? '18:00:00')]);
    $newId = (int)$db->lastInsertId();
    gov_assign_code($db, 'sla_policies', $newId,
        gov_domain_for(trim($b['name']) . ' ' . (string)($b['sla_type'] ?? ''), $b['department'] ?? null));
    gov_approval($db, 'sla', $newId, true);                       // starts as a draft
    gov_revise($db, 'sla', $newId, 'created', 'TAT policy "' . trim($b['name']) . '" created');
    ok(['id' => $newId, 'code' => gov_code($db, 'sla', $newId)]);
}

if ((method() === 'PATCH' || method() === 'PUT') && isset($_GET['policy_id'])) {
    if (!$canWrite) fail('Not authorized to change SLA policies', 403);
    $id = (int)$_GET['policy_id']; $b = body();
    $set = []; $args = [];
    foreach (['name','task_type','priority','department','sub_department','applies_to_type','applies_to_id','applies_to_role',
              'sla_type','description','status','tat_basis','tat_value','tat_clock','tat_offset_days','work_days','work_start','work_end'] as $f) {
        if (array_key_exists($f, $b)) { $set[] = "$f=?"; $args[] = ($b[$f] === '' ? null : $b[$f]); }
    }
    if (array_key_exists('tat_hours', $b))            { $set[] = "tat_hours=?";            $args[] = max(0.5, (float)$b['tat_hours']); }
    if (array_key_exists('escalate_every_hours', $b)) { $set[] = "escalate_every_hours=?"; $args[] = ($b['escalate_every_hours'] === '' || $b['escalate_every_hours'] === null) ? null : max(0.5, (float)$b['escalate_every_hours']); }
    if (array_key_exists('max_level', $b))            { $set[] = "max_level=?";            $args[] = max(1, (int)$b['max_level']); }
    if (array_key_exists('is_active', $b))            { $set[] = "is_active=?";            $args[] = (int)!!$b['is_active']; }
    if (array_key_exists('is_24x7', $b))              { $set[] = "is_24x7=?";              $args[] = (int)!!$b['is_24x7']; }
    if (array_key_exists('status', $b))               { $set[] = "is_active=?";            $args[] = ($b['status'] === 'active') ? 1 : 0; }
    if (!$set) fail('Nothing to update');
    $args[] = $id;
    $db->prepare("UPDATE sla_policies SET " . implode(',', $set) . " WHERE id=?")->execute($args);
    gov_revise($db, 'sla', $id, 'updated', 'edited: ' . implode(', ', array_map(fn($x) => explode('=', $x)[0], $set)));
    gov_touch($db, 'sla', $id);
    ok(['id' => $id, 'code' => gov_code($db, 'sla', $id)]);
}

if (method() === 'DELETE' && isset($_GET['policy_id'])) {
    if (!$canWrite) fail('Not authorized', 403);
    $db->prepare("UPDATE sla_policies SET is_active=0 WHERE id=?")->execute([(int)$_GET['policy_id']]);
    ok();
}

if (method() === 'POST' && isset($_GET['matrix'])) {
    if (!$canWrite) fail('Not authorized to define the escalation matrix', 403);
    $b = body();
    $lvl = (int)($b['level'] ?? 0);
    if (!$lvl) fail('level required');

    // WHOSE work the rule governs: a person, everyone in a role, or a group
    $st = in_array($b['subject_type'] ?? 'employee', ['employee','role','group'], true) ? ($b['subject_type'] ?? 'employee') : 'employee';
    $emp = $st === 'employee' ? (int)($b['employee_id'] ?? 0) : null;
    $sRole = $st === 'role' ? (string)($b['subject_role'] ?? '') : null;
    $sGroup = $st === 'group' ? (int)($b['subject_group_id'] ?? 0) : null;
    if ($st === 'employee' && !$emp) fail('Pick the person this rule applies to');
    if ($st === 'role' && !payg_role_exists((string)$sRole)) fail('Pick a valid role');
    if ($st === 'group' && !$sGroup) fail('Pick the group this rule applies to');

    // WHO it escalates to
    $tt = in_array($b['target_type'] ?? 'employee', ['employee','role','group'], true) ? ($b['target_type'] ?? 'employee') : 'employee';
    $to = $tt === 'employee' ? (int)($b['escalate_to'] ?? 0) : null;
    $tRole = $tt === 'role' ? (string)($b['target_role'] ?? '') : null;
    $tGroup = $tt === 'group' ? (int)($b['target_group_id'] ?? 0) : null;
    if ($tt === 'employee' && !$to) fail('Pick who it escalates to');
    if ($tt === 'role' && !payg_role_exists((string)$tRole)) fail('Pick a valid role to escalate to');
    if ($tt === 'group' && !$tGroup) fail('Pick the group to escalate to');
    if ($st === 'employee' && $tt === 'employee' && $emp === $to) fail('An employee cannot escalate to themselves');

    $tat = (isset($b['tat_hours']) && $b['tat_hours'] !== '') ? max(0.5, (float)$b['tat_hours']) : null;
    $db->prepare("INSERT INTO escalation_matrix
                    (employee_id, subject_type, subject_role, subject_group_id, level,
                     escalate_to, target_type, target_role, target_group_id, tat_hours, created_by)
                  VALUES (?,?,?,?,?,?,?,?,?,?,?)
                  ON DUPLICATE KEY UPDATE escalate_to=VALUES(escalate_to), target_type=VALUES(target_type),
                     target_role=VALUES(target_role), target_group_id=VALUES(target_group_id),
                     tat_hours=VALUES(tat_hours)")
       ->execute([$emp, $st, $sRole, $sGroup, $lvl, $to, $tt, $tRole, $tGroup, $tat, current_employee_id() ?: null]);

    $mrow = $db->prepare("SELECT id FROM escalation_matrix
                           WHERE level=? AND subject_type=?
                             AND (employee_id <=> ?) AND (subject_role <=> ?) AND (subject_group_id <=> ?)");
    $mrow->execute([$lvl, $st, $emp, $sRole, $sGroup]);
    $mid = (int)$mrow->fetchColumn();
    if ($mid) {
        $mdom = $st === 'role' ? 'ROLE' : ($st === 'group' ? 'GRP' : null);
        if ($mdom === null) {
            $d = $db->prepare("SELECT department FROM employees WHERE id=?"); $d->execute([$emp]);
            $mdom = gov_domain_for('', $d->fetchColumn() ?: '');
        }
        $exists = $db->prepare("SELECT code_domain FROM escalation_matrix WHERE id=?");
        $exists->execute([$mid]);
        if (!$exists->fetchColumn()) gov_assign_code($db, 'escalation_matrix', $mid, $mdom);
        gov_approval($db, 'esc', $mid, true);
        gov_revise($db, 'esc', $mid, 'updated', "level $lvl escalation route set ($st -> $tt)");
        gov_touch($db, 'esc', $mid);
    }
    ok(['id' => $mid, 'code' => $mid ? gov_code($db, 'esc', $mid) : null]);
}

if (method() === 'DELETE' && isset($_GET['matrix_row'])) {
    if (!$canWrite) fail('Not authorized', 403);
    $mid = (int)$_GET['matrix_row'];
    gov_revise($db, 'esc', $mid, 'deleted', (gov_code($db, 'esc', $mid) ?: "ESC-$mid") . ' escalation rule removed');
    $db->prepare("DELETE FROM escalation_matrix WHERE id=?")->execute([$mid]);
    $db->prepare("DELETE FROM governance_approvals WHERE obj_type='esc' AND obj_id=?")->execute([$mid]);
    ok();
}

fail('Unsupported request', 405);
