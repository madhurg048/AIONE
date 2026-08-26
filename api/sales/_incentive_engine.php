<?php
/**
 * Incentive engine — computes and books incentives when a merchant is activated.
 * Pure rule evaluation against the active incentive_plans / incentive_rules.
 */

/**
 * Evaluate all active rules for a merchant and return line items.
 * @return array{total: float, lines: array<array{amount:float,basis:string,rule_id:int,plan_id:int}>}
 */
function incentive_calculate(PDO $db, array $merchant): array
{
    // prefer ACTUAL transacted volume (fed by the transaction API); fall back to expected
    $volume = (float)($merchant['actual_monthly_volume'] ?? 0);
    if ($volume <= 0) $volume = (float)($merchant['expected_monthly_volume'] ?? 0);
    $dealAmount = 0.0;
    if (!empty($merchant['deal_id'])) {
        $s = $db->prepare("SELECT amount FROM deals WHERE id=?");
        $s->execute([$merchant['deal_id']]);
        $dealAmount = (float)$s->fetchColumn();
    } elseif (isset($GLOBALS['__preview_deal_amount'])) {
        $dealAmount = (float)$GLOBALS['__preview_deal_amount'];   // preview/dry-run
    }

    $plans = $db->query("SELECT * FROM incentive_plans
        WHERE is_active=1
          AND (effective_from IS NULL OR effective_from <= CURDATE())
          AND (effective_to   IS NULL OR effective_to   >= CURDATE())")->fetchAll();

    $lines = [];
    $total = 0.0;
    $mProducts = strtolower((string)($merchant['products'] ?? ''));
    $mCategory = strtolower((string)($merchant['category'] ?? ''));
    $mBizType  = strtolower((string)($merchant['business_type'] ?? ''));

    foreach ($plans as $plan) {
        $stack = !isset($plan['stack_rules']) || (int)$plan['stack_rules'] === 1; // sum vs highest-only
        $planLines = [];
        $rs = $db->prepare("SELECT * FROM incentive_rules WHERE plan_id=? AND is_active=1 ORDER BY priority ASC");
        $rs->execute([$plan['id']]);
        foreach ($rs->fetchAll() as $rule) {
            // ---- optional targeting filters ----
            // exact token match on the merchant's CSV products (so rule "card" ≠ merchant "gift_card")
            if (!empty($rule['product'])) {
                $mTokens = array_filter(array_map('trim', explode(',', $mProducts)));
                if (!in_array(strtolower($rule['product']), $mTokens, true)) continue;
            }
            if (!empty($rule['category']) && strtolower($rule['category']) !== $mCategory) continue;
            if (!empty($rule['business_type']) && strtolower($rule['business_type']) !== $mBizType) continue;

            $amount = 0.0; $basis = '';
            $tag = $rule['label'] ? ($rule['label'] . ': ') : '';
            switch ($rule['rule_type']) {
                case 'flat':
                    $amount = (float)$rule['flat_amount'];
                    $basis  = $tag . "Flat ₹" . number_format($amount) . " per activated merchant";
                    break;
                case 'percent':
                    $amount = round($dealAmount * (float)$rule['percent_rate'] / 100, 2);
                    $basis  = $tag . $rule['percent_rate'] . "% of deal ₹" . number_format($dealAmount);
                    break;
                case 'percent_volume':
                    $amount = round($volume * (float)$rule['percent_rate'] / 100, 2);
                    $basis  = $tag . $rule['percent_rate'] . "% of monthly volume ₹" . number_format($volume);
                    break;
                case 'slab_volume':
                    $min = (float)$rule['slab_min']; $max = (float)$rule['slab_max'];
                    // slab_max 0/NULL (or the legacy 999999999 sentinel) = open-ended top slab,
                    // so a volume at/above it is not silently dropped.
                    $openTop = $max <= 0 || $max >= 999999999;
                    if ($volume >= $min && ($openTop || $volume < $max)) {
                        $amount = (float)$rule['slab_amount'];
                        $hi = $openTop ? '∞' : number_format($max);
                        $basis  = $tag . "Volume ₹" . number_format($volume) . " in slab [" .
                                  number_format($min) . "–" . $hi . "] → ₹" . number_format($amount);
                    }
                    break;
                case 'per_product':
                    // ₹flat for each product the merchant has enabled (CSV in merchant.products)
                    $n = $mProducts === '' ? 0 : count(array_filter(array_map('trim', explode(',', $mProducts))));
                    $amount = (float)$rule['flat_amount'] * $n;
                    $basis  = $tag . "₹" . number_format($rule['flat_amount']) . " × $n products";
                    break;
            }
            if ($amount > 0) {
                $planLines[] = ['amount' => $amount, 'basis' => $basis,
                                'rule_id' => (int)$rule['id'], 'plan_id' => (int)$plan['id'],
                                'priority' => (int)($rule['priority'] ?? 0)];
            }
        }
        if (!$stack && $planLines) {
            // highest single rule only; ties broken by priority (lower runs first) for determinism
            usort($planLines, fn($a, $b) => ($b['amount'] <=> $a['amount']) ?: ($a['priority'] <=> $b['priority']));
            $planLines = [$planLines[0]];
        }
        foreach ($planLines as $ln) { $total += $ln['amount']; $lines[] = $ln; }
    }
    return ['total' => round($total, 2), 'lines' => $lines];
}

/** Book computed incentives into the ledger for the merchant's owner. Idempotent per merchant. */
function incentive_book(PDO $db, array $merchant): float
{
    $empId = (int)($merchant['owner_id'] ?? 0);
    if (!$empId) return 0.0;

    // Single-source guard (phase49): the quarterly scorecard is the primary incentive model.
    // The per-merchant model is off by default so the same GMV is never booked/reported twice;
    // an admin can re-enable it in incentive_config if both models are genuinely wanted.
    $cfg = incentive_config($db);
    if (empty($cfg['per_merchant_enabled'])) return 0.0;

    // Idempotency: skip if already booked for this merchant
    $chk = $db->prepare("SELECT COUNT(*) FROM incentive_ledger WHERE merchant_id=?");
    $chk->execute([$merchant['id']]);
    if ((int)$chk->fetchColumn() > 0) return 0.0;

    $calc = incentive_calculate($db, $merchant);
    if ($calc['total'] <= 0) return 0.0;

    $period = date('Y-m');
    $ins = $db->prepare("INSERT INTO incentive_ledger
        (employee_id,merchant_id,deal_id,plan_id,rule_id,amount,basis,status,period_month)
        VALUES (?,?,?,?,?,?,?, 'accrued', ?)");
    foreach ($calc['lines'] as $ln) {
        $ins->execute([
            $empId, $merchant['id'], $merchant['deal_id'] ?? null,
            $ln['plan_id'], $ln['rule_id'], $ln['amount'], $ln['basis'], $period
        ]);
    }
    return $calc['total'];
}

/* ============================================================================
 * QUARTERLY, SCORECARD-DRIVEN PAYOUT  (FY'26-'27 memo §7 + §8)
 *
 * This is the *primary* incentive model the memo describes: assessed per quarter on a
 * rep's scorecard, gated on GMV achievement, with a separate fee-income slab on top. It
 * is independent of the per-merchant rules above — it reads the rep's already-captured
 * scorecard actuals (manual or auto-synced) and the editable incentive_config, so it
 * works with pure manual data entry on prod (no transaction feed required).
 * ============================================================================ */

/** The single editable structure row, with the memo's defaults as a fallback. Cached per request. */
function incentive_config(PDO $db, bool $fresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$fresh) return $cache;
    $defaults = [
        'gmv_base_bps' => 1.0, 'gmv_base_share_pct' => 10.0,
        'gmv_gate_min_pct' => 100.0, 'gmv_accel_pct' => 110.0,
        'gmv_pass_mult_pct' => 100.0, 'gmv_accel_mult_pct' => 125.0,
        'fee_min' => 100000.0, 'fee_slab2' => 200000.0, 'fee_slab3' => 500000.0,
        'fee_rate1_pct' => 5.0, 'fee_rate2_pct' => 10.0, 'fee_overachieve_pct' => 5.0,
        'per_merchant_enabled' => 0.0,   // phase49: per-merchant model off by default (scorecard is primary)
    ];
    try {
        $row = $db->query("SELECT * FROM incentive_config WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $row = null; }
    if (!$row) return $cache = $defaults;
    foreach ($defaults as $k => $v) $row[$k] = isset($row[$k]) ? (float)$row[$k] : $v;
    return $cache = $row;
}

/**
 * Compute the quarterly payout from primitive inputs (fed by the scorecard).
 *   $in = quarterly_volume, gmv_attain_pct, fee_income, governance_ok(bool)
 * Returns lines[] (amount/basis/kind), total, gmv_incentive, fee_incentive, eligible, reason.
 */
function incentive_quarterly_calc(array $cfg, array $in): array
{
    $volume  = (float)($in['quarterly_volume'] ?? 0);
    $attain  = (float)($in['gmv_attain_pct'] ?? 0);
    $fee     = (float)($in['fee_income'] ?? 0);
    $govOk   = !empty($in['governance_ok']);

    $lines = []; $gmvInc = 0.0; $feeInc = 0.0;

    // ---- GMV achievement (memo §7) ----
    // base = base_share% of (base_bps bps of quarterly volume); 1 bps = 0.01% = /10000.
    $base = $volume * ($cfg['gmv_base_bps'] / 10000) * ($cfg['gmv_base_share_pct'] / 100);
    $mult = 0.0;
    if ($attain >= $cfg['gmv_accel_pct'])         $mult = $cfg['gmv_accel_mult_pct'] / 100;   // >110%
    elseif ($attain >= $cfg['gmv_gate_min_pct'])  $mult = $cfg['gmv_pass_mult_pct'] / 100;    // 100-110%
    $gmvInc = round($base * $mult, 2);
    if ($gmvInc > 0) {
        $band = $attain >= $cfg['gmv_accel_pct'] ? ('accelerator ' . rtrim(rtrim(number_format($cfg['gmv_accel_mult_pct'],2),'0'),'.') . '%')
                                                 : (rtrim(rtrim(number_format($cfg['gmv_pass_mult_pct'],2),'0'),'.') . '% payout');
        $lines[] = ['kind' => 'gmv', 'amount' => $gmvInc,
            'basis' => "GMV incentive: base ₹" . number_format($base, 2) . " × $band (attainment " . round($attain,1) . "%)"];
    }

    // ---- Fee income (memo §8), over & above GMV ----
    if ($fee >= $cfg['fee_slab3']) {
        $core  = round($fee * $cfg['fee_rate2_pct'] / 100, 2);
        $bonus = round($fee * $cfg['fee_overachieve_pct'] / 100, 2);
        $feeInc = $core + $bonus;
        $lines[] = ['kind' => 'fee', 'amount' => $core,
            'basis' => "Fee income: " . rtrim(rtrim(number_format($cfg['fee_rate2_pct'],2),'0'),'.') . "% of ₹" . number_format($fee)];
        $lines[] = ['kind' => 'fee_overachieve', 'amount' => $bonus,
            'basis' => "Fee overachievement (>₹" . number_format($cfg['fee_slab3']) . "): +" . rtrim(rtrim(number_format($cfg['fee_overachieve_pct'],2),'0'),'.') . "%"];
    } elseif ($fee >= $cfg['fee_slab2']) {
        $feeInc = round($fee * $cfg['fee_rate2_pct'] / 100, 2);
        $lines[] = ['kind' => 'fee', 'amount' => $feeInc,
            'basis' => "Fee income: " . rtrim(rtrim(number_format($cfg['fee_rate2_pct'],2),'0'),'.') . "% of ₹" . number_format($fee)];
    } elseif ($fee >= $cfg['fee_min']) {
        $feeInc = round($fee * $cfg['fee_rate1_pct'] / 100, 2);
        $lines[] = ['kind' => 'fee', 'amount' => $feeInc,
            'basis' => "Fee income: " . rtrim(rtrim(number_format($cfg['fee_rate1_pct'],2),'0'),'.') . "% of ₹" . number_format($fee)];
    }

    $total = round($gmvInc + $feeInc, 2);
    // Eligibility (memo §10): GMV gate must pass AND data must be validated/approved.
    $gatePass = $attain >= $cfg['gmv_gate_min_pct'];
    $eligible = $gatePass && $govOk && $total > 0;
    $reason = !$gatePass ? 'GMV below 100% — 100% docking (no payout)'
            : (!$govOk  ? 'Governance not validated — payout on hold'
            : ($total <= 0 ? 'No payout computed' : 'Eligible'));

    return ['total' => $total, 'gmv_incentive' => $gmvInc, 'fee_incentive' => round($feeInc,2),
            'lines' => $lines, 'eligible' => $eligible, 'gate_pass' => $gatePass, 'reason' => $reason];
}

/**
 * Book (or re-book) a scorecard's quarterly payout to the ledger. Idempotent per scorecard:
 * clears prior UNPAID rows for this scorecard and re-inserts, so re-running after the data
 * changes recomputes cleanly while never touching an already-paid row.
 * @return array{booked:bool, total:float, lines:array, reason:string}
 */
function incentive_book_quarterly(PDO $db, array $card, array $calc): array
{
    $empId = (int)($card['employee_id'] ?? 0);
    if (!$empId) return ['booked' => false, 'total' => 0, 'lines' => [], 'reason' => 'No employee on scorecard'];
    if (empty($calc['eligible'])) return ['booked' => false, 'total' => $calc['total'] ?? 0, 'lines' => [], 'reason' => $calc['reason'] ?? 'Not eligible'];

    $scId    = (int)$card['id'];
    $quarter = (string)$card['period'];                 // e.g. 2026-Q3
    $pm      = quarter_last_month($quarter);            // YYYY-MM for payout batching

    // never disturb a paid row; clear the rest for this scorecard, then re-insert
    $paid = $db->prepare("SELECT COUNT(*) FROM incentive_ledger WHERE scorecard_id=? AND status='paid'");
    $paid->execute([$scId]);
    if ((int)$paid->fetchColumn() > 0) {
        return ['booked' => false, 'total' => $calc['total'], 'lines' => [], 'reason' => 'A payout for this quarter is already marked paid — clear it in the ledger first'];
    }
    $db->prepare("DELETE FROM incentive_ledger WHERE scorecard_id=? AND status<>'paid'")->execute([$scId]);

    $ins = $db->prepare("INSERT INTO incentive_ledger
        (employee_id, scorecard_id, amount, basis, status, period_month, period_quarter)
        VALUES (?,?,?,?, 'accrued', ?, ?)");
    foreach ($calc['lines'] as $ln) {
        if ($ln['amount'] <= 0) continue;
        $ins->execute([$empId, $scId, $ln['amount'], $ln['basis'], $pm, $quarter]);
    }
    return ['booked' => true, 'total' => $calc['total'], 'lines' => $calc['lines'], 'reason' => 'Booked'];
}

/** '2026-Q3' -> '2026-09' (last month of the quarter), for payout batching. */
function quarter_last_month(string $period): string
{
    if (!preg_match('/^(\d{4})-Q([1-4])$/', $period, $m)) return date('Y-m');
    return sprintf('%04d-%02d', (int)$m[1], (int)$m[2] * 3);
}

/** Preview variant: same rules, deal amount supplied directly (no DB deal lookup). */
function incentive_calculate_preview(PDO $db, array $merchant, float $dealAmount): array
{
    $merchant['deal_id'] = null;
    // temporarily stash deal amount so incentive_calculate's percent rules have a value
    $GLOBALS['__preview_deal_amount'] = $dealAmount;
    $r = incentive_calculate($db, $merchant);
    unset($GLOBALS['__preview_deal_amount']);
    return $r;
}
