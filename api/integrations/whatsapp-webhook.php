<?php
/**
 * Meta WhatsApp Cloud API webhook — inbound messages + delivery-status callbacks.
 *
 *   GET  ?hub.mode=subscribe&hub.verify_token=...&hub.challenge=...   (Meta's one-time handshake)
 *   POST <Meta webhook payload>                                       (messages + statuses)
 *
 * This is a public, unauthenticated-by-token endpoint (Meta cannot send our normal X-API-Key
 * integration_tokens header) — auth instead is Meta's verify-token handshake (GET) and an
 * X-Hub-Signature-256 HMAC over the raw body (POST), both checked against whatsapp_settings.
 *
 * Must always respond fast with 200, or Meta will retry and eventually disable the webhook —
 * so every step below is best-effort / non-fatal, matching the automation engine's philosophy.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../sales/_whatsapp_engine.php';
require_once __DIR__ . '/../sales/_automation_engine.php';

function wadb(): PDO { $db = getDB(); if (!$db) { http_response_code(200); echo 'db_unavailable'; exit; } return $db; }

/* ---- GET: verification handshake ---- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $db = wadb();
    $cfg = wa_settings($db);
    $mode = $_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '';
    $token = $_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '';
    if ($mode === 'subscribe' && $cfg && !empty($cfg['webhook_verify_token']) && hash_equals($cfg['webhook_verify_token'], (string)$token)) {
        header('Content-Type: text/plain');
        echo $challenge;
        exit;
    }
    http_response_code(403);
    echo 'verification failed';
    exit;
}

/* ---- POST: inbound messages / status callbacks ---- */
$raw = file_get_contents('php://input');
$db = wadb();
$cfg = wa_settings($db);

if ($cfg && !empty($cfg['app_secret'])) {
    $sig = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? null;
    if (!wa_verify_signature($cfg['app_secret'], $raw, $sig)) {
        http_response_code(403);
        echo 'bad signature';
        exit;
    }
}

$payload = json_decode($raw, true);
try {
    foreach ((array)($payload['entry'] ?? []) as $entry) {
        foreach ((array)($entry['changes'] ?? []) as $change) {
            $value = $change['value'] ?? [];
            $contacts = $value['contacts'] ?? [];
            $nameByWaId = [];
            foreach ($contacts as $c) { $nameByWaId[$c['wa_id']] = $c['profile']['name'] ?? null; }

            foreach ((array)($value['messages'] ?? []) as $msg) {
                $phone = wa_normalize_phone($msg['from'] ?? '', $cfg['default_cc'] ?? '91');
                if ($phone === '') continue;
                $name = $nameByWaId[$msg['from']] ?? null;

                $leadId = wa_find_or_create_lead($db, $phone, $name);
                $thread = wa_find_or_create_thread($db, $phone, $leadId ?: null, $name);

                $type = $msg['type'] ?? 'text';
                $body = null; $mediaId = null; $mimeType = null;
                if ($type === 'text') {
                    $body = $msg['text']['body'] ?? '';
                } elseif (isset($msg[$type]['id'])) {
                    $mediaId = $msg[$type]['id'];
                    $mimeType = $msg[$type]['mime_type'] ?? null;
                    $body = $msg[$type]['caption'] ?? ('[' . $type . ']');
                } elseif ($type === 'button') {
                    $body = $msg['button']['text'] ?? '[button]';
                } elseif ($type === 'interactive') {
                    $body = $msg['interactive']['button_reply']['title'] ?? $msg['interactive']['list_reply']['title'] ?? '[interactive]';
                } else {
                    $body = '[' . $type . ']';
                }

                $db->prepare("INSERT INTO whatsapp_messages
                    (lead_id,phone,thread_id,direction,sender,body,media_id,media_type,mime_type,status,provider_msg_id)
                    VALUES (?,?,?, 'inbound','customer', ?,?,?,?, 'delivered', ?)")
                   ->execute([$leadId ?: null, $phone, $thread['id'], $body, $mediaId, $mediaId ? $type : null, $mimeType, $msg['id'] ?? null]);

                $db->prepare("UPDATE whatsapp_threads
                              SET last_inbound_at=NOW(), last_msg_at=NOW(), unread_count=unread_count+1, status='open',
                                  lead_id=COALESCE(lead_id,?), contact_name=COALESCE(contact_name,?)
                              WHERE id=?")
                   ->execute([$leadId ?: null, $name, $thread['id']]);
            }

            foreach ((array)($value['statuses'] ?? []) as $st) {
                $mapped = in_array($st['status'] ?? '', ['sent', 'delivered', 'read', 'failed'], true) ? $st['status'] : null;
                if ($mapped && !empty($st['id'])) {
                    $db->prepare("UPDATE whatsapp_messages SET status=?, read_at=IF(?='read',NOW(),read_at) WHERE provider_msg_id=?")
                       ->execute([$mapped, $mapped, $st['id']]);
                }
            }
        }
    }
} catch (Throwable $e) {
    error_log('whatsapp-webhook: ' . $e->getMessage());
}

http_response_code(200);
echo 'EVENT_RECEIVED';
