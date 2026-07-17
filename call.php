<?php
/**
 * call.php
 * ----------------------------------------------------------------------------
 * Places an outbound "connect two numbers" (click-to-call) request to Exotel.
 *
 * Flow:
 *   1. Read the customer number that index.php POSTed.
 *   2. Build the Exotel Calls/connect API request.
 *   3. Send it with cURL using HTTP Basic Auth (API Key : API Token).
 *   4. Log the request + response, then return the response as JSON.
 *
 * This endpoint is called two ways:
 *   - Via fetch() from index.php (returns JSON).
 *   - Directly for debugging (also returns JSON).
 *
 * NOTE: No credentials are hardcoded here — everything comes from config.php.
 * ----------------------------------------------------------------------------
 */

require_once __DIR__ . '/config.php';

// Always respond as JSON so the browser/fetch can parse it consistently.
header('Content-Type: application/json; charset=utf-8');

/**
 * Small helper to send a JSON response and stop execution.
 *
 * @param int   $httpCode HTTP status code to emit.
 * @param array $payload  Data to JSON-encode.
 */
function respond($httpCode, array $payload)
{
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- Step 1: Only accept POST --------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, [
        'success' => false,
        'error'   => 'Method not allowed. Use POST.',
    ]);
}

// ---- Step 2: Validate input ----------------------------------------------------

// The number we want to connect to (entered in index.php).
$customerNumber = isset($_POST['customer_number']) ? trim($_POST['customer_number']) : '';

if ($customerNumber === '') {
    respond(400, [
        'success' => false,
        'error'   => 'customer_number is required.',
    ]);
}

// Very light validation: digits, +, spaces and dashes only. Exotel wants E.164-ish.
if (!preg_match('/^\+?[0-9][0-9\s\-]{6,}$/', $customerNumber)) {
    respond(400, [
        'success' => false,
        'error'   => 'customer_number looks invalid. Use digits, e.g. 09XXXXXXXXX or +91XXXXXXXXXX.',
    ]);
}

// Strip spaces/dashes before sending to Exotel.
$customerNumber = preg_replace('/[\s\-]/', '', $customerNumber);

// ---- Step 3: Build the Exotel API request --------------------------------------

/*
 * Exotel "Connect two numbers" endpoint:
 *   POST {BASE}/v1/Accounts/{SID}/Calls/connect.json
 *
 * Parameters (form-encoded):
 *   From     -> first leg dialed (the agent). Answered first.
 *   To       -> second leg (the customer). Connected after From answers.
 *   CallerId -> your ExoPhone (must be owned in Exotel).
 */
$endpoint = sprintf(
    '%s/v1/Accounts/%s/Calls/connect.json',
    rtrim(EXOTEL_API_BASE_URL, '/'),
    EXOTEL_ACCOUNT_SID
);

$postFields = [
    'From'     => EXOTEL_AGENT_NUMBER,   // agent leg (dialed first)
    'To'       => $customerNumber,       // customer leg (from the form)
    'CallerId' => EXOTEL_CALLER_ID,      // your ExoPhone
    // Optional extras you may want later:
    // 'TimeLimit'   => 3600,
    // 'TimeOut'     => 30,
    // 'StatusCallback' => 'https://your-domain/webhook.php',
];

// ---- Step 4: Fire the request with cURL ----------------------------------------

$ch = curl_init();

curl_setopt_array($ch, [
    CURLOPT_URL            => $endpoint,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($postFields), // form-urlencoded body
    CURLOPT_RETURNTRANSFER => true,                          // return response as string
    CURLOPT_TIMEOUT        => EXOTEL_HTTP_TIMEOUT,

    // HTTP Basic Auth: username = API Key, password = API Token.
    CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
    CURLOPT_USERPWD        => EXOTEL_API_KEY . ':' . EXOTEL_API_TOKEN,

    // TLS verification ON (production-safe). If you hit a local CA issue on
    // XAMPP, fix your cacert.pem rather than disabling this.
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);

// Log the outgoing request (never log the raw token — mask it).
exotel_log('REQUEST', [
    'endpoint' => $endpoint,
    'fields'   => $postFields,
    'auth'     => EXOTEL_API_KEY . ':' . str_repeat('*', 8),
]);

$rawResponse = curl_exec($ch);
$httpStatus  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErrNo   = curl_errno($ch);
$curlError   = curl_error($ch);

curl_close($ch);

// ---- Step 5: Handle transport (cURL) errors ------------------------------------

if ($curlErrNo !== 0) {
    exotel_log('ERROR', [
        'stage'    => 'curl',
        'errno'    => $curlErrNo,
        'message'  => $curlError,
    ]);

    respond(502, [
        'success' => false,
        'error'   => 'Could not reach Exotel: ' . $curlError,
    ]);
}

// ---- Step 6: Parse and log the API response ------------------------------------

// Exotel returns JSON when the endpoint ends in .json.
$parsed = json_decode($rawResponse, true);

exotel_log('RESPONSE', [
    'http_status' => $httpStatus,
    'body'        => $parsed !== null ? $parsed : $rawResponse,
]);

// ---- Step 7: Return a clean result to the caller -------------------------------

$success = ($httpStatus >= 200 && $httpStatus < 300);

respond($success ? 200 : $httpStatus, [
    'success'     => $success,
    'http_status' => $httpStatus,
    // Pass through Exotel's own payload so you can inspect Sid/Status/etc.
    'exotel'      => $parsed !== null ? $parsed : $rawResponse,
]);
