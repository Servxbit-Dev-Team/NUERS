<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Models\User;
use App\Services\ApiAccess;
use App\Services\BirCompliance;
use App\Services\DeviceSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function session(Request $request): JsonResponse
    {
        $user = $this->userFromRequest($request);
        if ($user) $user = app(DeviceSession::class)->renew($user, $request);

        return response()->json([
            'session' => $user ? $this->sessionPayload($user) : null,
            'user' => $user ? $this->userPayload($user) : null,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            if($user) app(\App\Services\PlatformNotifications::class)->failedSignIn($user);
            throw ValidationException::withMessages([
                'email' => ['Invalid email or password.'],
            ]);
        }

        $user = app(DeviceSession::class)->issue($user, $request);

        return response()->json([
            'session' => $this->sessionPayload($user),
            'user' => $this->userPayload($user),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function signup(Request $request): JsonResponse
    {
        $v = $request->validate(['email' => 'required|email|max:255|unique:users,email', 'password' => ['required', 'string', 'max:72', Password::min(12)->letters()->numbers()], 'meta' => 'nullable|array:role,full_name,organization,phone', 'meta.role' => 'nullable|in:client,consumer', 'meta.full_name' => 'nullable|string|max:255', 'meta.organization' => 'nullable|string|max:255', 'meta.phone' => 'nullable|string|max:80']);

        return DB::transaction(function () use ($v, $request) {
            $meta = $v['meta'] ?? [];
            $user = User::create(['name' => $meta['full_name'] ?? $v['email'], 'email' => strtolower($v['email']), 'password' => $v['password']]);
            Profile::create(['id' => $user->id, 'email' => $user->email, 'role' => $meta['role'] ?? 'client', 'full_name' => $meta['full_name'] ?? '', 'organization' => $meta['organization'] ?? '', 'tin' => null, 'tin_bound_at' => null]);
            DB::table('client_accounts')->insert(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'account_number' => 'CL-'.Str::upper((string) Str::ulid()), 'full_name' => $meta['full_name'] ?? $user->email, 'email' => $user->email, 'mobile' => $meta['phone'] ?? null, 'account_type' => 'citizen', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            app(\App\Services\PlatformNotifications::class)->verification($user);
            app(\App\Services\PlatformNotifications::class)->user($user->id,'account.created',(string)$user->id,'Welcome to NUERS','Your citizen account has been created and is pending review. Sign in to complete your profile and review account requirements.');
            $user = app(DeviceSession::class)->issue($user, $request);

            return response()->json(['session' => $this->sessionPayload($user), 'user' => $this->userPayload($user)], 201)->header('Cache-Control', 'private, no-store');
        });
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $this->userFromRequest($request);
        if ($user) {
            $updates = ['api_token' => null];
            if (Schema::hasColumn('users', 'api_token_hash')) {
                $updates += ['api_token_hash' => null, 'api_expires_at' => null, 'api_mfa_verified_at' => null];
            }
            User::whereKey($user->id)->update($updates);
        }

        return response()->json(['success' => true]);
    }

    public function createMerchantAccount(Request $request): JsonResponse
    {
        $actor = app(BirCompliance::class)->administrativeUser($request);
        abort_unless(in_array(Profile::where('id', $actor->id)->value('role'), ['admin', 'super_admin']), 403);
        abort(410, 'Direct credential provisioning is retired. Generate a secure account-setup invitation from the business registry.');
    }

    private function userFromRequest(Request $request): ?User
    {
        $token = $request->bearerToken();

        if (! $token) {
            return null;
        }

        $user = app(ApiAccess::class)->user($request);

        return $user;
    }

    private function sessionPayload(User $user): array
    {
        return [
            ...app(DeviceSession::class)->metadata($user),
            'access_token' => $user->api_token ?: request()->bearerToken(),
            'token_type' => 'bearer',
            'user' => $this->userPayload($user),
            'mfa_required' => app(\App\Services\MfaSession::class)->required($user, Profile::where('id', $user->id)->value('role')),
        ];
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => (string) $user->id,
            'email' => $user->email,
            'user_metadata' => [
                'name' => $user->name,
            ],
        ];
    }
}
