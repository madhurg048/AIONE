<?php
/**
 * Self-service password reset for CRM logins, verified by an OTP sent to the contact
 * details already on the user's employee record (employees.email / employees.phone,
 * joined via sales_users.employee_id). A user can only reset to a destination that is
 * already on file — nobody gets to nominate a new address here.
 *
 *   POST forgot-password.php?action=request {username, channel: email|mobile, destination}
 *   POST forgot-password.php?action=verify  {otp}
 *   POST forgot-password.php?action=reset   {password}
 *
 * The account being reset is fixed server-side in $_SESSION['pwreset'] at step 1 and never
 * re-read from the request afterwards, so steps 2 and 3 cannot be pointed at another user.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_otp.php';

/**
 * OTP "Type" the PayG service files these under. 15 is the chatbot widget's type and the only
 * value proven against the service; password reset should get its own type/SMS template from
 * the PayG API team so the message text reads like a password reset rather than a chat verify.
 */
const PWRESET_OTP_TYPE = 15;

const PWRESET_OTP_TTL       = 600;   // OTP is good for 10 minutes from the send
const PWRESET_VERIFY_TTL    = 600;   // ...and 10 more from verify to submitting a new password
const PWRESET_MAX_OTP_TRIES = 5;     // wrong-OTP guesses before the request is torn down

/**
 * One message for "no such user", "user has no contact on file" and "the address you typed
 * isn't the one on record" alike — an attacker must not be able to tell valid usernames or
 * registered addresses apart by reading the response.
 */
const PWRESET_GENERIC = 'If that account exists and the details match, an OTP has been sent. '
    . 'Please make sure the email / mobile you entered is the one registered on your account.';

$db = db();
if (method() !== 'POST') fail('POST required', 405);

$action = $_GET['action'] ?? '';
$b = body();

function pwreset_clear(): void
{
    unset($_SESSION['pwreset']);
}

/** Last 10 digits, so "+91 95955 92088" and "9595592088" compare equal. */
function pwreset_norm_mobile(string $raw): string
{
    $d = preg_replace('/\D+/', '', $raw) ?? '';
    return strlen($d) > 10 ? substr($d, -10) : $d;
}

