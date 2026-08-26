<?php
/**
 * Client-portal integration API — powers the payg.in website's client panel.
 * Auth: per-app API key (X-API-Key) with scope 'portal' (IT → App API Keys).
 * The panel's own backend calls this server-to-server after verifying the
 * customer's mobile with the centralized OTP API — the key never reaches a browser.
 *
 *   POST portal.php?action=tickets   { "mobile": "98…" | "email": "…" }
 *     -> the contact's tickets (ref, subject, status, priority, SLA due, updates)
 *   POST portal.php?action=ticket    { "ticket_ref": "CHAT-2026-00042", "mobile": "98…" }
 *     -> one ticket's detail — the mobile must match the ticket's contact
 */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/_apps.php';

$db = idb();
$app = app_auth($db, 'portal');
$b = ibody();
$action = $_GET['action'] ?? ($b['action'] ?? '');

$cols = "ct.ticket_ref, ct.subject, ct.status, ct.priority, ct.assist_type, ct.vertical,
         ct.assigned_to_name, ct.waiting_on, ct.last_update_note, ct.sla_due_at,
         ct.created_at, ct.updated_at,
         COALESCE(ct.customer_name, TRIM(CONCAT(COALESCE(l.first_name,''),' ',COALESCE(l.last_name,'')))) AS customer_name";

if ($action === 'tickets') {
    $mobile = preg_replace('/\D+/', '', (string)($b['mobile'] ?? ''));
    if (strlen($mobile) > 10) $mobile = substr($mobile, -10);
    $email = trim((string)($b['email'] ?? ''));
    if ($mobile === '' && $email === '') ifail('mobile or email required');
    $w = []; $args = [];
    if ($mobile !== '') { $w[] = "(REPLACE(COALESCE(ct.customer_mobile, l.mobile, l.phone, ''), '+91', '') LIKE ?)"; $args[] = '%' . $mobile; }
    if ($email !== '')  { $w[] = "(COALESCE(ct.customer_email, l.email) = ?)"; $args[] = $email; }
    $s = $db->prepare("SELECT $cols FROM crm_tickets ct LEFT JOIN leads l ON l.id = ct.lead_id
                        WHERE " . implode(' OR ', $w) . " ORDER BY ct.id DESC LIMIT 50");
    $s->execute($args);
    iok(['tickets' => $s->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($action === 'ticket') {
    $ref = trim((string)($b['ticket_ref'] ?? ''));
    $mobile = preg_replace('/\D+/', '', (string)($b['mobile'] ?? ''));
    if (strlen($mobile) > 10) $mobile = substr($mobile, -10);
    if ($ref === '' || $mobile === '') ifail('ticket_ref and mobile required');
    $s = $db->prepare("SELECT $cols, ct.id FROM crm_tickets ct LEFT JOIN leads l ON l.id = ct.lead_id
                        WHERE ct.ticket_ref = ?");
    $s->execute([$ref]);
    $t = $s->fetch(PDO::FETCH_ASSOC);
    if (!$t) ifail('Ticket not found', 404);
    // possession of the ref alone isn't enough — the mobile must match the ticket's contact
    $onFile = preg_replace('/\D+/', '', (string)$db->query(
        "SELECT COALESCE(ct.customer_mobile, l.mobile, l.phone, '') FROM crm_tickets ct
          LEFT JOIN leads l ON l.id=ct.lead_id WHERE ct.id=" . (int)$t['id'])->fetchColumn());
    if ($onFile === '' || substr($onFile, -10) !== $mobile) ifail('Ticket not found', 404);
    unset($t['id']);
    iok(['ticket' => $t]);
}

ifail("Unknown action — use ?action=tickets or ?action=ticket");
