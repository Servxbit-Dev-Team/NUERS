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

class MfaDeviceController
{
    private function locked(Request $r): User
    {
        $actor = app(ApiAccess::class)->require($r);
        $u = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
        abort_unless($u->api_token_hash && hash_equals($u->api_token_hash, hash('sha256', (string) $r->bearerToken())), 401, 'Sign in again.');
        abort_unless(app(MfaSession::class)->verified($u), 403, 'Complete MFA verification for this login before managing devices.');

        return $u;
    }

    private function proof(Request $r, User $u): void
    {
        $v = $r->validate(['password' => 'required|string|max:255', 'current_code' => 'required|string|min:6|max:32']);
        abort_unless(Hash::check($v['password'], $u->password), 422, 'Your current password is incorrect.');
        $proof = app(MfaDevices::class)->consume($u, $v['current_code']);
        app(BirCompliance::class)->audit('security.mfa.verified', (string) $u->id, (string) $u->id, $proof);
    }

    public function index(Request $r)
    {
        return DB::transaction(fn () => response()->json(app(MfaDevices::class)->listing($this->locked($r)))->header('Cache-Control', 'private, no-store'));
    }

    public function start(Request $r)
    {
        $r->merge(['name' => trim((string) $r->input('name'))]);
        $v = $r->validate(['name' => 'required|string|min:2|max:80']);

        return DB::transaction(function () use ($r, $v) {
            $u = $this->locked($r);
            $s = app(MfaDevices::class);
            $s->materialize($u);
            abort_if($s->active($u)->count() >= MfaDevices::MAX_DEVICES, 409, 'You can keep up to five authenticators. Remove an unused device first.');
            abort_if($s->active($u)->whereRaw('LOWER(name) = ?', [strtolower($v['name'])])->exists(), 422, 'Choose a different device name.');
            $this->proof($r, $u);
            $s->cancelPending($u);
            $id = (string) Str::uuid();
            $secret = app(Totp::class)->secret();
            DB::table('account_mfa_devices')->insert(['id' => $id, 'user_id' => $u->id, 'name' => $v['name'], 'state' => 'pending', 'encrypted_secret' => Crypt::encryptString($secret), 'setup_binding' => $s->binding($u), 'expires_at' => now()->addMinutes(10), 'created_at' => now(), 'updated_at' => now()]);
            app(BirCompliance::class)->audit('security.mfa.device_setup_started', (string) $u->id, (string) $u->id, ['device_id' => $id]);

            return response()->json(['id' => $id, 'name' => $v['name'], 'secret' => $secret, 'otpauth_url' => 'otpauth://totp/'.rawurlencode('NUERS:'.$u->email.' ('.$v['name'].')').'?secret='.$secret.'&issuer=NUERS&algorithm=SHA1&digits=6&period=30', 'expires_at' => now()->addMinutes(10)->toIso8601String()], 201)->header('Cache-Control', 'private, no-store');
        });
    }

    public function confirm(Request $r, string $id)
    {
        $v = $r->validate(['code' => 'required|string|regex:/^[0-9]{6}$/D']);

        return DB::transaction(function () use ($r, $id, $v) {
            $u = $this->locked($r);
            $s = app(MfaDevices::class);
            $d = DB::table('account_mfa_devices')->where('user_id', $u->id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($d, 404);
            abort_unless($d->state === 'pending' && $d->expires_at && now()->lt($d->expires_at) && hash_equals((string) $d->setup_binding, $s->binding($u)), 409, 'Setup expired or the sign-in changed. Start again.');
            abort_if($s->active($u)->count() >= MfaDevices::MAX_DEVICES, 409, 'Authenticator limit reached.');
            $counter = app(Totp::class)->verify(Crypt::decryptString($d->encrypted_secret), $v['code']);
            abort_unless($counter !== null, 422, 'Enter the code shown on your new device.');
            DB::table('account_mfa_devices')->where('id', $id)->update(['state' => 'active', 'last_counter' => $counter, 'verified_at' => now(), 'setup_binding' => null, 'expires_at' => null, 'updated_at' => now()]);
            $s->notify($u, 'security.mfa.device_added', $id);

            return response()->json(['message' => 'Authenticator added. You can use any active authenticator at your next sign-in.', ...$s->listing($u)])->header('Cache-Control', 'private, no-store');
        });
    }

    public function cancel(Request $r, string $id)
    {
        return DB::transaction(function () use ($r, $id) {
            $u = $this->locked($r);
            $d = DB::table('account_mfa_devices')->where('user_id', $u->id)->where('id', $id)->first();
            abort_unless($d, 404);
            abort_unless(in_array($d->state, ['pending', 'cancelled']), 409);
            DB::table('account_mfa_devices')->where('id', $id)->update(['state' => 'cancelled', 'encrypted_secret' => null, 'setup_binding' => null, 'updated_at' => now()]);

            return response()->json(['cancelled' => true])->header('Cache-Control', 'private, no-store');
        });
    }

    public function revoke(Request $r, string $id)
    {
        return DB::transaction(function () use ($r, $id) {
            $u = $this->locked($r);
            $s = app(MfaDevices::class);
            $s->materialize($u);
            $d = $s->active($u)->where('id', $id)->first();
            abort_unless($d, 404);
            abort_if($s->active($u)->count() <= 1, 409, 'Add and verify another authenticator before removing your last device.');
            $this->proof($r, $u);
            DB::table('account_mfa_devices')->where('id', $id)->update(['state' => 'revoked', 'is_primary' => false, 'encrypted_secret' => null, 'revoked_at' => now(), 'updated_at' => now()]);
            if ($d->is_primary) {
                $replacement = $s->active($u)->orderBy('verified_at')->first();
                DB::table('account_mfa_devices')->where('id', $replacement->id)->update(['is_primary' => true, 'updated_at' => now()]);
                DB::table('users')->where('id', $u->id)->update(['mfa_secret' => $replacement->encrypted_secret, 'mfa_last_counter' => $replacement->last_counter]);
            }
            $s->cancelPending($u);
            $s->notify($u,'security.mfa.device_removed',$id);

            return response()->json(['message' => 'Authenticator removed. Its codes can no longer sign in.', ...$s->listing($u->fresh())])->header('Cache-Control','private, no-store');
        });
    }
}
