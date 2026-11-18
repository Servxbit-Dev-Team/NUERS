<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/** Small read summaries; callers must authenticate and resolve the merchant first. */
class DashboardReadCache
{
    public function remember(string $dataset, string $merchant, array $filters, callable $compute): mixed
    {
        ksort($filters);
        $revision = app(DashboardRevision::class)->token($merchant);
        $key = 'dashboard-read:v1:'.hash('sha256', json_encode([$dataset, $merchant, $revision, $filters], JSON_THROW_ON_ERROR));

        // App/worker writes and ledger triggers change the revision. The minute
        // boundary also reconciles external writes which bypass application events.
        return Cache::remember($key, 60, $compute);
    }
}
