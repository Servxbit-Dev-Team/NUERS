<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Profile;
use App\Models\User;
use App\Services\BirCompliance;
use App\Services\DeviceSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function session(Request $request): JsonResponse
    {
        $user = $this->userFromRequest($request);

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
            throw ValidationException::withMessages([
                'email' => ['Invalid email or password.'],
            ]);
        }

        if (Profile::where('id', $user->id)->value('role') === 'merchant') {
            abort_if(Merchant::where('merchant_account_id', $user->id)->whereIn('status', ['suspended', 'inactive'])->exists(), 403, 'Business access is suspended or inactive. Contact NUERS support.');
        }
        $user = app(DeviceSession::class)->issue($user, $request);

        return response()->json([
            'session' => $this->sessionPayload($user),
            'user' => $this->userPayload($user),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function signup(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'meta' => ['array'],
            'meta.role' => ['sometimes', Rule::in(['merchant', 'client', 'consumer'])],
        ]);

        $meta = $payload['meta'] ?? [];
        $name = $meta['full_name'] ?? $payload['email'];

        $user = User::create([
            'name' => $name,
            'email' => $payload['email'],
            'password' => Hash::make($payload['password']),
            'api_token' => Str::random(64),
        ]);

        Profile::create([
            'id' => $user->id,
            'email' => $user->email,
            'role' => $meta['role'] ?? 'merchant',
            'full_name' => $meta['full_name'] ?? '',
            'organization' => $meta['organization'] ?? '',
            'tin' => $meta['tin'] ?? null,
            'tin_bound_at' => ! empty($meta['tin']) ? now() : null,
        ]);

        $user = app(DeviceSession::class)->issue($user, $request);

        return response()->json([
            'session' => $this->sessionPayload($user),
            'user' => $this->userPayload($user),
        ], 201)->header('Cache-Control', 'private, no-store');
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $this->userFromRequest($request);
        if ($user) {
            User::whereKey($user->id)->where('api_token', $request->bearerToken())->update(['api_token' => null]);
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

        $user = User::where('api_token', $token)->first();
        if ($user && Profile::where('id', $user->id)->value('role') === 'merchant' && Merchant::where('merchant_account_id', $user->id)->whereIn('status', ['suspended', 'inactive'])->exists()) {
            return null;
        }

        return $user;
    }

    private function sessionPayload(User $user): array
    {
        return [
            'access_token' => $user->api_token,
            'token_type' => 'bearer',
            'user' => $this->userPayload($user),
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
