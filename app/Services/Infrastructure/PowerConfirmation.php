<?php

namespace App\Services\Infrastructure;

use App\Models\User;
use App\Services\MfaDevices;
use App\Services\MfaSession;
use Carbon\CarbonImmutable as C;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PowerConfirmation
{
    private function binding(User $actor, object $node, string $action, string $requestId): string
    {
        return hash_hmac('sha256', json_encode(['aws_power_confirmation', app(MfaDevices::class)->binding($actor), $node->id, $node->revision, $action, $requestId], JSON_THROW_ON_ERROR), config('app.key'));
    }

    public function issue(User $actor, object $node, string $action, string $requestId): string
    {
        abort_unless(app(MfaSession::class)->verified($actor), 403, 'Complete MFA sign-in first.');
        $id = (string) Str::uuid();
        DB::table('infra_proofs')->where('expires_at', '<', now('UTC'))->delete();
        DB::table('infra_proofs')->insert(['id' => $id, 'actor_id' => $actor->id,
            'binding' => $this->binding($actor, $node, $action, $requestId), 'expires_at' => now('UTC')->addMinutes(2)]);

        return $id;
    }

    public function consume(User $actor, object $node, array $request): void
    {
        abort_unless(app(MfaSession::class)->verified($actor), 403, 'Complete MFA sign-in first.');
        $proof = DB::table('infra_proofs')->where('id', $request['proof_id'])->lockForUpdate()->first();
        abort_unless(
            $proof && $proof->actor_id === (string) $actor->id && ! $proof->used_at
            && C::parse($proof->expires_at, 'UTC')->isFuture()
            && hash_equals($proof->binding, $this->binding($actor, $node, $request['action'], $request['idempotency_key'])),
            403,
            'Confirmation expired or the target changed. Close this dialog and try again.'
        );
        DB::table('infra_proofs')->where('id', $proof->id)->update(['used_at' => now('UTC')]);
    }
}
