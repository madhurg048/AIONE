<?php
/**
 * Web-to-Lead — PUBLIC inbound endpoint (no auth). Embed a form on any site that
 * POSTs here; it creates a lead and fires on_create automations (round-robin
 * assignment + welcome email happen automatically).
 *
 *   POST web-to-lead.php  (application/json OR form-encoded)
 *     first_name,last_name,company,email,phone,message, src(optional), website(honeypot)
 *
 * Security: honeypot field `website` must be empty; basic rate note in README.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/_automation_engine.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');          // tighten to your domains in prod
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'error'=>'POST required']); exit; }

$raw = file_get_contents('php://input');
$b = json_decode($raw, true);
if (!is_array($b)) $b = $_POST;

// Honeypot: real users never fill this hidden field.
if (!empty($b['website'])) { echo json_encode(['success'=>true]); exit; }

$email = trim($b['email'] ?? '');
$phone = trim($b['phone'] ?? '');
if ($email === '' && $phone === '') { http_response_code(400); echo json_encode(['success'=>false,'error'=>'email or phone required']); exit; }
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { http_response_code(400); echo json_encode(['success'=>false,'error'=>'invalid email']); exit; }

$db = getDB();
if (!$db) { http_response_code(500); echo json_encode(['success'=>false,'error'=>'unavailable']); exit; }

// Track source
$src = preg_replace('/[^a-z0-9_\-]/i', '', substr($b['src'] ?? 'web', 0, 60)) ?: 'web';
try {
    $db->prepare("INSERT INTO web_lead_sources (source_key,label,hits) VALUES (?,?,1)
                  ON DUPLICATE KEY UPDATE hits=hits+1")->execute([$src, $src]);
} catch (Throwable $e) { /* table optional */ }

// De-dupe: same email in last 24h -> don't double-create
if ($email) {
    $chk = $db->prepare("SELECT id FROM leads WHERE email=? AND created_at > NOW()-INTERVAL 1 DAY LIMIT 1");
    $chk->execute([$email]);
    if ($chk->fetchColumn()) { echo json_encode(['success'=>true,'deduped'=>true]); exit; }
}

$ins = $db->prepare("INSERT INTO leads (first_name,last_name,company,email,phone,lead_source,lead_status,description)
                     VALUES (?,?,?,?,?, ?, 'new', ?)");
$ins->execute([
    substr($b['first_name'] ?? '', 0, 80), substr($b['last_name'] ?? ($b['name'] ?? 'Web Lead'), 0, 80),
    substr($b['company'] ?? '', 0, 150), $email, $phone, $src,   // was hardcoded 'web' — now honours ?src
    substr($b['message'] ?? '', 0, 1000)
]);
$id = (int)$db->lastInsertId();

// Assign off the shared rotation, before automations fire. This used to be the 'Round-robin
// assign new leads' rule's job, but assignment now lives in code (the rule only notifies) so
// that a web form submission can never land as an unowned lead just because someone toggled a
// rule off in the UI. Automations run after, so rule 1's "notify the owner" has an owner.
$owner = sales_assign_next($db, $id, null, 'sales');

$row = $db->prepare("SELECT * FROM leads WHERE id=?");
$row->execute([$id]);
$fired = run_automations($db, 'lead', 'on_create', $row->fetch() ?: []);

echo json_encode(['success'=>true, 'lead_id'=>$id, 'automations'=>$fired]);
