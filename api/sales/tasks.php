<?php
/**
 * Tasks API — My Tasks panel + delegate to subordinates.
 *   GET  tasks.php?mine=1[&status=open]   -> tasks assigned TO me
 *   GET  tasks.php?assigned=1             -> tasks I assigned to others
 *   GET  tasks.php?count=1               -> {open: N} for the badge
 *   GET  tasks.php?assignees=1           -> people I can assign to (my subordinates, else all)
 *   POST tasks.php                       -> create/assign a task
 *   PATCH tasks.php?id=5                  -> update status/fields
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_escalation_engine.php';
require_login();
$db = db();
$me = current_employee_id();

if (method() === 'GET' && isset($_GET['count'])) {
    $s = $db->prepare("SELECT COUNT(*) FROM tasks WHERE assigned_to=? AND status IN('open','in_progress')");
    $s->execute([$me]);
    ok(['open' => (int)$s->fetchColumn()]);
}

if (method() === 'GET' && isset($_GET['assignees'])) {
    // subordinates first; if none configured, fall back to all other employees
    $sub = $db->prepare("SELECT id, full_name, designation FROM employees WHERE manager_id=? ORDER BY full_name");
    $sub->execute([$me]);
    $rows = $sub->fetchAll();
    if (!$rows) {
        $rows = $db->prepare("SELECT id, full_name, designation FROM employees WHERE id<>? ORDER BY full_name");
        $rows->execute([$me]); $rows = $rows->fetchAll();
    }
    ok($rows);
}

if (method() === 'GET' && isset($_GET['assigned'])) {
    $s = $db->prepare("SELECT t.*, e.full_name AS assignee_name
                       FROM tasks t LEFT JOIN employees e ON e.id=t.assigned_to
                       WHERE t.assigned_by=? ORDER BY FIELD(t.status,'open','in_progress','done','cancelled'), t.due_at IS NULL, t.due_at");
    $s->execute([$me]);
    ok($s->fetchAll());
}

if (method() === 'GET') { // mine (default)
    $args = [$me]; $w = 'WHERE t.assigned_to=?';
    if (!empty($_GET['status'])) { $w .= ' AND t.status=?'; $args[] = $_GET['status']; }
    $s = $db->prepare("SELECT t.*, b.full_name AS assigner_name
                       FROM tasks t LEFT JOIN employees b ON b.id=t.assigned_by
                       $w ORDER BY FIELD(t.status,'in_progress','open','done','cancelled'),
                                   FIELD(t.priority,'urgent','high','medium','low'), t.due_at IS NULL, t.due_at");
    $s->execute($args);
    ok($s->fetchAll());
}

if (method() === 'POST') {
    $b = body();
    if (empty($b['title']))       fail('title required');
    if (empty($b['assigned_to'])) fail('assigned_to required');
    $kpaGoalId = (isset($b['kpa_goal_id']) && $b['kpa_goal_id'] !== '' && (int)$b['kpa_goal_id'] > 0) ? (int)$b['kpa_goal_id'] : null;
    $hoursSpent = isset($b['hours_spent']) ? max(0, (float)$b['hours_spent']) : 0;
    $db->prepare("INSERT INTO tasks (title,description,task_type,assigned_to,assigned_by,related_type,related_id,kpa_goal_id,hours_spent,priority,due_at)
                  VALUES (?,?,?,?,?,?,?,?,?,?,?)")
       ->execute([$b['title'], $b['description'] ?? null, $b['task_type'] ?? 'general',
                  (int)$b['assigned_to'], $me ?: null, $b['related_type'] ?? 'none',
                  isset($b['related_id']) ? (int)$b['related_id'] : null,
                  $kpaGoalId, $hoursSpent,
                  $b['priority'] ?? 'medium', $b['due_at'] ?? null]);
    $id = (int)$db->lastInsertId();
    // stamp the SLA/TAT clock (non-fatal — a missing policy just means no SLA)
    try { sla_stamp_task($db, $id); } catch (Throwable $e) {}
    // notify the assignee (uses the existing notifications table)
    try {
        $db->prepare("INSERT INTO notifications (user_id,type,title,message,link)
                      VALUES (?,?,?,?,?)")
           ->execute([(int)$b['assigned_to'], 'task_assigned', 'New task: ' . $b['title'],
                      'You have been assigned a task by a manager.', 'sales-dashboard.php']);
    } catch (Throwable $e) {}
    ok(['id' => $id]);
}

if (method() === 'PATCH' || method() === 'PUT') {
    $id = (int)($_GET['id'] ?? 0); if (!$id) fail('id required');
    $b = body();
    // fetch current state (to detect the pipeline → active transition)
    $cur = $db->prepare("SELECT status, assigned_to, task_type, title, related_type, related_id FROM tasks WHERE id=?");
    $cur->execute([$id]); $task = $cur->fetch();
    if (!$task) fail('Task not found', 404);

    $set = []; $args = [];
    foreach (['status','priority','due_at','description','title'] as $f) {
        if (array_key_exists($f, $b)) { $set[] = "$f=?"; $args[] = $b[$f]; }
    }
    // KPA/KRA link + hours the assignee logged against it (effort attribution).
    if (array_key_exists('hours_spent', $b)) { $set[] = "hours_spent=?"; $args[] = max(0, (float)$b['hours_spent']); }
    if (array_key_exists('kpa_goal_id', $b)) {
        $set[] = "kpa_goal_id=?";
        $args[] = ($b['kpa_goal_id'] !== null && $b['kpa_goal_id'] !== '' && (int)$b['kpa_goal_id'] > 0) ? (int)$b['kpa_goal_id'] : null;
    }
    if (isset($b['status']) && $b['status'] === 'done') $set[] = "completed_at=NOW()";
    // finishing the task closes any open escalations riding on it
    if (isset($b['status']) && in_array($b['status'], ['done','cancelled'], true)) {
        try { $db->prepare("UPDATE task_escalations SET status='resolved', resolved_at=NOW() WHERE task_id=? AND status='open'")->execute([$id]); } catch (Throwable $e) {}
    }
    if (!$set) fail('Nothing to update');
    $args[] = $id;
    // allow the assignee OR the assigner to update
    $db->prepare("UPDATE tasks SET " . implode(',', $set) . " WHERE id=? AND (assigned_to=$me OR assigned_by=$me)")
       ->execute($args);

    // Starting a task (pipeline → active) auto-logs a timesheet draft for the person doing it.
    $tsLogged = false;
    if (isset($b['status']) && $b['status'] === 'in_progress' && $task['status'] !== 'in_progress') {
        $map = ['follow_up'=>'follow_up','callback'=>'call','onboarding'=>'onboarding',
                'kyc_validation'=>'onboarding','document_collection'=>'admin','review'=>'admin','general'=>'other'];
        $tsType = $map[$task['task_type']] ?? 'other';
        $rt  = in_array($task['related_type'], ['lead','deal','merchant'], true) ? $task['related_type'] : 'none';
        $rid = $rt !== 'none' ? (int)$task['related_id'] : null;
        $desc = 'Task: ' . $task['title'];
        // one auto entry per task-start per day
        $chk = $db->prepare("SELECT COUNT(*) FROM timesheets WHERE employee_id=? AND entry_date=CURDATE() AND source='auto' AND description=?");
        $chk->execute([(int)$task['assigned_to'], substr($desc, 0, 500)]);
        if (!$chk->fetchColumn()) {
            $db->prepare("INSERT INTO timesheets (employee_id,entry_date,hours,activity_type,related_type,related_id,description,source,status)
                          VALUES (?,CURDATE(),0,?,?,?,?, 'auto','draft')")
               ->execute([(int)$task['assigned_to'], $tsType, $rt, $rid, substr($desc, 0, 500)]);
            $tsLogged = true;
        }
    }
    ok(['id' => $id, 'timesheet_logged' => $tsLogged]);
}

fail('Unsupported method', 405);
