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

/**
 * Base URL of PayG's verification service.
 *
 * NOTE: this is the *dev* host — it is what the chatbot widget has always used. Point it at
 * the production host before go-live (define PAYG_OTP_BASE in config/app.php to override).
 */
if (!defined('PAYG_OTP_BASE')) {
    define('PAYG_OTP_BASE', 'https://apiv3.PayG.in/virtualPOS/api/WebSite/');
}

/** POST a payload to a PayG verification endpoint. Returns [rawBody, curlError, httpStatus]. */
function otp_call_payg(string $endpoint, array $payload, int $timeout = 20): array
{
    $ch = curl_init(PAYG_OTP_BASE . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => $timeout,
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
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
