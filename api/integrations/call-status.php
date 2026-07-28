<?php
/**
 * Provider status callback for click-to-call (public). The telephony provider POSTs the final
 * call status + duration here; we update call_logs and auto-log the agent's call time.
 *   POST call-status.php  (Exotel-style: CallSid, Status/DialCallStatus, DialCallDuration)
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../sales/_task_events.php';
require_once __DIR__ . '/../sales/_telephony.php';
header('Content-Type: application/json');

$db = getDB();
if (!$db) { http_response_code(500); echo '{"ok":false}'; exit; }

$in = $_POST ?: json_decode(file_get_contents('php://input'), true) ?: [];
$sid = $in['CallSid'] ?? $in['call_id'] ?? '';
$status = strtolower($in['Status'] ?? $in['DialCallStatus'] ?? $in['status'] ?? '');
$dur = (int)($in['DialCallDuration'] ?? $in['ConversationDuration'] ?? $in['duration'] ?? 0);
if ($sid === '') { echo '{"ok":false,"error":"no call id"}'; exit; }

// Optional shared secret: if TELEPHONY_WEBHOOK_SECRET is configured, the provider callback
// URL must carry ?token=<secret>. Blocks arbitrary spoofed status/duration posts.
@require_once __DIR__ . '/../../config/config.php';
if (defined('TELEPHONY_WEBHOOK_SECRET') && TELEPHONY_WEBHOOK_SECRET !== '') {
    $tok = $_GET['token'] ?? $in['token'] ?? '';
    if (!hash_equals(TELEPHONY_WEBHOOK_SECRET, (string)$tok)) { http_response_code(403); echo '{"ok":false,"error":"bad token"}'; exit; }
}

$s = $db->prepare("SELECT * FROM call_logs WHERE provider_call_id=? ORDER BY id DESC LIMIT 1");
$s->execute([$sid]);
$call = $s->fetch(PDO::FETCH_ASSOC);
if (!$call) { echo '{"ok":false,"error":"unknown call"}'; exit; }

// Replay/idempotency guard: only a call still in a non-terminal state may be finalised.
// Prevents re-posting a known CallSid to fabricate repeated billable time.
$already = in_array(strtolower($call['status'] ?? ''), ['completed','answered','failed','busy','no-answer'], true);
if ($already) { echo '{"ok":true,"note":"already finalised"}'; exit; }

$finalStatus = $status ?: 'completed';
$db->prepare("UPDATE call_logs SET status=?, duration_sec=?, ended_at=NOW() WHERE id=?")
   ->execute([$finalStatus, $dur, $call['id']]);

// Calls workspace (phase41): free the claiming agent's presence back to available once
// their call actually finalises. Non-fatal — the older Sales click-to-call rows have no
// claimed_by, so this simply no-ops for them.
if (!empty($call['claimed_by'])) {
    try {
        $db->prepare("UPDATE agent_call_presence SET status='available', current_call_id=NULL WHERE agent_id=? AND current_call_id=?")
           ->execute([(int)$call['claimed_by'], (int)$call['id']]);
    } catch (Throwable $e) { error_log('call-status presence release: ' . $e->getMessage()); }
}

// fetch + save the recording once the call has actually ended (best-effort, non-fatal)
if (in_array($status, ['completed', 'answered', 'no-answer', 'busy', 'failed', ''], true)) {
    try {
        $cfg = $db->query("SELECT * FROM telephony_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC);
        if ($cfg && $cfg['enabled'] && $cfg['api_key'] && $cfg['api_token'] && $cfg['account_sid']) {
            $recordingUrl = crm_fetch_recording_url($cfg, $sid);
            if ($recordingUrl) {
                $db->prepare("UPDATE call_logs SET recording_url=? WHERE id=?")->execute([$recordingUrl, $call['id']]);
            }
        }
    } catch (Throwable $e) { error_log('call-status recording fetch: ' . $e->getMessage()); }
}

// auto-log the call time once the call actually connected (only on this first finalisation)
if ($dur > 0 && in_array($status, ['completed', 'answered', ''], true)) {
    auto_log_activity($db, (int)$call['agent_id'], 'call',
        'Call with lead #' . $call['lead_id'] . ' (' . round($dur/60, 1) . ' min)',
        $call['lead_id'] ? (int)$call['lead_id'] : null, $dur);
}
echo '{"ok":true}';
