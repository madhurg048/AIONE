<?php
/**
 * PayG Merchant Onboarding API (external PIMS).
 *   payg_push_merchant($merchant, $contact, $account) : [ok(bool), payg_merchant_id|null, error|null]
 * Called manually from merchant-push-payg.php once a rep confirms the merchant's contact
 * details are correct — never fired automatically off deal-move-stage.
 *
 * Auth is a two-step OAuth2 client-credentials flow:
 *   1) POST GetAuthToken (multipart/form-data — the API 400s on JSON/urlencoded bodies)
 *      with clientid/clientsecret/scope -> short-lived Bearer access_token (~30 min).
 *   2) POST MerchantOnboarding with that token as "Authorization: Bearer <token>".
 */
if (!defined('PAYG_MERCHANT_API_URL'))  define('PAYG_MERCHANT_API_URL', 'https://devapi.payg.in/merchant/api/Merchantlevel/MerchantOnboarding');
if (!defined('PAYG_TOKEN_API_URL'))     define('PAYG_TOKEN_API_URL', 'https://devapi.payg.in/merchant/api/Merchantlevel/GetAuthToken');
if (!defined('PAYG_CLIENT_ID'))         define('PAYG_CLIENT_ID', 'payg.xsilica@paygdigitals.com');
if (!defined('PAYG_CLIENT_SECRET'))     define('PAYG_CLIENT_SECRET', 'payg.xsilica@1234');
if (!defined('PAYG_CLIENT_SCOPE'))      define('PAYG_CLIENT_SCOPE', 'CRM:MerchantCreation');

/** Fetches a fresh Bearer access token. Returns null on failure (network/credentials). */
function payg_get_token(): ?string
{
    $ch = curl_init(PAYG_TOKEN_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            'granttype'    => 'client_credentials',
            'clientid'     => PAYG_CLIENT_ID,
            'clientsecret' => PAYG_CLIENT_SECRET,
            'scope'        => PAYG_CLIENT_SCOPE,
        ],
        CURLOPT_TIMEOUT        => 20,
    ]);
    $resp  = curl_exec($ch);
    $errno = curl_errno($ch);
    $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno || $code < 200 || $code >= 300) return null;
    $j = json_decode((string)$resp, true);
    return $j['access_token'] ?? null;
}

function payg_push_merchant(array $merchant, array $contact, array $account): array
{
    $token = payg_get_token();
    if (!$token) return [false, null, 'Could not obtain PayG auth token'];

    $name = trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? ''));
    $payload = [
        'CustomerName' => $name !== '' ? $name : $merchant['legal_name'],
        'CompanyName'  => $merchant['legal_name'] ?: ($account['account_name'] ?? ''),
        'MobileNo'     => $contact['mobile'] ?: $contact['phone'] ?: $merchant['phone'] ?: '',
        'Email'        => $contact['email'] ?: $merchant['email'] ?: '',
        'WebSite'      => $account['website'] ?: $merchant['website'] ?: '',
        'Source_Id'    => (string)($merchant['merchant_code'] ?: $merchant['id']),
        'Source_User'  => $merchant['owner_name'] ?? '',
        'Source'       => 'CRM',
    ];

    $ch = curl_init(PAYG_MERCHANT_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT        => 20,
    ]);
    $resp  = curl_exec($ch);
    $errno = curl_errno($ch);
    $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) return [false, null, 'Network error contacting PayG'];

    $j = json_decode((string)$resp, true);

    // Success shape: {"Message":"...","Success":true,"Data":{"MID":"...","MerchantKeyId":...}}
    if (is_array($j) && !array_is_list($j) && ($j['Success'] ?? false) === true) {
        $mid = $j['Data']['MID'] ?? null;
        return [true, $mid !== null ? (string)$mid : null, null];
    }

    // Error shape: a JSON array of objects, e.g. [{"Code":"7017","Message":"Mobile Number is already registered","FieldName":"Mobile Number"}]
    if (is_array($j) && array_is_list($j) && isset($j[0])) {
        $e = $j[0];
        $msg = $e['Message'] ?? $e['FieldName'] ?? 'PayG API error';
        return [false, null, $msg . (!empty($e['Code']) ? ' (code ' . $e['Code'] . ')' : '')];
    }

    if (is_array($j) && ($j['Success'] ?? true) === false) {
        return [false, null, $j['Message'] ?? ('PayG API error (HTTP ' . $code . ')')];
    }

    return [false, null, 'PayG API error (HTTP ' . $code . ')'];
}
