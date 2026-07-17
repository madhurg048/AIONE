<?php
/**
 * config.php
 * ----------------------------------------------------------------------------
 * Central configuration for the Exotel Voice API test project.
 *
 * All credentials live here ONLY. No other file should hardcode secrets.
 * In a real deployment, keep this file OUT of version control (see .gitignore)
 * and/or load these values from environment variables / a secrets manager.
 *
 * Where to find these values:
 *   Exotel Dashboard -> Settings -> API Credentials
 *     - Account SID  (a.k.a. "sid" / subdomain account name)
 *     - API Key
 *     - API Token
 *   Exotel Dashboard -> ExoPhones -> your virtual number (CallerId)
 * ----------------------------------------------------------------------------
 */

// ---- Exotel account credentials ------------------------------------------------

/** Your Exotel Account SID (appears in the API URL path). */
define('EXOTEL_ACCOUNT_SID', 'abcd6144');

/** API Key (used as the username in HTTP Basic Auth). */
define('EXOTEL_API_KEY', '35ef0d37e8dfb5307b9869aa75822131e74b54e80b1e4721');

/** API Token (used as the password in HTTP Basic Auth). */
define('EXOTEL_API_TOKEN', 'e893159c192346dc5abda0f7d78e8c27305651646cd60091');

/**
 * API base URL (region-specific subdomain).
 *   - Global / default : https://api.exotel.com
 *   - India (Mumbai)   : https://api.in.exotel.com
 *   - Singapore        : https://api.exotel.com
 * Use the one shown in your Exotel dashboard. No trailing slash.
 */
define('EXOTEL_API_BASE_URL', 'https://api.exotel.com');

/**
 * Your ExoPhone / virtual number that shows up as the CallerId.
 * Must be a number you own inside Exotel. Format: 0XXXXXXXXXX or +91XXXXXXXXXX
 */
define('EXOTEL_CALLER_ID', '08047286158');

/**
 * The agent/first-leg number to dial FIRST in a click-to-call flow.
 * Exotel calls this number, and once answered, connects it to the customer.
 * For a quick test you can set this to your own mobile number.
 */
define('EXOTEL_AGENT_NUMBER', '9595592088');

// ---- App / logging settings ----------------------------------------------------

/** Absolute path to the log file. Everything (requests + responses) is appended here. */
define('EXOTEL_LOG_FILE', __DIR__ . '/logs/exotel.log');

/**
 * cURL timeout in seconds for the outbound API call.
 * Exotel usually responds fast; 30s is a safe upper bound.
 */
define('EXOTEL_HTTP_TIMEOUT', 30);

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

