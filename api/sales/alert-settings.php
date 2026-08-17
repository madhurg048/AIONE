<?php
/**
 * Auto-email alert configuration (Settings → Auto-email alerts).
 *   GET  alert-settings.php            -> settings + recent alert log + smtp readiness
 *   POST alert-settings.php            -> save settings
 *   POST alert-settings.php?run_now=1  -> run the daily sweep immediately (test)
 * Admin + managers (the people who own the escalation/settings surface).
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_alert_engine.php';
require_login();
if (!can_manage_all_records() && current_role() !== 'admin') fail('Alert settings are for managers and admins', 403);
$db = db();

if (method() === 'GET') {
    $cfg = alert_settings($db);
    $smtp = null;
    try { $smtp = crm_email_config(); } catch (Throwable $e) {}
    $log = $db->query("SELECT l.*, e.full_name FROM alert_log l LEFT JOIN employees e ON e.id=l.employee_id
                        ORDER BY l.id DESC LIMIT 50")->fetchAll();
    ok(['settings' => $cfg,
        'smtp_ready' => (bool)($smtp && !empty($smtp['is_active'])),
        'log' => $log]);
}

if (method() === 'POST' && isset($_GET['run_now'])) {
    ok(run_daily_alerts($db, true));   // the test button ignores day-of-week gates
}

if (method() === 'POST') {
    $b = body();
    $set = []; $args = [];
    foreach (['alerts_enabled', 'cc_manager', 'timesheet_alert', 'sla_alert', 'target_alert'] as $f) {
        if (array_key_exists($f, $b)) { $set[] = "$f=?"; $args[] = (int)!!$b[$f]; }
    }
    if (isset($b['target_threshold_pct'])) { $set[] = "target_threshold_pct=?"; $args[] = max(1, min(100, (int)$b['target_threshold_pct'])); }
    if (!$set) fail('Nothing to save');
    $db->prepare("UPDATE alert_settings SET " . implode(',', $set) . " WHERE id=1")->execute($args);
    ok();
}

fail('Unsupported request', 405);
