<?php
/**
 * Inbound email — mailboxes to read (IMAP/POP3) + AI triage + draft replies.
 *   GET  mailbox.php?boxes            -> list configured mailboxes (admin/it)
 *   POST mailbox.php?save             -> add/update a mailbox (admin/it)
 *   POST mailbox.php?del&id=          -> delete a mailbox (admin/it)
 *   POST mailbox.php?fetch            -> poll active mailboxes, store new emails, match leads
 *   GET  mailbox.php?inbox[&status=]  -> list fetched emails (+ AI fields)
 *   POST mailbox.php?triage&id=       -> run AI: summary, category, suggested action, draft reply
 *   POST mailbox.php?reply&id=        -> send the (edited) reply via SMTP
 *   POST mailbox.php?archive&id=      -> mark archived
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_ai.php';
require_once __DIR__ . '/../../includes/email-helper.php';
require_login();
require_module('inbox');
$db = db();
$me = current_employee_id();
$isAdmin = ($_SESSION['user']['user_type'] ?? '') === 'admin' || current_role() === 'it';

/* ---------- mailbox config (admin/IT) ---------- */
if (isset($_GET['boxes']) || isset($_GET['save']) || isset($_GET['del']) || isset($_GET['test'])) {
    if (!$isAdmin) fail('Admin or IT only', 403);

    if (isset($_GET['test'])) {
        if (!function_exists('imap_open')) fail('PHP IMAP extension not installed on the server', 500);
        $m = $db->prepare("SELECT * FROM email_mailboxes WHERE id=?"); $m->execute([(int)($_GET['id'] ?? 0)]);
        $m = $m->fetch(PDO::FETCH_ASSOC);
        if (!$m) fail('Save the mailbox first, then test it', 400);
        imap_timeout(IMAP_OPENTIMEOUT, 12); imap_timeout(IMAP_READTIMEOUT, 12);
        $mbox = @imap_open(imap_ref($m), $m['username'], $m['password'], 0, 1);
        if (!$mbox) {
            $err = imap_last_error() ?: 'connection failed';
            imap_errors();
            fail('Login failed: ' . $err, 502);
        }
        $n = @imap_num_msg($mbox);
        imap_close($mbox);
        ok(['messages' => (int)$n], ['message' => 'Connected ✓ — ' . (int)$n . ' message(s) in ' . ($m['folder'] ?: 'INBOX')]);
    }

    if (method() === 'POST' && isset($_GET['save'])) {
        $b = body();
        $id = (int)($b['id'] ?? 0);
        $cur = $id ? $db->query("SELECT password FROM email_mailboxes WHERE id=$id")->fetchColumn() : '';
        $pass = (isset($b['password']) && $b['password'] !== '' && $b['password'] !== '********') ? $b['password'] : $cur;
        if ($id) {
            $db->prepare("UPDATE email_mailboxes SET label=?,protocol=?,host=?,port=?,encryption=?,username=?,password=?,folder=?,is_active=? WHERE id=?")
               ->execute([$b['label']??'', $b['protocol']??'imap', $b['host']??'', (int)($b['port']??993), $b['encryption']??'ssl',
                          $b['username']??'', $pass, $b['folder']?:'INBOX', !empty($b['is_active'])?1:0, $id]);
        } else {
            $db->prepare("INSERT INTO email_mailboxes (label,protocol,host,port,encryption,username,password,folder,is_active) VALUES (?,?,?,?,?,?,?,?,1)")
               ->execute([$b['label']??'', $b['protocol']??'imap', $b['host']??'', (int)($b['port']??993), $b['encryption']??'ssl',
                          $b['username']??'', $pass, $b['folder']?:'INBOX']);
            $id = (int)$db->lastInsertId();
        }
        ok(['id' => $id], ['message' => 'Mailbox saved']);
    }
    if (method() === 'POST' && isset($_GET['del'])) {
        $db->prepare("DELETE FROM email_mailboxes WHERE id=?")->execute([(int)$_GET['id']]);
        ok([]);
    }
    ok($db->query("SELECT id,label,protocol,host,port,encryption,username,folder,is_active,last_polled_at,
                          (password IS NOT NULL AND password<>'') AS has_password FROM email_mailboxes ORDER BY id")->fetchAll());
}

/* ---------- helpers ---------- */
function imap_ref(array $m): string {
    $enc = $m['encryption'] === 'ssl' ? '/ssl' : ($m['encryption'] === 'tls' ? '/tls' : '/notls');
    $proto = $m['protocol'] === 'pop3' ? '/pop3' : '/imap';
    return '{' . $m['host'] . ':' . (int)$m['port'] . $proto . $enc . '/novalidate-cert}' . ($m['folder'] ?: 'INBOX');
}
function plain_body($mbox, int $num): string {
    $struct = @imap_fetchstructure($mbox, $num);
    $body = '';
    if ($struct && !empty($struct->parts)) {
        foreach ($struct->parts as $i => $p) {
            if (strtoupper($p->subtype ?? '') === 'PLAIN') { $body = imap_fetchbody($mbox, $num, (string)($i + 1)); $enc = $p->encoding; break; }
        }
        if ($body === '') { $body = imap_fetchbody($mbox, $num, '1'); $enc = $struct->parts[0]->encoding ?? 0; }
    } else { $body = imap_body($mbox, $num); $enc = $struct->encoding ?? 0; }
    if (($enc ?? 0) == 3) $body = base64_decode($body);
    elseif (($enc ?? 0) == 4) $body = quoted_printable_decode($body);
    $body = trim(strip_tags($body));
    return mb_substr($body, 0, 4000);
}

/* ---------- fetch (poll mailboxes) ---------- */
if (method() === 'POST' && isset($_GET['fetch'])) {
    if (!function_exists('imap_open')) fail('PHP IMAP extension not installed on the server', 500);
    $boxes = $db->query("SELECT * FROM email_mailboxes WHERE is_active=1")->fetchAll();
    if (!$boxes) fail('No active mailboxes configured (Settings → Mailboxes)', 400);
    imap_timeout(IMAP_OPENTIMEOUT, 12); imap_timeout(IMAP_READTIMEOUT, 12);
    $new = 0; $errs = [];
    foreach ($boxes as $m) {
        $mbox = @imap_open(imap_ref($m), $m['username'], $m['password'], 0, 1);
        if (!$mbox) { $errs[] = ($m['label'] ?: $m['host']) . ': ' . imap_last_error(); continue; }
        $total = imap_num_msg($mbox);
        for ($n = $total; $n > max(0, $total - 15); $n--) {   // last 15 messages
            $uid = (string)imap_uid($mbox, $n);
            $ov = imap_headerinfo($mbox, $n);
            $from = $ov->from[0] ?? null;
            $fromEmail = $from ? strtolower(($from->mailbox ?? '') . '@' . ($from->host ?? '')) : '';
            $subject = isset($ov->subject) ? imap_utf8($ov->subject) : '(no subject)';
            $recv = isset($ov->date) ? date('Y-m-d H:i:s', strtotime($ov->date)) : date('Y-m-d H:i:s');
            $snippet = plain_body($mbox, $n);
            // match a lead by sender
            $lead = $db->prepare("SELECT id FROM leads WHERE email=? LIMIT 1"); $lead->execute([$fromEmail]);
            $leadId = $lead->fetchColumn() ?: null;
            try {
                $db->prepare("INSERT IGNORE INTO inbox_emails (mailbox_id,msg_uid,from_email,from_name,subject,snippet,received_at,lead_id)
                              VALUES (?,?,?,?,?,?,?,?)")
                   ->execute([$m['id'], $uid, $fromEmail, $from->personal ?? '', mb_substr($subject,0,400), $snippet, $recv, $leadId]);
                if ($db->lastInsertId()) $new++;
            } catch (Throwable $e) {}
        }
        imap_close($mbox);
        $db->prepare("UPDATE email_mailboxes SET last_polled_at=NOW() WHERE id=?")->execute([$m['id']]);
    }
    ok(['fetched' => $new, 'errors' => $errs], ['message' => "$new new email(s) fetched"]);
}

/* ---------- inbox list ---------- */
if (method() === 'GET' && isset($_GET['inbox'])) {
    $status = in_array($_GET['status'] ?? '', ['new','replied','archived'], true) ? $_GET['status'] : 'new';
    $s = $db->prepare("SELECT e.*, TRIM(CONCAT(COALESCE(l.first_name,''),' ',COALESCE(l.last_name,''))) AS lead_name,
                              l.lead_status
                       FROM inbox_emails e LEFT JOIN leads l ON l.id=e.lead_id
                       WHERE e.status=? ORDER BY e.received_at DESC LIMIT 100");
    $s->execute([$status]);
    ok($s->fetchAll());
}

/* ---------- AI triage ---------- */
if (method() === 'POST' && isset($_GET['triage'])) {
    $id = (int)($_GET['id'] ?? 0);
    $e = $db->prepare("SELECT * FROM inbox_emails WHERE id=?"); $e->execute([$id]); $em = $e->fetch();
    if (!$em) fail('Email not found', 404);
    $prompt = 'You are a sales assistant for a payment-gateway company. An email arrived. Respond with ONLY raw JSON:
{"summary":"2-line summary","category":"new_enquiry|lead_reply|support|complaint|spam|other","suggested_action":"what the sales rep should update or do in the CRM","draft_reply":"a professional, concise reply the rep can send"}
From: ' . $em['from_email'] . '
Subject: ' . $em['subject'] . '
Body: ' . mb_substr($em['snippet'], 0, 1500);
    $r = crm_ai_call($prompt, 'medium', 'chat_v3');
    if (!$r) fail('AI unavailable (box down and no fallback)', 502);
    $clean = trim(preg_replace('/```(json)?/i', '', $r['response']));
    $p = null;
    if (preg_match('/\{[\s\S]*\}/u', $clean, $mm)) $p = json_decode($mm[0], true);
    if (!is_array($p)) fail('AI returned no structured result', 502);
    $db->prepare("UPDATE inbox_emails SET ai_summary=?, ai_category=?, ai_action=?, ai_draft=?, triaged=1 WHERE id=?")
       ->execute([$p['summary'] ?? '', substr($p['category'] ?? 'other',0,60), substr($p['suggested_action'] ?? '',0,500), $p['draft_reply'] ?? '', $id]);
    ok(['summary'=>$p['summary']??'', 'category'=>$p['category']??'other', 'suggested_action'=>$p['suggested_action']??'', 'draft_reply'=>$p['draft_reply']??'', 'source'=>$r['source']]);
}

/* ---------- send reply ---------- */
if (method() === 'POST' && isset($_GET['reply'])) {
    $id = (int)($_GET['id'] ?? 0);
    $e = $db->prepare("SELECT * FROM inbox_emails WHERE id=?"); $e->execute([$id]); $em = $e->fetch();
    if (!$em) fail('Email not found', 404);
    $b = body();
    $bodyText = trim($b['reply'] ?? $em['ai_draft'] ?? '');
    if ($bodyText === '') fail('Nothing to send');
    if (!filter_var($em['from_email'], FILTER_VALIDATE_EMAIL)) fail('Invalid recipient address');
    $subject = 'Re: ' . preg_replace('/^\s*re:\s*/i', '', $em['subject']);
    $html = nl2br(htmlspecialchars($bodyText));
    if (!sendCrmEmail($em['from_email'], $em['from_name'] ?: '', $subject, $html, $bodyText)) {
        fail('Send failed — check Settings → Email (SMTP) is configured & enabled', 502);
    }
    $db->prepare("UPDATE inbox_emails SET status='replied' WHERE id=?")->execute([$id]);
    // log a follow-up on the matched lead
    if ($em['lead_id']) {
        $db->prepare("INSERT INTO lead_followups (lead_id,channel,direction,outcome,feedback,created_by)
                      VALUES (?, 'email','outbound','message_sent',?,?)")->execute([$em['lead_id'], 'Email reply: ' . mb_substr($bodyText,0,300), $me ?: null]);
    }
    ok([], ['message' => 'Reply sent to ' . $em['from_email']]);
}

if (method() === 'POST' && isset($_GET['archive'])) {
    $db->prepare("UPDATE inbox_emails SET status='archived' WHERE id=?")->execute([(int)($_GET['id'] ?? 0)]);
    ok([]);
}

fail('Unsupported', 405);
