<?php
/**
 * config.php
 * ----------------------------------------------------------------------------
 * Exotel Voice API configuration for the standalone click-to-call test page
 * (call.php / webhook.php).
 *
 * NO SECRETS IN THIS FILE. Every credential is read from the environment:
 *   - local / VM   -> the .env file at the app root (see .env.example)
 *   - Azure        -> App Service → Settings → Environment variables
 *
 * Where to find these values:
 *   Exotel Dashboard -> Settings -> API Credentials
 *     - Account SID  (a.k.a. "sid" / subdomain account name)
 *     - API Key
 *     - API Token
 *   Exotel Dashboard -> ExoPhones -> your virtual number (CallerId)
 *
 * NOTE: the CRM's real click-to-call runs off the telephony_settings table
 * (Settings → Telephony), not this file. This file only backs the standalone
 * call.php test harness.
 * ----------------------------------------------------------------------------
 */

require_once __DIR__ . '/config/env.php';

// ---- Exotel account credentials ------------------------------------------------

/** Your Exotel Account SID (appears in the API URL path). */
define('EXOTEL_ACCOUNT_SID', env('EXOTEL_ACCOUNT_SID', ''));

/** API Key (used as the username in HTTP Basic Auth). */
define('EXOTEL_API_KEY', env('EXOTEL_API_KEY', ''));

/** API Token (used as the password in HTTP Basic Auth). */
define('EXOTEL_API_TOKEN', env('EXOTEL_API_TOKEN', ''));

/**
 * API base URL (region-specific subdomain).
 *   - Global / default : https://api.exotel.com
 *   - India (Mumbai)   : https://api.in.exotel.com
 * Use the one shown in your Exotel dashboard. No trailing slash.
 */
define('EXOTEL_API_BASE_URL', env('EXOTEL_API_BASE_URL', 'https://api.exotel.com'));

/**
 * Your ExoPhone / virtual number that shows up as the CallerId.
 * Must be a number you own inside Exotel. Format: 0XXXXXXXXXX or +91XXXXXXXXXX
 */
define('EXOTEL_CALLER_ID', env('EXOTEL_CALLER_ID', ''));

/**
 * The agent/first-leg number to dial FIRST in a click-to-call flow.
 * Exotel calls this number, and once answered, connects it to the customer.
 */
define('EXOTEL_AGENT_NUMBER', env('EXOTEL_AGENT_NUMBER', ''));

/** True only when a call can actually be placed. call.php checks this. */
define('EXOTEL_CONFIGURED', EXOTEL_ACCOUNT_SID !== '' && EXOTEL_API_KEY !== '' && EXOTEL_API_TOKEN !== '');

// ---- App / logging settings ----------------------------------------------------

/**
 * Absolute path to the log file.
 * On Azure App Service (Linux) only /home is persistent and writable, so the
 * default points there. Override with EXOTEL_LOG_FILE if you mount elsewhere.
 */
define('EXOTEL_LOG_FILE', env('EXOTEL_LOG_FILE', (is_dir('/home') && is_writable('/home') ? '/home/LogFiles/exotel.log' : __DIR__ . '/logs/exotel.log')));

/** cURL timeout in seconds for the outbound API call. */
define('EXOTEL_HTTP_TIMEOUT', env_int('EXOTEL_HTTP_TIMEOUT', 30));

// ---- Shared helpers ------------------------------------------------------------

/**
 * Append a timestamped, labelled entry to the log file.
 * Used by call.php (request/response) and webhook.php (incoming payloads).
 *
 * @param string $label A short tag, e.g. "REQUEST", "RESPONSE", "WEBHOOK", "ERROR".
 * @param mixed  $data  Any value; arrays/objects are pretty-printed as JSON.
 */
function exotel_log($label, $data)
{
    // Make sure the logs directory exists (first run on a fresh checkout).
    $dir = dirname(EXOTEL_LOG_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    // Normalise the payload to a readable string.
    if (is_array($data) || is_object($data)) {
        $body = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } else {
        $body = (string) $data;
    }

    $line = sprintf("[%s] %s\n%s\n%s\n",
        date('Y-m-d H:i:s'),
        strtoupper($label),
        $body,
        str_repeat('-', 60)
    );

    // FILE_APPEND + LOCK_EX = safe concurrent appends.
    @file_put_contents(EXOTEL_LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}
