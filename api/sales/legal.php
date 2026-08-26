<?php
/**
 * Legal documents (Governance → Legal) — the public payg.in legal pages,
 * managed here by admin + the "Compliance & Legal" group.
 *
 *   GET  legal.php?list=1            -> documents + revision counts
 *   GET  legal.php?id=N              -> one document + its revisions (meta only)
 *   GET  legal.php?revision=N        -> a revision's full content
 *   POST legal.php                   -> create {slug, title}
 *   PATCH legal.php?id=N             -> save {title?, content?} (snapshots the old version)
 *   POST legal.php?action=publish&id=N    | ?action=unpublish&id=N
 *   POST legal.php?action=restore&revision=N  -> restore a revision as the current draft
 *
 * PUBLISHED documents are served to the website by legal-public.php the
 * moment they're published — no deploy needed.
 */
require_once __DIR__ . '/_bootstrap.php';
require_login();
require_module('legal');
$db = db();
$me = current_employee_id();

function legal_snapshot(PDO $db, array $doc, ?int $by): void
{
    $db->prepare("INSERT INTO legal_document_revisions (document_id, title, content, status, saved_by)
                  VALUES (?,?,?,?,?)")
       ->execute([(int)$doc['id'], $doc['title'], $doc['content'], $doc['status'], $by]);
}

if (method() === 'GET' && isset($_GET['list'])) {
    ok($db->query("SELECT d.id, d.slug, d.title, d.status, d.published_at, d.updated_at,
                          LENGTH(COALESCE(d.content,'')) AS content_len,
                          e.full_name AS updated_by_name,
                          (SELECT COUNT(*) FROM legal_document_revisions r WHERE r.document_id=d.id) AS revisions
                     FROM legal_documents d LEFT JOIN employees e ON e.id=d.updated_by
                    ORDER BY d.title")->fetchAll());
}

if (method() === 'GET' && isset($_GET['revision'])) {
    $s = $db->prepare("SELECT r.*, e.full_name AS saved_by_name FROM legal_document_revisions r
                  LEFT JOIN employees e ON e.id=r.saved_by WHERE r.id=?");
    $s->execute([(int)$_GET['revision']]);
    $r = $s->fetch();
    if (!$r) fail('Revision not found', 404);
    ok($r);
}

if (method() === 'GET' && isset($_GET['id'])) {
    $s = $db->prepare("SELECT d.*, e.full_name AS updated_by_name FROM legal_documents d
                  LEFT JOIN employees e ON e.id=d.updated_by WHERE d.id=?");
    $s->execute([(int)$_GET['id']]);
    $doc = $s->fetch();
    if (!$doc) fail('Not found', 404);
    $rv = $db->prepare("SELECT r.id, r.status, r.created_at, e.full_name AS saved_by_name,
                               LENGTH(COALESCE(r.content,'')) AS content_len
                          FROM legal_document_revisions r LEFT JOIN employees e ON e.id=r.saved_by
                         WHERE r.document_id=? ORDER BY r.id DESC LIMIT 30");
    $rv->execute([(int)$doc['id']]);
    $doc['revision_list'] = $rv->fetchAll();
    ok($doc);
}

if (method() === 'POST' && !isset($_GET['action'])) {  // create
    $b = body();
    $slug = strtolower(preg_replace('/[^a-z0-9\-]/', '', str_replace(' ', '-', (string)($b['slug'] ?? ''))));
    $title = trim((string)($b['title'] ?? ''));
    if ($slug === '' || $title === '') fail('slug and title required');
    try {
        $db->prepare("INSERT INTO legal_documents (slug, title, content, updated_by) VALUES (?,?,?,?)")
           ->execute([$slug, $title, (string)($b['content'] ?? ''), $me ?: null]);
    } catch (Throwable $e) { fail('A document with that slug already exists'); }
    ok(['id' => (int)$db->lastInsertId(), 'slug' => $slug]);
}

if ((method() === 'PATCH' || method() === 'PUT') && isset($_GET['id'])) {
    $id = (int)$_GET['id']; $b = body();
    $s = $db->prepare("SELECT * FROM legal_documents WHERE id=?"); $s->execute([$id]);
    $doc = $s->fetch();
    if (!$doc) fail('Not found', 404);
    $set = []; $args = [];
    if (isset($b['title']) && trim($b['title']) !== '') { $set[] = "title=?"; $args[] = trim($b['title']); }
    if (array_key_exists('content', $b)) {
        if ((string)$b['content'] !== (string)$doc['content']) legal_snapshot($db, $doc, $me ?: null);
        $set[] = "content=?"; $args[] = (string)$b['content'];
    }
    if (!$set) fail('Nothing to save');
    $set[] = "updated_by=?"; $args[] = $me ?: null;
    $args[] = $id;
    $db->prepare("UPDATE legal_documents SET " . implode(',', $set) . " WHERE id=?")->execute($args);
    ok(['id' => $id]);
}

if (method() === 'POST' && isset($_GET['action'])) {
    $action = $_GET['action'];
    if ($action === 'restore' && isset($_GET['revision'])) {
        $s = $db->prepare("SELECT r.*, d.id AS doc_id FROM legal_document_revisions r
                             JOIN legal_documents d ON d.id=r.document_id WHERE r.id=?");
        $s->execute([(int)$_GET['revision']]);
        $r = $s->fetch();
        if (!$r) fail('Revision not found', 404);
        $cur = $db->prepare("SELECT * FROM legal_documents WHERE id=?"); $cur->execute([(int)$r['doc_id']]);
        legal_snapshot($db, $cur->fetch(), $me ?: null);
        $db->prepare("UPDATE legal_documents SET content=?, updated_by=? WHERE id=?")
           ->execute([$r['content'], $me ?: null, (int)$r['doc_id']]);
        ok(['id' => (int)$r['doc_id']]);
    }
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) fail('id required');
    if ($action === 'publish') {
        $db->prepare("UPDATE legal_documents SET status='published', published_at=NOW(), updated_by=? WHERE id=?")
           ->execute([$me ?: null, $id]);
        ok(['status' => 'published']);
    }
    if ($action === 'unpublish') {
        $db->prepare("UPDATE legal_documents SET status='draft', updated_by=? WHERE id=?")->execute([$me ?: null, $id]);
        ok(['status' => 'draft']);
    }
    fail('Unknown action');
}

fail('Unsupported request', 405);
