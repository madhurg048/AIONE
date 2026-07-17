<?php
/**
 * Central mailer for the CRM — sends app notifications through the account configured
 * in Settings → Email (SMTP / Microsoft 365-Exchange / IMAP-POP account).
 * Dependency-free: native SMTP over sockets with AUTH LOGIN + SSL/STARTTLS.
 *
 *   sendCrmEmail($to, $toName, $subject, $htmlBody, $textBody = '') : bool
 *   crm_email_config() : array|null
 *   crm_email_send_test($to) : array   // ['ok'=>bool, 'message'=>string]
 */
require_once __DIR__ . '/../config/database.php';

function crm_email_config(): ?array
{
    try {
        $db = getDB();
        if (!$db) return null;
        $r = $db->query("SELECT * FROM email_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    } catch (Throwable $e) { return null; }
}

/** Read one SMTP reply (handles multi-line). Returns [code, text]. */
function _smtp_read($fp): array
{
    $data = '';
    while (($line = fgets($fp, 515)) !== false) {
        $data .= $line;
        // last line has a space at position 3 (e.g. "250 OK"), continuation uses "250-"
        if (isset($line[3]) && $line[3] === ' ') break;
    }
    return [(int)substr($data, 0, 3), trim($data)];
}

function _smtp_cmd($fp, string $cmd, int $expect, string &$err): bool
{
    fwrite($fp, $cmd . "\r\n");
    [$code, $text] = _smtp_read($fp);
    if ($code !== $expect) { $err = "SMTP: expected $expect, got $text"; return false; }
    return true;
}

/**
 * Low-level SMTP send. Returns [ok(bool), message(string)].
 */
function crm_smtp_send(array $cfg, string $to, string $toName, string $subject, string $html, string $text = ''): array
{
    $host = trim($cfg['smtp_host'] ?? '');
    $port = (int)($cfg['smtp_port'] ?? 587);
    $enc  = $cfg['encryption'] ?? 'tls';
    $user = $cfg['username'] ?? '';
    $pass = $cfg['password'] ?? '';
    $fromEmail = $cfg['from_email'] ?: $user;
    $fromName  = $cfg['from_name'] ?: 'PayG CRM';
    if ($host === '' || $fromEmail === '') return [false, 'Email not configured (host / from address missing)'];

    $remote = ($enc === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) return [false, "Cannot connect to $host:$port — $errstr"];
    stream_set_timeout($fp, 15);

    $err = '';
    try {
        [$code] = _smtp_read($fp);                                   // 220 greeting
        if ($code !== 220) throw new Exception('No 220 greeting');
        $ehlo = 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost');
        if (!_smtp_cmd($fp, $ehlo, 250, $err)) throw new Exception($err);

        if ($enc === 'tls') {
            if (!_smtp_cmd($fp, 'STARTTLS', 220, $err)) throw new Exception($err);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT
                    | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT))
                throw new Exception('TLS negotiation failed');
            if (!_smtp_cmd($fp, $ehlo, 250, $err)) throw new Exception($err);
        }

        if ($user !== '') {
            if (!_smtp_cmd($fp, 'AUTH LOGIN', 334, $err)) throw new Exception($err);
            if (!_smtp_cmd($fp, base64_encode($user), 334, $err)) throw new Exception('Auth (user) rejected');
            if (!_smtp_cmd($fp, base64_encode($pass), 235, $err)) throw new Exception('Auth failed — check username/password');
        }

        if (!_smtp_cmd($fp, "MAIL FROM:<$fromEmail>", 250, $err)) throw new Exception($err);
        if (!_smtp_cmd($fp, "RCPT TO:<$to>", 250, $err) && !_smtp_cmd($fp, "RCPT TO:<$to>", 251, $err)) throw new Exception($err);
        if (!_smtp_cmd($fp, 'DATA', 354, $err)) throw new Exception($err);

        $boundary = 'b' . md5(uniqid('', true));
        $text = $text ?: trim(strip_tags($html));
        $headers  = 'From: ' . _mime_name($fromName) . " <$fromEmail>\r\n";
        $headers .= 'To: ' . ($toName ? _mime_name($toName) . " <$to>" : "<$to>") . "\r\n";
        $headers .= 'Subject: ' . _mime_name($subject) . "\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";
        $body  = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n$text\r\n";
        $body .= "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n$html\r\n";
        $body .= "--$boundary--\r\n";
        // dot-stuffing
        $data = preg_replace('/^\./m', '..', $headers . "\r\n" . $body);
        fwrite($fp, $data . "\r\n.\r\n");
        [$code, $t] = _smtp_read($fp);
        if ($code !== 250) throw new Exception("Message not accepted: $t");

        @fwrite($fp, "QUIT\r\n");
        fclose($fp);
        return [true, 'Sent'];
    } catch (Throwable $e) {
        @fclose($fp);
        return [false, $e->getMessage()];
    }
}

function _mime_name(string $s): string
{
    return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
}

/** App-wide entry point used by automations / notifications. Never throws. */
function sendCrmEmail(string $to, string $toName, string $subject, string $html, string $text = ''): bool
{
    $cfg = crm_email_config();
    if (!$cfg || empty($cfg['is_active'])) { error_log('sendCrmEmail: email not configured/active'); return false; }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    [$ok, $msg] = crm_smtp_send($cfg, $to, $toName, $subject, $html, $text);
    if (!$ok) error_log('sendCrmEmail failed: ' . $msg);
    return $ok;
}

function crm_email_send_test(string $to): array
{
    $cfg = crm_email_config();
    if (!$cfg) return ['ok' => false, 'message' => 'No configuration saved'];
    [$ok, $msg] = crm_smtp_send($cfg, $to, 'Test Recipient',
        'PayG CRM — test email',
        '<p>✅ This is a <b>test email</b> from your PayG CRM.</p><p>If you received this, notifications are configured correctly.</p>');
    return ['ok' => $ok, 'message' => $msg];
}
