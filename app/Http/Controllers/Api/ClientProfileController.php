<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApiAccess;
use App\Services\SafeImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class ClientProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $this->userFromRequest($request);

        abort_unless($user, 401, 'Authenticated Client session is required.');

        return response()->json([
            'data' => $this->clientPayload($user, $request),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $this->userFromRequest($request);

        abort_unless($user, 401, 'Authenticated Client session is required.');

        app(ApiAccess::class)->require($request, ['client', 'consumer']);

        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'organization' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('profiles', 'email')->ignore($user->id, 'id')],
            'mobile' => ['nullable', 'string', 'max:80'],
        ]);

        $oldContact = DB::table('profiles')->where('id',$user->id)->value('email');
        $now = now();
        $profileUpdates = [
            'full_name' => $validated['full_name'],
            'organization' => $validated['organization'] ?? 'Citizen Account',
            'email' => $validated['email'] ?: $user->email,
            'updated_at' => $now,
        ];

        if (DB::table('profiles')->where('id', $user->id)->exists()) {
            DB::table('profiles')->where('id', $user->id)->update($profileUpdates);
        } else {
            DB::table('profiles')->insert([
                'id' => $user->id,
                ...$profileUpdates,
                'role' => 'client',
                'created_at' => $now,
            ]);
        }

        $accountQuery = DB::table('client_accounts')->where('user_id', $user->id);

        if (! empty($user->email)) {

        }

        $account = $accountQuery->first();

        if ($account) {
            DB::table('client_accounts')->where('id', $account->id)->update([
                'full_name' => $validated['full_name'],
                'email' => $validated['email'] ?: $user->email,
                'mobile' => $validated['mobile'] ?? null,
                'updated_at' => $now,
            ]);
        }

        if ($oldContact !== ($validated['email'] ?: $user->email)) app(\App\Services\PlatformNotifications::class)->user($user->id,'security.contact.changed',(string)\Illuminate\Support\Str::uuid(),'Profile contact email changed','The contact email on your NUERS profile was changed. Your sign-in email has not changed. If this was unexpected, contact NUERS support.','security');

        return response()->json([
            'data' => $this->clientPayload($user, $request),
        ]);
    }

    public function uploadAvatar(Request $request): JsonResponse
    {
        $user = $this->userFromRequest($request);

        abort_unless($user, 401, 'Authenticated Client session is required.');
        abort_unless(Schema::hasColumn('profiles', 'profile_photo_url'), 500, 'Citizen profile photo storage is not migrated yet.');

        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:2048'],
        ]);

        $file = $request->file('avatar');
        $directory = public_path('uploads/client-avatars');
        File::ensureDirectoryExists($directory);

        $profile = DB::table('profiles')->where('id', $user->id)->first();
        $displayName = $profile?->full_name ?: $user->email ?: 'client-account';
        $fileName = app(SafeImage::class)->store($file, $directory);

        DB::table('profiles')->where('id', $user->id)->update([
            'profile_photo_url' => '/uploads/client-avatars/'.$fileName,
            'updated_at' => now(),
        ]);

        return response()->json([
            'data' => $this->clientPayload($user, $request),
        ]);
    }

    private function userFromRequest(Request $request): ?object
    {
        $token = $request->bearerToken();

        if (! $token) {
            return null;
        }

        return app(ApiAccess::class)->user($request);
    }

    private function clientPayload(object $user, Request $request): array
    {
        $profile = DB::table('profiles')->where('id', $user->id)->first();
        $accountQuery = DB::table('client_accounts')->where('user_id', $user->id);

        if (! empty($user->email)) {

        }

        $account = $accountQuery->first();

        return [
            'verified_tin' => app(ApiAccess::class)->taxpayerTin($user),
            'profile' => $profile ? [
                'id' => (int) $profile->id,
                'email' => $profile->email,
                'role' => $profile->role,
                'full_name' => $profile->full_name,
                'organization' => $profile->organization,
                'tin' => $profile->tin ?? null,
                'tin_bound_at' => $profile->tin_bound_at ?? null,
                'profile_photo_url' => $this->publicAssetUrl($profile->profile_photo_url ?? null, $request),
                'created_at' => $profile->created_at,
                'updated_at' => $profile->updated_at,
            ] : null,
            'account' => $account,
        ];
    }

    private function publicAssetUrl(?string $path, ?Request $request): ?string
    {
        $path = trim((string) $path);

        if ($path === '') {
            return null;
        }

        if (preg_match('/^(https?:|data:|blob:)/i', $path)) {
            return $path;
        }

        $path = '/'.ltrim($path, '/');

        if (str_starts_with($path, '/public/')) {
            return $path;
        }

        $baseUrl = $request ? rtrim($request->getBaseUrl(), '/') : '';

        if (str_ends_with($baseUrl, '/index.php')) {
            $baseUrl = substr($baseUrl, 0, -10);
        }

        return $baseUrl ? $baseUrl.$path : $path;
    }
}
