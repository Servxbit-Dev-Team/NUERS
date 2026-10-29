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
            // Compatibility alias: assurance is now scoped to the current login, without a timer.
            'fresh' => app(MfaSession::class)->verified($u),
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
            DB::table('users')->where('id', $u->id)->update(['mfa_secret' => $u->mfa_pending_secret, 'mfa_pending_secret' => null, 'mfa_pending_at' => null, 'mfa_last_counter' => $counter, 'api_mfa_verified_at' => now(), 'mfa_recovery_hashes' => json_encode(array_map(fn ($x) => hash('sha256', $x), $codes))]);
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
}
