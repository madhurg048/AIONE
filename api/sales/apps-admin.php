<?php
/**
 * IT console — App API Keys, message templates and email servers for the
 * centralized OTP / SMS / Email services. Admin + IT (head has full control).
 *
 *   GET  apps-admin.php?apps=1              -> apps + email servers + purposes
 *   POST apps-admin.php?app=1               -> create app {app_name, app_code, scopes[], sms_sender_id, email_server_id}
 *   PATCH apps-admin.php?app_id=N           -> update / toggle
 *   POST apps-admin.php?rotate=N            -> rotate an app's key
 *   GET  apps-admin.php?templates=1[&app_id=N|global=1&channel=]  -> template list
 *   POST apps-admin.php?template=1          -> create template
 *   PATCH apps-admin.php?template_id=N      -> update template
 *   DELETE apps-admin.php?template_id=N     -> deactivate template
 *   POST apps-admin.php?servers=1           -> create/update email server (password write-only)
 *   DELETE apps-admin.php?server_id=N       -> deactivate server
 *   POST apps-admin.php?test_server=N       -> test-send from that server {to}
 *   POST apps-admin.php?import_email=1      -> import OTP email templates from data/email-templates/<Product>/
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/email-helper.php';
require_login();
if (!in_array(current_role(), ['it', 'it_manager', 'admin'], true)) {
    fail('Centralized API administration is for IT and the admin', 403);
}
$db = db();

/* ------------------------------- reads -------------------------------- */

if (method() === 'GET' && isset($_GET['apps'])) {
    $apps = $db->query("SELECT a.*, es.name AS email_server_name,
                               (SELECT COUNT(*) FROM otp_codes oc WHERE oc.requested_via = CONCAT('app:', a.app_code)) AS otp_sent
                          FROM api_apps a LEFT JOIN email_servers es ON es.id=a.email_server_id
                         ORDER BY a.is_active DESC, a.app_name")->fetchAll();
    $servers = $db->query("SELECT id, name, from_name, from_email, smtp_host, smtp_port, encryption, username, is_active,
                                  (password IS NOT NULL AND password<>'') AS password_set
                             FROM email_servers ORDER BY is_active DESC, name")->fetchAll();
    $purposes = $db->query("SELECT DISTINCT purpose FROM message_templates WHERE is_active=1 ORDER BY purpose")->fetchAll(PDO::FETCH_COLUMN);
    ok(['apps' => $apps, 'servers' => $servers, 'purposes' => $purposes]);
}

if (method() === 'GET' && isset($_GET['templates'])) {
    $w = []; $args = [];
    if (!empty($_GET['app_id'])) { $w[] = "t.app_id=?"; $args[] = (int)$_GET['app_id']; }
    elseif (isset($_GET['global'])) { $w[] = "t.app_id IS NULL"; }
    if (!empty($_GET['channel']) && in_array($_GET['channel'], ['sms', 'email'], true)) { $w[] = "t.channel=?"; $args[] = $_GET['channel']; }
    if (!empty($_GET['q'])) { $w[] = "(t.name LIKE ? OR t.purpose LIKE ? OR t.content LIKE ?)"; $q = '%' . $_GET['q'] . '%'; array_push($args, $q, $q, $q); }
    $s = $db->prepare("SELECT t.*, a.app_name FROM message_templates t
                  LEFT JOIN api_apps a ON a.id=t.app_id " .
                  ($w ? 'WHERE ' . implode(' AND ', $w) : '') .
                  " ORDER BY t.is_active DESC, t.is_otp DESC, t.purpose, t.id LIMIT 300");
    $s->execute($args);
    ok($s->fetchAll());
}

/* ------------------------------- writes ------------------------------- */

if (method() === 'POST' && isset($_GET['app'])) {
    $b = body();
    $name = trim((string)($b['app_name'] ?? ''));
    $code = strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string)($b['app_code'] ?? '')));
    if ($name === '' || $code === '') fail('app_name and app_code required');
    $scopes = array_values(array_intersect((array)($b['scopes'] ?? ['otp']), ['otp', 'sms', 'email', 'portal']));
    if (!$scopes) fail('Pick at least one scope (otp / sms / email)');
    $key = 'app_' . bin2hex(random_bytes(16));
    try {
        $db->prepare("INSERT INTO api_apps (app_name, app_code, description, api_key, scopes, sms_sender_id, email_server_id, created_by)
                      VALUES (?,?,?,?,?,?,?,?)")
           ->execute([$name, $code, ($b['description'] ?? null) ?: null, $key, implode(',', $scopes),
                      ($b['sms_sender_id'] ?? null) ?: null,
                      !empty($b['email_server_id']) ? (int)$b['email_server_id'] : null,
                      current_employee_id() ?: null]);
    } catch (Throwable $e) { fail('That app code already exists'); }
    ok(['id' => (int)$db->lastInsertId(), 'api_key' => $key]);
}

