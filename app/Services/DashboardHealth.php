<?php

namespace App\Services;

use App\Services\Infrastructure\ObservedInventory;
use Carbon\CarbonImmutable as C;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class DashboardHealth
{
    public function overview(): array
    {
        $now = now()->toIso8601String();
        $row = fn ($name, $status, $detail, $at = null, $href = '/super-admin/server-management') => compact('name', 'status', 'detail', 'href') + ['checked_at' => $at];
        $nodes = [];
        try {
            $snapshot = app(ObservedInventory::class)->snapshot();
            foreach ($snapshot['nodes'] as $node) {
                $pin = config('infrastructure.node_pins.'.$node['code']);
                if ($pin && ($node['environment'] ?? '') === 'production'
                    && ($node['fingerprint'] ?? '') === $pin['fingerprint']
                    && ($node['provider_reference'] ?? '') === $pin['provider_reference']) {
                    $nodes[$node['code']] = $node;
                }
            }
        } catch (\Throwable) {
            // Missing or invalid signatures cannot establish infrastructure health.
        }
        $production = collect(config('infrastructure.node_pins', []))->filter(fn ($p) => $p['environment'] === 'production')->keys();
        $fresh = count($nodes) > 0 && $production->count() === count($nodes);
        foreach ($nodes as $node) {
            $age = C::parse($node['observed_at'])->diffInSeconds(now(), false);
            $fresh = $fresh && $age >= -5 && $age <= config('infrastructure.freshness_seconds', 120);
        }
        $healthy = collect($nodes)->filter(fn ($n) => ($n['reachable'] ?? false) && ($n['healthy'] ?? false))->count();
        $latest = collect($nodes)->pluck('observed_at')->min();
        $provider = app(ProviderRecovery::class)->overview();
        $jobs = collect($provider['regions'] ?? [])->flatMap(fn ($r) => $r['jobs'] ?? []);
        $copies = collect($provider['regions'] ?? [])->flatMap(fn ($r) => $r['copies'] ?? []);
        $recent = fn ($rows) => collect(['Database', 'Shared files'])->every(fn ($asset) => $rows->contains(fn ($r) => ($r['resource'] ?? '') === $asset && $r['status'] === 'COMPLETED' && !empty($r['completed_at']) && C::parse($r['completed_at'])->between(now()->subHours(36), now()->addSeconds(5))));
        $backupFresh = ($provider['fresh'] ?? false);
        $restore = [];
        if (Schema::hasTable('bcdr_evidence')) {
            $restore = Cache::remember('dashboard-reviewed-recovery-v1', 30, fn () => app(BackupRecovery::class)->overview(1)['stats']);
        }
        return [
            $row('Application response', 'online', 'This authenticated dashboard request completed successfully.', $now),
            $row('Database query', 'online', 'The dashboard database queries completed successfully.', $now),
            $row('Application storage', is_writable(storage_path('app')) ? 'writable' : 'unavailable', 'Permission check on this web node. Shared filesystem health is monitored separately.', $now),
            $row('Production infrastructure', !$nodes ? 'not_verified' : (!$fresh ? 'stale' : ($healthy === count($nodes) ? 'healthy' : 'degraded')), $healthy.' of '.$production->count().' production resources healthy. Staging is excluded. Observations expire after '.config('infrastructure.freshness_seconds', 120).' seconds.', $latest),
            $row('Database & file backups', !$backupFresh ? 'not_verified' : ($recent($jobs) ? 'current' : 'attention'), 'Requires a completed database backup and shared-files backup within 36 hours. Backup completion does not prove restoration.', $provider['observed_at'] ?? null, '/super-admin/backups'),
            $row('Sydney recovery copies', !$backupFresh ? 'not_verified' : ($recent($copies->where('destination_region', 'ap-southeast-2')) ? 'current' : 'attention'), 'Requires completed database and shared-files copies in Sydney within 36 hours.', $provider['observed_at'] ?? null, '/super-admin/backups'),
            $row('Verified restore test', ($restore['measurement_current'] ?? false) ? 'verified' : 'not_tested', 'Only an independently reviewed recovery drill establishes tested RPO and RTO.', $restore['last_restore_at'] ?? null, '/super-admin/backups'),
            $row('BIR Gateway', 'pending_connection', 'BIR connectivity is deferred. AWS health and saved receipts do not establish BIR acceptance.', null, '/super-admin/eis-transmissions'),
        ];
    }
}
