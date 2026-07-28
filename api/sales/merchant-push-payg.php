<?php
/**
 * Manually push a PIMS merchant to the external PayG Merchant Onboarding API.
 *   POST merchant-push-payg.php { merchant_id }
 * Rep-triggered from the merchant detail page — requires legal_name (-> CompanyName) and
 * a contact mobile number (-> MobileNo), PayG's only two mandatory fields. Both are normally
 * populated already at lead-convert time.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_payg_merchant_api.php';
require_login();
$db = db();
if (method() !== 'POST') fail('POST required', 405);

$b = body();
$id = (int)($b['merchant_id'] ?? 0);
if (!$id) fail('merchant_id required');

$s = $db->prepare("SELECT m.*, e.full_name AS owner_name FROM merchants m
                   LEFT JOIN employees e ON e.id=m.owner_id WHERE m.id=?");
$s->execute([$id]);
$merchant = $s->fetch();
if (!$merchant) fail('Merchant not found', 404);
assert_can_see($merchant['owner_id']);

$contact = ['first_name' => '', 'last_name' => '', 'email' => null, 'mobile' => null, 'phone' => null];
if ($merchant['contact_id']) {
    $c = $db->prepare("SELECT first_name,last_name,email,mobile,phone FROM contacts WHERE id=?");
    $c->execute([$merchant['contact_id']]);
    $contact = $c->fetch() ?: $contact;
}
$account = ['account_name' => null, 'website' => null];
if ($merchant['account_id']) {
    $a = $db->prepare("SELECT account_name,website FROM accounts WHERE id=?");
    $a->execute([$merchant['account_id']]);
    $account = $a->fetch() ?: $account;
}

if (empty($merchant['legal_name'])) fail('Merchant company name is required before pushing to PayG');
if (empty($contact['mobile']) && empty($contact['phone']) && empty($merchant['phone'])) fail('Add a contact mobile number before pushing to PayG');

[$success, $paygId, $error] = payg_push_merchant($merchant, $contact, $account);

if ($success) {
    $db->prepare("UPDATE merchants SET payg_merchant_id=?, payg_push_status='pushed',
                  payg_pushed_at=NOW(), payg_push_error=NULL WHERE id=?")
       ->execute([$paygId, $id]);
    ok(['merchant_id' => $id, 'payg_merchant_id' => $paygId], ['message' => 'Pushed to PayG onboarding']);
} else {
    $db->prepare("UPDATE merchants SET payg_push_status='failed', payg_push_error=? WHERE id=?")
       ->execute([$error, $id]);
    fail($error ?: 'PayG push failed', 502);
}
