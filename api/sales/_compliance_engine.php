<?php
/**
 * Compliance reminder engine.
 *
 * An item enters its reminder window `reminder_days` before valid_till and
 * stays there (daily nudges) until renewed or archived. Reminders go to every
 * stakeholder, the owner employee, and every member of the owner group; a
 * renewal TASK is created for the owner (which then rides the normal SLA /
 * escalation engine, so an ignored renewal escalates up the manager chain).
 */

/** Everyone who must hear about $item: stakeholders + owner + owner-group members. */
function compliance_recipients(PDO $db, array $item): array
{
    $ids = [];
    $s = $db->prepare("SELECT employee_id FROM compliance_stakeholders WHERE item_id=?");
    $s->execute([(int)$item['id']]);
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $e) $ids[(int)$e] = true;
    if (!empty($item['owner_employee_id'])) $ids[(int)$item['owner_employee_id']] = true;
    if (!empty($item['owner_group_id'])) {
        $g = $db->prepare("SELECT employee_id FROM emp_group_members WHERE group_id=?");
        $g->execute([(int)$item['owner_group_id']]);
        foreach ($g->fetchAll(PDO::FETCH_COLUMN) as $e) $ids[(int)$e] = true;
    }
    return array_keys($ids);
}

/** Send the expiry reminder for one item now. Returns how many people were notified. */
function compliance_remind_item(PDO $db, array $item, bool $force = false): int
{
    if ($item['status'] !== 'active' || empty($item['valid_till'])) return 0;
    $daysLeft = (int)floor((strtotime($item['valid_till'] . ' 23:59:59') - time()) / 86400);
    if (!$force && $daysLeft > (int)$item['reminder_days']) return 0;

    $expired = $daysLeft < 0;
    $title = ($expired ? '⚠ EXPIRED: ' : 'Renewal due: ') . $item['title'];
    $msg = $expired
        ? sprintf("%s '%s' (%s) EXPIRED %d day(s) ago on %s. Immediate renewal required.",
            ucfirst($item['item_type']), $item['title'], $item['authority'] ?: $item['category'] ?: 'internal',
            abs($daysLeft), $item['valid_till'])
        : sprintf("%s '%s' (%s) expires in %d day(s) on %s. Start the renewal now.",
            ucfirst($item['item_type']), $item['title'], $item['authority'] ?: $item['category'] ?: 'internal',
            $daysLeft, $item['valid_till']);

    $recipients = compliance_recipients($db, $item);
    $n = 0;
    $ins = $db->prepare("INSERT INTO notifications (user_id,type,title,message,link) VALUES (?,?,?,?,?)");
    foreach ($recipients as $emp) {
        try { $ins->execute([$emp, 'compliance', $title, $msg, 'sales-compliance.php']); $n++; }
        catch (Throwable $e) {}
    }

    // one open renewal task per item — assigned to the owner (or first stakeholder)
    $assignee = (int)($item['owner_employee_id'] ?: ($recipients[0] ?? 0));
    if ($assignee) {
        $taskTitle = 'Renew ' . $item['item_type'] . ': ' . $item['title'];
        $chk = $db->prepare("SELECT COUNT(*) FROM tasks WHERE title=? AND status IN('open','in_progress')");
        $chk->execute([$taskTitle]);
        if (!$chk->fetchColumn()) {
            $db->prepare("INSERT INTO tasks (title, description, task_type, assigned_to, priority, due_at)
                          VALUES (?,?,?,?,?,?)")
               ->execute([$taskTitle, $msg, 'review', $assignee, $expired ? 'urgent' : 'high',
                          $expired ? date('Y-m-d H:i:s', time() + 86400) : $item['valid_till'] . ' 23:59:59']);
            $tid = (int)$db->lastInsertId();
            if (function_exists('sla_stamp_task')) { try { sla_stamp_task($db, $tid); } catch (Throwable $e) {} }
        }
    }

    $db->prepare("UPDATE compliance_items SET last_reminded_at=NOW() WHERE id=?")->execute([(int)$item['id']]);
    try {
        $db->prepare("INSERT INTO compliance_log (item_id, action, notes) VALUES (?,?,?)")
           ->execute([(int)$item['id'], 'reminder_sent', 'Notified ' . $n . ' stakeholder(s); ' . ($expired ? 'EXPIRED' : $daysLeft . 'd left')]);
    } catch (Throwable $e) {}
    return $n;
}

/**
 * Daily sweep: remind every active item in (or past) its reminder window, throttled to 1/day.
 *
 * The throttle window is deliberately 23h, not 24h: the cron fires at roughly the
 * same clock time each day, so a strict 24h test would lose the race to a few
 * seconds of drift and silently skip a day's nudge. 23h gives the sweep an hour
 * of slack while still keeping it to one reminder per item per day.
 */
function run_compliance_reminders(PDO $db): array
{
    $stats = ['checked' => 0, 'reminded' => 0, 'notified' => 0, 'errors' => 0];
    $rows = $db->query("SELECT * FROM compliance_items
                         WHERE status='active' AND valid_till IS NOT NULL
                           AND DATE_SUB(valid_till, INTERVAL reminder_days DAY) <= CURDATE()
                           AND (last_reminded_at IS NULL OR last_reminded_at < DATE_SUB(NOW(), INTERVAL 23 HOUR))")
                ->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $item) {
        $stats['checked']++;
        try {
            $n = compliance_remind_item($db, $item);
            if ($n) { $stats['reminded']++; $stats['notified'] += $n; }
        } catch (Throwable $e) { $stats['errors']++; }
    }
    return $stats;
}