if ((method() === 'PATCH' || method() === 'PUT') && isset($_GET['app_id'])) {
    $id = (int)$_GET['app_id']; $b = body();
    $set = []; $args = [];
    if (isset($b['app_name']) && trim($b['app_name']) !== '') { $set[] = "app_name=?"; $args[] = trim($b['app_name']); }
    if (array_key_exists('description', $b)) { $set[] = "description=?"; $args[] = ($b['description'] ?? null) ?: null; }
    if (array_key_exists('sms_sender_id', $b)) { $set[] = "sms_sender_id=?"; $args[] = ($b['sms_sender_id'] ?? null) ?: null; }
    if (array_key_exists('email_server_id', $b)) { $set[] = "email_server_id=?"; $args[] = !empty($b['email_server_id']) ? (int)$b['email_server_id'] : null; }
    if (isset($b['scopes'])) {
        $scopes = array_values(array_intersect((array)$b['scopes'], ['otp', 'sms', 'email', 'portal']));
        if (!$scopes) fail('Pick at least one scope');
        $set[] = "scopes=?"; $args[] = implode(',', $scopes);
    }
    if (array_key_exists('is_active', $b)) { $set[] = "is_active=?"; $args[] = (int)!!$b['is_active']; }
    if (!$set) fail('Nothing to update');
    $args[] = $id;
    $db->prepare("UPDATE api_apps SET " . implode(',', $set) . " WHERE id=?")->execute($args);
    ok();
}

if (method() === 'POST' && isset($_GET['rotate'])) {
    $key = 'app_' . bin2hex(random_bytes(16));
    $db->prepare("UPDATE api_apps SET api_key=? WHERE id=?")->execute([$key, (int)$_GET['rotate']]);
    ok(['api_key' => $key]);
}

