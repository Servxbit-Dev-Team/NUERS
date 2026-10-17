<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Shared database admission. Both budgets commit together, including rejected business requests. */
class AtomicQuota
{
    public function admit(string $principal, int $daily, int $minute = 600): void
    {
        DB::transaction(function () use ($principal, $daily, $minute) {
            foreach ([['day', now()->format('Y-m-d'), $daily, now()->addDay()->startOfDay()], ['minute', now()->format('Y-m-d-H-i'), $minute, now()->addMinute()->startOfMinute()]] as [$kind,$window,$limit,$expires]) {
                $id = hash('sha256', $principal.'|'.$kind.'|'.$window);
                DB::table('api_quota_buckets')->insertOrIgnore(['id' => $id, 'used' => 0, 'expires_at' => $expires]);
                $bucket = DB::table('api_quota_buckets')->where('id', $id)->lockForUpdate()->first();
                if ($bucket->used >= $limit) {
                    $retry = max(1, (int) ceil(now()->diffInSeconds($expires)));
                    abort(429, 'The '.$kind.' quota for this action has been reached. Please retry after the current window.', ['Retry-After' => $retry, 'Cache-Control' => 'no-store']);
                }
                DB::table('api_quota_buckets')->where('id', $id)->increment('used');
            }
        }, 3);
    }
}
