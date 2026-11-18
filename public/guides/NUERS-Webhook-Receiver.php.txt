<?php
/** Example receiver core, PHP 8.2+. Your HTTPS route supplies raw bytes and private configuration.
 * Run business work asynchronously from the durable inbox. Never return 2xx before its commit.
 * $settings: secret, key_version, environment, merchant_id. Keep separate per environment.
 * Headers must use the lower-case names below; do not concatenate duplicate security headers.
 */
function nuers_webhook_receive(string $raw, array $headers, array $settings, PDO $db): array
{
    if (strlen($raw) > 65536) return [413, ['error' => 'body_too_large']];
    $id = $headers['x-nuers-event'] ?? '';
    $at = $headers['x-nuers-timestamp'] ?? '';
    $sig = $headers['x-nuers-signature'] ?? '';
    if (!is_string($id) || !preg_match('/^[a-f0-9-]{36}$/D', $id)
        || !is_string($at) || !ctype_digit($at) || strlen($at) > 12
        || abs(time() - (int)$at) > 300
        || !is_string($sig) || !preg_match('/^v1=[a-f0-9]{64}$/D', $sig)
        || ($headers['x-nuers-environment'] ?? '') !== $settings['environment']
        || ($headers['x-nuers-key-version'] ?? '') !== (string)$settings['key_version']) {
        return [401, ['error' => 'invalid_signature_headers']];
    }
    $mac = hash_hmac('sha256', $at.'.'.$id.'.'.$raw, $settings['secret']);
    if (!hash_equals($mac, substr($sig, 3))) return [401, ['error' => 'invalid_signature']];
    try { $event = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); }
    catch (JsonException) { return [400, ['error' => 'invalid_json']]; }
    if (!is_array($event) || ($event['id'] ?? null) !== $id
        || ($event['environment'] ?? null) !== $settings['environment']
        || ($event['merchant_id'] ?? null) !== $settings['merchant_id']) {
        return [400, ['error' => 'unexpected_event_identity']];
    }
    if (($event['type'] ?? '') === 'webhook.endpoint_challenge') {
        $nonce = $event['data']['challenge'] ?? null;
        return is_string($nonce) && strlen($nonce) === 48
            ? [200, ['nuers_challenge' => $nonce]] : [400, ['error' => 'invalid_challenge']];
    }
    if (!in_array($event['type'] ?? '', ['transaction.accepted', 'source.received',
        'invoice.issued', 'payment.recorded', 'webhook.test'], true)) {
        return [400, ['error' => 'unsupported_event']];
    }
    // Example uses SQLite. Set PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION.
    // Create inbox at deployment: environment TEXT, event_id TEXT, body_hash TEXT,
    // raw_body TEXT, state TEXT, PRIMARY KEY(environment, event_id).
    $digest = hash('sha256', $raw);
    try {
        $db->beginTransaction();
        $q = $db->prepare('INSERT INTO nuers_inbox(environment,event_id,body_hash,raw_body,state)
            VALUES(?,?,?,?,?) ON CONFLICT(environment,event_id) DO NOTHING');
        $q->execute([$settings['environment'], $id, $digest, $raw, 'pending']);
        $q = $db->prepare('SELECT body_hash FROM nuers_inbox WHERE environment=? AND event_id=?');
        $q->execute([$settings['environment'], $id]);
        if (!hash_equals((string)$q->fetchColumn(), $digest)) {
            $db->rollBack(); return [409, ['error' => 'event_content_conflict']];
        }
        $db->commit();
        return [200, ['received' => true]];
    } catch (Throwable) {
        if ($db->inTransaction()) $db->rollBack();
        return [503, ['error' => 'durable_storage_unavailable']];
    }
}
// HTTPS route adapter: require this core; read php://input once; map getallheaders()
// keys to lowercase; reject duplicates at the web-server boundary; load settings
// from your backend secret manager; call the function, then http_response_code()
// and json_encode() its returned body with Content-Type: application/json.
// Validate your TLS route and durable worker before enabling Live delivery.
