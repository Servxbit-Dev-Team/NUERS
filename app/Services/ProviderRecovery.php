<?php

namespace App\Services;

use App\Services\Infrastructure\ObservedInventory;
use Carbon\CarbonImmutable;

class ProviderRecovery
{
    public function overview(): array
    {
        $unavailable = ['available' => false, 'fresh' => false, 'observed_at' => null];
        try {
            $inventory = app(ObservedInventory::class);
            if (! $inventory->configured()) {
                return $unavailable;
            }
            $data = $inventory->snapshot()['recovery'] ?? null;
            if (! is_array($data) || ($data['available'] ?? false) !== true) {
                return $unavailable;
            }
            $observed = CarbonImmutable::parse($data['observed_at']);
            $age = $observed->diffInSeconds(now(), false);
            $data['fresh'] = $age >= -5 && $age <= 180;
            $data['restore_tested'] = false;
            $data['rpo_minutes'] = null;
            $data['rto_minutes'] = null;
            $jobs = collect($data['regions'])->flatMap(fn ($region) => $region['jobs']);
            $today = $jobs->filter(fn ($job) => ! empty($job['completed_at'])
                && CarbonImmutable::parse($job['completed_at'])->setTimezone('Asia/Manila')->toDateString() === now('Asia/Manila')->toDateString());
            $data['successful_today'] = $today->where('status', 'COMPLETED')->count();
            $data['failed_today'] = $today->whereIn('status', ['FAILED', 'ABORTED', 'EXPIRED'])->count();
            return $data;
        } catch (\Throwable) {
            // Invalid or missing signatures must never become healthy provider evidence.
            return $unavailable;
        }
    }
}
