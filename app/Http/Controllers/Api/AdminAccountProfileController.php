<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Services\ApiAccess;
use App\Services\BirCompliance;
use App\Services\DeviceSession;
use App\Services\PlatformNotifications;
use App\Services\SafeImage;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminAccountProfileController
{
    public const FIELDS = ['full_name', 'organization', 'mobile', 'position', 'department', 'office_address', 'telephone', 'alternate_email', 'timezone', 'time_format', 'density'];

    public const ZONES = ['Asia/Manila', 'Asia/Singapore', 'UTC', 'Europe/London', 'America/New_York'];

    private function actor(Request $r): User
    {
        return app(ApiAccess::class)->require($r, ['admin', 'super_admin']);
    }

    private function details(?object $row): array
    {
        return $row?->encrypted_details ? json_decode(Crypt::decryptString($row->encrypted_details), true, flags: JSON_THROW_ON_ERROR) : [];
    }

    private function date(?string $value, ?string $zone = null): ?string
    {
        return $value ? CarbonImmutable::parse($value, $zone ?? config('app.timezone'))->toIso8601String() : null;
    }

    private function lockedActor(Request $r): User
    {
        $actor = $this->actor($r);
        $u = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
        abort_unless($u->api_token_hash && hash_equals($u->api_token_hash, hash('sha256', (string) $r->bearerToken())), 401, 'Sign in again.');
        $role = DB::table('profiles')->where('id', $u->id)->lockForUpdate()->value('role');
        abort_unless(in_array($role, ['admin', 'super_admin'], true), 403);
        if (config('security.enforce_privileged_mfa')) {
            abort_unless($u->mfa_secret && $u->api_mfa_verified_at && now()->diffInMinutes($u->api_mfa_verified_at, true) <= config('security.privileged_step_up_minutes'), 403, 'Complete fresh MFA verification.');
        }

        return $u;
    }

    public function show(Request $r)
    {
        $u = $this->actor($r);
        $p = DB::table('profiles')->where('id', $u->id)->first();
        $row = DB::table('admin_account_profiles')->where('user_id', $u->id)->first();
        $d = $this->details($row);
        $prefs = DB::table('notification_preferences')->where('user_id', (string) $u->id)->first();
        $events = DB::table('eis_audit_events')->where('actor_id', (string) $u->id)->where('subject_id', (string) $u->id)
            ->where(function ($q) {
                $q->where('event', 'like', 'account.profile.%')->orWhere('event', 'like', 'account.preferences.%')->orWhere('event', 'like', 'account.photo.%')->orWhere('event', 'like', 'security.password.%')->orWhere('event', 'like', 'security.mfa.%');
            })
            ->orderByDesc('id')->limit(30)->get(['id', 'event', 'occurred_at'])->map(fn ($x) => ['id' => (string) $x->id, 'event' => $x->event, 'at' => $this->date($x->occurred_at, 'UTC')])->all();
        $lastPassword = DB::table('eis_audit_events')->where('subject_id', (string) $u->id)->whereIn('event', ['security.password.changed', 'security.password.reset'])->orderByDesc('id')->value('occurred_at');
        $ua = substr((string) $r->userAgent(), 0, 500);
        $browser = str_contains($ua, 'Edg/') ? 'Microsoft Edge' : (str_contains($ua, 'Chrome/') ? 'Chrome' : (str_contains($ua, 'Firefox/') ? 'Firefox' : (str_contains($ua, 'Safari/') ? 'Safari' : 'Browser')));
        $os = str_contains($ua, 'Windows') ? 'Windows' : (str_contains($ua, 'Android') ? 'Android' : (str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') ? 'iOS' : (str_contains($ua, 'Macintosh') ? 'macOS' : (str_contains($ua, 'Linux') ? 'Linux' : 'This device'))));

        return response()->json(['profile' => ['full_name' => $p->full_name ?: $u->name, 'organization' => $p->organization ?? '', 'mobile' => $d['mobile'] ?? '', 'position' => $d['position'] ?? '', 'department' => $d['department'] ?? '', 'office_address' => $d['office_address'] ?? '', 'telephone' => $d['telephone'] ?? '', 'alternate_email' => $d['alternate_email'] ?? '', 'timezone' => $d['timezone'] ?? 'Asia/Manila', 'time_format' => $d['time_format'] ?? '12', 'density' => $d['density'] ?? 'comfortable'],
            'account' => ['email' => $u->email, 'email_verified' => (bool) $u->email_verified_at, 'id' => 'NUERS-ADM-'.str_pad((string) $u->id, 6, '0', STR_PAD_LEFT), 'role' => $p->role, 'created_at' => $u->created_at?->toIso8601String(), 'has_avatar' => (bool) $row?->avatar_path, 'revision' => (int) ($row->revision ?? 0)],
            'security' => ['mfa_enrolled' => (bool) $u->mfa_secret, 'mfa_verified_at' => $this->date($u->getRawOriginal('api_mfa_verified_at')), 'password_changed_at' => $this->date($lastPassword, 'UTC'), 'idle_minutes' => config('security.token_minutes'), 'absolute_minutes' => config('security.session_absolute_minutes'), 'step_up_minutes' => config('security.privileged_step_up_minutes'), 'login_alerts' => true],
            'notifications' => ['security_email' => true, 'workflow_email' => (bool) ($prefs->workflow_email ?? true), 'support_email' => (bool) ($prefs->support_email ?? true), 'operations_email' => (bool) ($prefs->operations_email ?? true)],
            'session' => ['device' => $os, 'browser' => $browser, 'ip' => $r->ip(), 'started_at' => $u->api_authenticated_at?->toIso8601String(), ...app(DeviceSession::class)->metadata($u), 'observed_at' => now()->toIso8601String(), 'current' => true],
            'activity' => $events, 'generated_at' => now()->toIso8601String()])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $r)
    {
        $this->actor($r);
        $validated = $r->validate(['revision' => 'required|integer|min:0', 'profile' => 'required|array:'.implode(',', self::FIELDS),
            'profile.full_name' => 'required|string|max:150', 'profile.organization' => 'nullable|string|max:150', 'profile.mobile' => ['nullable', 'string', 'max:32', 'regex:/^\+?[0-9 ()-]{7,32}$/'],
            'profile.position' => 'nullable|string|max:100', 'profile.department' => 'nullable|string|max:100', 'profile.office_address' => 'nullable|string|max:1000', 'profile.telephone' => ['nullable', 'string', 'max:32', 'regex:/^\+?[0-9 ()-]{7,32}$/'],
            'profile.alternate_email' => 'nullable|email:rfc|max:254', 'profile.timezone' => ['required', Rule::in(self::ZONES)], 'profile.time_format' => 'required|in:12,24', 'profile.density' => 'required|in:comfortable,compact']);
        DB::transaction(function () use ($r, $validated) {
            $u = $this->lockedActor($r);
            $row = DB::table('admin_account_profiles')->where('user_id', $u->id)->lockForUpdate()->first();
            abort_unless((int) ($row->revision ?? 0) === (int) $r->input('revision'), 409, 'Your profile changed in another window. Reload before saving.');
            $v = $validated['profile'];
            foreach ($v as $key => $value) {
                if (is_string($value)) {
                    $v[$key] = trim($value);
                }
            }
            abort_if($v['full_name'] === '', 422, 'Full name is required.');
            $p = DB::table('profiles')->where('id', $u->id)->first();
            $old = $this->details($row) + ['full_name' => $p->full_name, 'organization' => $p->organization];
            $changed = [];
            foreach ($v as $key => $value) {
                if (($old[$key] ?? '') !== ($value ?? '')) {
                    $changed[] = $key;
                }
            }
            if (! $changed) {
                return;
            }
            DB::table('profiles')->where('id', $u->id)->update(['full_name' => $v['full_name'], 'organization' => $v['organization'] ?? '', 'updated_at' => now()]);
            DB::table('users')->where('id', $u->id)->update(['name' => $v['full_name'], 'updated_at' => now()]);
            unset($v['full_name'],$v['organization']);
            $payload = ['encrypted_details' => Crypt::encryptString(json_encode($v, JSON_THROW_ON_ERROR)), 'revision' => (int) ($row->revision ?? 0) + 1, 'updated_at' => now()];
            if ($row) {
                DB::table('admin_account_profiles')->where('user_id', $u->id)->update($payload);
            } else {
                DB::table('admin_account_profiles')->insert(['user_id' => $u->id, 'created_at' => now(), ...$payload]);
            }
            app(BirCompliance::class)->audit('account.profile.updated', (string) $u->id, (string) $u->id, ['fields' => $changed, 'role' => $p->role]);
            app(PlatformNotifications::class)->user($u->id, 'security.profile.changed', (string) Str::uuid(), 'Your account profile was updated', 'Your NUERS account profile or display preferences were updated. Your sign-in email and role have not changed. If this was unexpected, review Account Profile and contact NUERS support.', 'security');
        }, 3);

        return $this->show($r);
    }

    public function notifications(Request $r)
    {
        $this->actor($r);
        $v = $r->validate(['revision' => 'required|integer|min:0', 'workflow_email' => 'required|boolean', 'support_email' => 'required|boolean', 'operations_email' => 'required|boolean']);
        DB::transaction(function () use ($r, $v) {
            $u = $this->lockedActor($r);
            $row = DB::table('admin_account_profiles')->where('user_id', $u->id)->lockForUpdate()->first();
            abort_unless((int) ($row->revision ?? 0) === (int) $v['revision'], 409, 'Account preferences changed. Reload before saving.');
            $prefs = DB::table('notification_preferences')->where('user_id', (string) $u->id)->first();
            $changes = [];
            foreach (['workflow_email', 'support_email', 'operations_email'] as $key) {
                if ((bool) ($prefs?->$key ?? true) !== (bool) $v[$key]) {
                    $changes[] = $key;
                }
            }
            if (! $changes) {
                return;
            }unset($v['revision']);
            DB::table('notification_preferences')->updateOrInsert(['user_id' => (string) $u->id], [...$v, 'created_at' => $prefs?->created_at ?? now(), 'updated_at' => now()]);
            if ($row) {
                DB::table('admin_account_profiles')->where('user_id', $u->id)->update(['revision' => $row->revision + 1, 'updated_at' => now()]);
            } else {
                DB::table('admin_account_profiles')->insert(['user_id' => $u->id, 'encrypted_details' => Crypt::encryptString('{}'), 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
            }
            app(BirCompliance::class)->audit('account.preferences.updated', (string) $u->id, (string) $u->id, ['fields' => $changes, 'role' => $r->attributes->get('api_role')]);
        }, 3);

        return $this->show($r);
    }

    public function uploadAvatar(Request $r)
    {
        $u = $this->actor($r);
        $r->validate(['avatar' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048', 'revision' => 'required|integer|min:0']);
        $dir = storage_path('app/private/admin-avatars');
        $file = app(SafeImage::class)->store($r->file('avatar'), $dir);
        chmod($dir, 0700);
        chmod($dir.'/'.$file, 0600);
        try {
            DB::transaction(function () use ($r, $file) {
                $u = $this->lockedActor($r);
                $row = DB::table('admin_account_profiles')->where('user_id', $u->id)->lockForUpdate()->first();
                abort_unless((int) ($row->revision ?? 0) === (int) $r->input('revision'), 409, 'Your profile changed. Reload before uploading.');
                $payload = ['avatar_path' => $file, 'revision' => (int) ($row->revision ?? 0) + 1, 'updated_at' => now()];
                if ($row) {
                    DB::table('admin_account_profiles')->where('user_id', $u->id)->update($payload);
                } else {
                    DB::table('admin_account_profiles')->insert(['user_id' => $u->id, 'encrypted_details' => Crypt::encryptString('{}'), 'created_at' => now(), ...$payload]);
                }
                app(BirCompliance::class)->audit('account.photo.updated', (string) $u->id, (string) $u->id, ['role' => $r->attributes->get('api_role')]);
            }, 3);
        } catch (\Throwable$e) {
            File::delete($dir.'/'.$file);
            throw $e;
        }

        return $this->show($r);
    }

    public function avatar(Request $r)
    {
        $u = $this->actor($r);
        $name = DB::table('admin_account_profiles')->where('user_id',$u->id)->value('avatar_path');
        abort_unless($name && preg_match('/^[a-f0-9-]{36}\.png$/',$name),404);
        $path = storage_path('app/private/admin-avatars/'.$name);
        abort_unless(is_file($path),404);

        return response()->file($path,['Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
