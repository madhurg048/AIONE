<?php
/**
 * PUBLIC read-only feed of PUBLISHED legal documents — the payg.in website
 * renders its legal pages from this, so an update published in Governance →
 * Legal goes live on the site immediately (no deploy).
 *
 *   GET legal-public.php?slug=privacy-policy
 *     -> { success, title, content, published_at }   (published docs only)
 */
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=300');

$slug = strtolower(preg_replace('/[^a-z0-9\-]/', '', (string)($_GET['slug'] ?? '')));
if ($slug === '') { http_response_code(400); echo json_encode(['success' => false, 'error' => 'slug required']); exit; }

$db = getDB();
if (!$db) { http_response_code(500); echo json_encode(['success' => false, 'error' => 'unavailable']); exit; }

try {
    $s = $db->prepare("SELECT title, content, published_at FROM legal_documents WHERE slug=? AND status='published'");
    $s->execute([$slug]);
    $doc = $s->fetch(PDO::FETCH_ASSOC);
    if (!$doc || trim((string)$doc['content']) === '') {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'not published']);
        exit;
    }
    echo json_encode(['success' => true, 'title' => $doc['title'], 'content' => $doc['content'],
                      'published_at' => $doc['published_at']]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'unavailable']);
}
