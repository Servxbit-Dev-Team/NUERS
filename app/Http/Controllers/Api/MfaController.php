<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Services\ApiAccess;
use App\Services\BirCompliance;
use App\Services\MfaDevices;
use App\Services\MfaSession;
use App\Services\Totp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class MfaController
{
    private function locked(Request $r): User
    {
        $actor = app(ApiAccess::class)->require($r);
        $u = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
        abort_unless($u->api_token_hash && hash_equals($u->api_token_hash, hash('sha256', (string) $r->bearerToken())), 401, 'Sign in again.');

        return $u;
    }

    public function status(Request $r)
    {
        $u = app(ApiAccess::class)->require($r);

        return response()->json(['enrolled' => (bool) $u->mfa_secret,
            'verified' => app(MfaSession::class)->verified($u),
            'opted_out' => app(MfaSession::class)->optedOut($u),
            'fresh' => app(MfaSession::class)->fresh($u),
            'step_up_minutes' => app(\App\Services\SessionPolicy::class)->limits($u)['step_up_minutes'],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function enroll(Request $r)
    {
        $v = $r->validate(['password' => 'required|string|max:255']);

        return DB::transaction(function () use ($r, $v) {
            $u = $this->locked($r);
            abort_unless(Hash::check($v['password'], $u->password), 422, 'Re-enter your current password to configure an authenticator.');
            abort_if($u->mfa_secret, 409, 'An authenticator is already enrolled. Add another device from Account Security.');
            $secret = app(Totp::class)->secret();
            DB::table('users')->where('id', $u->id)->update(['mfa_pending_secret' => Crypt::encryptString($secret), 'mfa_pending_at' => now()]);
            app(BirCompliance::class)->audit('security.mfa.enrollment_started', (string) $u->id, (string) $u->id);

            return response()->json(['secret' => $secret, 'otpauth_url' => 'otpauth://totp/'.rawurlencode('NUERS:'.$u->email).'?secret='.$secret.'&issuer=NUERS&algorithm=SHA1&digits=6&period=30'])->header('Cache-Control', 'no-store');
        });
    }

    public function confirm(Request $r)
    {
        $v = $r->validate(['code' => 'required|string|size:6']);

        return DB::transaction(function () use ($r, $v) {
            $u = $this->locked($r);
            abort_if($u->mfa_secret || ! $u->mfa_pending_secret || ! $u->mfa_pending_at || now()->diffInMinutes($u->mfa_pending_at, true) > 10, 409, 'Start a fresh enrollment.');
            $counter = app(Totp::class)->verify(Crypt::decryptString($u->mfa_pending_secret), $v['code']);
            abort_unless($counter !== null, 422, 'Authenticator code is invalid or expired.');
            $codes = array_map(fn () => Str::random(32), range(1, 10));
            DB::table('users')->where('id', $u->id)->update(['mfa_secret' => $u->mfa_pending_secret, 'mfa_disabled_at' => null, 'mfa_pending_secret' => null, 'mfa_pending_at' => null, 'mfa_last_counter' => $counter, 'api_mfa_verified_at' => now(), 'mfa_recovery_hashes' => json_encode(array_map(fn ($x) => hash('sha256', $x), $codes))]);
            app(BirCompliance::class)->audit('security.mfa.enrolled', (string) $u->id, (string) $u->id);

            return response()->json(['recovery_codes' => $codes, 'message' => 'Store recovery codes privately. Each code can be used once.'])->header('Cache-Control', 'no-store');
        });
    }

    public function verify(Request $r)
    {
        $v = $r->validate(['code' => 'required|string|min:6|max:32']);

        return DB::transaction(function () use ($r, $v) {
            $u = $this->locked($r);
            abort_unless($u->mfa_secret, 409, 'Enroll an authenticator first.');
            $proof = app(MfaDevices::class)->consume($u, $v['code']);
            DB::table('users')->where('id', $u->id)->update(['api_mfa_verified_at' => now()]);
            app(BirCompliance::class)->audit('security.mfa.verified', (string) $u->id, (string) $u->id, $proof);

            return ['verified' => true];
        });
    }

    public function disable(Request $r)
    {
        $v = $r->validate([
            'password' => 'required|string|max:255',
            'current_code' => 'required|string|min:6|max:32',
            'confirm' => 'required|accepted',
        ]);

        return DB::transaction(function () use ($r, $v) {
            // Never accept a target user ID or an administrator override.
            $u = $this->locked($r);
            abort_unless($u->mfa_secret, 409, 'Two-factor authentication is already off.');
            abort_unless(Hash::check($v['password'], $u->password), 422, 'Your current password is incorrect.');
            $proof = app(MfaDevices::class)->consume($u, $v['current_code']);
            DB::table('account_mfa_devices')->where('user_id', $u->id)->update([
                'state' => 'revoked', 'is_primary' => false, 'encrypted_secret' => null,
                'setup_binding' => null, 'expires_at' => null, 'revoked_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('users')->where('id', $u->id)->update([
                ...app(ApiAccess::class)->revocation(),
                'mfa_secret' => null, 'mfa_pending_secret' => null, 'mfa_pending_at' => null,
                'mfa_last_counter' => null, 'mfa_recovery_hashes' => null, 'mfa_disabled_at' => now(),
                'api_authenticated_at' => null, 'api_device_hash' => null, 'remember_token' => null,
                'updated_at' => now(),
            ]);
            DB::table('sessions')->where('user_id', $u->id)->delete();
            app(BirCompliance::class)->audit('security.mfa.disabled', (string) $u->id, (string) $u->id, $proof);
            app(\App\Services\PlatformNotifications::class)->user($u->id, 'security.mfa.disabled', (string) Str::uuid(),
                'Two-factor authentication turned off',
                'Two-factor authentication was turned off for your NUERS account. All authenticators, recovery codes and sign-in sessions were revoked. If you did not make this change, reset your password and contact NUERS support immediately.', 'security');

            return response()->json(['disabled' => true, 'sign_in_required' => true,
                'message' => 'Two-factor authentication is off. All sessions were signed out. Sign in again to continue.'])
                ->header('Cache-Control', 'private, no-store');
        });
    }
}
