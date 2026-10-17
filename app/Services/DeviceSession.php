<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DeviceSession
{
    /** Only an authenticated, foreground activity check can renew the idle window. */
    public function renew(User $user, Request $request): ?User
    {
        if ($request->header('X-NUERS-Activity') !== '1' || ! $user->api_authenticated_at || ! Schema::hasColumn('users', 'api_token_hash')) {
            return $user;
        }
        $absolute = $user->api_authenticated_at->copy()->addMinutes(config('security.session_absolute_minutes'));
        $expires = now()->addMinutes(config('security.token_minutes'))->min($absolute);
        // Avoid writing on each poll. Token/device validation has already succeeded.
        if ($user->api_expires_at->lt($expires->copy()->subMinutes(5))) {
            User::whereKey($user->id)->where('api_token_hash', $user->getRawOriginal('api_token_hash'))
                ->where('api_authenticated_at', $user->getRawOriginal('api_authenticated_at'))
                ->where('api_expires_at', '>', now())->where('api_expires_at', '<', $expires)
                ->update(['api_expires_at' => $expires]);
            // A concurrent logout or new-device login must never be resurrected.
            return app(ApiAccess::class)->user($request);
        }

        return $user;
    }

    public function metadata(User $user): array
    {
        return ['expires_at' => $user->api_expires_at?->toIso8601String(),
            'absolute_expires_at' => $user->api_authenticated_at?->copy()->addMinutes(config('security.session_absolute_minutes'))->toIso8601String()];
    }

    public function deviceHash(Request $request): ?string
    {
        $device = $request->header('X-NUERS-Device');
        if (! $device) {
            abort_unless(app()->environment('testing'), 422, 'A valid browser device identifier is required.');

            return null;
        }
        abort_unless(Str::isUuid($device), 422, 'Invalid browser device identifier.');

        return hash('sha256', $device);
    }

    public function issue(User $user, Request $request): User
    {
        $device = $this->deviceHash($request);

        return DB::transaction(function () use ($user, $device, $request) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $supports = Schema::hasColumn('users', 'api_device_hash');
            $raw = $request->bearerToken();
            if (! Schema::hasColumn('users', 'api_token_hash') && app()->environment('testing') && $supports && $device && $locked->api_device_hash && hash_equals($locked->api_device_hash, $device)) {
                $raw = $locked->api_token;
            }
            $same = $supports && $device && $locked->api_device_hash && hash_equals($locked->api_device_hash, $device) && $raw && (! Schema::hasColumn('users', 'api_token_hash') || app(ApiAccess::class)->user($request)?->id === $locked->id);
            if ($supports && $device && $locked->api_device_hash && !hash_equals($locked->api_device_hash,$device)) app(PlatformNotifications::class)->user($locked->id,'security.new_browser',$locked->id.'|'.now('Asia/Manila')->format('Y-m-d-H'),'New browser sign-in','A sign-in from a new browser was completed on your NUERS account at '.now('Asia/Manila')->format('d M Y, g:i A').' PHT. If this was not you, reset your password and contact NUERS support.','security');
            $locked->api_token = $same ? $raw : Str::random(64);
            if (Schema::hasColumn('users', 'api_authenticated_at')) {
                $locked->forceFill(['api_authenticated_at' => now(), 'api_mfa_verified_at' => null]);
            }
            if ($supports) {
                $locked->forceFill(['api_device_hash' => $device]);
            }
            $locked->save();

            return $locked;
        });
    }
}
