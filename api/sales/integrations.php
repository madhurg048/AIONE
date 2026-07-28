<?php
/**
 * Admin view of the integration layer (session, admin-only).
 *   GET  integrations.php            -> { tokens, recent_transactions, recent_tickets, catalog }
 *   POST integrations.php?regenerate=ID  -> rotate a token
 *   POST integrations.php?token {label} -> create a token
 */
require_once __DIR__ . '/_bootstrap.php';
require_login();
if (($_SESSION['user']['user_type'] ?? '') !== 'admin') fail('Admin only', 403);
$db = db();

function gen_token(): string { return 'payg_live_' . bin2hex(random_bytes(16)); }

if (method() === 'POST' && isset($_GET['regenerate'])) {
    $t = gen_token();
    $db->prepare("UPDATE integration_tokens SET token=? WHERE id=?")->execute([$t, (int)$_GET['regenerate']]);
    ok(['token' => $t]);
}
if (method() === 'POST' && isset($_GET['token'])) {
    $b = body();
    $t = gen_token();
    $db->prepare("INSERT INTO integration_tokens (label,token) VALUES (?,?)")->execute([$b['label'] ?? 'API key', $t]);
    ok(['id' => (int)$db->lastInsertId(), 'token' => $t]);
}

if (method() === 'GET') {
    $tokens = $db->query("SELECT id,label,token,scopes,is_active,last_used_at FROM integration_tokens ORDER BY id")->fetchAll();
    $txns = $db->query("SELECT t.*, m.legal_name, m.merchant_code FROM merchant_transactions t
                        LEFT JOIN merchants m ON m.id=t.merchant_id ORDER BY t.id DESC LIMIT 25")->fetchAll();
    $tix = $db->query("SELECT c.*, m.merchant_code FROM crm_tickets c
                       LEFT JOIN merchants m ON m.id=c.merchant_id ORDER BY c.updated_at DESC LIMIT 25")->fetchAll();
    $catalog = [
        [
            'name' => 'Record a transaction',
            'method' => 'POST', 'path' => 'api/integrations/transaction.php',
            'desc' => 'Called by the payment gateway to record each merchant transaction (drives actual volume + volume-based incentives).',
            'body' => ['merchant_code' => 'PAYG2507xxxxx', 'txn_ref' => 'TXN12345', 'amount' => 4999.00, 'currency' => 'INR', 'status' => 'success', 'product' => 'upi', 'txn_date' => '2026-07-02 14:30:00'],
        ],
        [
            'name' => 'Merchant confirmation (from PIMS)',
            'method' => 'POST', 'path' => 'api/integrations/merchant-confirm.php',
            'desc' => 'External PIMS confirms onboarding/KYC. If activated + KYC verified, the CRM activates the merchant and books the sales incentive.',
            'body' => ['merchant_code' => 'PAYG2507xxxxx', 'onboarding_stage' => 'activated', 'kyc_status' => 'verified', 'mid' => 'REALMID999', 'risk_rating' => 'low'],
        ],
        [
            'name' => 'ITSM ticket status update',
            'method' => 'POST', 'path' => 'api/integrations/ticket-update.php',
            'desc' => 'ITSM pushes ticket status so sales can see if a merchant issue is open/closed, who owns it, and who it is waiting on.',
            'body' => ['ticket_ref' => 'INC0012345', 'status' => 'waiting_customer', 'assigned_to' => 'Support L2 - Rao', 'waiting_on' => 'customer', 'priority' => 'high', 'subject' => 'MDR clarification', 'merchant_code' => 'PAYG2507xxxxx', 'note' => 'Awaiting signed addendum'],
        ],
    ];
    ok(['tokens' => $tokens, 'recent_transactions' => $txns, 'recent_tickets' => $tix, 'catalog' => $catalog]);
}

fail('Unsupported', 405);
