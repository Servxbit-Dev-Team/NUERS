<?php

namespace App\Services;

use App\Models\User;

/** MFA is completed once per authenticated login. A new login, logout or revocation clears its assurance. */
class MfaSession
{
    public function verified(User $user): bool
    {
        return (bool) ($user->mfa_secret && $user->api_mfa_verified_at
            && $user->api_mfa_verified_at->lte(now())
            && (! $user->api_authenticated_at || $user->api_mfa_verified_at->gte($user->api_authenticated_at)));
    }

    public function required(User $user, ?string $role): bool
    {
        return config('security.enforce_privileged_mfa')
            && (in_array($role, config('security.privileged_roles'), true) || (bool) $user->mfa_secret)
            && ! $this->verified($user);
    }
}
