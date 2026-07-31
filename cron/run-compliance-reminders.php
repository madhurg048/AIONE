<?php
/**
 * Compliance expiry-reminder sweep. Run DAILY from cron, e.g.:
 *   0 8 * * *  php /path/payg/cron/run-compliance-reminders.php
 * Also callable from the Compliance page ("Run reminders now").
 *
 * Notifies stakeholders + owner group of every policy/certification inside
 * (or past) its reminder window, and opens a renewal task for the owner —
 * which then rides the normal SLA/escalation engine if ignored.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../api/sales/_escalation_engine.php';
require_once __DIR__ . '/../api/sales/_compliance_engine.php';

$db = getDB();
if (!$db) { fwrite(STDERR, "DB unavailable\n"); if (PHP_SAPI !== 'cli') { header('Content-Type: application/json'); echo json_encode(['success'=>false,'error'=>'DB unavailable']); } exit(1); }

$stats = run_compliance_reminders($db);

$out = ['success' => true, 'ran_at' => date('c'), 'stats' => $stats];
if (PHP_SAPI === 'cli') { echo json_encode($out, JSON_PRETTY_PRINT) . "\n"; }
else { header('Content-Type: application/json'); echo json_encode($out); }
