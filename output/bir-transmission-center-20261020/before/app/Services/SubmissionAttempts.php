<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Operational request evidence; never a sale, receipt, charge, or BIR acknowledgement. */
class SubmissionAttempts
{
    public const ENDPOINTS = ['/api/integrations/transactions', '/api/integrations/transactions/batch'];

    public function record(Request $r, int $status, array $response, float $started): void
    {
        if (! $r->isMethod('POST') || ! in_array('/'.$r->path(), self::ENDPOINTS, true)) {
            return;
        }
        // Attribute only possession of one actual key. Never trust merchant_id in the body.
        $header = $r->header('X-NUERS-API-Key');
        $bearer = $r->bearerToken();
        $raw = (string) ($header ?: $bearer);
        $unambiguous = $raw && strlen($raw) <= 256 && ! ($header && $bearer && ! hash_equals($header, $bearer));
        try {
            $key = $unambiguous ? DB::table('merchant_api_keys')->where('key_hash', hash('sha256', $raw))->first() : null;
            if (! $key && in_array($status, [429, 413], true)) {
                return; // Keep the early network/payload guard from generating unbounded anonymous writes.
            }
            // Do not parse an oversized payload again merely to collect telemetry.
            $metadata = strlen($r->getContent()) <= 1048576 && $status !== 413 ? $r->all() : [];
            $text = static fn ($value, $length = 120) => is_string($value) ? mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '', 0, $length) : null;
            DB::transaction(function () use ($r, $key, $status, $response, $started, $text, $metadata) {
                // Match financial admission's parent lock order.
                if ($key && ! DB::table('merchants')->where('id', $key->merchant_id)->lockForUpdate()->first()) {
                    return;
                }
                $current = $key ? DB::table('merchant_api_keys')->where('id', $key->id)->lockForUpdate()->first() : null;
                if ($key && ! $current) {
                    return;
                }
                $failed = $status >= 400;
                DB::table('merchant_api_request_logs')->insert([
                    'id' => (string) Str::uuid(), 'merchant_api_key_id' => $key?->id, 'merchant_id' => $key?->merchant_id,
                    'environment' => $key?->environment, 'branch_code' => $key?->branch_code,
                    'branch_type' => $key?->branch_type, 'branch_name' => $key?->branch_name,
                    'endpoint' => '/'.$r->path(), 'method' => 'POST', 'status_code' => $status,
                    'latency_ms' => max(0, (int) round((microtime(true) - $started) * 1000)),
                    'request_reference' => $text($metadata['external_reference'] ?? null),
                    'source_event_id' => $text($metadata['source_event_id'] ?? null),
                    'source_system' => $text($metadata['source_system'] ?? null),
                    'error_message' => $failed ? $text($response['error']['reason'] ?? $response['message'] ?? 'Request was not accepted.', 600) : null,
                    'error_code' => $failed ? $text($response['error']['code'] ?? 'REQUEST_FAILED', 80) : null,
                    'outcome' => $failed ? ($status >= 500 || $status === 408 ? 'unknown' : 'rejected') : 'accepted',
                    'ip_address' => $r->ip(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                if ($key && $status < 400) {
                    DB::table('merchant_api_keys')->where('id', $key->id)->update(['last_used_at' => now()]);
                }
            }, 3);
        } catch (\Throwable $e) {
            // An evidence failure must never report a committed sale as rejected.
            report($e);
        }
    }

    public function failures(string $environment = 'live'): Builder
    {
        return DB::table('merchant_api_request_logs as l')
            ->leftJoin('merchant_api_keys as k', 'k.id', '=', 'l.merchant_api_key_id')
            ->join('merchants as m', 'm.id', '=', 'l.merchant_id')
            ->whereIn('l.endpoint', self::ENDPOINTS)->where('l.method', 'POST')
            ->where('l.status_code', '>=', 400)
            ->where(function (Builder $q) use ($environment) {
                $q->where('l.environment', $environment)->orWhere(function (Builder $q) use ($environment) {
                    // Old logs did not capture the environment; use only the actual linked key.
                    $q->whereNull('l.environment')->where('k.environment', $environment);
                });
            });
    }
}
