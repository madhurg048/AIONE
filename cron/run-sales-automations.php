<?php
/**
 * Time-based automation sweep. Run from cron every 15-60 min, e.g.:
 *   * / 30 * * * *  php /path/payg/cron/run-sales-automations.php
 * Also callable via api/sales/automations.php?run_timebased=1 (manual trigger).
 *
 * For each active 'time_based' rule it selects records of the rule's entity and
 * runs the standard engine (which re-checks conditions like older_than_days).
 */
require_once __DIR__ . '/_cron_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../api/sales/_automation_engine.php';

$db = getDB();
if (!$db) { fwrite(STDERR, "DB unavailable\n"); if (PHP_SAPI !== 'cli') { header('Content-Type: application/json'); echo json_encode(['success'=>false,'error'=>'DB unavailable']); } exit(1); }

$tables = ['lead' => 'leads', 'deal' => 'deals', 'merchant' => 'merchants'];
$report = [];

$rules = $db->query("SELECT * FROM automation_rules WHERE trigger_event='time_based' AND is_active=1")->fetchAll();
foreach ($rules as $rule) {
    $table = $tables[$rule['entity']] ?? null;
    if (!$table) continue;
    // Pull a bounded working set; the engine re-evaluates conditions per record.
    $rows = $db->query("SELECT * FROM `$table` ORDER BY updated_at ASC LIMIT 500")->fetchAll();
    $fired = 0;
    foreach ($rows as $row) {
        $conds = json_decode($rule['conditions'] ?? '[]', true) ?: [];
        if (!automation_conditions_pass($conds, $rule['match_type'], $row)) continue;
        [$done] = automation_execute($db, $rule, $row);
        $db->prepare("INSERT INTO automation_logs (rule_id,entity,entity_id,actions_run,status,message)
                      VALUES (?,?,?,?, 'success', ?)")
           ->execute([$rule['id'], $rule['entity'], $row['id'], implode('; ', $done), 'time_based: ' . $rule['name']]);
        $fired++;
    }
    if ($fired) {
        $db->prepare("UPDATE automation_rules SET run_count=run_count+?, last_run_at=NOW() WHERE id=?")
           ->execute([$fired, $rule['id']]);
    }
    $report[$rule['name']] = $fired;
}

$out = ['success' => true, 'ran_at' => date('c'), 'fired' => $report];
if (PHP_SAPI === 'cli') { echo json_encode($out, JSON_PRETTY_PRINT) . "\n"; }
else { header('Content-Type: application/json'); echo json_encode($out); }