if (method() === 'POST' && isset($_GET['template'])) {
    $b = body();
    $name = trim((string)($b['name'] ?? ''));
    $content = trim((string)($b['content'] ?? ''));
    if ($name === '' || $content === '') fail('name and content required');
    $channel = in_array($b['channel'] ?? '', ['sms', 'email'], true) ? $b['channel'] : 'sms';
    $db->prepare("INSERT INTO message_templates (app_id, channel, purpose, name, dlt_template_id, sender_header, subject, content, is_otp)
                  VALUES (?,?,?,?,?,?,?,?,?)")
       ->execute([!empty($b['app_id']) ? (int)$b['app_id'] : null, $channel,
                  substr(trim((string)($b['purpose'] ?? 'generic')) ?: 'generic', 0, 80),
                  $name, ($b['dlt_template_id'] ?? null) ?: null, ($b['sender_header'] ?? null) ?: null,
                  ($b['subject'] ?? null) ?: null, $content, (int)!empty($b['is_otp'])]);
    ok(['id' => (int)$db->lastInsertId()]);
}

if ((method() === 'PATCH' || method() === 'PUT') && isset($_GET['template_id'])) {
    $id = (int)$_GET['template_id']; $b = body();
    $set = []; $args = [];
    foreach (['name', 'purpose', 'dlt_template_id', 'sender_header', 'subject', 'content'] as $f) {
        if (array_key_exists($f, $b)) { $set[] = "$f=?"; $args[] = ($b[$f] === '' ? null : $b[$f]); }
    }
    if (array_key_exists('is_otp', $b)) { $set[] = "is_otp=?"; $args[] = (int)!!$b['is_otp']; }
    if (array_key_exists('is_active', $b)) { $set[] = "is_active=?"; $args[] = (int)!!$b['is_active']; }
    if (array_key_exists('app_id', $b)) { $set[] = "app_id=?"; $args[] = !empty($b['app_id']) ? (int)$b['app_id'] : null; }
    if (!$set) fail('Nothing to update');
    $args[] = $id;
    $db->prepare("UPDATE message_templates SET " . implode(',', $set) . " WHERE id=?")->execute($args);
    ok();
}

if (method() === 'DELETE' && isset($_GET['template_id'])) {
    $db->prepare("UPDATE message_templates SET is_active=0 WHERE id=?")->execute([(int)$_GET['template_id']]);
    ok();
}

if (method() === 'POST' && isset($_GET['servers'])) {
    $b = body();
    $id = (int)($b['id'] ?? 0);
    $name = trim((string)($b['name'] ?? ''));
    $from = trim((string)($b['from_email'] ?? ''));
    if ($name === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) fail('name and a valid from_email required');
    if ($id) {
        $set = ['name=?', 'from_name=?', 'from_email=?', 'smtp_host=?', 'smtp_port=?', 'encryption=?', 'username=?', 'is_active=?'];
        $args = [$name, ($b['from_name'] ?? 'PayG') ?: 'PayG', $from, ($b['smtp_host'] ?? null) ?: null,
                 max(1, (int)($b['smtp_port'] ?? 587)),
                 in_array($b['encryption'] ?? '', ['none', 'ssl', 'tls'], true) ? $b['encryption'] : 'tls',
                 ($b['username'] ?? null) ?: null, (int)!empty($b['is_active'])];
        if (!empty($b['password'])) { $set[] = 'password=?'; $args[] = (string)$b['password']; }   // write-only
        $args[] = $id;
        $db->prepare("UPDATE email_servers SET " . implode(',', $set) . " WHERE id=?")->execute($args);
        ok(['id' => $id]);
    }
    try {
        $db->prepare("INSERT INTO email_servers (name, from_name, from_email, smtp_host, smtp_port, encryption, username, password, is_active)
                      VALUES (?,?,?,?,?,?,?,?,1)")
           ->execute([$name, ($b['from_name'] ?? 'PayG') ?: 'PayG', $from, ($b['smtp_host'] ?? null) ?: null,
                      max(1, (int)($b['smtp_port'] ?? 587)),
                      in_array($b['encryption'] ?? '', ['none', 'ssl', 'tls'], true) ? $b['encryption'] : 'tls',
                      ($b['username'] ?? null) ?: null, ($b['password'] ?? null) ?: null]);
    } catch (Throwable $e) { fail('A server with that name already exists'); }
    ok(['id' => (int)$db->lastInsertId()]);
}

if (method() === 'DELETE' && isset($_GET['server_id'])) {
    $db->prepare("UPDATE email_servers SET is_active=0 WHERE id=?")->execute([(int)$_GET['server_id']]);
    ok();
}

if (method() === 'POST' && isset($_GET['test_server'])) {
    $to = trim((string)(body()['to'] ?? ''));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) fail('Valid to address required');
    $s = $db->prepare("SELECT * FROM email_servers WHERE id=?");
    $s->execute([(int)$_GET['test_server']]);
    $server = $s->fetch(PDO::FETCH_ASSOC);
    if (!$server) fail('Server not found', 404);
    [$ok, $m] = crm_smtp_send($server, $to, 'Test', 'PayG — test from ' . $server['name'],
        '<p>✅ Test email from the <b>' . htmlspecialchars($server['name']) . '</b> sender account.</p>');
    ok(['sent' => $ok, 'detail' => $m]);
}

if (method() === 'POST' && isset($_GET['import_email'])) {
    // pull the OTP email templates from the client's library (data/email-templates/<Product>/*SendOTP*.html)
    $root = realpath(__DIR__ . '/../../data/email-templates');
    if (!$root) fail('data/email-templates not found');
    $imported = []; $skipped = 0;
    foreach (glob($root . '/*', GLOB_ONLYDIR) as $dir) {
        $product = basename($dir);
        foreach (glob($dir . '/*SendOTP*.html') as $file) {
            $purpose = 'send_otp_' . strtolower(preg_replace('/EmailTemplates$/', '', $product));
            $name = $product . ' / ' . basename($file, '.html');
            $chk = $db->prepare("SELECT COUNT(*) FROM message_templates WHERE channel='email' AND name=?");
            $chk->execute([$name]);
            if ($chk->fetchColumn()) { $skipped++; continue; }
            $html = (string)file_get_contents($file);
            // the client's templates use various OTP placeholders — normalise to {#otp#}
            $html = str_ireplace(['{{OTP}}', '{OTP}', '[OTP]', '@OTP', '{#OTPCODE#}'], '{#otp#}', $html);
            $db->prepare("INSERT INTO message_templates (app_id, channel, purpose, name, subject, content, is_otp)
                          VALUES (NULL, 'email', ?, ?, 'Your PayG verification code', ?, 1)")
               ->execute([$purpose, $name, $html]);
            $imported[] = $name;
        }
    }
    ok(['imported' => $imported, 'skipped_existing' => $skipped]);
}

fail('Unsupported request', 405);

