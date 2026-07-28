<?php
/**
 * Chatbot widget -> Ticket + Lead. PUBLIC inbound endpoint (no auth) for the
 * embedded support chatbot (see public/chatbot/chat.php).
 *
 * Creates a Lead (source = 'chatbot') so the enquiry flows into the normal
 * Leads pipeline, and logs a crm_tickets row (source = 'chatbot', linked via
 * lead_id) so it's visible as a ticket too. Fires on_create automations
 * (round-robin assignment + welcome email) exactly like web-to-lead.php.
 *
 *   POST chatbot-ticket.php  (application/json)
 *     { name, mobile, email, company?, assist_type (sales|service|transaction|callback),
 *       description?, website(honeypot) }
 *
 * Routing by assist_type:
 *   sales                 -> vertical=sales,   ticket only (Sales team follows up)
 *   service, transaction  -> vertical=service, ticket + (if a Service exec is currently
 *                            online) a live chat session is opened immediately, picked up
 *                            from the waiting queue via sales-chat.php. If nobody is online
 *                            right now it stays ticket-only — no point queueing a chat that
 *                            nobody is there to answer.
 *   callback              -> vertical=service, ticket only (Service team calls back)
 *
 * Ticket ownership: only Sales tickets are round-robin assigned to a sales_exec (same
 * fairness cursor as lead assignment, api/sales/_automation_engine.php). Service tickets
 * are left unowned — tickets.php shows every ticket to the whole team regardless of who's
 * assigned, so round robin only spreads Sales workload, it doesn't gate Service visibility.
 *
 * De-dupe: same email/mobile in the last 6 hours -> logs a new ticket against
 * the existing lead rather than creating a duplicate lead.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/_automation_engine.php';
require_once __DIR__ . '/_ticket_ref.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');           // tighten to your domains in prod
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

function resolveTransactionAttachment(string $assistType): array
{
    $attachmentData = null;
    $attachmentName = null;
    $attachmentType = null;
    // Only transaction requests use attachments
    if ($assistType !== 'transaction') {
        return [
            $attachmentData,
            $attachmentName,
            $attachmentType
        ];
    }
    // Attachment not supplied
    if (
        !isset($_FILES['attachment']) ||
        !isset($_FILES['attachment']['error']) ||
        $_FILES['attachment']['error'] === UPLOAD_ERR_NO_FILE
    ) {
        return [
            $attachmentData,
            $attachmentName,
            $attachmentType
        ];
    }
    $file = $_FILES['attachment'];
    // Upload error
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException(
            'Transaction attachment upload failed'
        );
    }
    // Maximum 5 MB
    if ($file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException(
            'Attachment size must not exceed 5MB'
        );
    }
    // Ensure PHP recognizes it as an uploaded file
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException(
            'Invalid uploaded attachment'
        );
    }
    // Detect actual MIME type from file contents
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detectedMime = $finfo->file(
        $file['tmp_name']
    );
    $allowedMimeTypes = [
        'image/jpeg',
        'image/png',
        'application/pdf'
    ];
    if (
        !$detectedMime ||
        !in_array(
            $detectedMime,
            $allowedMimeTypes,
            true
        )
    ) {
        throw new RuntimeException(
            'Only JPG, PNG and PDF attachments are allowed'
        );
    }
    // Read binary data directly from PHP temporary upload
    $attachmentData = file_get_contents(
        $file['tmp_name']
    );
    if ($attachmentData === false) {
        throw new RuntimeException(
            'Unable to read transaction attachment'
        );
    }
    $attachmentName = basename(
        $file['name']
    );
    $attachmentType = $detectedMime;
    return [
        $attachmentData,
        $attachmentName,
        $attachmentType
    ];
}

// $b = json_decode(file_get_contents('php://input'), true);
// if (!is_array($b))
//     $b = $_POST;
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

if (stripos($contentType, 'application/json') !== false) {

    $b = json_decode(
        file_get_contents('php://input'),
        true
    );

    if (!is_array($b)) {
        $b = [];
    }

} else {

    // multipart/form-data or normal form submission
    $b = $_POST;
}
// $b = json_decode(file_get_contents('php://input'), true);
// if (!is_array($b))
//     $b = $_POST;

// honeypot
if (!empty($b['website'])) {
    echo json_encode(['success' => true, 'ticket_ref' => 'CHAT-0000']);
    exit;
}

$name = trim($b['name'] ?? '');
$email = trim($b['email'] ?? '');
$mobile = preg_replace('/\D/', '', $b['mobile'] ?? '');
if (strlen($mobile) === 12 && str_starts_with($mobile, '91'))
    $mobile = substr($mobile, 2);

$assistType = strtolower(trim($b['assist_type'] ?? ''));
$allowedAssistTypes = ['sales', 'service', 'transaction', 'callback'];
if (!in_array($assistType, $allowedAssistTypes, true))
    $assistType = 'sales';

try {

    [
        $attachmentData,
        $attachmentName,
        $attachmentType
    ] = resolveTransactionAttachment($assistType);

} catch (RuntimeException $e) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);

    exit;
}

if (
    $assistType === 'transaction' &&
    $attachmentData === null
) {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'error' => 'Transaction screenshot or statement is required'
    ]);

    exit;
}

// Routing: sales enquiries stay with the Sales team; service, transaction and callback
// requests go to the Service team. Service + transaction additionally open a live chat
// session right away, but only if a Service exec is currently online to pick it up
// (sales + callback are always ticket-only — an agent follows up / calls back).
$vertical = ($assistType === 'sales') ? 'sales' : 'service';

if ($name === '' || !preg_match('/^[0-9]{10}$/', $mobile)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'name and a valid 10-digit mobile are required']);
    exit;
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid email']);
    exit;
}

$db = getDB();
if (!$db) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'unavailable']);
    exit;
}

$opensLiveChat = in_array($assistType, ['service', 'transaction'], true)
    && automation_vertical_has_online_agent($db, 'service');

$subjectMap = [
    'sales' => 'Sales Enquiry',
    'service' => 'Service Request',
    'transaction' => 'Transaction Issue',
    'callback' => 'Callback Request',
];
$subject = $subjectMap[$assistType];
$description = substr(trim($b['description'] ?? ''), 0, 2000);
$company = substr(trim($b['company'] ?? ''), 0, 150);

// $parts = preg_split('/\s+/', $name, 2);
// $first = $parts[0] ?? '';
// $last = $parts[1] ?? ($company ?: 'Chatbot Lead');
$nameParts = preg_split('/\s+/', trim($name));

if (count($nameParts) === 1) {
    // Single-word name
    $first = $nameParts[0];
    $last = '';
} else {
    // Everything except the last word = first name
    $last = array_pop($nameParts);
    $first = implode(' ', $nameParts);
}

// de-dupe: same email/mobile IN THE SAME VERTICAL within 6h -> reuse the lead, just log
// another ticket. Scoped by vertical (not just email/mobile) so a visitor who first raises
// a Sales enquiry and later, within the window, raises a Service/Transaction issue gets a
// separate Service-vertical lead -- otherwise the Service team would never see it (the lead
// would stay filed under 'sales' forever) and the Sales team's ticket_subject would show
// whatever the visitor's most recent unrelated ticket happened to be.
//
// The check-then-insert below is not atomic on its own — a double-submit from the widget (no
// button-disable while the first request is in flight) or a genuine double-click fires two
// requests a few seconds apart, and both can run their SELECT before either INSERT commits,
// producing two "duplicate" leads for the same visitor instead of one lead + two tickets.
// A named lock on mobile+vertical (mobile is always present and validated 10-digit by this
// point) serializes concurrent requests for the same visitor so the second one always sees
// the first one's committed INSERT.
$lockName = 'chatbot_lead_' . md5($vertical . '|' . $mobile);
$gotLock = (bool) $db->query("SELECT GET_LOCK(" . $db->quote($lockName) . ", 5)")->fetchColumn();
try {
    $existingLeadId = null;
    if ($email) {
        $s = $db->prepare("SELECT id FROM leads WHERE email=? AND vertical=? AND created_at>NOW()-INTERVAL 6 HOUR ORDER BY id DESC LIMIT 1");
        $s->execute([$email, $vertical]);
        $existingLeadId = $s->fetchColumn() ?: null;
    }
    if (!$existingLeadId && $mobile) {
        $s = $db->prepare("SELECT id FROM leads WHERE (phone=? OR mobile=?) AND vertical=? AND created_at>NOW()-INTERVAL 6 HOUR ORDER BY id DESC LIMIT 1");
        $s->execute([$mobile, $mobile, $vertical]);
        $existingLeadId = $s->fetchColumn() ?: null;
    }

    $fired = [];
    $isNewLead = false;
    if ($existingLeadId) {
        $leadId = (int) $existingLeadId;
        // Refresh identity fields (name/company/email) with what was submitted THIS time — the
        // reuse only used to append to `description`, so a repeat visitor who typed a different
        // name/email on their second request (e.g. corrected a typo, or is simply a different
        // person reusing a shared/office line) kept showing the FIRST submission's stale
        // identity forever, on every ticket, no matter what they actually typed later. The
        // chatbot widget always collects name/email/company before it lets a request through
        // (hasCompleteClient() in chat.php), so these are guaranteed non-empty on every call —
        // latest submission's identity wins, same "latest owns it" rule already applied to
        // ticket/lead ownership above.
        $db->prepare("UPDATE leads SET first_name=?, last_name=?, company=?, email=?, last_contacted_at=NOW() WHERE id=?")
            ->execute([substr($first, 0, 80), substr($last, 0, 80), $company, $email ?: null, $leadId]);
        if ($description) {
            $db->prepare("UPDATE leads SET description=CONCAT(COALESCE(description,''),'\n\n',?) WHERE id=?")
                ->execute([$description, $leadId]);
        }
    } else {
        // Score on insert via the shared helper (see _ticket_ref.php) — the same triage score
        // the Sales "New Lead" path applies. Without it every chatbot enquiry landed on the
        // Leads list at score 0 and read as cold regardless of how complete it was.
        $score = lead_completeness_score([
            'email' => $email, 'mobile' => $mobile, 'company' => $company,
            'lead_source' => 'chatbot',
        ]);
        $ins = $db->prepare("INSERT INTO leads (first_name,last_name,company,email,phone,mobile,lead_source,vertical,lead_status,description,score)
                             VALUES (?,?,?,?,?,?, 'chatbot', ?, 'new', ?, ?)");
        $ins->execute([substr($first, 0, 80), substr($last, 0, 80), $company, $email ?: null, $mobile, $mobile, $vertical, $description, $score]);
        $leadId = (int) $db->lastInsertId();
        // Automations are deliberately NOT fired here — see below. They run after the ticket
        // exists and the owner has been picked.
        $isNewLead = true;
    }
} finally {
    if ($gotLock) $db->query("SELECT RELEASE_LOCK(" . $db->quote($lockName) . ")");
}

$waitingOn = $vertical === 'service'
    ? 'service_team'
    : 'sales_team';

// Snapshot the identity as typed on THIS submission — this ticket must keep showing this
// name/company/email/mobile forever, even if the shared lead record's identity fields get
// overwritten later by a repeat visitor's different submission (see the lead upsert above).
$customerNameSnapshot = trim($name) !== '' ? substr(trim($name), 0, 160) : null;
$customerCompanySnapshot = $company !== '' ? substr($company, 0, 160) : null;
$customerEmailSnapshot = $email !== '' ? substr($email, 0, 160) : null;
$customerMobileSnapshot = $mobile !== '' ? substr($mobile, 0, 30) : null;

// Ticket ref: CHAT-<year>-<5 digit running number>, sharing the one global per-year sequence
// with every other source (see next_ticket_ref_and_insert() in _bootstrap.php) — a
// manually-created ticket from the Sales side uses a different 3-letter prefix off the same
// counter, so the tickets list (sorted newest-first by ticket_ref) reflects true creation
// order across both sources instead of one prefix always sorting above the other.
[$ticketRef, $ticketId] = next_ticket_ref_and_insert($db, 'CHAT', function ($ticketRef) use (
    $db, $vertical, $assistType, $subject, $waitingOn, $leadId,
    $customerNameSnapshot, $customerCompanySnapshot, $customerEmailSnapshot, $customerMobileSnapshot,
    $description, $attachmentData, $attachmentName, $attachmentType
) {
    $ticketStmt = $db->prepare("
        INSERT INTO crm_tickets (
            ticket_ref, source, vertical, assist_type, subject, status, waiting_on, priority,
            lead_id, customer_name, customer_company, customer_email, customer_mobile,
            last_update_note, attachment_data, attachment_name, attachment_type
        )
        VALUES (?, 'chatbot', ?, ?, ?, 'open', ?, 'medium', ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $ticketStmt->bindValue(1, $ticketRef, PDO::PARAM_STR);
    $ticketStmt->bindValue(2, $vertical, PDO::PARAM_STR);
    $ticketStmt->bindValue(3, $assistType, PDO::PARAM_STR);
    $ticketStmt->bindValue(4, $subject, PDO::PARAM_STR);
    $ticketStmt->bindValue(5, $waitingOn, PDO::PARAM_STR);
    $ticketStmt->bindValue(6, $leadId, PDO::PARAM_INT);
    $ticketStmt->bindValue(7, $customerNameSnapshot, PDO::PARAM_STR);
    $ticketStmt->bindValue(8, $customerCompanySnapshot, PDO::PARAM_STR);
    $ticketStmt->bindValue(9, $customerEmailSnapshot, PDO::PARAM_STR);
    $ticketStmt->bindValue(10, $customerMobileSnapshot, PDO::PARAM_STR);
    if ($description !== '') {
        $ticketStmt->bindValue(11, $description, PDO::PARAM_STR);
    } else {
        $ticketStmt->bindValue(11, null, PDO::PARAM_NULL);
    }
    if ($attachmentData !== null) {
        $ticketStmt->bindValue(12, $attachmentData, PDO::PARAM_LOB);
    } else {
        $ticketStmt->bindValue(12, null, PDO::PARAM_NULL);
    }
    if ($attachmentName !== null) {
        $ticketStmt->bindValue(13, $attachmentName, PDO::PARAM_STR);
    } else {
        $ticketStmt->bindValue(13, null, PDO::PARAM_NULL);
    }
    if ($attachmentType !== null) {
        $ticketStmt->bindValue(14, $attachmentType, PDO::PARAM_STR);
    } else {
        $ticketStmt->bindValue(14, null, PDO::PARAM_NULL);
    }
    $ticketStmt->execute();
    return (int) $db->lastInsertId();
});

// Round-robin only applies to Sales tickets — Service tickets stay unassigned (owner_id NULL)
// but remain visible to the whole Service team via tickets.php's vertical scope.
//
// Assigns the ticket AND points the lead at the same exec (sales_assign_next does both). The
// Sales Leads page is where reps actually work these — it shows the lead with its latest ticket
// ref, and its Owner column is the LEAD's owner. Without the lead update the ref would show up
// owned by a different exec than the one the ticket was handed to, because a reused lead (same
// visitor within 6h) never re-assigns on its own. Latest request wins: whoever gets the newest
// ticket owns the conversation, matching what the ref column shows.
//
// One rotation call per ticket, off the shared pointer — this used to round-robin here while
// the lead's on_create rule round-robined off a private 'rule:1' pointer, so a single enquiry
// advanced two rotations and the rule's pick was immediately overwritten by this one.
$owner = $vertical === 'sales' ? sales_assign_next($db, $leadId, $ticketId, 'sales') : null;

// Fire on_create automations AFTER the owner is set, not at insert time: rule 1's surviving
// action notifies "to owner", and at insert the lead has no owner yet — firing there would
// notify nobody. Re-read the lead so the record carries the owner just assigned.
if ($isNewLead) {
    $row = $db->prepare("SELECT * FROM leads WHERE id=?");
    $row->execute([$leadId]);
    $fired = run_automations($db, 'lead', 'on_create', $row->fetch() ?: []);
}

// service + transaction requests open a live chat session immediately so a Service agent
// can pick it up from the waiting queue (via the same engine as sales-chat.php's inbox —
// chat_open_waiting_session() in _automation_engine.php); sales + callback stay ticket-only
// (no chat session). The token is what the public chatbot widget polls with (v_poll/v_post/
// v_close in api/sales/chat.php) — it must be returned or the widget has no way to attach.
$chatSessionId = null;
$chatToken = null;
$chatLastMessageId = null;
if ($opensLiveChat) {
    $greeting = 'Hi ' . $name . '! Thanks for reaching out — you\'re in the queue, a Service agent will join shortly.';
    $opened = chat_open_waiting_session($db, $leadId, $name, $email ?: null, $mobile, 'service', $description ?: null, $greeting);
    $chatSessionId = (int) $opened['session_id'];
    $chatToken = $opened['token'];
    $chatLastMessageId = $opened['last_message_id'];

    /* =========================================
       LINK THIS CHAT TO THE EXACT CREATED TICKET
       ========================================= */

    $linkChatTicket = $db->prepare("
        UPDATE chat_sessions
        SET ticket_id = ?
        WHERE id = ?
    ");

    $linkChatTicket->execute([
        $ticketId,
        $chatSessionId
    ]);
} elseif (in_array($assistType, ['service', 'transaction'], true)) {
    // Service/transaction request but NO Service exec was online to take it. We deliberately don't
    // open a live-chat window for the visitor (nobody's here to answer), but we DO record a chat
    // session marked 'missed'/'no_agent_online' so it surfaces on the Live Chat "Missed" tab — the
    // Service team's single view of "a customer tried to reach us live and we weren't available".
    chat_open_missed_session($db, $leadId, $name, $email ?: null, $mobile, 'service', $description ?: null, $ticketId);
}

echo json_encode([
    'success' => true,
    'ticket_ref' => $ticketRef,
    'lead_id' => $leadId,
    'vertical' => $vertical,
    'chat_session_id' => $chatSessionId,
    'chat_token' => $chatToken,
    'chat_last_message_id' => $chatLastMessageId,
    'deduped' => (bool) $existingLeadId,
    'automations' => $fired,
]);
