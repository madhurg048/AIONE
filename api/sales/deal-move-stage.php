<?php
/**
 * Move a deal to a new stage (Kanban drag-drop).
 * When the target stage is a "won" stage, the funnel closes:
 *   -> a Merchant (PIMS) record is auto-created (onboarding_stage=created)
 *   -> incentives are booked to the owner ONLY when the merchant is later
 *      activated (see merchants.php). Here we just create the merchant shell.
 *
 *   POST deal-move-stage.php { deal_id, to_stage_id }
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_automation_engine.php';
require_login();
$db = db();
if (method() !== 'POST') fail('POST required', 405);

$b = body();
$dealId = (int)($b['deal_id'] ?? 0);
$toStage = (int)($b['to_stage_id'] ?? 0);
if (!$dealId || !$toStage) fail('deal_id and to_stage_id required');

$s = $db->prepare("SELECT * FROM deals WHERE id=?");
$s->execute([$dealId]);
$deal = $s->fetch();
if (!$deal) fail('Deal not found', 404);
assert_can_see($deal['owner_id']);   // reps can only move their own deals

$s = $db->prepare("SELECT * FROM deal_stages WHERE id=?");
$s->execute([$toStage]);
$stage = $s->fetch();
if (!$stage) fail('Stage not found', 404);

try {
    $db->beginTransaction();

    $fromStage = (int)$deal['stage_id'];
    $closedAt  = in_array($stage['stage_type'], ['won','lost'], true) ? date('Y-m-d H:i:s') : null;

    $db->prepare("UPDATE deals SET stage_id=?, probability=?, closed_at=? WHERE id=?")
       ->execute([$toStage, (int)$stage['probability'], $closedAt, $dealId]);

    $db->prepare("INSERT INTO deal_stage_history (deal_id,from_stage_id,to_stage_id,changed_by)
                  VALUES (?,?,?,?)")
       ->execute([$dealId, $fromStage, $toStage, current_employee_id() ?: null]);

    $merchantId = null;
    if ($stage['stage_type'] === 'won') {
        // create merchant shell if one doesn't already exist for this deal
        $chk = $db->prepare("SELECT id FROM merchants WHERE deal_id=? LIMIT 1");
        $chk->execute([$dealId]);
        $merchantId = $chk->fetchColumn() ?: null;
        if (!$merchantId) {
            $acc = $db->prepare("SELECT account_name FROM accounts WHERE id=?");
            $acc->execute([$deal['account_id']]);
            $legal = $acc->fetchColumn() ?: $deal['deal_name'];
            $code = 'PAYG' . date('ym') . str_pad((string)random_int(1, 99999), 5, '0', STR_PAD_LEFT);

            // trace the originating lead (for source ROI attribution)
            $srcLead = $db->prepare("SELECT id FROM leads WHERE converted_deal_id=? LIMIT 1");
            $srcLead->execute([$dealId]);
            $srcLeadId = $srcLead->fetchColumn() ?: null;

            $ins = $db->prepare("INSERT INTO merchants
                (merchant_code,legal_name,account_id,contact_id,deal_id,source_lead_id,owner_id,
                 expected_monthly_volume,onboarding_stage,kyc_status,created_by)
                VALUES (?,?,?,?,?,?,?,?, 'created','pending', ?)");
            $ins->execute([
                $code, $legal, $deal['account_id'], $deal['contact_id'], $dealId, $srcLeadId,
                $deal['owner_id'], (float)$deal['amount'], current_employee_id() ?: null
            ]);
            $merchantId = (int)$db->lastInsertId();
        }
    }

    $db->commit();

    // Fire automations after the transaction commits (non-fatal).
    $dealRec = array_merge($deal, ['stage_id' => $toStage, 'stage_name' => $stage['name'],
                                   'stage_type' => $stage['stage_type']]);
    $firedDeal = run_automations($db, 'deal', 'on_stage_change', $dealRec);
    $firedMerchant = [];
    if ($merchantId) {
        $mr = $db->prepare("SELECT * FROM merchants WHERE id=?");
        $mr->execute([$merchantId]);
        $firedMerchant = run_automations($db, 'merchant', 'on_create', $mr->fetch() ?: []);
    }

    ok([
        'deal_id' => $dealId,
        'automations' => array_merge($firedDeal, $firedMerchant),
        'stage'   => $stage['name'],
        'stage_type' => $stage['stage_type'],
        'merchant_id' => $merchantId ? (int)$merchantId : null,
        'message' => $stage['stage_type'] === 'won'
            ? 'Deal won — merchant created in PIMS (complete KYC to activate & release incentive)'
            : null
    ]);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    fail('Move failed: ' . $e->getMessage(), 500);
}
