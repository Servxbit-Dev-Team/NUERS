<?php

namespace App\Services\Infrastructure;

use Carbon\CarbonImmutable as C;
use Illuminate\Support\Facades\DB;

class Preflight
{
    public function inspect(object $node, string $action, array $parameters, ?string $exclude = null): array
    {
        $t = json_decode($node->telemetry ?? '{}', true);
        $checks = [];
        $risk = Catalog::risk($node, $action);
        $add = function (string $label, bool $passed) use (&$checks) {
            $checks[] = ['label' => $label, 'passed' => $passed];
        };
        $add('Independent management controller configured', app(ControllerGateway::class)->ready());
        $add('Live execution explicitly enabled by infrastructure operator', (bool) config('infrastructure.execution_enabled'));
        $add('Action approved in controller capability allowlist', in_array($action, config('infrastructure.capabilities'), true) && in_array($action, $t['capabilities'] ?? [], true));
        $pin = config('infrastructure.node_pins.'.$node->code);
        $add('Pinned node identity and environment match', $pin && $node->fingerprint && hash_equals($pin['fingerprint'], $node->fingerprint) && $pin['environment'] === $node->environment && ($pin['tier'] ?? '') === $node->tier && ($pin['ha_group'] ?? '') === $node->ha_group);
        $add('Fresh reachable and healthy node telemetry', app(Telemetry::class)->fresh($node) && ($t['reachable'] ?? false) && ($t['healthy'] ?? false));
        $add('Action supported by this server tier', in_array($node->tier, Catalog::actions()[$action]['tiers'], true));
        $groupIds = DB::table('infra_nodes')->where('ha_group', $node->ha_group)->where('environment', $node->environment)->pluck('id');
        $busy = DB::table('infra_actions')->whereIn('node_id', $groupIds)->whereIn('state', ['scheduled', 'running', 'validating', 'uncertain'])->when($exclude, fn ($q) => $q->where('id', '!=', $exclude))->exists();
        $add('HA group has no active or unresolved operation', ! $busy);
        if (str_starts_with($action, 'service.')) {
            $add('Service belongs to the approved node service registry', in_array($parameters['service'] ?? '', Catalog::SERVICES[$node->tier], true));
        }
        if ($action !== 'backup.create' && $action !== 'console.open') {
            $peers = DB::table('infra_nodes')->whereIn('id', $groupIds)->where('id', '!=', $node->id)->get();
            $healthy = $peers->filter(function ($p) {
                $v = json_decode($p->telemetry ?? '{}', true);

                return app(Telemetry::class)->fresh($p) && ($v['reachable'] ?? false) && ($v['healthy'] ?? false) && (! $p->maintenance_start || ! $p->maintenance_end || ! now('UTC')->between(C::parse($p->maintenance_start, 'UTC'), C::parse($p->maintenance_end, 'UTC')));
            });
            $add('All HA peers are fresh, healthy and outside maintenance', $peers->count() >= ($node->tier === 'storage' ? 2 : 1) && $healthy->count() === $peers->count());
        }
        if ($risk !== 'medium') {
            $add('An approved local maintenance window is currently open', $node->maintenance_start && $node->maintenance_end && now('UTC')->between(C::parse($node->maintenance_start, 'UTC'), C::parse($node->maintenance_end, 'UTC')));
            $add('Verified recovery backup less than 24 hours old', ! empty($t['backup_verified_at']) && C::parse($t['backup_verified_at'])->between(now('UTC')->subDay(), now('UTC')));
            $add('Reviewed runbook reference supplied', ! empty($parameters['runbook_id']));
        }
        if (in_array($node->tier, ['web', 'load_balancer']) && $action !== 'backup.create' && $action !== 'console.open') {
            $add('Load-balancing pool health verified', (bool) ($t['checks']['pool_healthy'] ?? false));
            if (! in_array($action, ['web.drain', 'web.rejoin', 'web.rolling_restart'])) {
                $add('Target is drained before disruption', (bool) ($t['checks']['drained'] ?? false));
            }
        }
        if ($node->tier === 'database' && $action !== 'backup.create') {
            foreach (['witness_healthy' => 'Independent witness healthy', 'fencing_ready' => 'Fencing verified', 'gtid_consistent' => 'GTID history consistent'] as $key => $label) {
                $add($label, (bool) ($t['checks'][$key] ?? false));
            }
            $add('Replication has zero confirmed lag', isset($t['replication_lag_seconds']) && (float) $t['replication_lag_seconds'] === 0.0);
            if ($action === 'database.promote') {
                $add('Target is the confirmed replica', ($t['role'] ?? '') === 'replica');
            }
        }
        if ($node->tier === 'storage' && $action !== 'backup.create') {
            foreach (['quorum_healthy' => 'Ceph MON quorum healthy', 'pg_clean' => 'Placement groups clean', 'replicas_safe' => 'Replication tolerates this operation'] as $key => $label) {
                $add($label, (bool) ($t['checks'][$key] ?? false));
            }
        }
        if (in_array($action, ['configuration.deploy', 'network.change', 'restore.test', 'restore.production'])) {
            $add('Immutable artifact verified by controller', ! empty($parameters['artifact_id']) && in_array($parameters['artifact_id'], $t['artifacts'] ?? [], true));
        }
        $required = match ($action) {
            'configuration.deploy' => ['syntax_valid', 'rollback_ready'], 'network.change' => ['out_of_band_ready', 'timed_revert_ready', 'rollback_ready'],
            'node.shutdown', 'node.reboot', 'lb.failover' => ['out_of_band_ready'],
            'restore.test' => ['isolated_target'], 'restore.production' => ['restore_rehearsed', 'rollback_ready'],
            'console.open' => ['bastion_recording'], default => [],
        };
        foreach ($required as $key) {
            $add(ucwords(str_replace('_', ' ', $key)), (bool) ($t['checks'][$key] ?? false));
        }

        return ['passed' => ! in_array(false, array_column($checks, 'passed'), true), 'risk' => $risk, 'approvals_required' => $action === 'restore.production' ? 2 : ($risk === 'critical' ? 1 : 0), 'checks' => $checks, 'observed_at' => $node->observed_at, 'evaluated_at' => now('UTC')->toIso8601String(), 'policy_version' => Catalog::VERSION];
    }
}
