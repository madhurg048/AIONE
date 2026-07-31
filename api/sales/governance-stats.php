<?php
/**
 * Governance panel for the executive/admin dashboard: SLA health, high-TAT
 * hotspots, escalations, settlement approvals in flight, and compliance
 * renewal outlook. Admin + managers only (company-wide aggregates).
 */
require_once __DIR__ . '/_bootstrap.php';
require_login();
if (!can_manage_all_records()) fail('Governance stats are for managers and admins', 403);
$db = db();

/* ---- SLA / TAT ---- */
$sla = $db->query("SELECT
        SUM(status IN('open','in_progress') AND sla_due_at IS NOT NULL AND sla_due_at < NOW()) AS breached_open,
        SUM(status IN('open','in_progress') AND sla_due_at IS NOT NULL AND sla_due_at >= NOW()
            AND sla_due_at < DATE_ADD(NOW(), INTERVAL 4 HOUR)) AS at_risk_4h,
        SUM(status IN('open','in_progress')) AS open_total
      FROM tasks")->fetch();

// who has NOT met SLA — open breached tasks per assignee, worst overdue first
$slaMissed = $db->query("SELECT t.assigned_to, e.full_name, e.designation,
        COUNT(*) breached, ROUND(AVG(TIMESTAMPDIFF(MINUTE, t.sla_due_at, NOW()))/60, 1) avg_overdue_h,
        ROUND(MAX(TIMESTAMPDIFF(MINUTE, t.sla_due_at, NOW()))/60, 1) worst_overdue_h
      FROM tasks t LEFT JOIN employees e ON e.id=t.assigned_to
      WHERE t.status IN('open','in_progress') AND t.sla_due_at IS NOT NULL AND t.sla_due_at < NOW()
      GROUP BY t.assigned_to ORDER BY breached DESC, worst_overdue_h DESC LIMIT 8")->fetchAll();

// where the TAT (time in queue) runs highest — by task type
$highTat = $db->query("SELECT t.task_type,
        COUNT(*) open_cnt,
        ROUND(AVG(TIMESTAMPDIFF(MINUTE, t.created_at, NOW()))/60, 1) avg_age_h,
        SUM(t.sla_due_at IS NOT NULL AND t.sla_due_at < NOW()) breached
      FROM tasks t WHERE t.status IN('open','in_progress')
      GROUP BY t.task_type ORDER BY avg_age_h DESC LIMIT 8")->fetchAll();

/* ---- escalations ---- */
$esc = $db->query("SELECT COUNT(*) total_30d, SUM(status='open') open_now,
        SUM(status='open' AND level>=2) at_level2_plus
      FROM task_escalations WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetch();

/* ---- settlements in flight ---- */
$settle = $db->query("SELECT SUM(status='pending_check') to_check, SUM(status='checked') to_approve,
        SUM(status='approved') to_pay,
        SUM(CASE WHEN status IN('pending_check','checked','approved') THEN net_amount ELSE 0 END) in_flight_amt
      FROM settlements")->fetch();

/* ---- compliance outlook ---- */
$comp = $db->query("SELECT COUNT(*) total,
        SUM(item_type='policy') policies, SUM(item_type='certification') certifications,
        SUM(item_type='compliance') compliances, SUM(item_type='license') licenses,
        SUM(valid_till IS NOT NULL AND valid_till < CURDATE()) expired,
        SUM(valid_till IS NOT NULL AND valid_till >= CURDATE()
            AND valid_till < DATE_ADD(CURDATE(), INTERVAL 6 MONTH)) renewals_6m,
        SUM(valid_till IS NOT NULL AND valid_till >= CURDATE()
            AND valid_till < DATE_ADD(CURDATE(), INTERVAL 30 DAY)) renewals_30d
      FROM compliance_items WHERE status='active'")->fetch();

// what's coming up for renewal in the next 6 months, soonest first
$upcoming = $db->query("SELECT i.id, i.title, i.item_type, i.category, i.valid_till,
        DATEDIFF(i.valid_till, CURDATE()) days_left, g.name owner_group_name, e.full_name owner_name
      FROM compliance_items i
      LEFT JOIN emp_groups g ON g.id=i.owner_group_id
      LEFT JOIN employees e ON e.id=i.owner_employee_id
      WHERE i.status='active' AND i.valid_till IS NOT NULL
        AND i.valid_till < DATE_ADD(CURDATE(), INTERVAL 6 MONTH)
      ORDER BY i.valid_till LIMIT 8")->fetchAll();

ok([
    'sla'        => $sla,
    'sla_missed' => $slaMissed,
    'high_tat'   => $highTat,
    'escalations'=> $esc,
    'settlements'=> $settle,
    'compliance' => $comp,
    'upcoming'   => $upcoming,
]);
