<?php
/**
 * WhatsApp Business API configuration
 * ------------------------------------------------------------------
 * This file is intentionally a placeholder. The PayG WhatsApp BSP
 * credentials (TEST and PRODUCTION) will be supplied by the client.
 * When they arrive, paste them into the WHATSAPP_* constants below.
 *
 *   ⚠ We are NOT using Twilio for this integration.
 *      Whatever BSP / WhatsApp Cloud API endpoint the client provides
 *      will be plugged into api/whatsapp.php — this file only holds
 *      the connection settings.
 *
 * Once these constants are populated, set WHATSAPP_ENABLED = true,
 * and the "WhatsApp Business API not connected" banner in the
 * dashboard will go away automatically.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/env.php';

// Master switch — flip to true once credentials below are filled in.
if (!defined('WHATSAPP_ENABLED'))        define('WHATSAPP_ENABLED', env_bool('WHATSAPP_ENABLED', false));

// Which environment the running app should use: 'test' or 'production'.
if (!defined('WHATSAPP_ENV'))            define('WHATSAPP_ENV', env('WHATSAPP_ENV', 'test'));

// Identifies the provider (so we can swap implementations later).
// Examples the client may supply: 'meta_cloud', 'gupshup', 'interakt', 'whapi'.
if (!defined('WHATSAPP_PROVIDER'))       define('WHATSAPP_PROVIDER', env('WHATSAPP_PROVIDER', ''));

// ── TEST credentials ────────────────────────────────────────────────
if (!defined('WHATSAPP_TEST_BASE_URL'))      define('WHATSAPP_TEST_BASE_URL', env('WHATSAPP_TEST_BASE_URL', ''));
if (!defined('WHATSAPP_TEST_API_KEY'))       define('WHATSAPP_TEST_API_KEY', env('WHATSAPP_TEST_API_KEY', ''));
if (!defined('WHATSAPP_TEST_PHONE_NUMBER'))  define('WHATSAPP_TEST_PHONE_NUMBER', env('WHATSAPP_TEST_PHONE_NUMBER', ''));
if (!defined('WHATSAPP_TEST_PHONE_ID'))      define('WHATSAPP_TEST_PHONE_ID', env('WHATSAPP_TEST_PHONE_ID', ''));
if (!defined('WHATSAPP_TEST_BUSINESS_ID'))   define('WHATSAPP_TEST_BUSINESS_ID', env('WHATSAPP_TEST_BUSINESS_ID', ''));

// ── PRODUCTION credentials ─────────────────────────────────────────
if (!defined('WHATSAPP_PROD_BASE_URL'))      define('WHATSAPP_PROD_BASE_URL', env('WHATSAPP_PROD_BASE_URL', ''));
if (!defined('WHATSAPP_PROD_API_KEY'))       define('WHATSAPP_PROD_API_KEY', env('WHATSAPP_PROD_API_KEY', ''));
if (!defined('WHATSAPP_PROD_PHONE_NUMBER'))  define('WHATSAPP_PROD_PHONE_NUMBER', env('WHATSAPP_PROD_PHONE_NUMBER', ''));
if (!defined('WHATSAPP_PROD_PHONE_ID'))      define('WHATSAPP_PROD_PHONE_ID', env('WHATSAPP_PROD_PHONE_ID', ''));
if (!defined('WHATSAPP_PROD_BUSINESS_ID'))   define('WHATSAPP_PROD_BUSINESS_ID', env('WHATSAPP_PROD_BUSINESS_ID', ''));

// ── Webhook ─────────────────────────────────────────────────────────
// Used to verify the BSP webhook handshake. Pick a long random string.
if (!defined('WHATSAPP_WEBHOOK_VERIFY_TOKEN')) define('WHATSAPP_WEBHOOK_VERIFY_TOKEN', env('WHATSAPP_WEBHOOK_VERIFY_TOKEN', ''));
// HMAC secret the BSP signs payloads with. Filled in when client provides it.
if (!defined('WHATSAPP_WEBHOOK_SECRET'))       define('WHATSAPP_WEBHOOK_SECRET', env('WHATSAPP_WEBHOOK_SECRET', ''));

// ── Policy + storage ───────────────────────────────────────────────
if (!defined('WHATSAPP_MESSAGING_WINDOW_HOURS')) define('WHATSAPP_MESSAGING_WINDOW_HOURS', env_int('WHATSAPP_MESSAGING_WINDOW_HOURS', 24));
if (!defined('WHATSAPP_DEFAULT_LANGUAGE'))       define('WHATSAPP_DEFAULT_LANGUAGE', env('WHATSAPP_DEFAULT_LANGUAGE', 'en'));
if (!defined('WHATSAPP_MEDIA_STORAGE_PATH'))     define('WHATSAPP_MEDIA_STORAGE_PATH', env('WHATSAPP_MEDIA_STORAGE_PATH', __DIR__ . '/../data/uploads/whatsapp/'));

/**
 * Helper: returns the active credential set based on WHATSAPP_ENV.
 * api/whatsapp.php uses this so the rest of the code does not care
 * whether we are pointing at test or production.
 */
if (!function_exists('whatsappCredentials')) {
    function whatsappCredentials(): array {
        if (WHATSAPP_ENV === 'production') {
            return [
                'base_url'     => WHATSAPP_PROD_BASE_URL,
                'api_key'      => WHATSAPP_PROD_API_KEY,
                'phone_number' => WHATSAPP_PROD_PHONE_NUMBER,
                'phone_id'     => WHATSAPP_PROD_PHONE_ID,
                'business_id'  => WHATSAPP_PROD_BUSINESS_ID,
            ];
        }
        return [
            'base_url'     => WHATSAPP_TEST_BASE_URL,
            'api_key'      => WHATSAPP_TEST_API_KEY,
            'phone_number' => WHATSAPP_TEST_PHONE_NUMBER,
            'phone_id'     => WHATSAPP_TEST_PHONE_ID,
            'business_id'  => WHATSAPP_TEST_BUSINESS_ID,
        ];
    }
}

if (!function_exists('whatsappIsConfigured')) {
    function whatsappIsConfigured(): bool {
        if (!WHATSAPP_ENABLED) return false;
        $c = whatsappCredentials();
        return !empty($c['base_url']) && !empty($c['api_key']);
    }
}
