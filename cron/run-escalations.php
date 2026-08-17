<?php
/**
 * SLA / escalation sweep. Run from cron every 10-30 min, e.g.:
 *   * / 15 * * * *  php /path/payg/cron/run-escalations.php
 * Also callable from the Escalations page ("Run sweep now", admin only).
 *
 * Backfills SLA stamps, escalates every open task past its TAT one level up
 * its escalation chain (manager -> manager's manager -> ... -> admin), and
 * closes escalation log rows whose task has been completed.
 */
require_once __DIR__ . '/_cron_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../api/sales/_escalation_engine.php';

$db = getDB();
if (!$db) { fwrite(STDERR, "DB unavailable\n"); if (PHP_SAPI !== 'cli') { header('Content-Type: application/json'); echo json_encode(['success'=>false,'error'=>'DB unavailable']); } exit(1); }

$stats = run_escalation_sweep($db);

$out = ['success' => true, 'ran_at' => date('c'), 'stats' => $stats];
if (PHP_SAPI === 'cli') { echo json_encode($out, JSON_PRETTY_PRINT) . "\n"; }
else { header('Content-Type: application/json'); echo json_encode($out); }
