<?php

namespace App\Services\Infrastructure;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ExpansionRequests
{
    public function create(User $actor, array $input): array
    {
        $body = array_diff_key($input, array_flip(['proof_id', 'idempotency_key']));
        ksort($body);
        $hash = hash('sha256', json_encode($body, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $input, $body, $hash) {
            app(ActionWorkflow::class)->lock();
            $old = DB::table('infra_expansion_requests')->where('actor_id', $actor->id)
                ->where('idempotency_key', $input['idempotency_key'])->first();
            if ($old) {
                abort_unless(hash_equals($old->request_hash, $hash), 409, 'Idempotency key was used for a different expansion request.');

                return $this->present($old);
            }
            app(ActionWorkflow::class)->consumeProof($actor, $input['proof_id']);
            Providers::validateCombination($body['provider'], $body['resource_type'], $body['tier']);
            abort_if($body['provider'] === 'unassigned', 422, 'Select a provider for the expansion request.');
            $cost = isset($body['estimated_monthly_php']) ? (int) round((float) $body['estimated_monthly_php'] * 100) : null;
            unset($body['estimated_monthly_php']);
            $id = (string) Str::uuid();
            $state = $cost !== null && $cost > config('infrastructure.budget_ceiling_php', 100000) * 100
                ? 'over_budget' : 'awaiting_review';
            DB::table('infra_expansion_requests')->insert($body + [
                'id' => $id, 'actor_id' => $actor->id, 'idempotency_key' => $input['idempotency_key'],
                'request_hash' => $hash, 'estimated_monthly_php_centavos' => $cost, 'state' => $state,
                'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ]);
            app(Audit::class)->record('capacity.requested', $id, (string) $actor->id, $body + [
                'estimated_monthly_php_centavos' => $cost, 'state' => $state, 'purchased' => false,
            ]);

            return $this->present(DB::table('infra_expansion_requests')->where('id', $id)->first());
        });
    }

    public function present(object $row): array
    {
        $result = (array) $row;
        unset($result['request_hash'], $result['idempotency_key']);
        $result['estimated_monthly_php'] = $row->estimated_monthly_php_centavos === null
            ? null : $row->estimated_monthly_php_centavos / 100;
        $result['execution_enabled'] = false;
        $result['next_step'] = 'Operator must verify the provider quote, total monthly spend, private connectivity and rollout plan. No resources have been purchased.';

        return $result;
    }
}
