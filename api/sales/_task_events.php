<?php
/**
 * Auto-create tasks in the My Tasks panel from system events
 * (lead status change · ITSM ticket · PIMS merchant confirmation).
 * These tasks also enrich the activity data the AI timesheet-fill draws from.
 * Deterministic + de-duplicated (won't spam the same open task).
 */

function create_system_task(PDO $db, int $assignee, string $title, string $type,
                            string $relType, ?int $relId, string $priority = 'medium',
                            ?string $desc = null, ?int $dueDays = null): ?int
{
    if ($assignee <= 0) return null;
    try {
        // de-dup: don't create if an equivalent open task already exists
        $chk = $db->prepare("SELECT id FROM tasks WHERE assigned_to=? AND related_type=? AND (related_id<=>?)
                             AND task_type=? AND status IN('open','in_progress') LIMIT 1");
        $chk->execute([$assignee, $relType, $relId, $type]);
        if ($chk->fetchColumn()) return null;

        $due = $dueDays !== null ? date('Y-m-d H:i:s', strtotime("+$dueDays days")) : null;
        $db->prepare("INSERT INTO tasks (title,description,task_type,assigned_to,assigned_by,related_type,related_id,priority,status,due_at)
                      VALUES (?,?,?,?,NULL,?,?,?, 'open', ?)")
           ->execute([substr($title, 0, 200), $desc, $type, $assignee, $relType, $relId, $priority, $due]);
        $id = (int)$db->lastInsertId();
        try {
            $db->prepare("INSERT INTO notifications (user_id,type,title,message,link) VALUES (?,?,?,?,?)")
               ->execute([$assignee, 'auto_task', substr('Task: ' . $title, 0, 250),
                          'Auto-created from a system event.', 'sales-dashboard.php']);
        } catch (Throwable $e) {}
        return $id;
    } catch (Throwable $e) { error_log('create_system_task: ' . $e->getMessage()); return null; }
}

/**
 * Ask the LLM to phrase a task title + description from the situation.
 * Fast-fails to the deterministic fallback (short timeout) so events never block.
 */
function ai_task_phrase(string $situation, string $fallbackTitle, ?string $fallbackDesc = null): array
{
    if (!function_exists('crm_ai_call')) {
        $ai = __DIR__ . '/_ai.php';
        if (is_file($ai)) @require_once $ai;
    }
    if (!function_exists('crm_ai_call')) return ['title' => $fallbackTitle, 'description' => $fallbackDesc];

    $prompt = 'Respond with ONLY raw JSON. Write a CRM task for a sales rep from this situation.
Situation: ' . $situation . '
Return: {"title":"action-oriented, max 9 words","description":"one concise sentence on what to do and why"}';
    $r = crm_ai_call($prompt, 'short', 'chat_v3');   // box -> cloud -> local
    if (!$r) return ['title' => $fallbackTitle, 'description' => $fallbackDesc];
    $clean = trim(preg_replace('/```(json)?/i', '', $r['response']));
    if (preg_match('/\{[\s\S]*\}/u', $clean, $m)) {
        $p = json_decode($m[0], true);
        if (!empty($p['title'])) return ['title' => $p['title'], 'description' => $p['description'] ?? $fallbackDesc];
    }
    return ['title' => $fallbackTitle, 'description' => $fallbackDesc];
}

/** Automated (source=auto) timesheet entries are capped at this many minutes, regardless of
 *  how long the underlying interaction's wall-clock duration actually was — a chat/call can sit
 *  open for 30-40+ minutes waiting on the customer or an AI response with the agent not actively
 *  working it the whole time, so raw elapsed duration overstates real effort for payroll/timesheet
 *  purposes. The full, uncapped duration is still stored on chat_sessions.duration_sec/call_logs
 *  and used as-is for KPIs/reporting (avg handle time etc.) — only the logged timesheet hours cap. */
const AUTO_TIMESHEET_MAX_MINUTES = 20;

/**
 * Auto-log a completed interaction (chat / whatsapp / call) as time + a touchpoint.
 * Creates a draft timesheet entry (source=auto) with the duration AND an activity record,
 * plus a lead_followup for the lead timeline. Feeds the My-Timesheet / activity screens.
 */
function auto_log_activity(PDO $db, int $agentId, string $type, string $desc, ?int $leadId = null, int $durationSec = 0): void
{
    if ($agentId <= 0) return;
    $tsType = in_array($type, ['chat','whatsapp','call'], true) ? $type : 'other';
    $mins   = min(AUTO_TIMESHEET_MAX_MINUTES, max(1, (int)round($durationSec / 60)));   // at least 1, capped at 20
    $hours  = round($mins / 60, 2);
    try {
        $db->prepare("INSERT INTO timesheets (employee_id,entry_date,hours,activity_type,related_type,related_id,description,source,status)
                      VALUES (?,CURDATE(),?,?,?,?,?, 'auto','draft')")
           ->execute([$agentId, $hours, $tsType, $leadId ? 'lead' : 'none', $leadId, substr($desc, 0, 500)]);
        if ($leadId) {   // activities.related_type has no 'none' — only log the touchpoint when tied to a lead
            $db->prepare("INSERT INTO activities (activity_type,subject,related_type,related_id,owner_id,status,duration_minutes,created_by)
                          VALUES (?,?, 'lead', ?, ?, 'completed', ?, ?)")
               ->execute([$type, substr($desc, 0, 200), $leadId, $agentId, $mins, $agentId]);
        }
        if ($leadId) {
            $db->prepare("INSERT INTO lead_followups (lead_id,channel,direction,outcome,feedback,created_by)
                          VALUES (?,?, 'outbound','connected',?,?)")
               ->execute([$leadId, $type, substr($desc, 0, 400), $agentId]);
            $db->prepare("UPDATE leads SET last_contacted_at=NOW() WHERE id=?")->execute([$leadId]);
        }
    } catch (Throwable $e) { error_log('auto_log_activity: ' . $e->getMessage()); }
}

/** Lead sales-status changed -> next action task for the owner. */
function on_lead_status_changed(PDO $db, int $leadId, string $status): void
{
    try {
        $s = $db->prepare("SELECT id,first_name,last_name,company,owner_id FROM leads WHERE id=?");
        $s->execute([$leadId]);
        $l = $s->fetch();
        if (!$l || empty($l['owner_id'])) return;
        $who = trim($l['company'] ?: ($l['first_name'] . ' ' . $l['last_name']));
        $owner = (int)$l['owner_id'];
        if ($status === 'qualified') {
            $ai = ai_task_phrase("Sales lead '$who' was just marked QUALIFIED — ready for a proposal.", "Prepare & send proposal to $who");
            create_system_task($db, $owner, $ai['title'], 'follow_up', 'lead', $leadId, 'high', $ai['description'], 2);
        } elseif ($status === 'contacted') {
            $ai = ai_task_phrase("Sales lead '$who' was just contacted — needs a follow-up call.", "Follow up with $who");
            create_system_task($db, $owner, $ai['title'], 'callback', 'lead', $leadId, 'medium', $ai['description'], 3);
        }
    } catch (Throwable $e) {}
}

/** ITSM ticket update -> "respond" task for the merchant owner (auto-closes when resolved). */
function on_ticket_update(PDO $db, array $ticket): void
{
    if (empty($ticket['merchant_id'])) return;
    $s = $db->prepare("SELECT owner_id,legal_name FROM merchants WHERE id=?");
    $s->execute([$ticket['merchant_id']]);
    $m = $s->fetch();
    if (!$m || empty($m['owner_id'])) return;
    $status = strtolower($ticket['status'] ?? '');
    $owner = (int)$m['owner_id'];
    if (($ticket['waiting_on'] ?? '') === 'us' || in_array($status, ['open', 'in_progress', 'waiting_customer'], true)) {
        $fb = "Respond to ticket {$ticket['ticket_ref']}: " . ($ticket['subject'] ?? '');
        $ai = ai_task_phrase("ITSM ticket {$ticket['ticket_ref']} '" . ($ticket['subject'] ?? '') . "' for merchant '{$m['legal_name']}' is waiting on our team. Note: " . ($ticket['last_update_note'] ?? '—'), $fb, $ticket['last_update_note'] ?? null);
        create_system_task($db, $owner, $ai['title'], 'review', 'merchant', (int)$ticket['merchant_id'],
            (($ticket['priority'] ?? '') === 'high' ? 'urgent' : 'high'), $ai['description'], 1);
    }
    if (in_array($status, ['closed', 'resolved'], true)) {
        $db->prepare("UPDATE tasks SET status='done', completed_at=NOW()
                      WHERE related_type='merchant' AND related_id=? AND task_type='review' AND status IN('open','in_progress')")
           ->execute([$ticket['merchant_id']]);
    }
}

/** PIMS merchant confirmation -> KYC / rejection / activation task for the owner. */
function on_merchant_confirm(PDO $db, array $merchant): void
{
    $owner = (int)($merchant['owner_id'] ?? 0);
    if (!$owner) return;
    $name = $merchant['legal_name'] ?? ($merchant['merchant_code'] ?? 'merchant');
    $kyc = $merchant['kyc_status'] ?? '';
    $stage = $merchant['onboarding_stage'] ?? '';
    $id = (int)($merchant['id'] ?? 0);
    if (in_array($kyc, ['pending', 'partial'], true)) {
        $ai = ai_task_phrase("PIMS reports merchant '$name' has KYC still pending — documents needed to activate.", "Complete KYC for $name");
        create_system_task($db, $owner, $ai['title'], 'kyc_validation', 'merchant', $id, 'high', $ai['description'], 2);
    }
    if ($stage === 'rejected') {
        $ai = ai_task_phrase("PIMS REJECTED merchant '$name' onboarding — needs review and resubmission.", "Merchant $name rejected — review & resubmit");
        create_system_task($db, $owner, $ai['title'], 'review', 'merchant', $id, 'urgent', $ai['description']);
    }
    if ($stage === 'activated') {
        $ai = ai_task_phrase("Merchant '$name' just went LIVE (activated) — do a welcome/onboarding call.", "Welcome call for activated merchant $name");
        create_system_task($db, $owner, $ai['title'], 'onboarding', 'merchant', $id, 'medium', $ai['description'], 1);
    }
}
