<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Small aggregate results only. Authorization and source catch-up precede every lookup. */
class ReportingSnapshotCache
{
    public function remember(string $purpose, string $merchant, array $filters, callable $compute): mixed
    {
        $revision = (int) DB::table('ledger_projection_state')->where('id', 1)->value('revision');
        ksort($filters);
        $key = 'report-snapshot:v1:'.hash('sha256', json_encode([
            config('database.default'), DB::connection()->getDatabaseName(),
            $purpose, $merchant, $filters, $revision,
            config('bir_compliance.transport_implemented'), config('bir_compliance.official_interface_verified'), config('bir_compliance.production_response_verification_implemented'),
        ], JSON_THROW_ON_ERROR));
        // A source or report-metadata write changes the revision before the next read.
        // TTL bounds disk/cache growth; it is not a stale-financial-data grace period.
        return Cache::remember($key, 120, $compute);
    }
}
