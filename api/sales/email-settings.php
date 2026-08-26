<?php
/**
 * Email/notification account settings (admin only).
 *   GET  email-settings.php          -> current config (password never returned)
 *   POST email-settings.php          -> save config
 *   POST email-settings.php?test=1   { to } -> send a test email
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/email-helper.php';
require_login();
if (($_SESSION['user']['user_type'] ?? '') !== 'admin' && current_role() !== 'it') {
    fail('Admin or IT only', 403);   // IT manages integrations/settings
}
$db = db();

if (method() === 'POST' && isset($_GET['test'])) {
    $to = trim(body()['to'] ?? '');
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) fail('Valid recipient required');
    $r = crm_email_send_test($to);
    if ($r['ok']) ok([], ['message' => 'Test email sent to ' . $to]);
    fail('Send failed: ' . $r['message'], 502);
}

if (method() === 'POST') {
    $b = body();
    $method = in_array($b['method'] ?? '', ['smtp','ad_exchange','imap_pop'], true) ? $b['method'] : 'smtp';
    $enc    = in_array($b['encryption'] ?? '', ['none','ssl','tls'], true) ? $b['encryption'] : 'tls';

    // keep the existing password unless a new one is supplied
    $cur = $db->query("SELECT password FROM email_settings WHERE id=1")->fetchColumn();
    $pass = (isset($b['password']) && $b['password'] !== '' && $b['password'] !== '********') ? $b['password'] : $cur;

    $db->prepare("UPDATE email_settings SET method=?, from_name=?, from_email=?, smtp_host=?, smtp_port=?,
                  encryption=?, username=?, password=?, ad_domain=?, imap_host=?, imap_port=?, is_active=? WHERE id=1")
       ->execute([
           $method, substr($b['from_name'] ?? 'PayG CRM',0,120), trim($b['from_email'] ?? ''),
           trim($b['smtp_host'] ?? ''), (int)($b['smtp_port'] ?? 587), $enc,
           trim($b['username'] ?? ''), $pass, trim($b['ad_domain'] ?? ''),
           trim($b['imap_host'] ?? ''), (int)($b['imap_port'] ?? 993),
           !empty($b['is_active']) ? 1 : 0,
       ]);
    ok([], ['message' => 'Email settings saved']);
}

// GET — return everything except the password
$cfg = $db->query("SELECT id,method,from_name,from_email,smtp_host,smtp_port,encryption,username,ad_domain,
                          imap_host,imap_port,is_active,
                          (password IS NOT NULL AND password<>'') AS has_password
                   FROM email_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC);
ok($cfg ?: []);
