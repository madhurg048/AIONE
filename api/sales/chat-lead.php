<?php
/**
 * Live-chat → Lead capture. PUBLIC inbound endpoint (no auth) for the website chat widget.
 * Creates a lead with source = "live_chat", stores the conversation transcript, fires
 * on_create automations (round-robin assign + welcome), and returns a friendly reference.
 *
 *   POST chat-lead.php  (application/json)
 *     { name, email, phone, company?, interest?, transcript?[ {from,text} ], website(honeypot) }
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/_automation_engine.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');           // tighten to your domains in prod
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'error'=>'POST required']); exit; }

$b = json_decode(file_get_contents('php://input'), true);
if (!is_array($b)) $b = $_POST;

// honeypot
if (!empty($b['website'])) { echo json_encode(['success'=>true,'lead_ref'=>'LC000000']); exit; }

$name  = trim($b['name'] ?? '');
$email = trim($b['email'] ?? '');
$phone = trim($b['phone'] ?? '');
if ($email === '' && $phone === '') { http_response_code(400); echo json_encode(['success'=>false,'error'=>'email or phone required']); exit; }
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { http_response_code(400); echo json_encode(['success'=>false,'error'=>'invalid email']); exit; }

$db = getDB();
if (!$db) { http_response_code(500); echo json_encode(['success'=>false,'error'=>'unavailable']); exit; }

// split name -> first / last
$parts = preg_split('/\s+/', $name, 2);
$first = $parts[0] ?? '';
$last  = $parts[1] ?? ($b['company'] ?? 'Live Chat Lead');

// build the description from interest + transcript
$desc = trim($b['interest'] ?? '');
if (!empty($b['transcript']) && is_array($b['transcript'])) {
    $lines = [];
    foreach ($b['transcript'] as $m) {
        $who = ($m['from'] ?? 'visitor') === 'bot' ? 'PayG' : 'Visitor';
        $lines[] = $who . ': ' . trim($m['text'] ?? '');
    }
    if ($lines) $desc .= "\n\n— Chat transcript —\n" . implode("\n", $lines);
}
$desc = substr(trim($desc), 0, 2000);

// de-dupe: same email/phone in the last 6 hours -> update the transcript instead of a new lead
$existing = null;
if ($email) { $s = $db->prepare("SELECT id FROM leads WHERE email=? AND created_at>NOW()-INTERVAL 6 HOUR ORDER BY id DESC LIMIT 1"); $s->execute([$email]); $existing = $s->fetchColumn(); }
if (!$existing && $phone) { $s = $db->prepare("SELECT id FROM leads WHERE phone=? AND created_at>NOW()-INTERVAL 6 HOUR ORDER BY id DESC LIMIT 1"); $s->execute([$phone]); $existing = $s->fetchColumn(); }

if ($existing) {
    $db->prepare("UPDATE leads SET description=?, last_contacted_at=NOW() WHERE id=?")->execute([$desc, (int)$existing]);
    echo json_encode(['success'=>true, 'lead_id'=>(int)$existing, 'lead_ref'=>'LC' . str_pad((string)$existing,6,'0',STR_PAD_LEFT), 'deduped'=>true]);
    exit;
}

$ins = $db->prepare("INSERT INTO leads (first_name,last_name,company,email,phone,mobile,lead_source,lead_status,description)
                     VALUES (?,?,?,?,?,?, 'live_chat', 'new', ?)");
$ins->execute([
    substr($first,0,80), substr($last,0,80), substr($b['company'] ?? '',0,150),
    $email, $phone, $phone, $desc
]);
$id = (int)$db->lastInsertId();

// track the source hit (optional table)
try { $db->prepare("INSERT INTO web_lead_sources (source_key,label,hits) VALUES ('live_chat','Live Chat',1)
                    ON DUPLICATE KEY UPDATE hits=hits+1")->execute(); } catch (Throwable $e) {}

// Assign off the shared rotation before automations fire — see sales_assign_next(). Assignment
// is no longer the on_create rule's job (it only notifies now), so this can't silently stop.
$owner = sales_assign_next($db, $id, null, 'sales');

// fire on_create automations (welcome email, notify the owner assigned above…)
$row = $db->prepare("SELECT * FROM leads WHERE id=?"); $row->execute([$id]);
$fired = run_automations($db, 'lead', 'on_create', $row->fetch() ?: []);

echo json_encode(['success'=>true, 'lead_id'=>$id, 'lead_ref'=>'LC'.str_pad((string)$id,6,'0',STR_PAD_LEFT), 'automations'=>$fired]);
