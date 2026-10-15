<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function session(Request $request): JsonResponse
    {
        $user = $this->userFromRequest($request);

        return response()->json([
            'session' => $user ? $this->sessionPayload($user) : null,
            'user' => $user ? $this->userPayload($user) : null,
        ]);
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

        $user->forceFill(['api_token' => Str::random(64)])->save();

        return response()->json([
            'session' => $this->sessionPayload($user),
            'user' => $this->userPayload($user),
        ]);
    }

    public function signup(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'meta' => ['array'],
            'meta.role' => ['sometimes', \Illuminate\Validation\Rule::in(['merchant', 'client', 'consumer'])],
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

        return response()->json([
            'session' => $this->sessionPayload($user),
            'user' => $this->userPayload($user),
        ], 201);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $this->userFromRequest($request);
        $user?->forceFill(['api_token' => null])->save();

        return response()->json(['success' => true]);
    }

    public function createMerchantAccount(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:6'],
            'merchantId' => ['required', 'string'],
            'businessName' => ['required', 'string'],
        ]);

        $user = User::firstOrCreate(
            ['email' => $payload['email']],
            [
                'name' => $payload['businessName'],
                'password' => Hash::make($payload['password']),
                'api_token' => null,
            ],
        );

        if (! $user->wasRecentlyCreated) {
            $user->forceFill([
                'name' => $user->name ?: $payload['businessName'],
                'password' => Hash::make($payload['password']),
            ])->save();
        }

        Profile::updateOrCreate(
            ['id' => $user->id],
            [
                'email' => $user->email,
                'role' => 'merchant',
                'full_name' => $payload['businessName'],
                'organization' => $payload['businessName'],
            ],
        );

        Merchant::whereKey($payload['merchantId'])->update([
            'merchant_account_id' => $user->id,
            'merchant_account_email' => $user->email,
        ]);

        return response()->json([
            'success' => true,
            'userId' => (string) $user->id,
        ]);
    }

    private function userFromRequest(Request $request): ?User
    {
        $token = $request->bearerToken();

        if (! $token) {
            return null;
        }

        return User::where('api_token', $token)->first();
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
