<?php
/**
 * Document Q&A over the AI box (Superchat Chat API).
 * Upload a merchant/lead document to the box, then ask grounded questions or get a summary.
 *   GET  doc-ai.php?merchant_id=5           -> list uploaded docs
 *   POST doc-ai.php?action=upload           -> multipart { file, merchant_id? , lead_id? }
 *   POST doc-ai.php?action=ask              -> { doc_id, question }
 *   POST doc-ai.php?action=summary          -> { doc_id }
 *   POST doc-ai.php?action=delete&id=       -> remove the reference
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_ai.php';
require_login();
$db = db();
$me = current_employee_id();

function box_or_fail(): array {
    $s = crm_ai_settings();
    if (!$s || empty($s['box_url'])) fail('AI box URL not set (Settings → AI & Voice)', 400);
    $h = crm_ai_box_health($s['box_url']);
    if (empty($h['up'])) fail('AI box is down — document Q&A needs the box online', 502);
    return $s;
}
function box_post($url, $fields, $key, $multipart = false) {
    $ch = curl_init($url);
    $headers = $multipart ? [] : ['Content-Type: application/json'];
    if ($key) $headers[] = 'Authorization: Bearer ' . $key;
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>$multipart ? $fields : json_encode($fields),
        CURLOPT_HTTPHEADER=>$headers, CURLOPT_TIMEOUT=>60, CURLOPT_SSL_VERIFYPEER=>false]);
    $resp = curl_exec($ch); $errno = curl_errno($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$errno, $code, json_decode($resp, true)];
}

/* ---- list ---- (docs carry PAN/GST/KYC-grade merchant data — only the owner or a manager/admin may see them) */
if (method() === 'GET') {
    if (!empty($_GET['merchant_id'])) {
        $m = $db->prepare("SELECT owner_id FROM merchants WHERE id=?"); $m->execute([(int)$_GET['merchant_id']]);
        $owner = $m->fetchColumn();
        if ($owner === false) fail('Merchant not found', 404);
        assert_can_see($owner);
    } elseif (!empty($_GET['lead_id'])) {
        $l = $db->prepare("SELECT owner_id FROM leads WHERE id=?"); $l->execute([(int)$_GET['lead_id']]);
        $owner = $l->fetchColumn();
        if ($owner === false) fail('Lead not found', 404);
        assert_can_see($owner);
    } elseif (!can_manage_all_records()) {
        fail('merchant_id or lead_id required', 400);
    }
    $where = []; $args = [];
    if (!empty($_GET['merchant_id'])) { $where[] = 'merchant_id=?'; $args[] = (int)$_GET['merchant_id']; }
    if (!empty($_GET['lead_id']))     { $where[] = 'lead_id=?';     $args[] = (int)$_GET['lead_id']; }
    $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $s = $db->prepare("SELECT id,filename,mode,chunk_count,created_at FROM ai_documents $w ORDER BY id DESC");
    $s->execute($args);
    ok($s->fetchAll());
}

/** Guard: only the merchant/lead owner or a manager/admin may act on this ai_documents row. */
function doc_assert_can_see(PDO $db, array $d): void {
    if (!empty($d['merchant_id'])) {
        $m = $db->prepare("SELECT owner_id FROM merchants WHERE id=?"); $m->execute([$d['merchant_id']]);
        assert_can_see($m->fetchColumn());
    } elseif (!empty($d['lead_id'])) {
        $l = $db->prepare("SELECT owner_id FROM leads WHERE id=?"); $l->execute([$d['lead_id']]);
        assert_can_see($l->fetchColumn());
    } elseif (!can_manage_all_records()) {
        fail('Not authorized to view this document', 403);
    }
}

