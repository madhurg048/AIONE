<?php
/**
 * AI + Voice settings, box health, and a "hit the box" tester (admin / IT).
 *   GET  ai-settings.php            -> config (keys masked) + live box health
 *   POST ai-settings.php            -> save config
 *   POST ai-settings.php?health=1   -> re-probe box /health
 *   POST ai-settings.php?test=1     { text, action(summarize|chat|translate), target_lang? }
 *   POST ai-settings.php?restart=1  -> POST /restart to bring the box up (needs restart_token)
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_ai.php';
require_login();
if (($_SESSION['user']['user_type'] ?? '') !== 'admin' && current_role() !== 'it') fail('Admin or IT only', 403);
$db = db();

$mask = fn($v) => $v ? '********' : '';
$keep = function ($new, $col) use ($db) {
    if (isset($new) && $new !== '' && $new !== '********') return $new;
    return $db->query("SELECT `$col` FROM ai_settings WHERE id=1")->fetchColumn();
};

if (method() === 'POST' && isset($_GET['health'])) {
    ok(crm_ai_box_health());
}

if (method() === 'POST' && isset($_GET['restart'])) {
    $s = crm_ai_settings();
    if (empty($s['box_url'])) fail('No box URL set');
    if (empty($s['restart_token'])) fail('No restart token set — cannot restart the box remotely', 400);
    $ch = curl_init(rtrim($s['box_url'], '/') . '/restart');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>json_encode(['scope'=>'software','confirm'=>true]),
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Restart-Token: '.$s['restart_token']],
        CURLOPT_TIMEOUT=>15, CURLOPT_SSL_VERIFYPEER=>false]);
    $resp = curl_exec($ch); $errno = curl_errno($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($errno) fail('Box unreachable — restart it manually on the host', 502);
    if ($code === 401 || $code === 403) fail('Invalid restart token', 502);
    if ($code === 503) fail('Restart disabled on the box (SUPERCHAT_RESTART_TOKEN not set)', 502);
    ok([], ['message' => 'Restart requested — the box should come up shortly']);
}

if (method() === 'POST' && isset($_GET['test'])) {
    $b = body();
    $text = trim($b['text'] ?? '');
    if ($text === '') fail('Enter some text to send');
    $action = in_array($b['action'] ?? '', ['summarize','chat','translate'], true) ? $b['action'] : 'chat';
    $path = $action === 'chat' ? 'chat_v3' : $action;
    $r = crm_ai_call($text, $b['response_length'] ?? 'short', $path);
    if (!$r) fail('No AI endpoint answered (box down and no fallback)', 502);
    ok(['response' => $r['response'], 'source' => $r['source']]);
}

if (method() === 'POST') {
    $b = body();
    // voice_url / voice_api_key are intentionally NOT written any more — speech-to-text
    // has been removed from the product. The columns still exist in the schema so old
    // rows load, but nothing reads or updates them.
    $db->prepare("UPDATE ai_settings SET box_enabled=?, box_url=?, ai_api_key=?, restart_token=?,
                  cloud_enabled=?, cloud_url=?, cloud_api_key=? WHERE id=1")
       ->execute([
           !empty($b['box_enabled']) ? 1 : 0, trim($b['box_url'] ?? ''),
           $keep($b['ai_api_key'] ?? null, 'ai_api_key'), $keep($b['restart_token'] ?? null, 'restart_token'),
           !empty($b['cloud_enabled']) ? 1 : 0, trim($b['cloud_url'] ?? ''),
           $keep($b['cloud_api_key'] ?? null, 'cloud_api_key'),
       ]);
    ok([], ['message' => 'AI settings saved']);
}

// GET — config with keys masked + live health
$c = $db->query("SELECT id,box_enabled,box_url,restart_token,cloud_enabled,cloud_url,ai_api_key,cloud_api_key FROM ai_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC) ?: [];
foreach (['ai_api_key','cloud_api_key','restart_token'] as $k) { $c['has_' . $k] = !empty($c[$k]) ? 1 : 0; unset($c[$k]); }
ok(['config' => $c, 'health' => ($c['box_enabled'] ?? 0) ? crm_ai_box_health($c['box_url'] ?? '') : ['up' => null, 'error' => 'box disabled']]);
