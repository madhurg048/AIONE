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
require_once __DIR__ . '/_escalation_engine.php';
require_login();
$db = db();

$canWrite = current_role() === 'admin' || payg_role_can(current_role(), 'people');

if (method() === 'GET' && isset($_GET['employees'])) {
    ok($db->query("SELECT id, full_name, designation FROM employees ORDER BY full_name")->fetchAll());
}

if (method() === 'GET' && isset($_GET['policies'])) {
    ok($db->query("SELECT p.*, (SELECT COUNT(*) FROM tasks t WHERE t.sla_policy_id=p.id AND t.status IN('open','in_progress')) AS open_tasks
                     FROM sla_policies p ORDER BY p.is_active DESC, p.tat_hours")->fetchAll());
}

if (method() === 'GET' && isset($_GET['chain'])) {
    $emp = (int)$_GET['chain'];
    $chain = escalation_chain($db, $emp);
    $ids = array_merge([$emp], array_column($chain, 'employee_id'));
    $in = implode(',', array_map('intval', array_unique($ids)));
    $names = $in ? $db->query("SELECT id, full_name, designation FROM employees WHERE id IN ($in)")->fetchAll(PDO::FETCH_UNIQUE) : [];
    ok(['chain' => $chain, 'people' => $names]);
}

if (method() === 'GET' && isset($_GET['matrix'])) {
    $w = ''; $args = [];
    if (!empty($_GET['employee_id'])) { $w = 'WHERE m.employee_id=?'; $args[] = (int)$_GET['employee_id']; }
    $s = $db->prepare("SELECT m.*, e.full_name AS employee_name, x.full_name AS escalate_to_name
                         FROM escalation_matrix m
                    LEFT JOIN employees e ON e.id=m.employee_id
                    LEFT JOIN employees x ON x.id=m.escalate_to
                        $w ORDER BY e.full_name, m.level");
    $s->execute($args);
    ok($s->fetchAll());
}

if (method() === 'POST' && isset($_GET['policy'])) {
    if (!$canWrite) fail('Not authorized to define SLA policies', 403);
    $b = body();
    if (empty($b['name']) || empty($b['tat_hours'])) fail('name and tat_hours required');
    $db->prepare("INSERT INTO sla_policies (name, task_type, priority, tat_hours, escalate_every_hours, max_level, is_active, created_by)
                  VALUES (?,?,?,?,?,?,1,?)")
       ->execute([trim($b['name']), ($b['task_type'] ?? null) ?: null, ($b['priority'] ?? null) ?: null,
                  max(0.5, (float)$b['tat_hours']),
                  isset($b['escalate_every_hours']) && $b['escalate_every_hours'] !== '' ? max(0.5, (float)$b['escalate_every_hours']) : null,
                  max(1, (int)($b['max_level'] ?? 10)), current_employee_id() ?: null]);
    ok(['id' => (int)$db->lastInsertId()]);
}

if ((method() === 'PATCH' || method() === 'PUT') && isset($_GET['policy_id'])) {
    if (!$canWrite) fail('Not authorized to change SLA policies', 403);
    $id = (int)$_GET['policy_id']; $b = body();
    $set = []; $args = [];
    foreach (['name','task_type','priority'] as $f) {
        if (array_key_exists($f, $b)) { $set[] = "$f=?"; $args[] = ($b[$f] === '' ? null : $b[$f]); }
    }
    if (array_key_exists('tat_hours', $b))            { $set[] = "tat_hours=?";            $args[] = max(0.5, (float)$b['tat_hours']); }
    if (array_key_exists('escalate_every_hours', $b)) { $set[] = "escalate_every_hours=?"; $args[] = ($b['escalate_every_hours'] === '' || $b['escalate_every_hours'] === null) ? null : max(0.5, (float)$b['escalate_every_hours']); }
    if (array_key_exists('max_level', $b))            { $set[] = "max_level=?";            $args[] = max(1, (int)$b['max_level']); }
    if (array_key_exists('is_active', $b))            { $set[] = "is_active=?";            $args[] = (int)!!$b['is_active']; }
    if (!$set) fail('Nothing to update');
    $args[] = $id;
    $db->prepare("UPDATE sla_policies SET " . implode(',', $set) . " WHERE id=?")->execute($args);
    ok(['id' => $id]);
}

if (method() === 'DELETE' && isset($_GET['policy_id'])) {
    if (!$canWrite) fail('Not authorized', 403);
    $db->prepare("UPDATE sla_policies SET is_active=0 WHERE id=?")->execute([(int)$_GET['policy_id']]);
    ok();
}

if (method() === 'POST' && isset($_GET['matrix'])) {
    if (!$canWrite) fail('Not authorized to define the escalation matrix', 403);
    $b = body();
    $emp = (int)($b['employee_id'] ?? 0); $lvl = (int)($b['level'] ?? 0); $to = (int)($b['escalate_to'] ?? 0);
    if (!$emp || !$lvl || !$to) fail('employee_id, level and escalate_to required');
    if ($emp === $to) fail('An employee cannot escalate to themselves');
    $tat = (isset($b['tat_hours']) && $b['tat_hours'] !== '') ? max(0.5, (float)$b['tat_hours']) : null;
    $db->prepare("INSERT INTO escalation_matrix (employee_id, level, escalate_to, tat_hours, created_by)
                  VALUES (?,?,?,?,?)
                  ON DUPLICATE KEY UPDATE escalate_to=VALUES(escalate_to), tat_hours=VALUES(tat_hours)")
       ->execute([$emp, $lvl, $to, $tat, current_employee_id() ?: null]);
    ok();
}

if (method() === 'DELETE' && isset($_GET['matrix_row'])) {
    if (!$canWrite) fail('Not authorized', 403);
    $db->prepare("DELETE FROM escalation_matrix WHERE id=?")->execute([(int)$_GET['matrix_row']]);
    ok();
}

fail('Unsupported request', 405);
