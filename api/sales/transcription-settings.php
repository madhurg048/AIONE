<?php
/**
 * Call-recording transcription settings (admin/IT). Same physical STT server as timesheet
 * voice dictation (voice-transcribe.php) but configured independently, so it can be enabled
 * on request without touching that flow.
 *   GET  transcription-settings.php   -> config (api_key masked)
 *   POST transcription-settings.php   -> save
 */
require_once __DIR__ . '/_bootstrap.php';
require_login();
if (($_SESSION['user']['user_type'] ?? '') !== 'admin' && current_role() !== 'it') fail('Admin or IT only', 403);
$db = db();

if (method() === 'POST') {
    $b = body();
    $cur = $db->query("SELECT api_key FROM transcription_settings WHERE id=1")->fetchColumn();
    $key = (isset($b['api_key']) && $b['api_key'] !== '' && $b['api_key'] !== '********') ? $b['api_key'] : $cur;
    $db->prepare("UPDATE transcription_settings SET enabled=?, api_url=?, api_key=?, lang=? WHERE id=1")
       ->execute([
           !empty($b['enabled']) ? 1 : 0, trim($b['api_url'] ?? ''), $key, trim($b['lang'] ?? '') ?: 'en',
       ]);
    ok([], ['message' => 'Transcription settings saved']);
}

$c = $db->query("SELECT id,enabled,api_url,lang,(api_key IS NOT NULL AND api_key<>'') AS has_key
                 FROM transcription_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC) ?: [];
ok($c);
