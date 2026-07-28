<?php
/**
 * Call-recording transcription. Uses the same contract as voice-transcribe.php's STT service
 * (raw audio bytes POSTed with Authorization: Bearer <api_key>, JSON {"text":...} back) since
 * it's the same physical server — configured independently here (transcription_settings) so
 * it can be enabled/pointed at a different URL/key without touching timesheet voice dictation.
 */

function crm_transcription_settings(PDO $db): ?array
{
    try { return $db->query("SELECT * FROM transcription_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC) ?: null; }
    catch (Throwable $e) { return null; }
}

/**
 * Downloads a recording URL into raw bytes. Exotel recording URLs are usually fetchable as-is;
 * some accounts gate them behind the same API key/token used for placing calls, so retry with
 * that Basic auth if the plain GET fails. Returns [bytes|null, mime].
 */
function crm_download_recording(string $url, ?array $telephonyCfg): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => false]);
    $bytes = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $errno = curl_errno($ch);
    curl_close($ch);
    if (!$errno && $code >= 200 && $code < 300 && $bytes) return [$bytes, $ctype ?: 'audio/mpeg'];

    if ($telephonyCfg && !empty($telephonyCfg['api_key']) && !empty($telephonyCfg['api_token'])) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERPWD => $telephonyCfg['api_key'] . ':' . $telephonyCfg['api_token'],
        ]);
        $bytes = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ctype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $errno = curl_errno($ch);
        curl_close($ch);
        if (!$errno && $code >= 200 && $code < 300 && $bytes) return [$bytes, $ctype ?: 'audio/mpeg'];
    }
    return [null, null];
}

/** Transcribe a call recording URL. Returns [ok, transcript|null, error|null]. */
function crm_transcribe_recording(PDO $db, string $recordingUrl, ?array $telephonyCfg = null): array
{
    $cfg = crm_transcription_settings($db);
    if (!$cfg || !$cfg['enabled'] || !$cfg['api_url'] || !$cfg['api_key']) {
        return [false, null, 'Transcription is not enabled/configured (Settings → Call Transcription)'];
    }

    [$bytes, $mime] = crm_download_recording($recordingUrl, $telephonyCfg);
    if (!$bytes) return [false, null, 'Could not download the recording'];

    $lang = $cfg['lang'] ?: 'en';
    $url = rtrim($cfg['api_url'], '/') . (strpos($cfg['api_url'], '?') === false ? '?' : '&') . 'lang=' . rawurlencode($lang);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $bytes,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $cfg['api_key'], 'Content-Type: ' . $mime],
        CURLOPT_TIMEOUT => 60, CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch); $errno = curl_errno($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($errno) return [false, null, 'Transcription service unreachable'];
    if ($code === 401 || $code === 403) return [false, null, 'Transcription auth failed (check API key)'];

    $j = json_decode((string)$resp, true);
    $text = is_array($j) ? ($j['text'] ?? $j['transcript'] ?? $j['result'] ?? null) : null;
    if ($text === null || trim((string)$text) === '') return [false, null, 'No transcript returned'];
    return [true, trim($text), null];
}
