<?php
/**
 * Automation rules API.
 *   GET  automations.php                 -> rules[]
 *   GET  automations.php?logs=1&rule=5    -> recent logs
 *   GET  automations.php?id=5             -> single rule
 *   POST automations.php                 -> create
 *   PATCH automations.php?id=5            -> update (incl. is_active toggle)
 *   DELETE automations.php?id=5
 *   POST automations.php?run_timebased=1 -> manually trigger the time-based sweep
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_automation_engine.php';
require_login();
/* Automation rules can reassign owners, send emails, and touch every record in the
   system — configuring or triggering them is an admin/manager action, not something
   a sales_exec should be able to do (they could otherwise reroute leads to themselves,
   or read/alter rules affecting the whole team). */
if (!can_manage_all_records()) fail('Not authorized', 403);
$db = db();

if (method() === 'POST' && isset($_GET['run_timebased'])) {
    require __DIR__ . '/../../cron/run-sales-automations.php';
    exit; // that script echoes its own JSON
}

if (method() === 'GET' && isset($_GET['logs'])) {
    $args = []; $w = '';
    if (!empty($_GET['rule'])) { $w = 'WHERE l.rule_id=?'; $args[] = (int)$_GET['rule']; }
    $s = $db->prepare("SELECT l.*, r.name rule_name FROM automation_logs l
                       LEFT JOIN automation_rules r ON r.id=l.rule_id
                       $w ORDER BY l.created_at DESC LIMIT 100");
    $s->execute($args);
    ok($s->fetchAll());
}

if (method() === 'GET' && isset($_GET['id'])) {
    $s = $db->prepare("SELECT * FROM automation_rules WHERE id=?");
    $s->execute([(int)$_GET['id']]);
    $r = $s->fetch();
    if (!$r) fail('Not found', 404);
    ok($r);
}

if (method() === 'GET') {
    ok($db->query("SELECT * FROM automation_rules ORDER BY entity, id")->fetchAll());
}

if (method() === 'POST') {
    $b = body();
    foreach (['name','entity','trigger_event','actions'] as $req) if (empty($b[$req])) fail("$req required");
    $conds   = is_array($b['conditions'] ?? null) ? json_encode($b['conditions']) : ($b['conditions'] ?? '[]');
    $actions = is_array($b['actions'])            ? json_encode($b['actions'])    : $b['actions'];
    // validate JSON
    if (json_decode($conds, true) === null && $conds !== '[]') fail('conditions must be valid JSON');
    if (json_decode($actions, true) === null) fail('actions must be valid JSON');
    $db->prepare("INSERT INTO automation_rules (name,entity,trigger_event,match_type,conditions,actions,is_active,created_by)
                  VALUES (?,?,?,?,?,?,?,?)")
       ->execute([$b['name'], $b['entity'], $b['trigger_event'], $b['match_type'] ?? 'all',
                  $conds, $actions, isset($b['is_active']) ? (int)$b['is_active'] : 1, current_employee_id() ?: null]);
    ok(['id' => (int)$db->lastInsertId()]);
}

if (method() === 'PATCH' || method() === 'PUT') {
    $id = (int)($_GET['id'] ?? 0); if (!$id) fail('id required');
    $b = body();
    $allowed = ['name','entity','trigger_event','match_type','conditions','actions','is_active'];
    $set = []; $args = [];
    foreach ($allowed as $f) {
        if (!array_key_exists($f, $b)) continue;
        $v = $b[$f];
        if (in_array($f, ['conditions','actions'], true) && is_array($v)) $v = json_encode($v);
        $set[] = "$f=?"; $args[] = $v;
    }
    if (!$set) fail('Nothing to update');
    $args[] = $id;
    $db->prepare("UPDATE automation_rules SET " . implode(',', $set) . " WHERE id=?")->execute($args);
    ok(['id' => $id]);
}

if (method() === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0); if (!$id) fail('id required');
    $db->prepare("DELETE FROM automation_rules WHERE id=?")->execute([$id]);
    ok(['deleted' => $id]);
}

fail('Unsupported method', 405);
