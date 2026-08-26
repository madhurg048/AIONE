<?php
/**
 * Record a merchant transaction (called by the payment-gateway / switch).
 *   POST /api/integrations/transaction.php   Header: X-API-Key: <token>
 *   Body: { merchant_code | merchant_id, txn_ref, amount, currency?, status?, txn_date?, product? }
 * Updates the merchant's actual monthly volume + last transaction time.
 */
require_once __DIR__ . '/_auth.php';
$db = idb();
integration_auth($db, 'transaction');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') ifail('POST required', 405);
$b = ibody();
$m = resolve_merchant($db, $b);
if (!$m) ifail('Unknown merchant (send merchant_code or merchant_id)', 404);
if (empty($b['txn_ref']) || !isset($b['amount'])) ifail('txn_ref and amount required');

$txnDate = !empty($b['txn_date']) ? date('Y-m-d H:i:s', strtotime($b['txn_date'])) : date('Y-m-d H:i:s');
try {
    $db->prepare("INSERT INTO merchant_transactions (merchant_id,txn_ref,amount,currency,status,txn_date,product,source)
                  VALUES (?,?,?,?,?,?,?,?)")
       ->execute([$m['id'], $b['txn_ref'], (float)$b['amount'], $b['currency'] ?? 'INR',
                  $b['status'] ?? 'success', $txnDate, $b['product'] ?? null, $b['source'] ?? 'pg']);
} catch (Throwable $e) {
    if (preg_match('/Duplicate/i', $e->getMessage())) ifail('Duplicate txn_ref (already recorded)', 409);
    ifail('insert failed', 500);
}

// refresh this month's actual volume (successful txns only)
$vol = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM merchant_transactions
                     WHERE merchant_id=? AND status='success'
                       AND DATE_FORMAT(txn_date,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m')");
$vol->execute([$m['id']]);
$monthVol = (float)$vol->fetchColumn();
$db->prepare("UPDATE merchants SET actual_monthly_volume=?, last_txn_at=? WHERE id=?")
   ->execute([$monthVol, $txnDate, $m['id']]);

iok(['merchant_id'=>(int)$m['id'], 'month_volume'=>$monthVol], ['message'=>'transaction recorded']);
