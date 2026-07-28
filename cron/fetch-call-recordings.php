<?php
/**
 * Recording refire sweep. Exotel doesn't always have RecordingUrl ready the instant
 * call-status.php's webhook fires, so this re-checks recently-ended calls that are
 * still missing one. Run from cron every ~10 min.
 * Also callable directly in a browser/CLI for a manual trigger.
 
 * Window: only calls that connected (duration_sec>0), ended 5-60 min ago, with no
 * recording_url yet -- bounds retries so we don't hammer Exotel forever on calls that
 * will never get one (e.g. recording disabled account-side).
 */
// crontab, run every 10 min: */10 * * * *  php /path/payg/cron/fetch-call-recordings.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../api/sales/_telephony.php';

$db = getDB();
if (!$db) { fwrite(STDERR, "DB unavailable\n"); if (PHP_SAPI !== 'cli') { header('Content-Type: application/json'); echo json_encode(['success'=>false,'error'=>'DB unavailable']); } exit(1); }

$cfg = $db->query("SELECT * FROM telephony_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$checked = 0; $found = 0;

if ($cfg && $cfg['enabled'] && ($cfg['provider'] ?: 'exotel') === 'exotel' && $cfg['api_key'] && $cfg['api_token'] && $cfg['account_sid']) {
    $rows = $db->query("SELECT id, provider_call_id FROM call_logs
                         WHERE recording_url IS NULL AND duration_sec > 0 AND provider_call_id IS NOT NULL
                           AND started_at BETWEEN NOW() - INTERVAL 60 MINUTE AND NOW() - INTERVAL 5 MINUTE")
                ->fetchAll();
    foreach ($rows as $row) {
        $checked++;
        $url = crm_fetch_recording_url($cfg, $row['provider_call_id']);
        if ($url) {
            $db->prepare("UPDATE call_logs SET recording_url=? WHERE id=?")->execute([$url, $row['id']]);
            $found++;
        }
    }
}

$out = ['success' => true, 'ran_at' => date('c'), 'checked' => $checked, 'found' => $found];
if (PHP_SAPI === 'cli') { echo json_encode($out, JSON_PRETTY_PRINT) . "\n"; }
else { header('Content-Type: application/json'); echo json_encode($out); }
