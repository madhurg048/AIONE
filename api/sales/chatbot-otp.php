<?php
/**
 * OTP send/verify for the public chatbot widget (public/chatbot/chat.php).
 * Thin proxy to PayG's own verification API (devapi.payg.in) — there is no
 * local OTP secret here, this endpoint just relays to/from that service and
 * tracks per-mobile rate limiting + per-email OTP id in the PHP session.
 *
 *   POST chatbot-otp.php?action=mobile-send    {mobile}
 *   POST chatbot-otp.php?action=mobile-verify  {mobile, otp}
 *   POST chatbot-otp.php?action=email-send     {email}
 *   POST chatbot-otp.php?action=email-verify   {email, otp}
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/_otp.php';
payg_start_session();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'POST required']); exit; }

function otp_reply($data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

$action = $_GET['action'] ?? '';
$b = json_decode(file_get_contents('php://input'), true);
if (!is_array($b)) $b = $_POST;
$db = getDB();

if ($action === 'mobile-send') {
    $mobile = trim($b['mobile'] ?? '');
    if (!preg_match('/^[6-9]\d{9}$/', $mobile)) {
        otp_reply(['success' => false, 'message' => 'Invalid mobile number'], 400);
    }
    if ($db) {
        $rl = otp_rate_limit_check($db, $mobile, 'mobile');
        if (!$rl['allowed']) {
            $minutes = (int)ceil($rl['retry_after'] / 60);
            otp_reply(['success' => false, 'limited' => true, 'retry_after' => $rl['retry_after'],
                       'message' => "You have used the maximum number of OTP attempts. Please try again after {$minutes} minute(s)."], 429);
        }
    }
    [$resp, $err, $httpCode] = otp_call_payg('Send_MobileOTP', ['MobileNumber' => $mobile, 'Type' => 15], 30);
    if ($err !== '') otp_reply(['success' => false, 'message' => 'OTP service error: ' . $err], 500);
    if ($httpCode !== 200) otp_reply(['success' => false, 'message' => 'OTP API failed with status ' . $httpCode], 500);

    [$isSuccess, $resultId] = otp_parse_send_response($resp);
    if ($isSuccess) {
        $_SESSION['chatbot_mobile_otp_id'] = $resultId;
        $_SESSION['chatbot_mobile_number'] = $mobile;
        otp_reply(['success' => true, 'message' => 'OTP sent successfully', 'id' => $resultId]);
    }
    otp_reply(['success' => false, 'message' => 'OTP sending failed'], 500);
}

if ($action === 'mobile-verify') {
    $mobile = preg_replace('/\D/', '', $b['mobile'] ?? '');
    if (strlen($mobile) > 10) $mobile = substr($mobile, -10);
    $otp = trim($b['otp'] ?? '');
    $otpId = $_SESSION['chatbot_mobile_otp_id'] ?? ($b['id'] ?? 0);

    [$resp, $err, $httpCode] = otp_call_payg('Verify_MobileOTP', [
        'MobileNumber' => $mobile, 'Type' => 15, 'ConfirmOtp' => $otp, 'Id' => (int)$otpId,
    ]);
    if ($err === '' && $httpCode === 200 && otp_is_verify_success($resp)) {
        $_SESSION['chatbot_mobile_verified'] = true;
        otp_reply(['success' => true, 'message' => 'OTP verified successfully']);
    }
    otp_reply(['success' => false, 'message' => 'OTP verification failed'], 400);
}

if ($action === 'email-send') {
    $email = trim((string)($b['email'] ?? ''));
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        otp_reply(['success' => false, 'message' => 'Invalid email address'], 400);
    }
    [$resp, $err, $httpCode] = otp_call_payg('Send_EmailOTP', ['Email' => $email, 'Type' => 15], 15);
    if ($err !== '' || $httpCode < 200 || $httpCode >= 300) {
        otp_reply(['success' => false, 'message' => 'PayG API communication error: ' . $err], 500);
    }
    [$isSuccess, $resultId] = otp_parse_send_response($resp);
    if ($isSuccess) {
        $_SESSION['chatbot_email_otp_id'][$email] = $resultId;
        otp_reply(['success' => true, 'message' => 'Email OTP sent successfully', 'id' => $resultId]);
    }
    otp_reply(['success' => false, 'message' => otp_error_message($resp, 'Failed to send Email OTP')], 400);
}

if ($action === 'email-verify') {
    $email = trim($b['email'] ?? '');
    $otp = trim($b['otp'] ?? '');
    $id = trim((string)($b['id'] ?? ($_SESSION['chatbot_email_otp_id'][$email] ?? '')));
    if (!$email || !$otp) otp_reply(['success' => false, 'message' => 'Missing email or OTP'], 400);
    if (!$id) otp_reply(['success' => false, 'message' => 'Missing verification ID'], 400);

    [$resp, $err, $httpCode] = otp_call_payg('Verify_EmailOTP', [
        'Email' => $email, 'Type' => 15, 'ConfirmOtp' => $otp, 'Id' => $id,
    ], 15);
    if ($err !== '' || $httpCode < 200 || $httpCode >= 300) {
        otp_reply(['success' => false, 'message' => 'PayG API communication error: ' . $err], 500);
    }
    if (otp_is_verify_success($resp)) {
        unset($_SESSION['chatbot_email_otp_id'][$email]);
        otp_reply(['success' => true, 'message' => 'Email OTP verified successfully']);
    }
    otp_reply(['success' => false, 'message' => otp_error_message($resp, 'Email verification failed')], 400);
}

otp_reply(['success' => false, 'message' => 'Unknown action'], 400);
