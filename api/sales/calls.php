<?php
/**
 * Calls workspace (Service only) — the agent-facing softphone screen's data API.
 * Distinct from conversations.php (admin/manager read-only monitor) and call.php (the older
 * Sales lead-panel click-to-call trigger, still used as-is by Sales).
 *
 * Call lifecycle: ringing (inbound, unclaimed) -> claimed (an agent accepted) -> active
 * (connected) -> completed | missed | failed. Outbound dials skip straight to initiated -> active.
 *
 *   GET   calls.php?action=state                 -> presence roster, unclaimed ringing calls,
 *                                                     my active call, recent call log
 *   POST  calls.php?action=claim      { call_id }
 *   POST  calls.php?action=dial       { to_number, lead_id?, merchant_id? }
 *   POST  calls.php?action=manual-log { channel, direction, customer_name?, phone?, notes?, disposition? }
 *   PATCH calls.php?action=notes      { call_id, customer_name?, notes?, lead_id?, merchant_id?, disposition? }
 *   POST  calls.php?action=hangup     { call_id }
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_telephony.php';
require_once __DIR__ . '/_automation_engine.php';
require_login();
if (!in_array(current_role(), ['admin', 'service_manager', 'service_exec'], true)) fail('Service module only', 403);
$db = db();
$me = current_employee_id();

/** Upserts this agent's presence row and returns it. */
function calls_touch_presence(PDO $db, int $agentId, string $status, ?int $callId = null): void
{
    $db->prepare("INSERT INTO agent_call_presence (agent_id, status, current_call_id)
                  VALUES (?,?,?)
                  ON DUPLICATE KEY UPDATE status=VALUES(status), current_call_id=VALUES(current_call_id)")
       ->execute([$agentId, $status, $callId]);
}

$action = $_GET['action'] ?? '';

if (method() === 'GET' && $action === 'state') {
    // A ringing call nobody claims within 60s is swept to 'missed' here (same inline-on-every-poll
    // pattern as chat_sweep_missed()) so it doesn't clutter every agent's banner forever and shows
    // up correctly in the call log/KPIs.
    call_sweep_missed($db);
    // Make sure I have a presence row (defaults to available) without clobbering an existing on_call state.
    $db->prepare("INSERT IGNORE INTO agent_call_presence (agent_id, status) VALUES (?, 'available')")->execute([$me]);

    $roster = $db->query("
        SELECT e.id AS agent_id, e.full_name, p.status, p.current_call_id, p.updated_at,
               IF(cl.direction='inbound', cl.from_number, cl.to_number) AS with_number,
               cl.customer_name AS with_customer
        FROM sales_users su
        JOIN employees e ON e.id = su.employee_id
        LEFT JOIN agent_call_presence p ON p.agent_id = e.id
        LEFT JOIN call_logs cl ON cl.id = p.current_call_id
        WHERE su.is_active = 1 AND su.role IN ('service_exec','service_manager')
        ORDER BY e.full_name")->fetchAll(PDO::FETCH_ASSOC);

    $ringing = $db->query("
        SELECT id, to_number, from_number, direction, started_at, lead_id
        FROM call_logs
        WHERE status = 'ringing' AND claimed_by IS NULL AND vertical = 'service'
        ORDER BY started_at ASC")->fetchAll(PDO::FETCH_ASSOC);

    $activeStmt = $db->prepare("
        SELECT * FROM call_logs
        WHERE claimed_by = ? AND status IN ('claimed','active','initiated')
        ORDER BY id DESC LIMIT 1");
    $activeStmt->execute([$me]);
    $myActive = $activeStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    // Team-wide within the vertical, same convention as call.php/leads.php ("any exec can see
    // any lead/call in their vertical, not just their own") — a shared team queue is the whole
    // point of missed calls needing a remark from whoever picks them up next, not just the owner.
    $recent = $db->query("
        SELECT cl.*, l.first_name, l.last_name
        FROM call_logs cl LEFT JOIN leads l ON l.id = cl.lead_id
        WHERE cl.vertical = 'service'
        ORDER BY cl.id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);

    $cfg = $db->query("SELECT * FROM telephony_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    ok([
        'roster'        => $roster,
        'ringing'       => $ringing,
        'my_active'     => $myActive,
        'recent'        => $recent,
        'webrtc_ready'  => $cfg ? crm_webrtc_ready($cfg) : false,
        'toll_free'     => $cfg['toll_free_number'] ?? null,
    ]);
}

if (method() === 'POST' && $action === 'claim') {
    $b = body();
    $callId = (int)($b['call_id'] ?? 0);
    if (!$callId) fail('call_id required');
    $upd = $db->prepare("UPDATE call_logs SET claimed_by=?, claimed_at=NOW(), agent_id=?, status='claimed'
                          WHERE id=? AND claimed_by IS NULL AND status='ringing'");
    $upd->execute([$me, $me, $callId]);
    if ($upd->rowCount() === 0) fail('Already claimed by another agent', 409);
    calls_touch_presence($db, $me, 'on_call', $callId);
    $row = $db->prepare("SELECT * FROM call_logs WHERE id=?"); $row->execute([$callId]);
    ok($row->fetch(PDO::FETCH_ASSOC), ['message' => 'Call claimed']);
}

if (method() === 'POST' && $action === 'dial') {
    $b = body();
    $to = crm_norm_number((string)($b['to_number'] ?? ''));
    if ($to === '') fail('to_number required');
    $leadId = !empty($b['lead_id']) ? (int)$b['lead_id'] : null;
    $merchantId = !empty($b['merchant_id']) ? (int)$b['merchant_id'] : null;

    $cfg = $db->query("SELECT * FROM telephony_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    $from = $cfg['caller_id'] ?? '';

    $db->prepare("INSERT INTO call_logs (lead_id, merchant_id, agent_id, claimed_by, claimed_at, to_number,
                    from_number, status, direction, vertical, source, channel)
                  VALUES (?,?,?,?,NOW(),?,?, 'initiated','outbound','service','exotel','telephony')")
       ->execute([$leadId, $merchantId, $me, $me, $to, $from]);
    $callId = (int)$db->lastInsertId();
    calls_touch_presence($db, $me, 'on_call', $callId);

    // Prefer in-browser WebRTC calling; fall back to the existing phone-bridge dial.
    if ($cfg && crm_webrtc_ready($cfg)) {
        $token = crm_webrtc_token($cfg, 'agent-' . $me);
        if ($token) {
            ok(['mode' => 'webrtc', 'call_id' => $callId, 'token' => $token, 'to' => $to], ['message' => 'Ready to dial in-browser']);
        }
    }
    if ($cfg && $cfg['enabled'] && $cfg['api_key'] && $cfg['api_token'] && $cfg['account_sid'] && $cfg['caller_id']) {
        $statusCb = ($_SERVER['REQUEST_SCHEME'] ?? 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . dirname($_SERVER['SCRIPT_NAME']) . '/../integrations/call-status.php';
        $agentNumber = preg_replace('/\D+/', '', $b['agent_number'] ?? '');
        if ($agentNumber === '') {
            $db->prepare("UPDATE call_logs SET status='failed' WHERE id=?")->execute([$callId]);
            calls_touch_presence($db, $me, 'available', null);
            fail('Your callback number is required for phone-bridge dialing (in-browser calling is not configured yet)');
        }
        [$okCall, $providerCallId, $err] = crm_place_call($cfg, crm_norm_number($agentNumber), $to, $statusCb);
        $db->prepare("UPDATE call_logs SET provider_call_id=?, status=? WHERE id=?")
           ->execute([$providerCallId, $okCall ? 'active' : 'failed', $callId]);
        if (!$okCall) { calls_touch_presence($db, $me, 'available', null); fail('Call failed: ' . $err, 502); }
        ok(['mode' => 'phone-bridge', 'call_id' => $callId], ['message' => 'Connecting your phone to the customer…']);
    }

    // Nothing configured at all — device dialer fallback (dev / pre-setup state).
    ok(['mode' => 'device', 'call_id' => $callId, 'dial' => '+' . $to],
       ['message' => 'Telephony not yet configured — dialing from this device']);
}

if (method() === 'POST' && $action === 'manual-log') {
    $b = body();
    $channel = in_array($b['channel'] ?? '', ['mobile', 'whatsapp', 'other', 'telephony'], true) ? $b['channel'] : 'other';
    $direction = in_array($b['direction'] ?? '', ['inbound', 'outbound', 'missed'], true) ? $b['direction'] : 'inbound';
    $db->prepare("INSERT INTO call_logs (agent_id, claimed_by, claimed_at, to_number, customer_name, status, direction,
                    duration_sec, notes, disposition, vertical, source, channel, started_at, ended_at)
                  VALUES (?,?,NOW(),?,?, 'completed', ?, ?, ?, ?, 'service','manual',?, NOW(), NOW())")
       ->execute([
            $me, $me, trim($b['phone'] ?? ''), trim($b['customer_name'] ?? '') ?: null, $direction,
            max(0, (int)($b['duration_sec'] ?? 0)), trim($b['notes'] ?? '') ?: null, trim($b['disposition'] ?? '') ?: null, $channel,
       ]);
    ok(['id' => (int)$db->lastInsertId()], ['message' => 'Call logged']);
}

if ((method() === 'PATCH') && $action === 'notes') {
    $b = body();
    $callId = (int)($b['call_id'] ?? 0);
    if (!$callId) fail('call_id required');
    $row = $db->prepare("SELECT * FROM call_logs WHERE id=?"); $row->execute([$callId]);
    $call = $row->fetch(PDO::FETCH_ASSOC);
    if (!$call) fail('Call not found', 404);
    assert_can_see_vertical($call['vertical']);
    // Live in-call fields (customer_name/notes overwrite/disposition/lead/merchant link) are only
    // for whoever's actually on that call, or a manager. A "remark" is different — team-wide within
    // the vertical, same as leads/calls are team-visible elsewhere in this app (call.php's own
    // comment: "any exec can call any lead in their vertical, not just their own") — a missed call
    // is a shared queue item and whoever follows it up next needs to be able to annotate it.
    $unclaimed = $call['claimed_by'] === null && $call['agent_id'] === null;
    $ownCall = can_manage_all_records() || (int)$call['claimed_by'] === $me || (int)$call['agent_id'] === $me || $unclaimed;
    if (!$ownCall && (array_key_exists('customer_name', $b) || array_key_exists('notes', $b) || array_key_exists('disposition', $b)
        || array_key_exists('lead_id', $b) || array_key_exists('merchant_id', $b))) {
        fail('Not authorized to edit this call', 403);
    }
    $set = []; $args = [];
    if ($ownCall) {
        foreach (['customer_name' => 'customer_name', 'notes' => 'notes', 'disposition' => 'disposition'] as $k => $col) {
            if (array_key_exists($k, $b)) { $set[] = "$col=?"; $args[] = trim((string)$b[$k]) ?: null; }
        }
    }
    // Post-call "remark" — appended, attributed, timestamped (same convention as the missed-chat
    // remark feature), so multiple agents/managers can add follow-up context to one call over time
    // without clobbering each other's notes. Distinct from the live in-call 'notes' field above,
    // which is a single freeform draft overwritten as the agent types during the call itself.
    // Team-wide: any Service agent may add one, not just whoever claimed the call.
    if (!empty(trim((string)($b['remark'] ?? '')))) {
        $myName = $db->prepare("SELECT full_name FROM employees WHERE id=?"); $myName->execute([$me]);
        $stamp = '[' . date('Y-m-d H:i') . ' · ' . ($myName->fetchColumn() ?: 'Agent') . '] ' . trim((string)$b['remark']);
        $existing = trim((string)($call['notes'] ?? ''));
        $set[] = 'notes=?';
        $args[] = $existing !== '' ? ($existing . "\n" . $stamp) : $stamp;
    }
    if ($ownCall) {
        if (array_key_exists('lead_id', $b)) { $set[] = 'lead_id=?'; $args[] = $b['lead_id'] ? (int)$b['lead_id'] : null; }
        if (array_key_exists('merchant_id', $b)) { $set[] = 'merchant_id=?'; $args[] = $b['merchant_id'] ? (int)$b['merchant_id'] : null; }
    }
    if (!$set) fail('Nothing to update');
    $args[] = $callId;
    $db->prepare("UPDATE call_logs SET " . implode(',', $set) . " WHERE id=?")->execute($args);
    ok(['id' => $callId], ['message' => 'Saved']);
}

if (method() === 'POST' && $action === 'hangup') {
    $b = body();
    $callId = (int)($b['call_id'] ?? 0);
    if (!$callId) fail('call_id required');
    $row = $db->prepare("SELECT * FROM call_logs WHERE id=?"); $row->execute([$callId]);
    $call = $row->fetch(PDO::FETCH_ASSOC);
    if (!$call) fail('Call not found', 404);
    if (!can_manage_all_records() && (int)$call['claimed_by'] !== $me && (int)$call['agent_id'] !== $me) {
        fail('Not authorized to end this call', 403);
    }
    if (!in_array($call['status'], ['completed', 'failed', 'missed'], true)) {
        $db->prepare("UPDATE call_logs SET status='completed', ended_at=NOW() WHERE id=?")->execute([$callId]);
    }
    calls_touch_presence($db, (int)($call['claimed_by'] ?: $me), 'available', null);
    ok(['id' => $callId], ['message' => 'Call ended']);
}

fail('Unsupported', 405);
