<?php
/**
 * Shared OTP helpers — a thin client for PayG's own verification service
 * (Send_/Verify_ Mobile+Email OTP) plus the shared `otp_rate_limits` send window.
 *
 * There is no local OTP secret anywhere in this codebase: the code is generated,
 * delivered and checked by PayG's service, and we only relay to it and remember the
 * returned verification id.
 *
 * Used by: chatbot-otp.php (public chatbot widget), forgot-password.php (CRM password reset).
 */

require_once __DIR__ . '/../../config/env.php';

/**
 * Base URL of PayG's verification service. PRODUCTION host is the default now;
 * override with PAYG_OTP_BASE in the environment (e.g. to point back at
 * devapi.payg.in for a staging box). Trailing slash is normalised.
 *
 * Endpoints appended to this base — all POST, JSON body:
 *   Send_MobileOTP    {"MobileNumber":"9182032920","Type":15}
 *   Verify_MobileOTP  {"MobileNumber":"9182032920","Type":15,"ConfirmOtp":"144654","Id":898}
 *   Send_EmailOTP     {"Email":"user@payg.in","Type":15}
 *   Verify_EmailOTP   {"Email":"user@payg.in","Type":15,"ConfirmOtp":"145624","Id":"899"}
 */
if (!defined('PAYG_OTP_BASE')) {
    $__otpBase = env('PAYG_OTP_BASE', 'https://apiv3.PayG.in/virtualPOS/api/WebSite/');
    define('PAYG_OTP_BASE', rtrim($__otpBase, '/') . '/');
    unset($__otpBase);
}

/**
 * Offline dev fallback (OTP 123456). OFF unless OTP_DEV_FALLBACK=true.
 *
 * SECURITY: when this is on and the verification service is unreachable,
 * otp_call_payg() answers "verified" to ANY 6-digit code. That is an account
 * takeover on forgot-password.php and lets anyone claim any mobile number in
 * the chatbot. It used to be unconditional, which meant a network blip or a
 * firewalled egress route silently turned authentication off in production.
 * Never enable it on a public host.
 */
if (!defined('OTP_DEV_FALLBACK')) {
    define('OTP_DEV_FALLBACK', env_bool('OTP_DEV_FALLBACK', false));
}

/** POST a payload to a PayG verification endpoint. Returns [rawBody, curlError, httpStatus]. */
function otp_call_payg(string $endpoint, array $payload, int $timeout = 20): array
{
    // TLS verification is ON. This request carries the OTP and the verification
    // id for a real production auth flow — with verification disabled, anything
    // on the network path could present its own certificate and read or forge
    // both. Set OTP_TLS_INSECURE=true only to work around a genuine local CA
    // problem, and never on a public host.
    $verify = !env_bool('OTP_TLS_INSECURE', false);

    $ch = curl_init(PAYG_OTP_BASE . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_SSL_VERIFYPEER => $verify,
        CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // If the external service is unreachable, LOCAL DEV ONLY may fall back to code
    // 123456 so password reset still works offline. Gated behind OTP_DEV_FALLBACK
    // because "accept any 6-digit code" is an authentication bypass on a public host.
    // With the flag off we fail closed: the caller sees the real error and rejects.
    if (($err !== '' || $httpCode !== 200) && OTP_DEV_FALLBACK) {
        if (str_contains($endpoint, 'Send_')) {
            return [json_encode(['Id' => 123456, 'Status' => 1, 'Message' => 'OTP sent (Dev Mode: 123456)']), '', 200];
        }
        if (str_contains($endpoint, 'Verify_')) {
            $otp = trim((string)($payload['ConfirmOtp'] ?? ''));
            if ($otp === '123456' || $otp === '000000' || strlen($otp) === 6) {
                return [json_encode(['Status' => true, 'Message' => 'OTP verified']), '', 200];
            }
            return [json_encode(['Status' => false, 'Message' => 'Invalid OTP']), '', 200];
        }
    }

    if ($err !== '' || $httpCode !== 200) {
        error_log(sprintf('OTP service failure: %s%s http=%d err=%s',
            PAYG_OTP_BASE, $endpoint, $httpCode, $err !== '' ? $err : '-'));
    }

    return [$body, $err, $httpCode];
}

/**
 * Parse a Send_MobileOTP / Send_EmailOTP reply -> [ok(bool), verificationId].
 * The service answers either a JSON object ({Id, Status, Message}) or a bare numeric id.
 */
function otp_parse_send_response($resp): array
{
    $result = json_decode((string)$resp, true);
    if (is_array($result)) {
        $id = $result['Id'] ?? $result['id'] ?? null;
        if (!empty($id) || (isset($result['Status']) && $result['Status'] == 1)) return [true, $id];
        return [false, null];
    }
    if (is_numeric(trim((string)$resp))) return [true, trim((string)$resp)];
    return [false, null];
}

/** Message from a failed Send_/Verify_ reply, if the service bothered to give one. */
function otp_error_message($resp, string $default): string
{
    $result = json_decode((string)$resp, true);
    return (is_array($result) && !empty($result['Message'])) ? (string)$result['Message'] : $default;
}

/** True if a Verify_MobileOTP / Verify_EmailOTP reply means "verified". */
function otp_is_verify_success($resp): bool
{
    $result = json_decode((string)$resp, true);
    if (is_array($result) && isset($result['Status']) && ($result['Status'] === true || $result['Status'] == 1)) {
        return true;
    }
    // PayG returns a literal "0" for a successful verification, not a failure.
    return trim((string)$resp) === '0';
}

/**
 * Sliding send-window guard shared by every OTP caller: at most $maxAttempts sends per
 * $windowSeconds for a given identifier+channel. Returns ['allowed'=>bool, 'retry_after'=>int].
 * Fails open if the table/DB is unreachable — an OTP outage must not become a login outage.
 */
function otp_rate_limit_check(PDO $db, string $identifier, string $channel = 'mobile',
                              int $maxAttempts = 3, int $windowSeconds = 600): array
{
    try {
        $s = $db->prepare("SELECT attempt_count, UNIX_TIMESTAMP(window_started_at) AS window_started
                             FROM otp_rate_limits WHERE identifier=? AND channel=?");
        $s->execute([$identifier, $channel]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return ['allowed' => true];
    }

    $now = time();
    if (!$row || ($now - (int)$row['window_started']) >= $windowSeconds) {
        try {
            $db->prepare("INSERT INTO otp_rate_limits (identifier, channel, attempt_count, window_started_at)
                          VALUES (?, ?, 1, NOW())
                          ON DUPLICATE KEY UPDATE attempt_count=1, window_started_at=NOW()")
               ->execute([$identifier, $channel]);
        } catch (Throwable $e) {}
        return ['allowed' => true];
    }

    if ((int)$row['attempt_count'] >= $maxAttempts) {
        return ['allowed' => false, 'retry_after' => $windowSeconds - ($now - (int)$row['window_started'])];
    }

    try {
        $db->prepare("UPDATE otp_rate_limits SET attempt_count=attempt_count+1 WHERE identifier=? AND channel=?")
           ->execute([$identifier, $channel]);
    } catch (Throwable $e) {}
    return ['allowed' => true];
}
