<?php
/**
 * Centralized OTP API — machine-to-machine (any internal PayG system can call it).
 * Auth: X-API-Key / Bearer token with scope 'otp' (manage keys in IT → Centralized APIs).
 *
 *   POST /api/integrations/otp.php?action=send
 *        { "channel": "email"|"sms"|"whatsapp", "to": "<email or phone>", "purpose": "login" }
 *     -> { success, message, otp_id }        (the code itself is NEVER returned)
 *
 *   POST /api/integrations/otp.php?action=verify
 *        { "channel": "...", "to": "...", "code": "123456", "purpose": "login" }
 *     -> { success, verified: true|false, message }
 */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../sales/_otp_engine.php';

$db = idb();
$tok = integration_auth($db, 'otp');
$b = ibody();
$action = $_GET['action'] ?? ($b['action'] ?? '');

$channel = strtolower(trim((string)($b['channel'] ?? '')));
$to      = (string)($b['to'] ?? ($b['recipient'] ?? ''));
$purpose = substr(trim((string)($b['purpose'] ?? 'generic')) ?: 'generic', 0, 60);

if ($action === 'send') {
    [$ok, $msg, $id] = otp_send($db, $channel, $to, $purpose, 'token:' . ($tok['label'] ?? 'api'));
    if (!$ok) ifail($msg, 422);
    iok(['message' => $msg, 'otp_id' => $id]);
}

if ($action === 'verify') {
    $code = (string)($b['code'] ?? ($b['otp'] ?? ''));
    if ($code === '') ifail('code required');
    [$ok, $msg] = otp_verify($db, $channel, $to, $code, $purpose);
    iok(['verified' => $ok, 'message' => $msg]);
}

ifail("Unknown action — use ?action=send or ?action=verify");
