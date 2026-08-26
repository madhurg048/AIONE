<?php
/**
 * Auto-email alert engine — every alert loops the person's MANAGER in.
 *
 *   alert_send()        one throttled alert: employee email + manager copy
 *   alert_sla_email()   called from the escalation engine on every hop
 *   run_daily_alerts()  cron: unfilled timesheets (daily) + sales-target
 *                       attainment below threshold (weekly, Mondays)
 *
 * Config lives in alert_settings (Settings → Auto-email alerts); SMTP is the
 * existing email_settings (Settings → Email). Throttle: one email per
 * (type, employee, ref) per day via alert_log — which doubles as the audit
 * trail. Non-fatal everywhere: a dead SMTP must never break a sweep.
 */
require_once __DIR__ . '/../../includes/email-helper.php';

function alert_settings(PDO $db): array
{
    static $c = null;
    if ($c !== null) return $c;
    try { $c = $db->query("SELECT * FROM alert_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC) ?: []; }
    catch (Throwable $e) { $c = []; }
    return $c += ['alerts_enabled' => 1, 'cc_manager' => 1, 'timesheet_alert' => 1,
                  'sla_alert' => 1, 'target_alert' => 1, 'target_threshold_pct' => 70];
}

/** employee row (name/email) + their manager's row, for the CC loop. */
function alert_contacts(PDO $db, int $empId): array
{
    $s = $db->prepare("SELECT e.full_name, e.email, m.id AS mgr_id, m.full_name AS mgr_name, m.email AS mgr_email
                         FROM employees e LEFT JOIN employees m ON m.id=e.manager_id WHERE e.id=?");
    $s->execute([$empId]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Send one alert email to $empId (manager looped in when configured).
 * Throttled per (type, employee, ref) per day. Returns true if a mail went out.
 */
function alert_send(PDO $db, string $type, int $empId, string $subject, string $html, ?string $ref = null): bool
{
    $cfg = alert_settings($db);
    if (empty($cfg['alerts_enabled'])) return false;

    // throttle: one per type+employee+ref per day
    $chk = $db->prepare("SELECT COUNT(*) FROM alert_log
                          WHERE alert_type=? AND employee_id=? AND (ref <=> ?) AND created_at >= CURDATE()
                            AND status IN ('sent','failed')");
    $chk->execute([$type, $empId, $ref]);
    if ((int)$chk->fetchColumn() > 0) return false;

    $c = alert_contacts($db, $empId);
    $to = trim((string)($c['email'] ?? ''));
    $mgrTo = (!empty($cfg['cc_manager']) && !empty($c['mgr_email'])) ? trim($c['mgr_email']) : null;

    $sentAny = false; $detail = [];
    if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $ok = @sendCrmEmail($to, $c['full_name'] ?? 'Colleague', $subject, $html);
        $sentAny = $sentAny || $ok;
        $detail[] = 'emp:' . ($ok ? 'ok' : 'fail');
    } else { $detail[] = 'emp:no-email'; }

    if ($mgrTo && filter_var($mgrTo, FILTER_VALIDATE_EMAIL)) {
        $mgrHtml = "<p style='color:#64748b;font-size:12px'>You are copied as <b>" . htmlspecialchars($c['full_name'] ?? '') . "</b>'s manager.</p>" . $html;
        $ok = @sendCrmEmail($mgrTo, $c['mgr_name'] ?? 'Manager', '[Team] ' . $subject, $mgrHtml);
        $sentAny = $sentAny || $ok;
        $detail[] = 'mgr:' . ($ok ? 'ok' : 'fail');
    }

    try {
        $db->prepare("INSERT INTO alert_log (alert_type, employee_id, ref, sent_to, cc_manager_to, status, detail)
                      VALUES (?,?,?,?,?,?,?)")
           ->execute([$type, $empId, $ref, $to ?: null, $mgrTo,
                      $sentAny ? 'sent' : 'failed', substr(implode(' ', $detail), 0, 255)]);
    } catch (Throwable $e) {}
    return $sentAny;
}

/** SLA/TAT breach email — fired from escalate_item() on every hop (assignee + receiver, managers looped). */
function alert_sla_email(PDO $db, array $it, int $level, $receiverIds, string $reason, bool $toBoard): void
{
    $cfg = alert_settings($db);
    if (empty($cfg['alerts_enabled']) || empty($cfg['sla_alert'])) return;
    $subjBase = sprintf('SLA breach L%d%s: %s', $level, $toBoard ? ' — BOARD' : '', $it['title']);
    $html = "<p><b>" . htmlspecialchars($it['title']) . "</b> (" . $it['entity'] . ", " . htmlspecialchars($it['priority'] ?? '') . " priority) "
          . "has breached its TAT and was escalated to level $level" . ($toBoard ? " — <b style='color:#dc2626'>THE BOARD</b>" : "") . ".</p>"
          . "<p>" . htmlspecialchars($reason) . "</p>"
          . "<p style='font-size:12px;color:#64748b'>Open the CRM → Escalations to act on it.</p>";
    $ref = $it['entity'] . ':' . $it['id'] . ':L' . $level;
    if (!empty($it['assignee'])) alert_send($db, 'sla_breach', (int)$it['assignee'], $subjBase, $html, $ref);
    foreach ((array)$receiverIds as $r) {
        if ((int)$r && (int)$r !== (int)($it['assignee'] ?? 0)) {
            alert_send($db, 'sla_escalated_to', (int)$r, $subjBase, $html, $ref);
        }
    }
}

/** Current quarter label like scorecards use (2026-Q3). */
function alert_current_period(): string
{
    return date('Y') . '-Q' . (int)ceil((int)date('n') / 3);
}

/**
 * Daily cron body. Timesheet nudges every workday; sales-target attainment
 * check on Mondays (weekly cadence — the alert_log ref carries the period).
 */
function run_daily_alerts(PDO $db, bool $force = false): array
{
    $cfg = alert_settings($db);
    $stats = ['timesheet_alerts' => 0, 'target_alerts' => 0, 'skipped' => 0, 'errors' => 0];
    if (empty($cfg['alerts_enabled'])) return $stats + ['note' => 'alerts disabled'];

    /* ---- 1. timesheets not filled today (skip Sundays) ---- */
    if (!empty($cfg['timesheet_alert']) && ($force || (int)date('w') !== 0)) {
        $rows = $db->query("SELECT e.id, e.full_name FROM employees e
                              JOIN sales_users su ON su.employee_id=e.id AND su.is_active=1
                             WHERE NOT EXISTS (SELECT 1 FROM timesheets ts WHERE ts.employee_id=e.id AND ts.entry_date=CURDATE())")
                    ->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            try {
                $html = "<p>Hi " . htmlspecialchars($r['full_name']) . ",</p>"
                      . "<p>Your <b>timesheet for " . date('d M Y') . "</b> has not been filled yet. "
                      . "Please log your day in the CRM → Timesheet before end of day.</p>";
                if (alert_send($db, 'timesheet_missing', (int)$r['id'],
                    'Timesheet pending for ' . date('d M'), $html, date('Y-m-d'))) $stats['timesheet_alerts']++;
                else $stats['skipped']++;
            } catch (Throwable $e) { $stats['errors']++; }
        }
    }

    /* ---- 2. sales targets below threshold (Mondays; per current quarter) ---- */
    if (!empty($cfg['target_alert']) && ($force || (int)date('w') === 1)) {
        $period = alert_current_period();
        $th = max(1, min(100, (int)$cfg['target_threshold_pct']));
        // months elapsed in the quarter so far (>=1), for a fair pro-rata monthly-average compare
        $qStartMonth = (floor((date('n') - 1) / 3) * 3) + 1;
        $monthsIn = max(1, (int)date('n') - $qStartMonth + 1);
        $qStart = sprintf('%d-%02d-01', (int)date('Y'), $qStartMonth);
        $cards = $db->query("SELECT sc.employee_id, sc.gmv_target, e.full_name,
                                    COALESCE((SELECT SUM(t.amount) FROM merchant_transactions t
                                              JOIN merchants m ON m.id=t.merchant_id
                                             WHERE m.owner_id=sc.employee_id AND t.status='success'
                                               AND t.txn_date >= " . $db->quote($qStart) . "), 0) AS actual_gmv
                               FROM scorecards sc JOIN employees e ON e.id=sc.employee_id
                              WHERE sc.period=" . $db->quote($period) . " AND sc.status<>'draft' AND sc.gmv_target > 0")
                     ->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cards as $c2) {
            try {
                // compare monthly-average run-rate against the (monthly-average) target
                $attain = (float)$c2['gmv_target'] > 0
                    ? round((((float)$c2['actual_gmv'] / $monthsIn) / (float)$c2['gmv_target']) * 100) : 100;
                if ($attain >= $th) continue;
                $html = "<p>Hi " . htmlspecialchars($c2['full_name']) . ",</p>"
                      . "<p>Your <b>$period sales target attainment is {$attain}%</b> — below the {$th}% alert threshold.</p>"
                      . "<p>GMV so far: ₹" . number_format((float)$c2['actual_gmv']) . " against a monthly target of ₹"
                      . number_format((float)$c2['gmv_target']) . ".</p>"
                      . "<p style='font-size:12px;color:#64748b'>See CRM → Scorecards for the full picture.</p>";
                if (alert_send($db, 'target_below', (int)$c2['employee_id'],
                    "Sales target attention — $period at {$attain}%", $html, $period . ':w' . date('W'))) $stats['target_alerts']++;
                else $stats['skipped']++;
            } catch (Throwable $e) { $stats['errors']++; }
        }
    }
    return $stats;
}
