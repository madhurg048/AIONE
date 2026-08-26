<?php
/**
 * Per-app API-key auth for the centralized messaging services.
 * Keys are minted on IT → Centralized APIs → App API Keys (api_apps table).
 */

/** The raw key from X-API-Key / Authorization: Bearer. */
function app_auth_key(): string
{
    $k = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if ($k === '' && preg_match('/Bearer\s+(.+)/i', $_SERVER['HTTP_AUTHORIZATION'] ?? '', $m)) $k = trim($m[1]);
    return trim((string)$k);
}

/**
 * Resolve the calling app if the key belongs to api_apps and carries $scope.
 * Returns the app row or null (so callers can fall back to legacy tokens).
 * A matching-but-wrong-scope app key fails hard — no silent privilege creep.
 */
function app_auth_optional(PDO $db, string $scope): ?array
{
    $key = app_auth_key();
    if ($key === '') return null;
    $s = $db->prepare("SELECT * FROM api_apps WHERE api_key=? AND is_active=1");
    $s->execute([$key]);
    $app = $s->fetch(PDO::FETCH_ASSOC);
    if (!$app) return null;
    $scopes = array_map('trim', explode(',', (string)$app['scopes']));
    if (!in_array($scope, $scopes, true)) ifail("This app key does not carry the '$scope' scope", 403);
    try { $db->prepare("UPDATE api_apps SET last_used_at=NOW() WHERE id=?")->execute([(int)$app['id']]); } catch (Throwable $e) {}
    return $app;
}

/** Hard-require an app key with $scope (401/403 otherwise). */
function app_auth(PDO $db, string $scope): array
{
    $app = app_auth_optional($db, $scope);
    if (!$app) ifail('Invalid or inactive app API key', 401);
    return $app;
}
