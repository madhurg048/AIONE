<?php
/**
 * webhook.php
 * ----------------------------------------------------------------------------
 * Receives callbacks from Exotel (e.g. StatusCallback / passthru / call events)
 * and records the full payload to logs/exotel.log for inspection.
 *
 * Configure this URL inside Exotel (or pass it as StatusCallback in call.php):
 *   http://YOUR_PUBLIC_HOST/exotel-test/webhook.php
 *
 * NOTE: Exotel must be able to REACH this URL. On localhost it can't, so during
 * testing expose it with a tunnel (e.g. ngrok) and use that HTTPS URL.
 *
 * Always returns HTTP 200 quickly so Exotel does not retry.
 * ----------------------------------------------------------------------------
 */

require_once __DIR__ . '/config.php';

// Collect everything Exotel might send, regardless of method / content-type.
$payload = [
    'received_at'  => date('Y-m-d H:i:s'),
    'method'       => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
    'remote_ip'    => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? '',
    'query'        => $_GET,   // GET params (Exotel often uses query string)
    'form'         => $_POST,  // form-encoded POST params
    'raw_body'     => file_get_contents('php://input'), // JSON or raw body, if any
];

// Persist the webhook exactly as received.
exotel_log('WEBHOOK', $payload);

// Acknowledge fast with 200 OK so Exotel considers it delivered.
http_response_code(200);
header('Content-Type: text/plain; charset=utf-8');
echo 'OK';