/* ---- step 1: identify the account + send an OTP to what's on file ---- */
if ($action === 'request') {
    $username = trim((string)($b['username'] ?? ''));
    $channel  = (($b['channel'] ?? '') === 'mobile') ? 'mobile' : 'email';
    $dest     = trim((string)($b['destination'] ?? ''));
    if ($username === '' || $dest === '') {
        fail('Enter your username and your registered ' . ($channel === 'mobile' ? 'mobile number' : 'email address'));
    }

    pwreset_clear();

    // Rate-limit on the username whether or not it resolves, so the limit itself can't be
    // used to probe which usernames are real.
    $rl = otp_rate_limit_check($db, 'pwreset:' . strtolower($username), $channel);
    if (!$rl['allowed']) {
        $mins = (int)ceil($rl['retry_after'] / 60);
        fail("Too many OTP requests for this account. Please try again after {$mins} minute(s).", 429);
    }

    $s = $db->prepare("SELECT u.id, e.email, e.phone
                         FROM sales_users u
                    LEFT JOIN employees e ON e.id = u.employee_id
                        WHERE u.username = ? AND u.is_active = 1");
    $s->execute([$username]);
    $user = $s->fetch();

    $sendTo = '';
    if ($user) {
        if ($channel === 'email') {
            $onFile = trim((string)($user['email'] ?? ''));
            if ($onFile !== '' && filter_var($onFile, FILTER_VALIDATE_EMAIL) && strcasecmp($onFile, $dest) === 0) {
                $sendTo = $onFile;
            }
        } else {
            $onFile = pwreset_norm_mobile((string)($user['phone'] ?? ''));
            if (preg_match('/^[6-9]\d{9}$/', $onFile) && $onFile === pwreset_norm_mobile($dest)) {
                $sendTo = $onFile;
            }
        }
    }
    // No match: answer exactly as though one had been sent.
    if ($sendTo === '') ok(['sent' => true, 'message' => PWRESET_GENERIC]);

    [$resp, $err, $code] = $channel === 'email'
        ? otp_call_payg('Send_EmailOTP',  ['Email' => $sendTo, 'Type' => PWRESET_OTP_TYPE], 15)
        : otp_call_payg('Send_MobileOTP', ['MobileNumber' => $sendTo, 'Type' => PWRESET_OTP_TYPE], 30);
    if ($err !== '' || $code !== 200) fail('The OTP service is unreachable right now. Please try again in a minute.', 502);

    [$sentOk, $otpId] = otp_parse_send_response($resp);
    if (!$sentOk) fail(otp_error_message($resp, 'Could not send the OTP. Please try again in a minute.'), 502);

    $_SESSION['pwreset'] = [
        'user_id'     => (int)$user['id'],
        'channel'     => $channel,
        'destination' => $sendTo,
        'otp_id'      => $otpId,
        'tries'       => 0,
        'sent_at'     => time(),
        'verified_at' => null,
    ];
    ok(['sent' => true, 'message' => PWRESET_GENERIC]);
}

/* ---- step 2: check the OTP ---- */
if ($action === 'verify') {
    $st = $_SESSION['pwreset'] ?? null;
    if (!$st) fail('No OTP request in progress. Please start again.', 400);
    if (time() - (int)$st['sent_at'] > PWRESET_OTP_TTL) {
        pwreset_clear();
        fail('That OTP has expired. Please request a new one.', 400);
    }
    if ((int)$st['tries'] >= PWRESET_MAX_OTP_TRIES) {
        pwreset_clear();
        fail('Too many incorrect attempts. Please request a new OTP.', 429);
    }
    $otp = trim((string)($b['otp'] ?? ''));
    if ($otp === '') fail('Enter the OTP you received');
    $_SESSION['pwreset']['tries'] = (int)$st['tries'] + 1;

    [$resp, $err, $code] = $st['channel'] === 'email'
        ? otp_call_payg('Verify_EmailOTP', ['Email' => $st['destination'], 'Type' => PWRESET_OTP_TYPE,
                                            'ConfirmOtp' => $otp, 'Id' => $st['otp_id']], 15)
        : otp_call_payg('Verify_MobileOTP', ['MobileNumber' => $st['destination'], 'Type' => PWRESET_OTP_TYPE,
                                             'ConfirmOtp' => $otp, 'Id' => (int)$st['otp_id']], 20);
    if ($err !== '' || $code !== 200) fail('The OTP service is unreachable right now. Please try again in a minute.', 502);
    if (!otp_is_verify_success($resp)) fail('That OTP is not correct. Please check and try again.', 400);

    $_SESSION['pwreset']['verified_at'] = time();
    ok(['verified' => true]);
}

/* ---- step 3: set the new password ---- */
if ($action === 'reset') {
    $st = $_SESSION['pwreset'] ?? null;
    if (!$st || empty($st['verified_at'])) fail('Verify the OTP before setting a new password.', 403);
    if (time() - (int)$st['verified_at'] > PWRESET_VERIFY_TTL) {
        pwreset_clear();
        fail('This reset has timed out. Please start again.', 403);
    }

    $pw = (string)($b['password'] ?? '');
    if (strlen($pw) < 8) fail('Password must be at least 8 characters');
    if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) {
        fail('Password must contain at least one letter and one number');
    }

    $upd = $db->prepare("UPDATE sales_users SET password_hash=? WHERE id=? AND is_active=1");
    $upd->execute([password_hash($pw, PASSWORD_DEFAULT), (int)$st['user_id']]);
    if ($upd->rowCount() === 0) {
        pwreset_clear();
        fail('Could not update the password. Please start again.', 400);
    }

    // A completed reset also clears the failed-login lockout — otherwise someone who reset
    // *because* they got locked out still can't sign in — and the OTP send window.
    try {
        $s = $db->prepare("SELECT username FROM sales_users WHERE id=?");
        $s->execute([(int)$st['user_id']]);
        $uname = (string)$s->fetchColumn();
        $db->prepare("DELETE FROM auth_login_attempts WHERE username=?")->execute([$uname]);
        $db->prepare("DELETE FROM otp_rate_limits WHERE identifier=?")->execute(['pwreset:' . strtolower($uname)]);
    } catch (Throwable $e) { /* lockout tables are best-effort; the password did change */ }

    pwreset_clear();
    session_regenerate_id(true);        // reset does not log anyone in — sign in with the new password
    payg_rotate_csrf_token();
    ok(['reset' => true, 'csrf' => payg_csrf_token()]);
}

fail('Unknown action', 400);
