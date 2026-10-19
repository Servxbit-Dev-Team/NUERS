<?php
function verifyNuers(string $raw, array $h, array $cfg): array
{
    $id = $h['x-nuers-event'] ?? '';
    $ts = $h['x-nuers-timestamp'] ?? '';
    $sig = $h['x-nuers-signature'] ?? '';
    if (strlen($raw) > 65536 ||
        !preg_match('/^[a-f0-9-]{36}$/D', $id) ||
        !ctype_digit($ts) || strlen($ts) > 12 ||
        abs(time() - (int)$ts) > 300 ||
        !preg_match('/^v1=[a-f0-9]{64}$/D', $sig) ||
        ($h['x-nuers-environment'] ?? '') !== $cfg['environment'] ||
        ($h['x-nuers-key-version'] ?? '') !== $cfg['key_version']) {
        throw new RuntimeException('Invalid webhook headers');
    }
    $mac = hash_hmac('sha256', $ts.'.'.$id.'.'.$raw, $cfg['secret']);
    if (!hash_equals($mac, substr($sig, 3))) {
        throw new RuntimeException('Invalid webhook signature');
    }
    $event = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (($event['id'] ?? null) !== $id ||
        ($event['environment'] ?? null) !== $cfg['environment'] ||
        ($event['merchant_id'] ?? null) !== $cfg['merchant_id']) {
        throw new RuntimeException('Unexpected webhook identity');
    }
    return $event;
}
