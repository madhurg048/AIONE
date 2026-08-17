<?php
require_once __DIR__ . '/env.php';

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', env_bool('APP_DEBUG', false) ? '1' : '0');
date_default_timezone_set(env('APP_TIMEZONE', 'Asia/Kolkata'));

require_once __DIR__ . '/app.php';
payg_start_session();

/**
 * AI fallback endpoint.
 * NOTE: the primary AI is the box configured in Settings → AI (stored in the
 * ai_settings table). The constants below are only the last-resort fallback
 * used when the box AND the cloud endpoint are both unreachable.
 *
 * Voice/speech-to-text has been removed from this product — there is no
 * VOICE_API_* config any more.
 */
if (!defined('SERVICE_BASE_URL')) {
    define('SERVICE_BASE_URL', env('SERVICE_BASE_URL', ''));
}
if (!defined('SERVICE_CHAT_KEY')) {
    define('SERVICE_CHAT_KEY', env('SERVICE_CHAT_KEY', ''));
}
if (!defined('SERVICE_CHAT_ENDPOINT')) {
    $__ep = env('SERVICE_CHAT_ENDPOINT');
    if (!$__ep && SERVICE_BASE_URL !== '') {
        $__ep = rtrim(SERVICE_BASE_URL, '/') . '/v1/chat/completions';
    }
    define('SERVICE_CHAT_ENDPOINT', $__ep ?: '');
    unset($__ep);
}
