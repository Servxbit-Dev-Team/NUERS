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
        if (! app(SessionPolicy::class)->valid($user) || ! $user->api_expires_at || now()->gte($user->api_expires_at)) {
            return null;
        }
        if ($request->header('X-NUERS-Activity') !== '1' || ! $user->api_authenticated_at || ! Schema::hasColumn('users', 'api_token_hash')) {
            return $user;
        }
        $limits = app(SessionPolicy::class)->limits($user);
        $absolute = $user->api_authenticated_at->copy()->addMinutes($limits['absolute_minutes']);
        $expires = now()->addMinutes($limits['idle_minutes'])->min($absolute);
        // Foreground activity only; no writes more frequently than once a minute.
        if (! $user->api_last_activity_at || $user->api_last_activity_at->lte(now()->subMinute())) {
            User::whereKey($user->id)->where('api_token_hash', $user->getRawOriginal('api_token_hash'))
                ->where('api_authenticated_at', $user->getRawOriginal('api_authenticated_at'))
                ->where('api_expires_at', '>', now())
                ->update(['api_expires_at' => $expires, 'api_last_activity_at' => now()]);
            // A concurrent logout or new-device login must never be resurrected.
            return app(ApiAccess::class)->user($request);
        }

        return $user;
    }

    public function metadata(User $user): array
    {
        $limits = app(SessionPolicy::class)->limits($user);
        $absolute = $user->api_authenticated_at?->copy()->addMinutes($limits['absolute_minutes']);
        $expires = $user->api_expires_at?->copy();
        if ($absolute && $expires) {
            $expires = $expires->min($absolute);
            if (app(SessionPolicy::class)->privileged($user)) {
                $expires = $expires->min(($user->api_last_activity_at ?? $user->api_authenticated_at)->copy()->addMinutes($limits['idle_minutes']));
            }
        }

        return ['expires_at' => $expires?->toIso8601String(), 'absolute_expires_at' => $absolute?->toIso8601String()];
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
            // Password verification happens before this row lock. A reset that commits
            // in between must invalidate that earlier authentication result.
            abort_unless(hash_equals((string) $locked->getRawOriginal('password'), (string) $user->getRawOriginal('password')),
                401, 'Your credentials changed. Sign in again.');
            $supports = Schema::hasColumn('users', 'api_device_hash');
            $raw = $request->bearerToken();
            if (! Schema::hasColumn('users', 'api_token_hash') && app()->environment('testing') && $supports && $device && $locked->api_device_hash && hash_equals($locked->api_device_hash, $device)) {
                $raw = $locked->api_token;
            }
            $same = $supports && $device && $locked->api_device_hash && hash_equals($locked->api_device_hash, $device) && $raw && (! Schema::hasColumn('users', 'api_token_hash') || app(ApiAccess::class)->user($request)?->id === $locked->id);
            if ($supports && $device && $locked->api_device_hash && !hash_equals($locked->api_device_hash, $device)) {
                app(PlatformNotifications::class)->user($locked->id, 'security.new_browser', $locked->id.'|'.now('Asia/Manila')->format('Y-m-d-H'), 'New browser sign-in', 'A sign-in from a new browser was completed on your NUERS account at '.now('Asia/Manila')->format('d M Y, g:i A').' PHT. If this was not you, reset your password and contact NUERS support.', 'security');
            }
            $locked->api_token = $same ? $raw : Str::random(64);
            if (Schema::hasColumn('users', 'api_authenticated_at')) {
                $limits = app(SessionPolicy::class)->limits($locked);
                $locked->forceFill(['api_authenticated_at' => now(), 'api_last_activity_at' => now(), 'api_mfa_challenged_at' => null,
                    'api_mfa_verified_at' => null, 'api_expires_at' => now()->addMinutes(min($limits['idle_minutes'], $limits['absolute_minutes']))]);
            }
            if ($supports) {
                $locked->forceFill(['api_device_hash' => $device]);
            }
            $locked->save();

            return $locked;
        });
    }
}
