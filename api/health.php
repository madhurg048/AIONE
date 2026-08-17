<?php
/**
 * Deployment health probe.  GET /api/health.php
 *
 * Point Azure App Service → Monitoring → Health check at this path. It answers
 * 200 only when PHP is serving AND the database is reachable with the core
 * schema present, so a bad DB_HOST / firewall / wrong DB_NAME shows up here
 * instead of as a broken page an agent finds later.
 *
 * Deliberately leaks nothing: no host, user, password, version or row counts.
 * Detail is written to the server error log for the deployment team to read in
 * Log stream. 200 = healthy, 503 = not.
 */

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$checks = ['php' => true, 'database' => false, 'schema' => false];
$detail = [];

$db = getDB();
if ($db) {
    $checks['database'] = true;

    // A handful of tables the app cannot run without. Cheap: metadata only,
    // no row scans, so this stays fast enough for a frequent probe.
    $required = ['leads', 'crm_tickets', 'chat_sessions', 'employees', 'sales_users', 'role_access'];
    try {
        $in = implode(',', array_fill(0, count($required), '?'));
        $st = $db->prepare(
            "SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name IN ($in)"
        );
        $st->execute($required);
        $found = (int) $st->fetchColumn();
        $checks['schema'] = ($found === count($required));
        if (!$checks['schema']) {
            $detail[] = sprintf('schema incomplete: %d/%d core tables found', $found, count($required));
        }
    } catch (Throwable $e) {
        $detail[] = 'schema probe failed: ' . $e->getMessage();
    }
} else {
    $detail[] = 'database unreachable — check DB_HOST / DB_PORT / DB_USER / DB_PASS / DB_SSL and the server firewall';
}

$healthy = !in_array(false, $checks, true);

if (!$healthy) {
    error_log('health check FAILED: ' . implode(' | ', $detail));
}

http_response_code($healthy ? 200 : 503);
echo json_encode([
    'status' => $healthy ? 'ok' : 'unhealthy',
    'checks' => $checks,
    'time'   => date('c'),
], JSON_UNESCAPED_SLASHES);
