<?php

namespace App\Services\Infrastructure;

use RuntimeException;

/** Read-only observations, signed by the collector's KMS key, never an execution gateway. */
class ObservedInventory
{
    public function configured(): bool
    {
        return is_readable((string) config('infrastructure.observation_file'))
            && is_readable((string) config('infrastructure.observation_public_key'));
    }

    public function snapshot(): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('Observation collector is not configured.');
        }
        $raw = file_get_contents(config('infrastructure.observation_file'), false, null, 0, 1048577);
        if (strlen($raw) > 1048576) {
            throw new RuntimeException('Observation size limit exceeded.');
        }
        $envelope = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        $body = base64_decode($envelope['body'] ?? '', true);
        $signature = base64_decode($envelope['signature'] ?? '', true);
        if (! $body || ! $signature || openssl_verify($body, $signature, file_get_contents(config('infrastructure.observation_public_key')), OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('Observation signature is invalid.');
        }
        $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        // Observation credentials cannot confer execution or claim a tested restore.
        foreach ($payload['nodes'] as &$node) {
            $node['capabilities'] = [];
            $node['checks'] = [];
            unset($node['backup_verified_at'], $node['artifacts']);
        }
        unset($node);
        return $payload;
    }
}
