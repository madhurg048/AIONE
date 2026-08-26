<?php
/**
 * My Activity — a personal view for ANY role: task monitor, timesheet summary, activity feed.
 *   GET activity.php
 */
require_once __DIR__ . '/_bootstrap.php';
require_login();
$db = db();
$me = current_employee_id();
$one = fn($sql, $args = []) => (function () use ($db, $sql, $args) { try { $s = $db->prepare($sql); $s->execute($args); return $s->fetchColumn(); } catch (Throwable $e) { return 0; } })();

$out = [
    'tasks' => [
        'open'      => (int)$one("SELECT COUNT(*) FROM tasks WHERE assigned_to=? AND status IN('open','in_progress')", [$me]),
        'overdue'   => (int)$one("SELECT COUNT(*) FROM tasks WHERE assigned_to=? AND status IN('open','in_progress') AND due_at IS NOT NULL AND due_at<NOW()", [$me]),
        'done_today'=> (int)$one("SELECT COUNT(*) FROM tasks WHERE assigned_to=? AND status='done' AND DATE(completed_at)=CURDATE()", [$me]),
    ],
    'timesheet' => [
        'hours_today'     => (float)$one("SELECT COALESCE(SUM(hours),0) FROM timesheets WHERE employee_id=? AND entry_date=CURDATE()", [$me]),
        'hours_week'      => (float)$one("SELECT COALESCE(SUM(hours),0) FROM timesheets WHERE employee_id=? AND entry_date>=CURDATE()-INTERVAL WEEKDAY(CURDATE()) DAY", [$me]),
        'pending_approval'=> (int)$one("SELECT COUNT(*) FROM timesheets WHERE employee_id=? AND status='submitted'", [$me]),
    ],
];

// task list (open, soonest due first)
$t = $db->prepare("SELECT id,title,task_type,priority,status,due_at,related_type,related_id
                   FROM tasks WHERE assigned_to=? AND status IN('open','in_progress')
                   ORDER BY due_at IS NULL, due_at LIMIT 12");
$t->execute([$me]);
$out['task_list'] = $t->fetchAll();

// time logged by activity type this week
$bt = $db->prepare("SELECT activity_type, COALESCE(SUM(hours),0) hrs FROM timesheets
                    WHERE employee_id=? AND entry_date>=CURDATE()-INTERVAL WEEKDAY(CURDATE()) DAY
                    GROUP BY activity_type ORDER BY hrs DESC");
$bt->execute([$me]);
$out['time_by_type'] = $bt->fetchAll();

// recent activity timeline (activities + timesheet entries + follow-ups)
try {
    $tl = $db->prepare("
        (SELECT 'activity' AS src, activity_type AS kind, subject AS label, created_at AS ts FROM activities WHERE owner_id=?)
        UNION ALL
        (SELECT 'time' AS src, activity_type AS kind, CONCAT(hours,'h — ', COALESCE(description,'')) AS label, created_at AS ts FROM timesheets WHERE employee_id=?)
        UNION ALL
        (SELECT 'touch' AS src, channel AS kind, COALESCE(feedback,outcome) AS label, created_at AS ts FROM lead_followups WHERE created_by=?)
        ORDER BY ts DESC LIMIT 15");
    $tl->execute([$me, $me, $me]);
    $out['timeline'] = $tl->fetchAll();
} catch (Throwable $e) { $out['timeline'] = []; }

ok($out);
