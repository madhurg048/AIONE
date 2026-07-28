<?php
/**
 * HR dashboard: headcount, timesheet compliance, pending approvals, team activity, and the
 * HR Head's change-request queue.
 *
 *   GET  hr.php                              -> dashboard payload (incl. change requests)
 *   POST hr.php?approve&id= / ?reject&id=    -> approve/reject a submitted TIMESHEET
 *   POST hr.php?action=decide                -> HR Head decides a queued CHANGE REQUEST
 *                                               { id, decision: approve|reject, note? }
 *
 * Access: the Admin, the HR department, and Sales/Service managers who need the compliance
 * view. Deciding change requests is the HR Head's (or Admin's) alone — see hr_decide().
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_hr_approvals.php';
require_login();
if (!payg_is_hr_role(current_role())
    && !in_array(current_role(), ['admin', 'manager', 'sales_head', 'service_manager'], true)) {
    fail('HR/admin only', 403);
}
$db = db();
$me = current_employee_id();

/* ---- HR Head: approve / reject a queued change request ---- */
if (method() === 'POST' && ($_GET['action'] ?? '') === 'decide') {
    $b = body();
    $decision = ($b['decision'] ?? '') === 'approve' ? 'approve' : 'reject';
    ok(hr_decide($db, (int)($b['id'] ?? 0), $decision, trim((string)($b['note'] ?? '')) ?: null));
}

if (method() === 'POST' && (isset($_GET['approve']) || isset($_GET['reject']))) {
    // Approving a timesheet is routine HR work and is deliberately NOT queued for the HR
    // Head: gating every day's entries behind the Head would stall the department rather
    // than govern it. Only structural change (people, roles, goals, access) is queued.
    if (!can_approve_all_timesheets() && !can_manage_all_records()) {
        fail('Not authorized to approve timesheets', 403);
    }
    $id = (int)($_GET['id'] ?? 0);
    $status = isset($_GET['approve']) ? 'approved' : 'rejected';
    $db->prepare("UPDATE timesheets SET status=?, approved_by=?, approved_at=NOW() WHERE id=? AND status='submitted'")
       ->execute([$status, $me, $id]);
    ok(['id' => $id, 'status' => $status]);
}

$one = fn($sql) => (function () use ($db, $sql) { try { return $db->query($sql)->fetchColumn(); } catch (Throwable $e) { return 0; } })();

// staff = employees that have an active login
$staffJoin = "employees e JOIN sales_users su ON su.employee_id=e.id AND su.is_active=1";

$out = [
    'kpis' => [
        'headcount'        => (int)$one("SELECT COUNT(DISTINCT e.id) FROM $staffJoin"),
        'logged_today'     => (int)$one("SELECT COUNT(DISTINCT employee_id) FROM timesheets WHERE entry_date=CURDATE()"),
        'pending_approval' => (int)$one("SELECT COUNT(*) FROM timesheets WHERE status='submitted'"),
        'hours_week'       => (float)$one("SELECT COALESCE(SUM(hours),0) FROM timesheets WHERE entry_date>=CURDATE()-INTERVAL WEEKDAY(CURDATE()) DAY"),
        'open_tasks'       => (int)$one("SELECT COUNT(*) FROM tasks WHERE status IN('open','in_progress')"),
        'overdue_tasks'    => (int)$one("SELECT COUNT(*) FROM tasks WHERE status IN('open','in_progress') AND due_at<NOW()"),
    ],
];

// per-person timesheet compliance (today + this week)
$comp = $db->query("
    SELECT e.id, e.full_name, e.designation, su.role,
           COALESCE((SELECT SUM(hours) FROM timesheets t WHERE t.employee_id=e.id AND t.entry_date=CURDATE()),0) AS hours_today,
           COALESCE((SELECT SUM(hours) FROM timesheets t WHERE t.employee_id=e.id AND t.entry_date>=CURDATE()-INTERVAL WEEKDAY(CURDATE()) DAY),0) AS hours_week,
           (SELECT COUNT(*) FROM tasks tk WHERE tk.assigned_to=e.id AND tk.status IN('open','in_progress')) AS open_tasks
    FROM $staffJoin ORDER BY hours_week ASC, e.full_name");
$out['compliance'] = $comp->fetchAll();

// pending timesheet approvals
$pend = $db->query("
    SELECT t.id, t.entry_date, t.hours, t.activity_type, t.description, e.full_name AS employee
    FROM timesheets t JOIN employees e ON e.id=t.employee_id
    WHERE t.status='submitted' ORDER BY t.entry_date DESC, e.full_name LIMIT 40");
$out['pending'] = $pend->fetchAll();

// headcount by role
$byRole = $db->query("SELECT su.role, COUNT(*) c FROM sales_users su WHERE su.is_active=1 GROUP BY su.role");
$out['by_role'] = array_map(
    fn($r) => $r + ['role_label' => payg_role_label($r['role'])],
    $byRole->fetchAll(PDO::FETCH_ASSOC)
);

// Governance queue: what HR's own team has changed and is waiting on the Head to sign off.
$out['change_requests'] = [
    'pending'  => hr_pending_requests($db),
    'decided'  => hr_decided_requests($db),
    'count'    => hr_pending_count($db),
    'can_decide'     => in_array(current_role(), ['hr_head', 'admin'], true),
    'needs_approval' => payg_role_needs_hr_approval(current_role()),
];
$out['me'] = ['role' => current_role(), 'role_label' => payg_role_label(current_role())];

ok($out);
