<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DeviceSession
{
    public function deviceHash(Request $request): ?string
    {
        $device = $request->header('X-NUERS-Device');
        if (! $device) {
            return null;
        }
        abort_unless(Str::isUuid($device), 422, 'Invalid browser device identifier.');

        return hash('sha256', $device);
    }

    public function issue(User $user, Request $request): User
    {
        $device = $this->deviceHash($request);

        return DB::transaction(function () use ($user, $device) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $supports = Schema::hasColumn('users', 'api_device_hash');
            $same = $supports && $device && $locked->api_device_hash && hash_equals($locked->api_device_hash, $device) && $locked->api_token;
            if (! $same) {
                $locked->forceFill(['api_token' => Str::random(64)]);
            }
            if ($supports) {
                $locked->forceFill(['api_device_hash' => $device]);
            }
            $locked->save();

            return $locked;
        });
    }
}
