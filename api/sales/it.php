<?php
/**
 * IT dashboard: integration health, user accounts, automation, API tokens.
 *   GET it.php   (IT / admin only)
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_ai.php';
require_login();
if (!in_array(current_role(), ['it', 'it_manager', 'admin'], true)) fail('IT/admin only', 403);
$db = db();
$one = fn($sql) => (function () use ($db, $sql) { try { return $db->query($sql)->fetchColumn(); } catch (Throwable $e) { return null; } })();
$row = fn($sql) => (function () use ($db, $sql) { try { return $db->query($sql)->fetch(PDO::FETCH_ASSOC); } catch (Throwable $e) { return null; } })();

// ---- integration health ----
$ai   = $row("SELECT box_enabled,box_url,cloud_enabled FROM ai_settings WHERE id=1") ?: [];
$em   = $row("SELECT is_active,smtp_host FROM email_settings WHERE id=1") ?: [];
$wa   = $row("SELECT enabled,provider FROM whatsapp_settings WHERE id=1") ?: [];
$tp   = $row("SELECT enabled,provider FROM telephony_settings WHERE id=1") ?: [];
$boxHealth = !empty($ai['box_enabled']) ? crm_ai_box_health($ai['box_url'] ?? '') : ['up' => null];

$integrations = [
    ['key'=>'ai',        'label'=>'AI Engine',      'icon'=>'fa-microchip',    'enabled'=>(int)!empty($ai['box_enabled']), 'detail'=>$ai['box_url'] ?? '', 'up'=>$boxHealth['up'] ?? null, 'note'=>($boxHealth['up']??null)===false?'box down — using fallback':''],
    ['key'=>'email',     'label'=>'Email (SMTP)',   'icon'=>'fa-envelope',     'enabled'=>(int)!empty($em['is_active']), 'detail'=>$em['smtp_host'] ?? '', 'up'=>null, 'note'=>''],
    ['key'=>'whatsapp',  'label'=>'WhatsApp',       'icon'=>'fa-brands fa-whatsapp', 'enabled'=>(int)!empty($wa['enabled']), 'detail'=>$wa['provider'] ?? '', 'up'=>null, 'note'=>''],
    ['key'=>'telephony', 'label'=>'Click-to-Call',  'icon'=>'fa-phone-volume', 'enabled'=>(int)!empty($tp['enabled']), 'detail'=>$tp['provider'] ?? '', 'up'=>null, 'note'=>''],
    ['key'=>'mailboxes', 'label'=>'Inbound Mail',   'icon'=>'fa-inbox',        'enabled'=>(int)($one("SELECT COUNT(*) FROM email_mailboxes WHERE is_active=1") > 0), 'detail'=>$one("SELECT COUNT(*) FROM email_mailboxes")." configured", 'up'=>null, 'note'=>''],
];

$out = [
    'kpis' => [
        'integrations_on' => count(array_filter($integrations, fn($i) => $i['enabled'])),
        'integrations_all'=> count($integrations),
        'users_active'    => (int)$one("SELECT COUNT(*) FROM sales_users WHERE is_active=1"),
        'users_inactive'  => (int)$one("SELECT COUNT(*) FROM sales_users WHERE is_active=0"),
        'rules_active'    => (int)$one("SELECT COUNT(*) FROM automation_rules WHERE is_active=1"),
        'automation_runs' => (int)$one("SELECT COALESCE(SUM(run_count),0) FROM automation_rules"),
    ],
    'integrations' => $integrations,
    'users_by_role'=> $db->query("SELECT role, SUM(is_active) active, COUNT(*)-SUM(is_active) inactive FROM sales_users GROUP BY role")->fetchAll(),
    'api_tokens'   => $db->query("SELECT label, scopes, is_active, last_used_at FROM integration_tokens ORDER BY id")->fetchAll(),
    'automation_logs' => (function () use ($db) {
        try { return $db->query("SELECT al.status, al.actions_run, al.created_at, ar.name AS rule_name
                                 FROM automation_logs al LEFT JOIN automation_rules ar ON ar.id=al.rule_id
                                 ORDER BY al.id DESC LIMIT 10")->fetchAll(); }
        catch (Throwable $e) { return []; }
    })(),
    'system' => [
        'transactions_today' => (int)$one("SELECT COUNT(*) FROM merchant_transactions WHERE DATE(txn_date)=CURDATE()"),
        'tickets_open'       => (int)$one("SELECT COUNT(*) FROM crm_tickets WHERE status NOT IN('closed','resolved') OR status IS NULL"),
    ],
];
ok($out);
