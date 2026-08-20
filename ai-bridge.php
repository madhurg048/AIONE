<?php
/**
 * DISABLED — third-party LLM bridge (removed from the product).
 *
 * This file used to forward CRM prompts to an external OpenAI-compatible endpoint
 * (LLM_URL / LLM_KEY) as a last-resort AI fallback. That behaviour has been removed:
 * AI now runs ONLY against the AI box URL configured in Settings → AI Engine, and no
 * CRM data is sent to any third-party host.
 *
 * The endpoint is kept (rather than deleted) so any stale configuration still pointing
 * here fails loudly and closed instead of 404-ing into a confusing error. Nothing in the
 * CRM calls it — see api/sales/_ai.php, which is box-only.
 */

http_response_code(410);
header('Content-Type: application/json');
echo json_encode([
    'error'  => 'ai bridge disabled',
    'detail' => 'External AI fallback has been removed. Configure the AI box in Settings → AI Engine.',
]);
