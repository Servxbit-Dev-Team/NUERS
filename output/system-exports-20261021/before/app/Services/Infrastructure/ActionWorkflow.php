<?php

namespace App\Services\Infrastructure;

use App\Models\User;
use App\Services\MfaDevices;
use App\Services\MfaSession;
use Carbon\CarbonImmutable as C;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ActionWorkflow
{
    public function lock(): void
    {
        DB::table('infra_state')->where('id', 1)->lockForUpdate()->first();
    }

    public function consumeProof(User $actor, ?string $id): void
    {
        $proof = DB::table('infra_proofs')->where('id', $id)->lockForUpdate()->first();
        abort_unless($proof && $proof->actor_id === (string) $actor->id && ! $proof->used_at && C::parse($proof->expires_at, 'UTC')->isFuture() && hash_equals($proof->binding, app(MfaDevices::class)->binding($actor)), 403, 'Fresh password and authenticator verification is required for this operation.');
        DB::table('infra_proofs')->where('id', $id)->update(['used_at' => now('UTC')]);
    }

    public function present(object $row): array
    {
        $v = (array) $row;
        foreach (['parameters', 'preflight', 'policy', 'result'] as $key) {
            $v[$key] = json_decode($v[$key] ?? 'null', true);
        }
        unset($v['session_binding'], $v['request_hash']);
        $v['node_code'] = DB::table('infra_nodes')->where('id', $row->node_id)->value('code');
        $v['approvals'] = DB::table('infra_approvals')->where('action_id', $row->id)->get(['actor_id', 'decision', 'note', 'created_at']);

        return $v;
    }

    public function create(User $actor, array $v): array
    {
        return DB::transaction(function () use ($actor, $v) {
            $this->lock();
            $hash = hash('sha256', json_encode([$v['node_id'], $v['action'], $v['reason'], $v['parameters']], JSON_THROW_ON_ERROR));
            $old = DB::table('infra_actions')->where('actor_id', $actor->id)->where('idempotency_key', $v['idempotency_key'])->first();
            if ($old) {
                abort_unless(hash_equals($old->request_hash, $hash), 409, 'Idempotency key was used for a different request.');

                return $this->present($old);
            }
            $node = DB::table('infra_nodes')->where('id', $v['node_id'])->first();
            abort_unless($node, 404);
            $this->consumeProof($actor, $v['proof_id'] ?? null);
            abort_unless(($v['confirmation'] ?? '') === $node->code, 422, 'Type the exact server code to confirm the target.');
            $preflight = app(Preflight::class)->inspect($node, $v['action'], $v['parameters']);
            $id = (string) Str::uuid();
            $state = ! $preflight['passed'] ? 'blocked' : ($preflight['approvals_required'] ? 'awaiting_approval' : 'scheduled');
            $policy = ['version' => Catalog::VERSION, 'fingerprint' => $node->fingerprint, 'environment' => $node->environment, 'ha_group' => $node->ha_group, 'node_revision' => $node->revision, 'risk' => $preflight['risk'], 'approvals_required' => $preflight['approvals_required'], 'mfa_at' => now('UTC')->toIso8601String()];
            DB::table('infra_actions')->insert(['id' => $id, 'node_id' => $node->id, 'actor_id' => $actor->id, 'session_binding' => app(MfaDevices::class)->binding($actor), 'idempotency_key' => $v['idempotency_key'], 'request_hash' => $hash, 'action' => $v['action'], 'risk' => $preflight['risk'], 'state' => $state, 'reason' => $v['reason'], 'parameters' => json_encode($v['parameters']), 'preflight' => json_encode($preflight), 'policy' => json_encode($policy), 'expires_at' => now('UTC')->addMinutes(15), 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            app(Audit::class)->record('action.requested', $id, (string) $actor->id, ['node' => $node->code, 'action' => $v['action'], 'state' => $state, 'reason' => $v['reason'], 'parameters' => $v['parameters'], 'request_hash' => $hash, 'policy' => $policy, 'preflight' => $preflight]);

            return $this->present(DB::table('infra_actions')->where('id', $id)->first());
        });
    }

    public function review(User $actor, string $id, array $v): array
    {
        return DB::transaction(function () use ($actor, $id, $v) {
            $this->lock();
            $row = DB::table('infra_actions')->where('id', $id)->first();
            abort_unless($row, 404);
            abort_unless($row->revision === $v['revision'], 409, 'This action changed. Refresh before reviewing.');
            $decision = $v['decision'];
            abort_unless(in_array($row->state, ['awaiting_approval', 'scheduled']), 409, 'This action cannot be reviewed or cancelled now.');
            $this->consumeProof($actor, $v['proof_id']);
            if ($decision === 'cancel') {
                abort_unless($row->actor_id === (string) $actor->id, 403, 'Only the requester can cancel.');
                $state = 'cancelled';
            } else {
                abort_unless($row->state === 'awaiting_approval' && $row->actor_id !== (string) $actor->id, 403, 'An independent Super Administrator must review this action.');
                abort_if(DB::table('infra_approvals')->where('action_id', $id)->where('actor_id', $actor->id)->exists(), 409, 'This administrator already reviewed the action.');
                abort_unless(C::parse($row->expires_at, 'UTC')->isFuture(), 409, 'The action approval window expired.');
                DB::table('infra_approvals')->insert(['action_id' => $id, 'actor_id' => $actor->id, 'session_binding' => app(MfaDevices::class)->binding($actor), 'decision' => $decision, 'note' => $v['note'], 'created_at' => now('UTC')]);
                $policy = json_decode($row->policy, true);
                $count = DB::table('infra_approvals')->where('action_id', $id)->where('decision', 'approve')->count();
                $state = $decision === 'reject' ? 'rejected' : ($count >= $policy['approvals_required'] ? 'scheduled' : 'awaiting_approval');
                if ($state === 'scheduled') {
                    $preflight = app(Preflight::class)->inspect(DB::table('infra_nodes')->where('id', $row->node_id)->first(), $row->action, json_decode($row->parameters, true), $id);
                    if (! $preflight['passed']) {
                        $state = 'blocked';
                    }
                    DB::table('infra_actions')->where('id', $id)->update(['preflight' => json_encode($preflight)]);
                }
            }
            $this->transition($row, $state, (string) $actor->id, ['decision' => $decision, 'note' => $v['note']]);

            return $this->present(DB::table('infra_actions')->where('id', $id)->first());
        });
    }

    public function transition(object $row, string $state, string $actor, array $details = []): void
    {
        DB::table('infra_actions')->where('id', $row->id)->update(['state' => $state, 'revision' => $row->revision + 1, 'updated_at' => now('UTC'), ...(in_array($state, ['succeeded', 'failed', 'rolled_back', 'rejected', 'cancelled', 'expired', 'blocked']) ? ['finished_at' => now('UTC')] : [])]);
        app(Audit::class)->record('action.'.$state, $row->id, $actor, ['previous_state' => $row->state] + $details);
    }

    private function sessionActive(string $id, string $binding): bool
    {
        $actor = User::find($id);

        return $actor && $actor->api_token_hash && $actor->api_expires_at && $actor->api_expires_at->isFuture() && $actor->api_authenticated_at && $actor->api_authenticated_at->copy()->addMinutes(config('security.session_absolute_minutes'))->isFuture() && DB::table('profiles')->where('id', $id)->value('role') === 'super_admin' && app(MfaSession::class)->verified($actor) && hash_equals(app(MfaDevices::class)->binding($actor), $binding);
    }

    public function dispatchOne(): ?string
    {
        $row = DB::transaction(function () {
            $this->lock();
            // Expire abandoned approvals, releasing no execution locks.
            foreach (DB::table('infra_actions')->whereIn('state', ['awaiting_approval', 'scheduled'])->where('expires_at', '<=', now('UTC'))->get() as $expired) {
                $this->transition($expired, 'expired', 'worker');
            }
            $r = DB::table('infra_actions')->where('state', 'scheduled')->orderBy('created_at')->first();
            if (! $r) {
                return null;
            }
            $node = DB::table('infra_nodes')->where('id', $r->node_id)->first();
            $p = json_decode($r->policy, true);
            $check = app(Preflight::class)->inspect($node, $r->action, json_decode($r->parameters, true), $r->id);
            $authorized = $this->sessionActive($r->actor_id, $r->session_binding);
            $approvals = DB::table('infra_approvals')->where('action_id', $r->id)->where('decision', 'approve')->get();
            foreach ($approvals as $a) {
                $authorized = $authorized && $this->sessionActive($a->actor_id, $a->session_binding);
            }
            if (! $check['passed'] || ! $authorized || count($approvals) < $p['approvals_required'] || $node->fingerprint !== $p['fingerprint'] || $node->environment !== $p['environment']) {
                DB::table('infra_actions')->where('id', $r->id)->update(['preflight' => json_encode($check)]);
                $this->transition($r, 'blocked', 'worker', ['preflight' => $check, 'authorization_current' => $authorized]);

                return null;
            }
            $this->transition($r, 'running', 'worker', ['preflight' => $check]);
            DB::table('infra_actions')->where('id', $r->id)->update(['dispatched_at' => now('UTC')]);

            return DB::table('infra_actions')->where('id', $r->id)->first();
        });
        if (! $row) {
            return null;
        }
        try {
            $policy = json_decode($row->policy, true);
            $job = ['schema_version' => Catalog::VERSION, 'job_id' => $row->id, 'node_code' => DB::table('infra_nodes')->where('id', $row->node_id)->value('code'), 'fingerprint' => $policy['fingerprint'], 'environment' => $policy['environment'], 'action' => $row->action, 'parameters' => json_decode($row->parameters, true), 'actor_id' => $row->actor_id, 'policy' => $policy, 'approvals' => DB::table('infra_approvals')->where('action_id', $row->id)->where('decision', 'approve')->get(['actor_id', 'created_at'])->toArray(), 'issued_at' => now('UTC')->toIso8601String(), 'expires_at' => min(C::parse($row->expires_at, 'UTC')->utc(), now('UTC')->addSeconds(60))->toIso8601String()];
            $this->acceptResult($row->id, app(ControllerGateway::class)->submit($job));
        } catch (\Throwable $e) {
            // Never retry a possibly executed command. Reconcile by the SAME job ID.
            DB::transaction(function () use ($row) {
                $this->lock();
                $r = DB::table('infra_actions')->where('id', $row->id)->first();
                $this->transition($r, 'uncertain', 'worker', ['code' => 'CONTROLLER_OUTCOME_UNKNOWN']);
            });
        }

        return $row->id;
    }

    public function reconcile(): int
    {
        $count = 0;
        foreach (DB::table('infra_actions')->whereIn('state', ['running', 'validating', 'uncertain'])->orderBy('created_at')->limit(25)->get() as $r) {
            try {
                $this->acceptResult($r->id, app(ControllerGateway::class)->result($r->id));
                $count++;
            } catch (\Throwable $e) { /* Preserve execution lock until authenticated evidence arrives. */
            }
        }

        return $count;
    }

    public function acceptResult(string $id, array $payload): void
    {
        $v = Validator::make($payload, ['job_id' => 'required|uuid', 'status' => 'required|in:running,succeeded,failed,rolled_back', 'postcheck_passed' => 'required|boolean', 'code' => 'required|in:ACCEPTED,COMPLETED,HANDLER_FAILED,POSTCHECK_FAILED,ROLLED_BACK,PREFLIGHT_DENIED', 'evidence_id' => 'nullable|uuid'])->validate();
        abort_unless($v['job_id'] === $id, 422, 'Result does not match the action.');
        DB::transaction(function () use ($id, $v) {
            $this->lock();
            $r = DB::table('infra_actions')->where('id', $id)->first();
            abort_unless($r && in_array($r->state, ['running', 'validating', 'uncertain']), 409, 'Result is not valid for the current action state.');
            // No stdout, URLs, credentials or arbitrary log messages enter application storage.
            $state = $v['status'] === 'running' ? 'running' : ($v['postcheck_passed'] ? $v['status'] : 'uncertain');
            DB::table('infra_actions')->where('id', $id)->update(['result' => json_encode($v)]);
            if ($state !== 'running') {
                $this->transition($r, 'validating', 'controller', ['code' => $v['code']]);
            }
            $current = DB::table('infra_actions')->where('id', $id)->first();
            $this->transition($current, $state, 'controller', $v);
        });
    }
}
