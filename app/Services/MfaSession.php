<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Login assurance plus fresh verification before privileged changes or exports. */
class MfaSession
{
    public function verified(User $user): bool
    {
        return (bool) ($user->mfa_secret && $user->api_mfa_verified_at
            && $user->api_mfa_verified_at->lte(now())
            && (! $user->api_mfa_challenged_at || $user->api_mfa_verified_at->gte($user->api_mfa_challenged_at))
            && (! $user->api_authenticated_at || $user->api_mfa_verified_at->gte($user->api_authenticated_at)));
    }

    public function recent(User $user): bool
    {
        return $this->verified($user)
            && now()->lt($user->api_mfa_verified_at->copy()->addMinutes(config('security.privileged_mfa_minutes')));
    }

    public function fresh(User $user): bool
    {
        return app(SessionPolicy::class)->privileged($user) ? $this->recent($user) : $this->verified($user);
    }

    public function sensitive(Request $request): bool
    {
        // These workflows verify the current password and/or authenticator themselves.
        if ($request->is('api/auth/mfa/*', 'api/admin/infrastructure/step-up')) {
            return false;
        }
        if (! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return true;
        }
        $action = (string) $request->route()?->getActionMethod();
        $format = $request->query('format');
        abort_unless($format === null || is_string($format), 422, 'The format must be a string.');

        return (bool) preg_match('/export|download|pdf|statement|portability/i', $action.' '.$request->path())
            || in_array(strtolower($format ?? ''), ['csv', 'xlsx', 'pdf', 'zip'], true);
    }

    public function challenge(User $user): void
    {
        // The existing MFA screen uses `verified`. A pending challenge therefore prevents
        // redirect loops without changing or replaying the original sensitive request.
        DB::table('users')->where('id', $user->id)->where('api_token_hash', $user->api_token_hash)
            ->where('api_mfa_verified_at', $user->api_mfa_verified_at)
            ->update(['api_mfa_challenged_at' => now()]);
    }

    public function required(User $user, ?string $role): bool
    {
        return $this->enforced($user, $role) && ! $this->verified($user);
    }

    public function optedOut(User $user): bool
    {
        // Only the password-and-factor-verified disable workflow writes this field.
        // A secret always takes precedence, including during re-enrollment.
        return ! $user->mfa_secret && $user->mfa_disabled_at !== null;
    }

    /** Credential-management checks must respect a verified, persisted opt-out. */
    public function credentialRequired(User $user): bool
    {
        return config('security.enforce_privileged_mfa')
            && ! $this->optedOut($user) && ! $this->verified($user);
    }

    public function enforced(User $user, ?string $role): bool
    {
        return config('security.enforce_privileged_mfa')
            && ((bool) $user->mfa_secret
                || (in_array($role, config('security.privileged_roles'), true) && ! $this->optedOut($user)));
    }
}
