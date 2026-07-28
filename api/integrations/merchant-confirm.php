<?php
/**
 * Merchant confirmation from an external PIMS.
 *   POST /api/integrations/merchant-confirm.php   Header: X-API-Key
 *   Body: { merchant_code | merchant_id, onboarding_stage?, kyc_status?, mid?, risk_rating?, remarks? }
 * If it reports the merchant activated + KYC verified, we activate + book the incentive.
 */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../sales/_incentive_engine.php';
require_once __DIR__ . '/../sales/_task_events.php';
$db = idb();
integration_auth($db, 'merchant_confirm');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') ifail('POST required', 405);
$b = ibody();
$m = resolve_merchant($db, $b);
if (!$m) ifail('Unknown merchant', 404);

$set = []; $args = [];
foreach (['onboarding_stage','kyc_status','risk_rating'] as $f) if (!empty($b[$f])) { $set[] = "$f=?"; $args[] = $b[$f]; }
if (!empty($b['mid'])) { $set[] = "merchant_code=?"; $args[] = $b['mid']; }
if ($set) { $args[] = $m['id']; $db->prepare("UPDATE merchants SET " . implode(',', $set) . " WHERE id=?")->execute($args); }

$booked = 0;
$stage = $b['onboarding_stage'] ?? $m['onboarding_stage'];
$kyc   = $b['kyc_status'] ?? $m['kyc_status'];
if ($stage === 'activated' && $kyc === 'verified') {
    $db->prepare("UPDATE merchants SET activated_at=COALESCE(activated_at,NOW()), go_live_at=COALESCE(go_live_at,NOW()) WHERE id=?")->execute([$m['id']]);
    $m2 = $db->prepare("SELECT * FROM merchants WHERE id=?"); $m2->execute([$m['id']]);
    $booked = incentive_book($db, $m2->fetch(PDO::FETCH_ASSOC));
}

// system event -> auto task for the merchant owner (KYC / rejection / activation)
$mc = $db->prepare("SELECT * FROM merchants WHERE id=?"); $mc->execute([$m['id']]);
on_merchant_confirm($db, $mc->fetch(PDO::FETCH_ASSOC) ?: []);

iok(['merchant_id'=>(int)$m['id'], 'incentive_booked'=>$booked], ['message'=>'merchant updated from PIMS']);
