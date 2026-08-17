<?php
/**
 * Daily auto-email alert sweep. Run DAILY (e.g. early evening so timesheet
 * nudges land before end of day):
 *   0 17 * * *  php /path/payg/cron/run-daily-alerts.php
 *
 * Sends (per alert_settings, each with the manager looped in):
 *   - "timesheet not filled today" nudges (workdays)
 *   - "sales target attainment below threshold" (Mondays, current quarter)
 * SLA/TAT breach emails are NOT sent here — they fire from the escalation
 * sweep itself (cron/run-escalations.php) on every hop.
 */
require_once __DIR__ . '/_cron_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../api/sales/_alert_engine.php';

$db = getDB();
if (!$db) { fwrite(STDERR, "DB unavailable\n"); if (PHP_SAPI !== 'cli') { header('Content-Type: application/json'); echo json_encode(['success'=>false,'error'=>'DB unavailable']); } exit(1); }

$stats = run_daily_alerts($db);

$out = ['success' => true, 'ran_at' => date('c'), 'stats' => $stats];
if (PHP_SAPI === 'cli') { echo json_encode($out, JSON_PRETTY_PRINT) . "\n"; }
else { header('Content-Type: application/json'); echo json_encode($out); }
