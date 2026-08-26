<?php
/**
 * Shared bootstrap for EXTERNAL integration endpoints (machine-to-machine).
 * Auth = API token via header  X-API-Key: <token>  or  Authorization: Bearer <token>.
 * No PHP session (these are server-to-server calls).
 */
require_once __DIR__ . '/../../config/database.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

function idb(): PDO { $db = getDB(); if (!$db){ http_response_code(500); echo json_encode(['success'=>false,'error'=>'db']); exit; } return $db; }
function ibody(): array { $j = json_decode(file_get_contents('php://input'), true); return is_array($j) ? $j : $_POST; }
function iok($d = [], array $x = []): void { echo json_encode(array_merge(['success'=>true], (array)$d, $x)); exit; }
function ifail(string $m, int $c = 400): void { http_response_code($c); echo json_encode(['success'=>false,'error'=>$m]); exit; }

function integration_auth(PDO $db, string $scope): array {
    $hdr = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (!$hdr && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
        if (preg_match('/Bearer\s+(.+)/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) $hdr = trim($m[1]);
    }
    if ($hdr === '') ifail('Missing API key (X-API-Key header)', 401);
    $s = $db->prepare("SELECT * FROM integration_tokens WHERE token=? AND is_active=1");
    $s->execute([$hdr]);
    $tok = $s->fetch(PDO::FETCH_ASSOC);
    if (!$tok) ifail('Invalid or inactive API key', 401);
    $scopes = array_values(array_filter(array_map('trim', explode(',', (string)($tok['scopes'] ?? '')))));
    if ($scopes && !in_array($scope, $scopes, true)) ifail("Key not allowed for scope '$scope'", 403);
    $db->prepare("UPDATE integration_tokens SET last_used_at=NOW() WHERE id=?")->execute([$tok['id']]);
    return $tok;
}

/** Resolve a merchant by merchant_code (MID) or numeric id. */
function resolve_merchant(PDO $db, array $b): ?array {
    if (!empty($b['merchant_id'])) { $s = $db->prepare("SELECT * FROM merchants WHERE id=?"); $s->execute([(int)$b['merchant_id']]); }
    elseif (!empty($b['merchant_code'])) { $s = $db->prepare("SELECT * FROM merchants WHERE merchant_code=?"); $s->execute([$b['merchant_code']]); }
    else return null;
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}
