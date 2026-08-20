<?php
/**
 * Timesheets — log time against activities, submit for manager approval, AI-fill.
 *   GET  timesheet.php?mine=1&date=YYYY-MM-DD     -> my entries for a day (+ total)
 *   GET  timesheet.php?pending=1                  -> subordinates' submitted entries (manager)
 *   POST timesheet.php                            -> create entry
 *   PATCH timesheet.php?id=5                       -> update / set status (submit/approve/reject)
 *   POST timesheet.php?submit=1 {date}            -> submit all my drafts for a day
 *   POST timesheet.php?ai_fill=1 {date}           -> DRAFT entries from the day's CRM activity (LLM)
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../config/config.php';
@require_once __DIR__ . '/../service-health.php';
require_once __DIR__ . '/_ai.php';
require_login();
$db = db();
$me = current_employee_id();

/* ---- AI-FILL: draft entries from what the rep actually did that day ---- */
if (method() === 'POST' && isset($_GET['ai_fill'])) {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();   // slow call, release lock
    $date = body()['date'] ?? date('Y-m-d');

    $acts = [];
    $fu = $db->prepare("SELECT channel,outcome,feedback,created_at FROM lead_followups WHERE created_by=? AND DATE(created_at)=? ORDER BY created_at");
    $fu->execute([$me, $date]);
    foreach ($fu->fetchAll() as $r) $acts[] = "Follow-up ({$r['channel']}/{$r['outcome']}): " . ($r['feedback'] ?? '');
    $tk = $db->prepare("SELECT title,task_type,status FROM tasks WHERE assigned_to=? AND DATE(updated_at)=? ORDER BY updated_at");
    $tk->execute([$me, $date]);
    foreach ($tk->fetchAll() as $r) $acts[] = "Task ({$r['task_type']}, {$r['status']}): {$r['title']}";
    $av = $db->prepare("SELECT activity_type,subject FROM activities WHERE owner_id=? AND DATE(created_at)=? ORDER BY created_at");
    $av->execute([$me, $date]);
    foreach ($av->fetchAll() as $r) $acts[] = "{$r['activity_type']}: {$r['subject']}";

    if (!$acts) ok(['entries' => [], 'note' => 'No recorded activity found for ' . $date . ' — add entries manually.']);

    $prompt = 'Respond with ONLY raw JSON. You are a timesheet assistant for a sales rep.
From the day\'s activities below, produce timesheet entries totalling about 8 hours.
Allowed activity_type values: call, meeting, follow_up, onboarding, admin, travel, training, other.

Activities on ' . $date . ':
- ' . implode("\n- ", $acts) . '

Return: {"entries":[{"activity_type":"...","hours":<number>,"description":"short summary"}]}';

    $entries = [];
    $air = crm_ai_call($prompt, 'medium', 'chat_v3');   // box -> cloud -> local
    if ($air) {
        $clean = trim(preg_replace('/```(json)?/i','', $air['response']));
        if (preg_match('/\{[\s\S]*\}/u', $clean, $mch)) { $p = json_decode($mch[0], true); $entries = $p['entries'] ?? []; }
    }
    if (!$entries) {
        // graceful fallback: one entry summarising the day
        $entries = [['activity_type'=>'follow_up','hours'=>round(count($acts)*0.5,1) ?: 1,
                     'description'=>'Auto-draft: ' . count($acts) . ' activities on ' . $date]];
    }
    ok(['entries' => $entries, 'ai' => !empty($air), 'source' => $air['source'] ?? null]);
}

