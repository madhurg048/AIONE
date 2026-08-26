<?php
/**
 * File attachments for the live-chat engine (api/sales/chat.php).
 * Uses api/uploades.php's Uploader for validation/storage; writes the result
 * as a normal chat_messages row (body = filename, attachment_url/type set)
 * so both v_poll/a_poll pick it up like any other message.
 *
 *   Visitor: ?action=v_upload  POST multipart {token, file}
 *   Agent:   ?action=a_upload  POST multipart {session_id, file}
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../uploades.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if (method() === 'OPTIONS') { http_response_code(204); exit; }

$db = db();
$action = $_GET['action'] ?? '';

function chat_upload_push(PDO $db, int $sid, string $sender, array $up): void
{
    $db->prepare("INSERT INTO chat_messages (session_id, sender, body, attachment_url, attachment_type)
                  VALUES (?, ?, ?, ?, ?)")
       ->execute([$sid, $sender, $up['name'], $up['path'], $up['mime']]);
    $db->prepare("UPDATE chat_sessions SET last_activity_at=NOW() WHERE id=?")->execute([$sid]);
}

if ($action === 'v_upload') {
    $token = $_POST['token'] ?? '';
    $s = $db->prepare("SELECT * FROM chat_sessions WHERE token=?");
    $s->execute([$token]);
    $session = $s->fetch(PDO::FETCH_ASSOC);
    if (!$session) fail('invalid session', 404);
    if ($session['status'] === 'closed') fail('chat closed', 410);

    $up = Uploader::handle('chat', $_FILES['file'] ?? []);
    if (!$up['ok']) fail($up['error'], 422);

    chat_upload_push($db, (int)$session['id'], 'visitor', $up);
    ok(['url' => $up['path'], 'name' => $up['name'], 'type' => $up['mime']]);
}

if ($action === 'a_upload') {
    require_login();
    $me = current_employee_id();
    $sid = (int)($_POST['session_id'] ?? 0);
    $chk = $db->prepare("SELECT agent_id, status FROM chat_sessions WHERE id=?");
    $chk->execute([$sid]);
    $session = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$session || (int)$session['agent_id'] !== $me) fail('not your chat', 403);
    if ($session['status'] === 'closed') fail('chat closed', 410);

    $up = Uploader::handle('chat', $_FILES['file'] ?? []);
    if (!$up['ok']) fail($up['error'], 422);

    chat_upload_push($db, $sid, 'agent', $up);
    ok(['url' => $up['path'], 'name' => $up['name'], 'type' => $up['mime']]);
}

fail('Unknown action', 400);
