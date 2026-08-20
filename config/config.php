<?php
require_once __DIR__ . '/env.php';

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', env_bool('APP_DEBUG', false) ? '1' : '0');
date_default_timezone_set(env('APP_TIMEZONE', 'Asia/Kolkata'));

require_once __DIR__ . '/app.php';
payg_start_session();

/**
 * AI configuration lives entirely in the database (ai_settings, edited via
 * Settings → AI Engine). The CRM calls that box URL and nothing else.
 *
 * The former SERVICE_BASE_URL / SERVICE_CHAT_ENDPOINT / SERVICE_CHAT_KEY and
 * LLM_URL / LLM_KEY fallbacks pointed at third-party OpenAI-compatible hosts and
 * have been removed, so no CRM data can leave for an unowned endpoint.
 *
 * Voice/speech-to-text has been removed from this product — there is no
 * VOICE_API_* config any more.
 *
 * SERVICE_BASE_URL is kept defined-but-empty only so the legacy reachability
 * helper in api/service-health.php stays inert instead of erroring.
 */
if (!defined('SERVICE_BASE_URL')) {
    define('SERVICE_BASE_URL', '');
}