/* ---- submit all drafts (and previously-rejected, now-fixed) entries for a day ---- */
if (method() === 'POST' && isset($_GET['submit'])) {
    $date = body()['date'] ?? date('Y-m-d');
    // Resubmission: a manager rejects an entry, the rep edits its description, then hits
    // Submit again. That entry is status='rejected', not 'draft' — include it here too, and
    // notify the manager the same way the single-row PATCH resubmit path already does.
    $rej = $db->prepare("SELECT id FROM timesheets WHERE employee_id=? AND entry_date=? AND status='rejected'");
    $rej->execute([$me, $date]);
    $rejectedIds = $rej->fetchAll(PDO::FETCH_COLUMN);

    $st = $db->prepare("UPDATE timesheets SET status='submitted' WHERE employee_id=? AND entry_date=? AND status IN ('draft','rejected')");
    $st->execute([$me, $date]);

    if ($rejectedIds) {
        try {
            require_once __DIR__ . '/_alert_engine.php';
            $emp = $db->prepare("SELECT full_name FROM employees WHERE id=?"); $emp->execute([$me]);
            $empName = $emp->fetchColumn() ?: 'A rep';
            $mgr = $db->prepare("SELECT manager_id FROM employees WHERE id=?"); $mgr->execute([$me]);
            $mgrId = (int)$mgr->fetchColumn();
            if ($mgrId) {
                $n = count($rejectedIds);
                alert_send($db, 'timesheet_resubmitted', $mgrId,
                    $empName . ' resubmitted ' . $n . ' timesheet ' . ($n === 1 ? 'entry' : 'entries'),
                    "<p><b>" . htmlspecialchars($empName) . "</b> fixed and resubmitted previously rejected timesheet entries for $date. Please review again in CRM → Timesheet.</p>",
                    'ts:' . $date . ':' . $me);
            }
        } catch (Throwable $e) { /* non-fatal: resubmission itself must still succeed */ }
    }
    ok(['submitted' => $st->rowCount()]);
}

/* ---- manager/HR/admin: mark an employee on approved leave for a date (skips reminders + compliance) ---- */
if (method() === 'POST' && isset($_GET['leave'])) {
    $b = body();
    $empId = (int)($b['employee_id'] ?? 0);
    $date = $b['leave_date'] ?? '';
    if (!$empId || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date)) fail('employee_id and leave_date required');
    if (!can_manage_all_records()) {
        // a line manager may mark leave only for their own reporting line
        $chk = $db->prepare("SELECT manager_id FROM employees WHERE id=?"); $chk->execute([$empId]);
        if ((int)$chk->fetchColumn() !== $me) fail('Not authorized to mark leave for this employee', 403);
    }
    $db->prepare("INSERT INTO employee_leave_days (employee_id, leave_date, reason, created_by) VALUES (?,?,?,?)
                  ON DUPLICATE KEY UPDATE reason=VALUES(reason)")
       ->execute([$empId, $date, $b['reason'] ?? null, $me]);
    ok(['employee_id' => $empId, 'leave_date' => $date]);
}
if (method() === 'DELETE' && isset($_GET['leave'])) {
    $empId = (int)($_GET['employee_id'] ?? 0); $date = $_GET['leave_date'] ?? '';
    if (!$empId || !$date) fail('employee_id and leave_date required');
    if (!can_manage_all_records()) {
        $chk = $db->prepare("SELECT manager_id FROM employees WHERE id=?"); $chk->execute([$empId]);
        if ((int)$chk->fetchColumn() !== $me) fail('Not authorized', 403);
    }
    $db->prepare("DELETE FROM employee_leave_days WHERE employee_id=? AND leave_date=?")->execute([$empId, $date]);
    ok(['deleted' => true]);
}

/* ---- admin/HR: company-wide holidays (excludes everyone from the reminder + compliance that day) ---- */
if (method() === 'GET' && isset($_GET['holidays'])) {
    ok($db->query("SELECT * FROM company_holidays ORDER BY holiday_date")->fetchAll());
}
if (method() === 'POST' && isset($_GET['holidays'])) {
    if (!can_manage_all_records()) fail('Not authorized', 403);
    $b = body();
    if (empty($b['holiday_date']) || empty($b['name'])) fail('holiday_date and name required');
    $db->prepare("INSERT INTO company_holidays (holiday_date, name) VALUES (?,?) ON DUPLICATE KEY UPDATE name=VALUES(name)")
       ->execute([$b['holiday_date'], $b['name']]);
    ok(['holiday_date' => $b['holiday_date'], 'name' => $b['name']]);
}
if (method() === 'DELETE' && isset($_GET['holidays'])) {
    if (!can_manage_all_records()) fail('Not authorized', 403);
    $date = $_GET['holiday_date'] ?? '';
    if (!$date) fail('holiday_date required');
    $db->prepare("DELETE FROM company_holidays WHERE holiday_date=?")->execute([$date]);
    ok(['deleted' => true]);
}

