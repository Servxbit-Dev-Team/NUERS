<?php

namespace App\Services\Infrastructure;

use Carbon\CarbonImmutable as C;
use Illuminate\Support\Facades\DB;

class AwsPowerPolicy
{
    public const ACTIONS = ['node.start', 'node.reboot', 'node.shutdown'];
    public const RUNBOOK = 'AWS-POWER-001';

    public function supports(object $node): bool
    {
        return config('infrastructure.driver') === 'aws_power'
            && ($node->provider ?? '') === 'aws' && ($node->resource_type ?? '') === 'virtual_machine'
            && ($node->tier ?? '') === 'web'
            && in_array($node->code, config('infrastructure.aws_power_nodes', []), true);
    }

    public function actions(object $node): array
    {
        if (! $this->supports($node) || ! app(Telemetry::class)->fresh($node)
            || ! config('infrastructure.execution_enabled') || ! app(ControllerGateway::class)->ready()) {
            return [];
        }
        $state = json_decode($node->telemetry ?? '{}', true)['resource_state'] ?? '';

        return array_values(array_intersect(config('infrastructure.capabilities'), match ($state) {
            'stopped' => ['node.start'], 'running' => ['node.reboot', 'node.shutdown'], default => [],
        }));
    }

    public function inspect(object $node, string $action, array $parameters, ?string $exclude): array
    {
        $checks = [];
        $add = function (string $label, bool $passed) use (&$checks) {
            $checks[] = compact('label', 'passed');
        };
        $add('AWS resource and action explicitly enabled by operator', in_array($action, $this->actions($node), true));
        $add('Fresh signed inventory and pinned provider identity', app(Telemetry::class)->fresh($node));
        $add('Reviewed power-operation runbook selected', $parameters === ['runbook_id' => self::RUNBOOK]);
        $ids = DB::table('infra_nodes')->where('environment', $node->environment)->where('ha_group', $node->ha_group)->pluck('id');
        $add('No active or unresolved action in this HA group', ! DB::table('infra_actions')->whereIn('node_id', $ids)
            ->whereIn('state', ['scheduled', 'running', 'validating', 'uncertain'])->when($exclude, fn ($q) => $q->where('id', '!=', $exclude))->exists());
        if ($node->environment === 'production' && $action !== 'node.start') {
            $peer = DB::table('infra_nodes')->whereIn('id', $ids)->where('id', '!=', $node->id)->where('enrollment', 'enrolled')->get();
            $add('Other production web server is healthy and outside maintenance', $peer->count() === 1 && $peer->every(function ($p) {
                $t = json_decode($p->telemetry ?? '{}', true);
                $maintenance = $p->maintenance_start && $p->maintenance_end && now('UTC')->between(C::parse($p->maintenance_start, 'UTC'), C::parse($p->maintenance_end, 'UTC'));
                return app(Telemetry::class)->fresh($p) && ($t['healthy'] ?? false) && ! $maintenance;
            }));
        }
        try {
            $remote = app(AwsPowerController::class)->inspect($node->code, $action);
            $valid = is_bool($remote['passed'] ?? null) && is_array($remote['checks'] ?? null) && count($remote['checks']) > 0 && count($remote['checks']) <= 10;
            $add('Independent AWS preflight completed', $valid && $remote['passed']);
            if ($valid) {
                foreach ($remote['checks'] as $check) {
                    $add(substr((string) ($check['label'] ?? 'AWS check'), 0, 180), ($check['passed'] ?? false) === true);
                }
            }
        } catch (\Throwable $e) {
            $add('Independent AWS preflight available', false);
        }

        return ['passed' => ! in_array(false, array_column($checks, 'passed'), true), 'risk' => 'high',
            'approvals_required' => 0, 'checks' => $checks, 'observed_at' => $node->observed_at,
            'evaluated_at' => now('UTC')->toIso8601String(), 'policy_version' => Catalog::VERSION];
    }
}
