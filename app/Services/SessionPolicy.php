<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class SessionPolicy
{
    public function privileged(User $user): bool
    {
        return in_array(DB::table('profiles')->where('id', $user->id)->value('role'), config('security.privileged_roles'), true);
    }

    public function limits(User $user): array
    {
        $privileged = $this->privileged($user);
        $mfaOptedOut = app(MfaSession::class)->optedOut($user);

        return [
            'idle_minutes' => (int) config($privileged ? 'security.privileged_idle_minutes' : 'security.token_minutes'),
            'absolute_minutes' => (int) config($privileged ? 'security.privileged_absolute_minutes' : 'security.session_absolute_minutes'),
            'step_up_minutes' => $privileged && ! $mfaOptedOut ? (int) config('security.privileged_mfa_minutes') : null,
            'mfa_challenge' => $mfaOptedOut ? 'disabled_by_account_owner' : ($privileged ? 'privileged_sensitive_action' : 'login_session'),
        ];
    }

    public function valid(User $user): bool
    {
        $started = $user->api_authenticated_at;
        $limits = $this->limits($user);
        if (! $started || $started->isFuture() || now()->gte($started->copy()->addMinutes($limits['absolute_minutes']))) {
            return false;
        }
        // Legacy sessions have no activity timestamp. Do not grant them a new idle window.
        $activity = $user->api_last_activity_at ?? $started;

        return ! $activity->isFuture()
            && (! $this->privileged($user) || now()->lt($activity->copy()->addMinutes($limits['idle_minutes'])));
    }
}
