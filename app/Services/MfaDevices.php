<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Caller holds the user row lock for every credential mutation and code consumption. */
class MfaDevices
{
    public const MAX_DEVICES = 5;

    public function legacyId(object $u): string
    {
        return hash('sha256', 'nuers-primary-authenticator:'.$u->id);
    }

    public function binding(object $u): string
    {
        return hash('sha256', $u->api_token_hash.'|'.$u->password);
    }

    public function active(object $u)
    {
        return DB::table('account_mfa_devices')->where('user_id', $u->id)->where('state', 'active');
    }

    public function listing(object $u): array
    {
        $rows = $this->active($u)->orderByDesc('is_primary')->orderBy('created_at')->get()->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'primary' => (bool) $d->is_primary, 'added_at' => $d->verified_at, 'last_used_at' => $d->last_used_at])->all();
        if (! $rows && $u->mfa_secret) {
            $rows[] = ['id' => $this->legacyId($u), 'name' => 'Primary authenticator', 'primary' => true, 'added_at' => null, 'last_used_at' => null];
        }

        return ['recovery_codes_remaining' => count(json_decode($u->fresh()->mfa_recovery_hashes ?? '[]', true)), 'devices' => $rows, 'maximum' => self::MAX_DEVICES, 'can_add' => (bool) $u->mfa_secret && count($rows) < self::MAX_DEVICES];
    }

    public function materialize(object $u): void
    {
        if ($u->mfa_secret && ! $this->active($u)->exists()) {
            DB::table('account_mfa_devices')->insert(['id' => $this->legacyId($u), 'user_id' => $u->id, 'name' => 'Primary authenticator', 'encrypted_secret' => $u->mfa_secret, 'state' => 'active', 'is_primary' => true, 'last_counter' => $u->mfa_last_counter, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function consume(object $u, string $code): array
    {
        $this->materialize($u);
        foreach ($this->active($u)->orderByDesc('is_primary')->orderBy('id')->get() as $d) {
            $counter = app(Totp::class)->verify(Crypt::decryptString($d->encrypted_secret), $code, $d->last_counter);
            if ($counter !== null) {
                DB::table('account_mfa_devices')->where('id', $d->id)->update(['last_counter' => $counter, 'last_used_at' => now(), 'updated_at' => now()]);
                if ($d->is_primary) {
                    DB::table('users')->where('id', $u->id)->update(['mfa_last_counter' => $counter]);
                }

                return ['recovery_code' => false, 'device_id' => $d->id];
            }
        }
        $recovery = json_decode($u->mfa_recovery_hashes ?? '[]', true);
        foreach ($recovery as $i => $hash) {
            if (hash_equals($hash, hash('sha256', $code))) {
                unset($recovery[$i]);
                DB::table('users')->where('id', $u->id)->update(['mfa_recovery_hashes' => json_encode(array_values($recovery))]);

                return ['recovery_code' => true];
            }
        }
        abort(422, 'Invalid, expired or already used verification code.');
    }

    public function cancelPending(object $u): void
    {
        DB::table('account_mfa_devices')->where('user_id', $u->id)->where('state', 'pending')->update(['state' => 'cancelled', 'encrypted_secret' => null, 'setup_binding' => null, 'updated_at' => now()]);
    }

    public function notify(object $u, string $event, string $id): void
    {
        app(BirCompliance::class)->audit($event, (string) $u->id, (string) $u->id, ['device_id' => $id]);
        app(PlatformNotifications::class)->user($u->id, $event, $id, $event === 'security.mfa.device_added' ? 'Authenticator device added' : 'Authenticator device removed', 'Your NUERS authenticator devices changed. Open Account Security to review your devices. If you did not authorize this change, secure your account and contact NUERS support.', 'security');
    }
}
