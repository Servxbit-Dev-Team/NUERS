<?php
function sendNuers(string $savedJson): array
{
    $event = json_decode($savedJson, true, 64,
        JSON_THROW_ON_ERROR);
    $key = getenv('NUERS_API_KEY');
    $env = getenv('NUERS_ENVIRONMENT');
    if (!$key || !in_array($env, ['live', 'sandbox'], true)) {
        throw new RuntimeException('Missing connector config');
    }
    $retryAfter = null;
    $ch = curl_init(
        'https://nuers.net/api/integrations/transactions'
    );
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $savedJson,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'X-NUERS-API-Key: '.$key,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_HEADERFUNCTION =>
            function ($ch, $line) use (&$retryAfter) {
                if (stripos($line, 'Retry-After:') === 0) {
                    $retryAfter = trim(substr($line, 12));
                }
                return strlen($line);
            },
    ]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($raw === false) {
        throw new RuntimeException('Transport outcome unknown');
    }
    $body = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    $accepted = $status === 201 ||
        ($status === 200 && ($body['duplicate'] ?? false) === true);
    $valid = $accepted && !empty($body['id']) &&
        !empty($body['transaction_id']) &&
        !empty($body['receipt_id']) &&
        ($body['environment'] ?? null) === $env &&
        ($body['status'] ?? null) === 'durably_recorded' &&
        ($body['nuers_status'] ?? null) === 'success' &&
        ($body['source_event_id'] ?? null) ===
            $event['source_event_id'] &&
        ($body['external_reference'] ?? null) ===
            $event['external_reference'];
    if (!$valid) {
        return ['acknowledged' => false,
            'http_status' => $status,
            'retry_after' => $retryAfter];
    }
    return ['acknowledged' => true, 'response' => $body];
}
