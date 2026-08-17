<?php
/**
 * Lead follow-up process.
 *   GET  followups.php?lead_id=5     -> follow-up history for a lead (+ the lead row)
 *   GET  followups.php?due=1[&mine=1]-> leads due for follow-up (queue)
 *   POST followups.php               -> log a follow-up + optionally set final status
 *     { lead_id, channel, direction?, outcome, feedback, next_followup_at?, update_status?, lost_reason? }
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_task_events.php';
require_once __DIR__ . '/_automation_engine.php';
require_login();
require_module('leads');
$db = db();
$me = current_employee_id();

if (method() === 'GET' && isset($_GET['due'])) {
    $args = []; $w = "WHERE l.next_followup_at IS NOT NULL AND l.next_followup_at <= NOW()+INTERVAL 0 DAY AND l.is_converted=0";
    $w .= " AND " . owner_scope('l.owner_id', $args);
    if (!empty($_GET['mine'])) { $w .= " AND l.owner_id=?"; $args[] = $me; }
    $s = $db->prepare("SELECT l.id,l.first_name,l.last_name,l.company,l.phone,l.email,l.lead_status,
                              l.next_followup_at,l.followup_count, e.full_name AS owner_name
                       FROM leads l LEFT JOIN employees e ON e.id=l.owner_id
                       $w ORDER BY l.next_followup_at ASC LIMIT 200");
    $s->execute($args);
    ok($s->fetchAll());
}

if (method() === 'GET') {
    $lid = (int)($_GET['lead_id'] ?? 0);
    if (!$lid) fail('lead_id required');
    $lead = $db->prepare("SELECT l.*, e.full_name AS owner_name FROM leads l LEFT JOIN employees e ON e.id=l.owner_id WHERE l.id=?");
    $lead->execute([$lid]);
    $l = $lead->fetch();
    if (!$l) fail('Lead not found', 404);
    // Team-wide within a vertical, same as leads.php's list/single fetch — owner_id is who
    // it's assigned to, not a visibility restriction, so any exec in this lead's vertical can
    // open its follow-up history (and act on it from a Leads-page icon), not just its owner.
    assert_can_see_vertical($l['vertical']);

    // Same stale-reassignment warning as leads.php (see lead_stale_threshold()) — this is the
    // screen a rep actually logs a follow-up from, so it's the most useful place to tell them
    // "log something now or this moves to someone else."
    $st = lead_stale_threshold($db);
    $l['stale_idle_days'] = null;
    $l['stale_days_left'] = null;
    if ($st['active'] && in_array($l['lead_status'], $st['statuses'], true)) {
        $idle = (int)((strtotime('now') - strtotime($l['updated_at'])) / 86400);
        $l['stale_idle_days'] = $idle;
        $l['stale_days_left'] = max(0, $st['days'] - $idle);
    }

    $h = $db->prepare("SELECT f.*, e.full_name AS by_name FROM lead_followups f
                       LEFT JOIN employees e ON e.id=f.created_by WHERE f.lead_id=? ORDER BY f.created_at DESC");
    $h->execute([$lid]);
    ok(['lead' => $l, 'followups' => $h->fetchAll()]);
}

if (method() === 'POST') {
    $b = body();
    $lid = (int)($b['lead_id'] ?? 0);
    if (!$lid) fail('lead_id required');
    if (empty($b['outcome'])) fail('outcome required');
    $own = $db->prepare("SELECT vertical FROM leads WHERE id=?"); $own->execute([$lid]);
    $vertical = $own->fetchColumn();
    if ($vertical === false) fail('Lead not found', 404);
    // Team-wide within a vertical — any exec can log a follow-up on any lead in their vertical,
    // matching leads.php's visibility model (see GET above).
    assert_can_see_vertical($vertical);

    $db->prepare("INSERT INTO lead_followups (lead_id,channel,direction,outcome,feedback,next_followup_at,created_by)
                  VALUES (?,?,?,?,?,?,?)")
       ->execute([$lid, $b['channel'] ?? 'call', $b['direction'] ?? 'outbound', $b['outcome'],
                  $b['feedback'] ?? null, $b['next_followup_at'] ?: null, $me ?: null]);

    // roll up onto the lead: contacted, count, next follow-up, and final status/disposition
    $sets = ["last_contacted_at=NOW()", "followup_count=followup_count+1", "next_followup_at=?"];
    $args = [$b['next_followup_at'] ?: null];
    // explicit status override, else derive a sensible one from the outcome
    $status = $b['update_status'] ?? null;
    if (!$status) {
        $map = ['interested'=>'qualified','not_interested'=>'unqualified','converted'=>'converted',
                'callback_requested'=>'contacted','connected'=>'contacted'];
        $status = $map[$b['outcome']] ?? null;
    }
    if ($status) { $sets[] = "lead_status=?"; $args[] = $status; }
    if (!empty($b['lost_reason'])) { $sets[] = "lost_reason=?"; $args[] = $b['lost_reason']; }
    $args[] = $lid;
    $db->prepare("UPDATE leads SET " . implode(',', $sets) . " WHERE id=?")->execute($args);

    // system event -> auto task in the owner's My Tasks panel
    if ($status) on_lead_status_changed($db, $lid, $status);

    ok(['lead_id' => $lid, 'new_status' => $status]);
}

fail('Unsupported method', 405);
