<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Services\ApiAccess;
use App\Services\Infrastructure\Audit;
use App\Services\Infrastructure\AwsBackupGateway;
use App\Services\Infrastructure\PowerConfirmation;
use App\Services\Infrastructure\Telemetry;
use App\Services\MfaSession;
use App\Services\ProviderRecovery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InfrastructureBackupController
{
    private function actor(Request $request): User
    {
        $actor = app(ApiAccess::class)->require($request, ['super_admin']);
        abort_unless(app(MfaSession::class)->verified($actor), 403, 'Complete MFA sign-in first.');

        return $actor;
    }

    private function node(string $id): object
    {
        $node = DB::table('infra_nodes')->where('id', $id)->first();
        abort_unless($node, 404);
        $pin = config('infrastructure.node_pins.'.$node->code, []);
        abort_unless($node->provider === 'aws' && in_array($node->code, config('infrastructure.backup_nodes', []), true)
            && in_array($node->resource_type, ['managed_database', 'managed_filesystem'], true)
            && $node->enrollment === 'enrolled' && $node->fingerprint
            && ($pin['fingerprint'] ?? null) === $node->fingerprint
            && ($pin['provider_reference'] ?? null) === $node->provider_reference
            && ($pin['environment'] ?? null) === $node->environment
            && ($pin['region'] ?? null) === $node->region, 422, 'This resource is not enabled for on-demand AWS Backup.');

        return $node;
    }

    private function json(array $data, int $status = 200)
    {
        return response()->json($data, $status)->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request)
    {
        $this->actor($request);
        $provider = app(ProviderRecovery::class)->overview();
        $jobs = collect($provider['regions'] ?? [])->flatMap(fn ($r) => $r['jobs'] ?? [])->keyBy('id');
        $requests = DB::table('bcdr_requests')->where('kind', 'provider_backup')->orderByDesc('created_at')->limit(25)->get()->map(function ($row) use ($jobs) {
            $payload = json_decode($row->payload, true);
            $observed = $jobs->get($payload['job_id'] ?? '');
            return ['id' => $row->id, 'request_id' => $row->request_id, 'node_code' => $payload['node_code'],
                'job_id' => $payload['job_id'] ?? null, 'status' => $observed['status'] ?? strtoupper($row->status),
                'created_at' => \Carbon\CarbonImmutable::parse($row->created_at, config('app.timezone'))->toIso8601String(),
                'completed_at' => $observed['completed_at'] ?? null];
        });

        return $this->json(['enabled' => app(AwsBackupGateway::class)->ready(), 'nodes' => config('infrastructure.backup_nodes', []),
            'provider' => $provider, 'requests' => $requests, 'retention_days' => 7]);
    }

    public function prepare(Request $request)
    {
        $actor = $this->actor($request);
        $v = $request->validate(['node_id' => 'required|uuid', 'request_id' => 'required|uuid']);
        abort_unless(app(AwsBackupGateway::class)->ready(), 503, 'On-demand backup controller is not configured.');
        $node = $this->node($v['node_id']);
        abort_unless(app(Telemetry::class)->fresh($node), 409, 'Refresh the resource telemetry before requesting a backup.');
        $proof = DB::transaction(fn () => app(PowerConfirmation::class)->issue($actor, $node, 'backup.aws', $v['request_id']));

        return $this->json(['confirmation_id' => $proof, 'expires_in' => 120]);
    }

    public function confirm(Request $request)
    {
        $actor = $this->actor($request);
        $v = $request->validate(['node_id' => 'required|uuid', 'request_id' => 'required|uuid', 'confirmation_id' => 'required|uuid', 'confirmed' => 'required|accepted']);
        abort_unless(app(AwsBackupGateway::class)->ready(), 503, 'On-demand backup controller is not configured.');
        $node = $this->node($v['node_id']);
        $row = DB::transaction(function () use ($actor, $node, $v) {
            DB::table('bcdr_policy')->where('id', 1)->lockForUpdate()->first();
            $old = DB::table('bcdr_requests')->where('actor_id', $actor->id)->where('request_id', $v['request_id'])->first();
            if ($old) {
                abort_unless($old->kind === 'provider_backup' && $old->request_hash === hash('sha256', $node->id), 409, 'Request ID already belongs to another operation.');
                return $old;
            }
            abort_unless(app(Telemetry::class)->fresh($node), 409, 'Resource telemetry is stale. Refresh and try again.');
            abort_if(DB::table('bcdr_requests')->where('kind', 'provider_backup')->where('payload->node_code', $node->code)
                ->where('created_at', '>=', now()->subMinutes(15))->exists(), 409, 'A backup was recently requested for this resource. Check its job history before creating another.');
            app(PowerConfirmation::class)->consume($actor, $node, ['action' => 'backup.aws', 'idempotency_key' => $v['request_id'], 'proof_id' => $v['confirmation_id']]);
            $id = (string) Str::uuid();
            DB::table('bcdr_requests')->insert(['id' => $id, 'request_id' => $v['request_id'], 'actor_id' => $actor->id,
                'kind' => 'provider_backup', 'request_hash' => hash('sha256', $node->id), 'status' => 'dispatching',
                'payload' => json_encode(['node_id' => $node->id, 'node_code' => $node->code, 'fingerprint' => $node->fingerprint,
                    'environment' => $node->environment, 'scope' => $node->label, 'target_label' => 'AWS Backup · Singapore · 7 days']),
                'evidence_reference' => 'AWS on-demand backup request '.$v['request_id'], 'created_at' => now(), 'updated_at' => now()]);
            app(Audit::class)->record('backup.confirmed', $node->id, (string) $actor->id, ['request_id' => $v['request_id'], 'node' => $node->code]);
            return DB::table('bcdr_requests')->where('id', $id)->first();
        });
        $payload = json_decode($row->payload, true);
        if (isset($payload['job_id'])) {
            return $this->json(['job_id' => $payload['job_id'], 'message' => 'This backup request was already accepted by AWS.']);
        }
        abort_unless(\Carbon\CarbonImmutable::parse($row->created_at, config('app.timezone'))->gte(now('UTC')->subMinutes(15)), 409, 'Dispatch window expired. Reconcile this request in AWS before retrying.');
        try {
            $result = app(AwsBackupGateway::class)->start(['method' => 'backup', 'request_id' => $v['request_id'], 'node_code' => $node->code,
                'fingerprint' => $payload['fingerprint'], 'environment' => $payload['environment'], 'issued_at' => now('UTC')->toIso8601String()]);
        } catch (\Throwable $error) {
            DB::table('bcdr_requests')->where('id', $row->id)->whereNull('payload->job_id')->update(['status' => 'uncertain', 'updated_at' => now()]);
            app(Audit::class)->record('backup.response_uncertain', $node->id, (string) $actor->id, ['request_id' => $v['request_id']]);
            abort(502, 'AWS response could not be confirmed. Retry this same request; do not create a new one.');
        }
        $payload['job_id'] = $result['job_id'];
        DB::table('bcdr_requests')->where('id', $row->id)->update(['payload' => json_encode($payload), 'status' => 'accepted', 'updated_at' => now()]);
        app(Audit::class)->record('backup.aws_accepted', $node->id, (string) $actor->id, ['request_id' => $v['request_id'], 'job_id' => $result['job_id']]);

        return $this->json(['job_id' => $result['job_id'], 'message' => 'AWS accepted the backup. Completion will appear in job history after the next observation.'], 202);
    }
}
