<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors',0);
date_default_timezone_set('Asia/Kolkata');
require_once __DIR__ . '/app.php';
payg_start_session();
// NOTE: primary AI is the box configured in Settings → AI & Voice (e.g. http://192.168.0.100:8000).
// The two localhost endpoints below are only the LAST-RESORT local fallback used if the box AND cloud are unreachable.
if(!defined('SERVICE_BASE_URL'))     define('SERVICE_BASE_URL', getenv('SERVICE_BASE_URL') ?: 'https://167.235.90.27');
// Voice STT (timesheet dictation) has been retired — VOICE_API_* removed deliberately.
// Call-recording transcription is a separate feature and keeps its own URL/key in the
// `transcription_settings` table; it is unaffected.
// AI chat completions endpoint (last-resort fallback). Its key is now independent of voice.
if(!defined('SERVICE_CHAT_ENDPOINT'))define('SERVICE_CHAT_ENDPOINT', getenv('SERVICE_CHAT_ENDPOINT') ?: rtrim(SERVICE_BASE_URL,'/').'/v1/chat/completions');
if(!defined('SERVICE_CHAT_KEY'))     define('SERVICE_CHAT_KEY', getenv('SERVICE_CHAT_KEY') ?: '');
