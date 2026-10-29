<?php

namespace App\Services\Infrastructure;

use Carbon\CarbonImmutable as C;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class Telemetry
{
    public const CHECKS = ['drained', 'pool_healthy', 'quorum_healthy', 'pg_clean', 'replicas_safe', 'witness_healthy', 'fencing_ready', 'gtid_consistent', 'out_of_band_ready', 'syntax_valid', 'rollback_ready', 'timed_revert_ready', 'bastion_recording', 'isolated_target', 'restore_rehearsed'];

    public function fresh(object $node): bool
    {
        $pin = config('infrastructure.node_pins.'.$node->code);

        return $pin && ($pin['fingerprint'] ?? '') === $node->fingerprint && ($pin['environment'] ?? '') === $node->environment && ($pin['tier'] ?? '') === $node->tier && ($pin['ha_group'] ?? '') === $node->ha_group && $node->enrollment === 'enrolled' && $node->observed_at && C::parse($node->observed_at, 'UTC')->between(now('UTC')->subSeconds(config('infrastructure.freshness_seconds')), now('UTC')->addSeconds(5));
    }

    public function present(object $node): array
    {
        $t = json_decode($node->telemetry ?? '{}', true);
        $fresh = $this->fresh($node);
        $maintenance = $node->maintenance_start && $node->maintenance_end && now('UTC')->between(C::parse($node->maintenance_start, 'UTC'), C::parse($node->maintenance_end, 'UTC'));

        return array_merge((array) $node, ['telemetry' => $t, 'fresh' => $fresh, 'maintenance' => (bool) $maintenance,
            'status' => $node->enrollment !== 'enrolled' ? 'not_configured' : (! $fresh ? 'stale' : (! ($t['reachable'] ?? false) ? 'offline' : ($maintenance ? 'maintenance' : (($t['healthy'] ?? false) ? 'online' : 'degraded')))),
            'services' => array_map(fn ($key) => ['key' => $key, 'state' => $fresh ? ($t['services'][$key] ?? 'unknown') : 'unknown'], Catalog::SERVICES[$node->tier]),
        ]);
    }

    public function ingest(array $payload): int
    {
        Validator::make($payload, ['schema_version' => ['required', Rule::in([Catalog::VERSION])], 'nodes' => 'required|array|max:100'])->validate();
        $seen = [];
        $validated = [];
        foreach ($payload['nodes'] as $row) {
            $v = Validator::make($row, [
                'code' => 'required|string|max:30', 'fingerprint' => 'required|regex:/^[a-f0-9]{64}$/', 'environment' => 'required|in:production,staging',
                'observed_at' => ['required', 'date', 'before_or_equal:now', 'after:'.now('UTC')->subSeconds(config('infrastructure.freshness_seconds'))->toIso8601String(), 'regex:/(Z|[+-]\d{2}:\d{2})$/'],
                'reachable' => 'required|boolean', 'healthy' => 'required|boolean',
                'hostname' => 'nullable|regex:/^[a-zA-Z0-9.-]{1,100}$/', 'ip' => 'nullable|ip',
                'os' => 'nullable|string|max:100', 'kernel' => 'nullable|regex:/^[a-zA-Z0-9._+-]{1,100}$/',
                'role' => 'nullable|in:active,standby,primary,replica,member,web',
                'uptime_seconds' => 'nullable|integer|min:0|max:315360000', 'cpu_cores' => 'nullable|integer|min:1|max:4096',
                'cpu_percent' => 'nullable|numeric|min:0|max:100', 'memory_percent' => 'nullable|numeric|min:0|max:100', 'disk_percent' => 'nullable|numeric|min:0|max:100',
                'memory_bytes' => 'nullable|integer|min:0|max:9007199254740991', 'disk_used_bytes' => 'nullable|integer|min:0|max:9007199254740991', 'disk_total_bytes' => 'nullable|integer|min:0|max:9007199254740991',
                'network_rx_bytes' => 'nullable|integer|min:0|max:9007199254740991', 'network_tx_bytes' => 'nullable|integer|min:0|max:9007199254740991',
                'replication_lag_seconds' => 'nullable|numeric|min:0|max:86400', 'backup_verified_at' => ['nullable', 'date', 'before_or_equal:now', 'regex:/(Z|[+-]\d{2}:\d{2})$/'],
                'services' => 'present|array', 'capabilities' => 'present|array|max:20', 'capabilities.*' => Rule::in(array_keys(Catalog::actions())),
                'checks' => 'present|array:'.implode(',', self::CHECKS), 'checks.*' => 'boolean',
                'artifacts' => 'sometimes|array|max:50', 'artifacts.*' => 'uuid',
            ])->validate();
            abort_if(isset($seen[$v['code']]), 422, 'Duplicate node in snapshot.');
            $seen[$v['code']] = true;
            $node = DB::table('infra_nodes')->where('code', $v['code'])->first();
            $pin = config('infrastructure.node_pins.'.$v['code']);
            abort_unless($node && $pin && hash_equals($pin['fingerprint'], $v['fingerprint']) && $pin['environment'] === $v['environment'] && $node->environment === $v['environment'] && ($pin['tier'] ?? '') === $node->tier && ($pin['ha_group'] ?? '') === $node->ha_group, 422, 'Node identity or environment is not pinned.');
            Validator::make(['services' => $v['services']], ['services' => 'array:'.implode(',', Catalog::SERVICES[$node->tier]), 'services.*' => 'in:running,stopped,failed,unknown'])->validate();
            // External raw logs, commands, URLs, secrets and arbitrary data are discarded.
            $v['os'] = preg_match('/^[a-zA-Z0-9 ._()+-]{1,100}$/', $v['os'] ?? '') ? $v['os'] : null;
            if (isset($v['disk_total_bytes'], $v['disk_used_bytes'])) {
                abort_if($v['disk_used_bytes'] > $v['disk_total_bytes'], 422, 'Invalid disk totals.');
            }
            $validated[] = [$node, $v];
        }

        return DB::transaction(function () use ($validated) {
            DB::table('infra_state')->where('id', 1)->lockForUpdate()->first();
            $count = 0;
            foreach ($validated as [$node, $v]) {
                $current = DB::table('infra_nodes')->where('id', $node->id)->first();
                $at = C::parse($v['observed_at'])->utc();
                if ($current->observed_at && $at->lte(C::parse($current->observed_at, 'UTC'))) {
                    continue;
                } // Reject replay without moving freshness forward.
                DB::table('infra_nodes')->where('id', $node->id)->update(['fingerprint' => $v['fingerprint'], 'enrollment' => 'enrolled', 'observed_at' => $at, 'telemetry' => json_encode($v), 'updated_at' => now('UTC')]);
                $last = DB::table('infra_samples')->where('node_id', $node->id)->max('observed_at');
                if (! $last || $at->gte(C::parse($last, 'UTC')->addMinutes(15))) {
                    DB::table('infra_samples')->insert(['node_id' => $node->id, 'observed_at' => $at, 'metrics' => json_encode(array_intersect_key($v, array_flip(['cpu_percent', 'memory_percent', 'disk_percent', 'network_rx_bytes', 'network_tx_bytes'])))]);
                    $ids = DB::table('infra_samples')->where('node_id', $node->id)->orderByDesc('observed_at')->limit(config('infrastructure.sample_limit'))->pluck('id');
                    DB::table('infra_samples')->where('node_id', $node->id)->whereNotIn('id', $ids)->delete();
                }
                if ($current->enrollment !== 'enrolled') {
                    app(Audit::class)->record('node.identity_verified', $node->id, 'controller', ['code' => $node->code, 'fingerprint' => $v['fingerprint']]);
                }
                $count++;
            }
            DB::table('infra_state')->where('id', 1)->update(['last_sync_at' => now('UTC'), 'sync_state' => 'connected']);

            return $count;
        });
    }
}