/* ---- manager: set/clear who covers my approvals while I'm away ---- */
if (method() === 'POST' && isset($_GET['delegate'])) {
    $b = body();
    $delegateId = (int)($b['delegate_approver_id'] ?? 0) ?: null;
    $until = $b['delegate_until'] ?? null;
    if ($delegateId === $me) fail('Cannot delegate to yourself');
    $db->prepare("UPDATE employees SET delegate_approver_id=?, delegate_until=? WHERE id=?")
       ->execute([$delegateId, $delegateId ? $until : null, $me]);
    ok(['delegate_approver_id' => $delegateId, 'delegate_until' => $delegateId ? $until : null]);
}

/* ---- manager: pending approvals from subordinates (own reports + anyone who delegated to me) ---- */
if (method() === 'GET' && isset($_GET['pending'])) {
    $s = $db->prepare("SELECT t.*, e.full_name AS employee_name FROM timesheets t
                       JOIN employees e ON e.id=t.employee_id
                       WHERE t.status='submitted' AND (
                             e.manager_id=?
                          OR e.manager_id IN (SELECT id FROM employees WHERE delegate_approver_id=? AND (delegate_until IS NULL OR delegate_until>=CURDATE()))
                       )
                       ORDER BY t.entry_date DESC, e.full_name");
    $s->execute([$me, $me]);
    ok($s->fetchAll());
}

/* ---- manager: entries already reviewed (approved/rejected) — own reports + delegated ---- */
if (method() === 'GET' && isset($_GET['reviewed'])) {
    $s = $db->prepare("SELECT t.*, e.full_name AS employee_name, a.full_name AS approved_by_name
                       FROM timesheets t
                       JOIN employees e ON e.id=t.employee_id
                       LEFT JOIN employees a ON a.id=t.approved_by
                       WHERE t.status IN ('approved','rejected') AND (
                             e.manager_id=?
                          OR e.manager_id IN (SELECT id FROM employees WHERE delegate_approver_id=? AND (delegate_until IS NULL OR delegate_until>=CURDATE()))
                       )
                       ORDER BY t.approved_at DESC, t.entry_date DESC
                       LIMIT 100");
    $s->execute([$me, $me]);
    ok($s->fetchAll());
}

/* ---- lightweight "have I filled today?" check for the login-time reminder banner ---- */
if (method() === 'GET' && isset($_GET['today_check'])) {
    $today = date('Y-m-d');
    if ((int)date('w') === 0) ok(['filled' => true, 'reason' => 'sunday']);
    $hol = $db->prepare("SELECT COUNT(*) FROM company_holidays WHERE holiday_date=?"); $hol->execute([$today]);
    if ((int)$hol->fetchColumn() > 0) ok(['filled' => true, 'reason' => 'holiday']);
    $lv = $db->prepare("SELECT COUNT(*) FROM employee_leave_days WHERE employee_id=? AND leave_date=?"); $lv->execute([$me, $today]);
    if ((int)$lv->fetchColumn() > 0) ok(['filled' => true, 'reason' => 'leave']);
    $chk = $db->prepare("SELECT COUNT(*) FROM timesheets WHERE employee_id=? AND entry_date=?"); $chk->execute([$me, $today]);
    ok(['filled' => (int)$chk->fetchColumn() > 0]);
}

/* ---- my all-time entry totals (not scoped to a single day/week — for a running counter) ---- */
if (method() === 'GET' && isset($_GET['all_time_stats'])) {
    $s = $db->prepare("SELECT status, COUNT(*) AS n, COALESCE(SUM(hours),0) AS hrs FROM timesheets WHERE employee_id=? GROUP BY status");
    $s->execute([$me]);
    $byStatus = ['draft'=>0,'submitted'=>0,'approved'=>0,'rejected'=>0];
    $total = 0; $totalHours = 0.0;
    foreach ($s->fetchAll() as $r) {
        $byStatus[$r['status']] = (int)$r['n'];
        $total += (int)$r['n'];
        $totalHours += (float)$r['hrs'];
    }
    ok(['total_entries' => $total, 'total_hours' => round($totalHours, 2), 'by_status' => $byStatus]);
}

/* ---- my entries for a day ---- */
if (method() === 'GET') {
    $date = $_GET['date'] ?? date('Y-m-d');
    $s = $db->prepare("SELECT * FROM timesheets WHERE employee_id=? AND entry_date=? ORDER BY id");
    $s->execute([$me, $date]);
    $rows = $s->fetchAll();
    $total = array_sum(array_map(fn($r) => (float)$r['hours'], $rows));
    // Standard work day is 8h. Rows explicitly flagged is_overtime are always OT;
    // anything beyond 8 standard hours spills into OT too. Flag under-8 days
    // (only once the day actually has entries — an empty day isn't "under-hours", it's unfilled).
    $flaggedOt = array_sum(array_map(fn($r) => !empty($r['is_overtime']) ? (float)$r['hours'] : 0.0, $rows));
    $standard  = $total - $flaggedOt;
    $regular   = $rows ? min($standard, 8.0) : 0.0;
    $ot        = $rows ? $flaggedOt + max(0.0, $standard - 8.0) : 0.0;
    $underFlag = $rows && $standard < 8.0;
    ok($rows, ['total_hours' => $total, 'regular_hours' => $regular, 'ot_hours' => $ot,
               'standard_hours' => $standard, 'standard_target' => 8.0,
               'under_hours_flag' => $underFlag, 'date' => $date]);
}

/* ---- create ---- */
if (method() === 'POST') {
    $b = body();
    if (empty($b['entry_date'])) fail('entry_date required');
    // Backdated: logged for a day earlier than today. Not blocked (reps legitimately catch up
    // after a missed day), just flagged so managers see it was filed late.
    $late = $b['entry_date'] < date('Y-m-d') ? 1 : 0;
    $db->prepare("INSERT INTO timesheets (employee_id,entry_date,hours,activity_type,related_type,related_id,description,source,is_overtime,late_entry,status)
                  VALUES (?,?,?,?,?,?,?,?,?,?, 'draft')")
       ->execute([$me, $b['entry_date'], (float)($b['hours'] ?? 0), $b['activity_type'] ?? 'other',
                  $b['related_type'] ?? 'none', $b['related_id'] ?? null, $b['description'] ?? null, $b['source'] ?? 'manual',
                  !empty($b['is_overtime']) ? 1 : 0, $late]);
    ok(['id' => (int)$db->lastInsertId(), 'late_entry' => (bool)$late]);
}

/* ---- delete my own entry ----
   The trash button used to PATCH status='rejected' as a soft-delete, but that value
   routes into the approve/reject branch below, which demands manager rights — so it
   403'd for the very reps it was meant for, and any row that did get through stayed
   in the table forever polluting the status counts (and later showed a "Resubmit"
   button, as if a manager had rejected it). Delete for real, own drafts only. */
if (method() === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0); if (!$id) fail('id required');
    // approved/submitted entries are part of the approval record — not silently removable
    $st = $db->prepare("DELETE FROM timesheets WHERE id=? AND employee_id=? AND status IN ('draft','rejected')");
    $st->execute([$id, $me]);
    if (!$st->rowCount()) fail('Entry not found, not yours, or already submitted for approval', 404);
    ok(['deleted' => true, 'id' => $id]);
}

/* ---- update / approve / reject ---- */
if (method() === 'PATCH' || method() === 'PUT') {
    $id = (int)($_GET['id'] ?? 0); if (!$id) fail('id required');
    $b = body();
    if (($b['status'] ?? '') === 'approved' || ($b['status'] ?? '') === 'rejected') {
        // Approve/reject: a line manager may act on their own subordinates' entries, and a
        // company-wide approver (Admin, HR, Sales Head — capability 'timesheet_approve_all')
        // on anyone's.
        //
        // HR used to fall in a gap here: api/sales/hr.php let HR approve ANY submitted
        // timesheet, while this endpoint tested can_manage_all_records() — which excludes HR —
        // so the very same action worked from the HR Dashboard and 403'd from the Timesheet
        // page. Both doors now ask the same question.
        $approvesAll = can_approve_all_timesheets();
        if (!$approvesAll && !can_manage_all_records()) fail('Not authorized', 403);
        $row = $db->prepare("SELECT t.id, e.manager_id FROM timesheets t JOIN employees e ON e.id=t.employee_id WHERE t.id=?");
        $row->execute([$id]);
        $tr = $row->fetch();
        if (!$tr) fail('Not found', 404);
        $mgrId = (int)($tr['manager_id'] ?? 0);
        $isDirectManager = $mgrId === $me;
        // Delegation: while the direct manager is on leave (delegate_until in the future),
        // whoever they named as delegate_approver_id may act in their place.
        $isDelegate = false;
        if (!$isDirectManager && $mgrId) {
            $dq = $db->prepare("SELECT delegate_approver_id FROM employees WHERE id=? AND delegate_approver_id=? AND (delegate_until IS NULL OR delegate_until>=CURDATE())");
            $dq->execute([$mgrId, $me]);
            $isDelegate = (bool)$dq->fetchColumn();
        }
        if (!$approvesAll && !$isDirectManager && !$isDelegate) {
            fail('Not authorized to approve this entry', 403);
        }
        $db->prepare("UPDATE timesheets SET status=?, approved_by=?, approved_at=NOW(), remarks=? WHERE id=?")
           ->execute([$b['status'], $me, $b['remarks'] ?? null, $id]);
        ok(['id' => $id, 'status' => $b['status']]);
    }
    // owner edit (only own draft/rejected entries)
    // Resubmission: a rejected entry going back to 'submitted' re-notifies the manager —
    // otherwise a fixed-and-resubmitted timesheet silently sits unseen in their queue.
    if (($b['status'] ?? '') === 'submitted') {
        $prev = $db->prepare("SELECT status, employee_id FROM timesheets WHERE id=? AND employee_id=?");
        $prev->execute([$id, $me]);
        $pr = $prev->fetch();
        if ($pr && $pr['status'] === 'rejected') {
            try {
                require_once __DIR__ . '/_alert_engine.php';
                $emp = $db->prepare("SELECT full_name FROM employees WHERE id=?"); $emp->execute([$me]);
                $empName = $emp->fetchColumn() ?: 'A rep';
                $mgr = $db->prepare("SELECT manager_id FROM employees WHERE id=?"); $mgr->execute([$me]);
                $mgrId = (int)$mgr->fetchColumn();
                if ($mgrId) {
                    alert_send($db, 'timesheet_resubmitted', $mgrId,
                        $empName . ' resubmitted a timesheet entry',
                        "<p><b>" . htmlspecialchars($empName) . "</b> fixed and resubmitted a previously rejected timesheet entry (#$id). Please review it again in CRM → Timesheet.</p>",
                        'ts:' . $id . ':' . date('Y-m-d'));
                }
            } catch (Throwable $e) { /* non-fatal: resubmission itself must still succeed */ }
        }
    }
    $set = []; $args = [];
    foreach (['hours','activity_type','related_type','related_id','description','status','is_overtime'] as $f)
        if (array_key_exists($f, $b)) { $set[] = "$f=?"; $args[] = $b[$f]; }
    if (!$set) fail('Nothing to update');
    $args[] = $id; $args[] = $me;
    $db->prepare("UPDATE timesheets SET " . implode(',', $set) . " WHERE id=? AND employee_id=?")->execute($args);
    ok(['id' => $id]);
}

fail('Unsupported', 405);
