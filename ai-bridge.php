<?php
/**
 * chat_v3 -> OpenAI adapter (bridges the CRM's {user_input} contract to any
 * OpenAI-compatible LLM). Used as the last-resort AI fallback.
 * Endpoint + key come from the environment (LLM_URL / LLM_KEY). With no LLM_URL
 * set this simply reports the AI as unavailable — every caller already handles
 * that (pages fall back to evidence-only mode).
 */
require_once __DIR__ . '/config/env.php';

header('Content-Type: application/json');
$LLM     = env('LLM_URL', '');
$LLM_KEY = env('LLM_KEY', '');
if ($LLM === '') { http_response_code(503); echo json_encode(['error' => 'llm not configured']); exit; }
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
