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

    if (!$acts) ok(['entries' => [], 'note' => 'No recorded activity found for ' . $date . ' — add entries manually or by voice.']);

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

/* ---- VOICE PARSE: removed. Timesheet voice dictation has been retired org-wide.
   Entries are added manually or via AI-fill (?ai=) above. Reject any stale caller. ---- */
if (method() === 'POST' && isset($_GET['parse'])) {
    fail('Voice dictation has been removed', 410);
}

/* ---- submit all drafts for a day ---- */
if (method() === 'POST' && isset($_GET['submit'])) {
    $date = body()['date'] ?? date('Y-m-d');
    $st = $db->prepare("UPDATE timesheets SET status='submitted' WHERE employee_id=? AND entry_date=? AND status='draft'");
    $st->execute([$me, $date]);
    ok(['submitted' => $st->rowCount()]);
}

/* ---- manager: pending approvals from subordinates ---- */
if (method() === 'GET' && isset($_GET['pending'])) {
    $s = $db->prepare("SELECT t.*, e.full_name AS employee_name FROM timesheets t
                       JOIN employees e ON e.id=t.employee_id
                       WHERE e.manager_id=? AND t.status='submitted'
                       ORDER BY t.entry_date DESC, e.full_name");
    $s->execute([$me]);
    ok($s->fetchAll());
}

/* ---- my entries for a day ---- */
if (method() === 'GET') {
    $date = $_GET['date'] ?? date('Y-m-d');
    $s = $db->prepare("SELECT * FROM timesheets WHERE employee_id=? AND entry_date=? ORDER BY id");
    $s->execute([$me, $date]);
    $rows = $s->fetchAll();
    $total = array_sum(array_map(fn($r) => (float)$r['hours'], $rows));
    ok($rows, ['total_hours' => $total, 'date' => $date]);
}

/* ---- create ---- */
if (method() === 'POST') {
    $b = body();
    if (empty($b['entry_date'])) fail('entry_date required');
    $db->prepare("INSERT INTO timesheets (employee_id,entry_date,hours,activity_type,related_type,related_id,description,source,status)
                  VALUES (?,?,?,?,?,?,?,?, 'draft')")
       ->execute([$me, $b['entry_date'], (float)($b['hours'] ?? 0), $b['activity_type'] ?? 'other',
                  $b['related_type'] ?? 'none', $b['related_id'] ?? null, $b['description'] ?? null, $b['source'] ?? 'manual']);
    ok(['id' => (int)$db->lastInsertId()]);
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
        if (!$approvesAll && (int)($tr['manager_id'] ?? 0) !== $me) {
            fail('Not authorized to approve this entry', 403);
        }
        $db->prepare("UPDATE timesheets SET status=?, approved_by=?, approved_at=NOW(), remarks=? WHERE id=?")
           ->execute([$b['status'], $me, $b['remarks'] ?? null, $id]);
        ok(['id' => $id, 'status' => $b['status']]);
    }
    // owner edit (only own draft entries)
    $set = []; $args = [];
    foreach (['hours','activity_type','related_type','related_id','description','status'] as $f)
        if (array_key_exists($f, $b)) { $set[] = "$f=?"; $args[] = $b[$f]; }
    if (!$set) fail('Nothing to update');
    $args[] = $id; $args[] = $me;
    $db->prepare("UPDATE timesheets SET " . implode(',', $set) . " WHERE id=? AND employee_id=?")->execute($args);
    ok(['id' => $id]);
}

fail('Unsupported', 405);