/* ---- upload ---- */
if (method() === 'POST' && ($_GET['action'] ?? '') === 'upload') {
    if (!empty($_POST['merchant_id'])) {
        $m = $db->prepare("SELECT owner_id FROM merchants WHERE id=?"); $m->execute([(int)$_POST['merchant_id']]);
        $owner = $m->fetchColumn();
        if ($owner === false) fail('Merchant not found', 404);
        assert_can_see($owner);
    } elseif (!empty($_POST['lead_id'])) {
        $l = $db->prepare("SELECT owner_id FROM leads WHERE id=?"); $l->execute([(int)$_POST['lead_id']]);
        $owner = $l->fetchColumn();
        if ($owner === false) fail('Lead not found', 404);
        assert_can_see($owner);
    }
    $s = box_or_fail();
    if (empty($_FILES['file']['tmp_name'])) fail('No file uploaded');
    $name = $_FILES['file']['name'];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf','docx','txt','csv'], true)) fail('Unsupported file type (pdf, docx, txt, csv)');
    $base = rtrim($s['box_url'], '/');
    $key = $s['ai_api_key'] ?: null;
    $cf = new CURLFile($_FILES['file']['tmp_name'], $_FILES['file']['type'] ?: 'application/octet-stream', $name);

    // prefer /document/upload (embeddings → Q&A); fall back to /file/upload (summary only)
    $mode = 'qa'; $chunks = 0;
    [$e1, $c1, $j1] = box_post($base . '/document/upload', ['file' => $cf], $key, true);
    $sid = $j1['session_id'] ?? null;
    if ($sid) { $chunks = (int)($j1['chunk_count'] ?? 0); }
    else {
        $cf2 = new CURLFile($_FILES['file']['tmp_name'], $_FILES['file']['type'] ?: 'application/octet-stream', $name);
        [$e2, $c2, $j2] = box_post($base . '/file/upload', ['file' => $cf2], $key, true);
        $sid = $j2['session_id'] ?? null;
        if (!$sid) fail('Box could not process the document: ' . ($j1['detail'] ?? $j2['detail'] ?? 'error'), 502);
        $mode = 'summary';
    }

    $db->prepare("INSERT INTO ai_documents (merchant_id,lead_id,filename,box_session_id,mode,chunk_count,uploaded_by)
                  VALUES (?,?,?,?,?,?,?)")
       ->execute([$_POST['merchant_id'] ?: null, $_POST['lead_id'] ?: null, $name, $sid, $mode, $chunks, $me ?: null]);
    ok(['id' => (int)$db->lastInsertId(), 'mode' => $mode, 'filename' => $name],
       ['message' => $mode === 'qa' ? 'Uploaded — you can ask questions now' : 'Uploaded (summary only — box has no embeddings)']);
}

/* ---- ask / summary ---- */
if (method() === 'POST' && in_array($_GET['action'] ?? '', ['ask','summary'], true)) {
    $s = box_or_fail();
    $b = body();
    $doc = $db->prepare("SELECT * FROM ai_documents WHERE id=?"); $doc->execute([(int)($b['doc_id'] ?? 0)]);
    $d = $doc->fetch();
    if (!$d) fail('Document not found', 404);
    doc_assert_can_see($db, $d);
    $base = rtrim($s['box_url'], '/'); $key = $s['ai_api_key'] ?: null;

    if (($_GET['action'] === 'summary') || $d['mode'] === 'summary') {
        if ($d['mode'] === 'qa') {
            [$e,$c,$j] = box_post($base . '/document/ask', ['session_id'=>$d['box_session_id'], 'question'=>'Summarize this document in a few clear bullet points.', 'response_length'=>'medium'], $key);
        } else {
            [$e,$c,$j] = box_post($base . '/file/summarize', ['session_id'=>$d['box_session_id'], 'response_length'=>'medium'], $key);
        }
    } else {
        $q = trim($b['question'] ?? '');
        if ($q === '') fail('Enter a question');
        [$e,$c,$j] = box_post($base . '/document/ask', ['session_id'=>$d['box_session_id'], 'question'=>$q, 'response_length'=>'medium'], $key);
    }
    if ($e) fail('AI box unreachable', 502);
    $answer = $j['response'] ?? $j['summary'] ?? null;
    if ($answer === null) fail('Box returned no answer' . ($c === 404 ? ' (session expired — re-upload)' : ''), 502);
    ok(['answer' => $answer]);
}

/* ---- delete ---- */
if (method() === 'POST' && ($_GET['action'] ?? '') === 'delete') {
    $id = (int)($_GET['id'] ?? 0);
    $doc = $db->prepare("SELECT * FROM ai_documents WHERE id=?"); $doc->execute([$id]);
    $d = $doc->fetch();
    if (!$d) fail('Document not found', 404);
    doc_assert_can_see($db, $d);
    $db->prepare("DELETE FROM ai_documents WHERE id=?")->execute([$id]);
    ok([]);
}

fail('Unsupported', 405);
