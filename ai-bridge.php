<?php
/**
 * chat_v3 -> OpenAI adapter (bridges the CRM's {user_input} contract to any
 * OpenAI-compatible LLM). Used as the last-resort AI fallback.
 * Defaults to the reachable Hetzner LLM so AI keeps working even when the
 * on-prem AI box is disabled or down. Override with env LLM_URL / LLM_KEY.
 * LLM_KEY has no hardcoded default — set it in the environment (or .env).
 */
require_once __DIR__ . '/config/app.php';
header('Content-Type: application/json');
$LLM = getenv('LLM_URL') ?: 'https://167.235.90.27/v1/chat/completions';   // Hetzner Qwen3 fallback
$LLM_KEY = getenv('LLM_KEY') ?: (getenv('SERVICE_CHAT_KEY') ?: '');
$in = json_decode(file_get_contents('php://input'), true) ?: [];
$userInput = $in['user_input'] ?? '';
$len = $in['response_length'] ?? 'medium';
$maxTok = ['short'=>350,'medium'=>700,'long'=>1200][$len] ?? 700;

$payload = [
  'messages' => [
    ['role'=>'system','content'=>'You are a precise sales-operations analyst. When asked for JSON, output ONLY raw JSON.'],
    ['role'=>'user','content'=>$userInput],
  ],
  'max_tokens' => $maxTok,
  'temperature' => 0.3,
];
$headers = ['Content-Type: application/json'];
if ($LLM_KEY) $headers[] = 'Authorization: Bearer ' . $LLM_KEY;
$ch = curl_init($LLM);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
  CURLOPT_POSTFIELDS=>json_encode($payload), CURLOPT_HTTPHEADER=>$headers,
  CURLOPT_CONNECTTIMEOUT=>6, CURLOPT_TIMEOUT=>60, CURLOPT_SSL_VERIFYPEER=>false, CURLOPT_SSL_VERIFYHOST=>false]);
$resp = curl_exec($ch); $err = curl_errno($ch); curl_close($ch);
if ($err) { echo json_encode(['error'=>'llm unreachable']); exit; }
$j = json_decode($resp, true);
$content = $j['choices'][0]['message']['content'] ?? '';
echo json_encode(['response'=>$content]);
