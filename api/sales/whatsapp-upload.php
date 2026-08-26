<?php
/**
 * Outbound WhatsApp media send — sales team attaches a file to a thread.
 *
 * Unlike inbound media (customer -> us), which stays on Meta's servers and is proxied
 * on demand by whatsapp-media.php (authenticated, never stored locally): what OUR team
 * sends must live at a public HTTPS URL, because Meta's servers fetch it from there and
 * hand it straight to the customer's WhatsApp app — there is no CRM session in that path,
 * so it cannot be behind our login-gated proxy. It's saved to the same public data/uploads/
 * location the live-chat attachments use (api/sales/chat-upload.php).
 *
 *   ?action=send  POST multipart {thread_id, file, caption?}
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_whatsapp_engine.php';
require_once __DIR__ . '/../uploades.php';
require_login();
$db = db();
$me = current_employee_id();
$action = $_GET['action'] ?? '';

/** Absolute, publicly-reachable URL for a data/uploads/... relative path. */
function wa_public_url(string $relativePath): string
{
    $scheme = payg_is_https() ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    // this file lives at api/sales/ -> webroot is two levels up
    $root = rtrim(str_replace('\\', '/', dirname(dirname(dirname($_SERVER['SCRIPT_NAME'])))), '/');
    return "$scheme://$host{$root}/{$relativePath}";
}

if ($action === 'send') {
    $tid = (int)($_POST['thread_id'] ?? 0);
    $t = $db->prepare("SELECT * FROM whatsapp_threads WHERE id=?");
    $t->execute([$tid]);
    $thread = $t->fetch(PDO::FETCH_ASSOC);
    if (!$thread) fail('Thread not found', 404);

    if (!can_manage_all_records() && (int)$thread['agent_id'] !== $me) {
        $ownerId = null;
        if ($thread['lead_id']) {
            $l = $db->prepare("SELECT owner_id FROM leads WHERE id=?");
            $l->execute([$thread['lead_id']]);
            $ownerId = $l->fetchColumn();
        }
        if ((int)$ownerId !== $me) fail('Not authorized to message this conversation', 403);
    }
    if (!wa_window_open($thread)) fail('24h window closed — send a template to reopen the conversation', 409);

    $cfg = wa_settings($db);
    if (!$cfg || !$cfg['enabled'] || !$cfg['api_token'] || !$cfg['phone_number_id']) fail('WhatsApp is not configured', 500);

    $up = Uploader::handle('whatsapp', $_FILES['file'] ?? []);
    if (!$up['ok']) fail($up['error'], 422);

    $type = wa_media_type_for_mime($up['mime']);
    $caption = trim($_POST['caption'] ?? '');
    $publicUrl = wa_public_url($up['path']);

    [$sent, $providerId, $err] = wa_send_media($cfg, $thread['phone'], $type, $publicUrl, $caption ?: null);
    $status = $sent ? 'sent' : 'failed';

    $db->prepare("INSERT INTO whatsapp_messages (lead_id,phone,thread_id,direction,sender,body,media_type,mime_type,local_path,status,provider_msg_id,created_by)
                  VALUES (?,?,?, 'outbound','agent', ?,?,?,?,?,?,?)")
       ->execute([$thread['lead_id'], $thread['phone'], $tid, $caption ?: $up['name'], $type, $up['mime'], $up['path'], $status, $providerId, $me]);
    $db->prepare("UPDATE whatsapp_threads SET last_msg_at=NOW(), agent_id=COALESCE(agent_id,?) WHERE id=?")->execute([$me, $tid]);

    if (!$sent) fail('Send failed: ' . $err, 502);
    ok(['ok' => true, 'url' => $up['path']]);
}

fail('Unknown action', 400);
