<?php
/**
 * Voice → text proxy for timesheet dictation.
 * Forwards recorded audio to the parakeet STT service (Hetzner voice API) and returns the transcript.
 * Contract (confirmed against the live service):
 *   POST {VOICE_API_URL}?lang=en   Authorization: Bearer <api key>   body = RAW audio bytes
 *   -> {"text":"...","words":[...],"lang":"en","ms":123}
 * Config (set on your server):
 *   define('VOICE_API_URL',   'https://167.235.90.27/voice/transcribe');
 *   define('VOICE_API_TOKEN', '<gck_... voice-app key>');
 *   define('VOICE_API_LANG',  'en');   // optional; default 'en'
 *
 *   POST voice-transcribe.php   (multipart from the browser: audio=<blob>)
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../config/config.php';
require_login();

// Voice transcription is disabled org-wide (product decision) — the timesheet UI's mic button
// has been removed, but hard-reject here too so the feature isn't reachable by calling this
// endpoint directly.
fail('Voice transcription is currently disabled', 503);

if (session_status() === PHP_SESSION_ACTIVE) session_write_close();  // external call, release lock

// Prefer the voice URL/key configured in Settings → AI & Voice; fall back to config constants.
$voiceUrl = defined('VOICE_API_URL') ? VOICE_API_URL : '';
$voiceKey = defined('VOICE_API_TOKEN') ? VOICE_API_TOKEN : '';
try {
    $as = db()->query("SELECT voice_url,voice_api_key FROM ai_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    if (!empty($as['voice_url']))     $voiceUrl = $as['voice_url'];
    if (!empty($as['voice_api_key'])) $voiceKey = $as['voice_api_key'];
} catch (Throwable $e) {}
if (!$voiceUrl || !$voiceKey) {
    fail('Voice service not configured (set the Voice API URL + key in Settings → AI & Voice)', 503);
}
if (empty($_FILES['audio']['tmp_name'])) fail('No audio uploaded (field "audio")');

$bytes = file_get_contents($_FILES['audio']['tmp_name']);
if ($bytes === false || $bytes === '') fail('Empty audio');
$mime = $_FILES['audio']['type'] ?: 'audio/webm';
$lang = defined('VOICE_API_LANG') && VOICE_API_LANG ? VOICE_API_LANG : 'en';
$url  = $voiceUrl . (strpos($voiceUrl, '?') === false ? '?' : '&') . 'lang=' . rawurlencode($lang);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $bytes,                         // RAW audio bytes (not multipart)
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $voiceKey, 'Content-Type: ' . $mime],
    CURLOPT_TIMEOUT => 45,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
]);
$resp  = curl_exec($ch);
$errno = curl_errno($ch);
$code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($errno) fail('Voice service unreachable', 502);
if ($code === 401 || $code === 403) fail('Voice service auth failed (check VOICE_API_TOKEN / rate limit)', 502);

$j = json_decode($resp, true);
$transcript = $j['text'] ?? $j['transcript'] ?? $j['result'] ?? (is_string($j) ? $j : '');
if ($transcript === '') fail('No transcript returned', 502);

ok(['transcript' => trim($transcript), 'lang' => $j['lang'] ?? $lang]);
