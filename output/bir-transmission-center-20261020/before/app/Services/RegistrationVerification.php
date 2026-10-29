<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;

class RegistrationVerification
{
    public function requireValid(?object $challenge, string $proof, ?string $email = null): void
    {
        if (! $challenge || ! $challenge->verified_at || $challenge->consumed_at || ! Carbon::parse($challenge->expires_at)->isFuture() || ($email !== null && $challenge->email !== $email) || ! hash_equals($challenge->proof_hash ?? '', hash('sha256', $proof))) {
            throw new HttpResponseException(response()->json([
                'code' => 'REGISTRATION_VERIFICATION_REQUIRED',
                'message' => 'Email verification expired or no longer matches this registration. Send a new code and verify your email again. Your form details are preserved.',
                'errors' => ['verification_token' => ['Send a new verification code to continue.']],
            ], 422)->header('Cache-Control', 'no-store'));
        }
    }
}
