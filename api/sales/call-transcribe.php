<?php
/**
 * On-request call-recording transcription. Admin/manager can transcribe any call; a sales_exec
 * only their own. Requires a recording to already exist (call-status.php / the recording refire
 * cron populate call_logs.recording_url) and Settings → Call Transcription to be enabled.
 *   POST call-transcribe.php { call_id }
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_transcription_engine.php';
require_login();

// Voice/call transcription is disabled org-wide (product decision) — the "Transcribe" button
// has been removed from sales-conversations.php, but hard-reject here too so it isn't reachable
// by calling this endpoint directly. (transcription_settings.enabled is already 0, which
// crm_transcribe_recording() checks — this is a second, unconditional gate.)
fail('Call transcription is currently disabled', 503);

if (session_status() === PHP_SESSION_ACTIVE) session_write_close();  // external call, release lock
$db = db();
$me = current_employee_id();

$id = (int)(body()['call_id'] ?? 0);
if (!$id) fail('call_id required');

$s = $db->prepare("SELECT * FROM call_logs WHERE id=?"); $s->execute([$id]);
$call = $s->fetch(PDO::FETCH_ASSOC);
if (!$call) fail('Call not found', 404);
if (!can_manage_all_records() && (int)$call['agent_id'] !== $me) fail('Not authorized to transcribe this call', 403);
if (empty($call['recording_url'])) fail('No recording available for this call yet', 400);

$tp = $db->query("SELECT * FROM telephony_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC);
[$okT, $transcript, $err] = crm_transcribe_recording($db, $call['recording_url'], $tp ?: null);

if (!$okT) {
    $db->prepare("UPDATE call_logs SET transcript_status='failed' WHERE id=?")->execute([$id]);
    fail($err, 502);
}

$db->prepare("UPDATE call_logs SET transcript=?, transcript_status='done', transcribed_at=NOW() WHERE id=?")
   ->execute([$transcript, $id]);
ok(['transcript' => $transcript]);
