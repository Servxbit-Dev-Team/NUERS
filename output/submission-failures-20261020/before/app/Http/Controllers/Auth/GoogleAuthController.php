<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Models\User;
use App\Services\DeviceSession;
use App\Services\PlatformNotifications;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Throwable;

class GoogleAuthController extends Controller
{
    private const ROLES = ['admin', 'super_admin', 'bir', 'rdo', 'merchant', 'consumer', 'client'];

    public function redirect(Request $request): RedirectResponse
    {
        $request->session()->forget('google_login');

        if (! config('services.google.client_id') || ! config('services.google.client_secret')) {
            return $this->loginRedirect('google_error', 'not_configured');
        }

        // Google's existing browser session and prior consent can be reused.
        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        $request->session()->forget('google_login');

        if ($request->has('error')) {
            $request->session()->forget('state');

            return $this->loginRedirect('google_error', 'cancelled');
        }

        if (! config('services.google.client_id') || ! config('services.google.client_secret')) {
            return $this->loginRedirect('google_error', 'not_configured');
        }

        try {
            // Socialite validates the OAuth state against this browser's session.
            $identity = Socialite::driver('google')->user();
        } catch (InvalidStateException) {
            return $this->loginRedirect('google_error', 'invalid_state');
        } catch (Throwable) {
            // Provider exceptions can contain tokens; do not expose or log them.
            return $this->loginRedirect('google_error', 'provider_failed');
        }

        $subject = (string) $identity->getId();
        $email = strtolower(trim((string) $identity->getEmail()));
        $claims = $identity->getRaw();

        if ($subject === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || ($claims['email_verified'] ?? false) !== true) {
            return $this->loginRedirect('google_error', 'unverified');
        }

        if (! Schema::hasColumn('users', 'google_id')) {
            return $this->loginRedirect('google_error', 'not_configured');
        }

        try {
            $result = DB::transaction(function () use ($email, $subject, $claims) {
                // Both the registered email and the stable Google subject must agree.
                // A previously linked subject never bypasses the registered-email gate.
                $matches = User::whereRaw('LOWER(email) = ?', [$email])->lockForUpdate()->get();
                if ($matches->count() !== 1) {
                    return ['error' => 'account_not_found'];
                }
                $user = $matches->first();
                $linkedOwner = User::where('google_id', $subject)->first();
                if (($linkedOwner && $linkedOwner->id !== $user->id)
                    || ($user->google_id !== null && $user->google_id !== $subject)) {
                    return ['error' => 'identity_conflict'];
                }
                if ($user->google_id === null) {
                    // Only Google-managed addresses can establish a new email link.
                    $domain = substr(strrchr($email, '@'), 1);
                    $hostedDomain = strtolower((string) ($claims['hd'] ?? ''));
                    if ($domain !== 'gmail.com' && ($hostedDomain === '' || $domain !== $hostedDomain)) {
                        return ['error' => 'email_link_unavailable'];
                    }
                }
                $profile = Profile::find($user->id);
                if (! $profile || ! in_array($profile->role, self::ROLES, true)) {
                    return ['error' => 'account_unavailable'];
                }
                if ($user->google_id === null) {
                    $user->forceFill(['google_id' => $subject])->save();
                    app(PlatformNotifications::class)->user($user->id, 'security.google.connected', (string) $user->id, 'Google sign-in connected', 'Google sign-in has been connected to your existing NUERS account. If you did not authorize this, contact NUERS support immediately.', 'security');
                }

                return ['user' => $user];
            }, 3);
        } catch (UniqueConstraintViolationException) {
            return $this->loginRedirect('google_error', 'identity_conflict');
        }
        if (isset($result['error'])) {
            return $this->loginRedirect('google_error', $result['error']);
        }
        $user = $result['user'];

        $request->session()->regenerate();
        $request->session()->put('google_login', ['user_id' => $user->id, 'expires_at' => now()->addMinutes(5)->timestamp, 'credential_stamp' => hash('sha256', $user->password), 'registered_email' => $email, 'google_subject' => $subject]);

        // The URL carries only a status flag, never Google or NUERS bearer tokens.
        return $this->loginRedirect('google', 'success');
    }

    public function session(Request $request): JsonResponse
    {
        $pending = $request->session()->pull('google_login');
        abort_unless(is_array($pending) && ($pending['expires_at'] ?? 0) > now()->timestamp, 401, 'Google sign-in expired. Please try again.');

        $payload = DB::transaction(function () use ($pending, $request) {
            $user = User::whereKey($pending['user_id'])->lockForUpdate()->first();
            $profile = $user ? Profile::find($user->id) : null;
            abort_unless($user && $profile && in_array($profile->role, self::ROLES, true), 401, 'This NUERS account is unavailable.');
            abort_unless(hash_equals((string) ($pending['credential_stamp'] ?? ''), hash('sha256', $user->password)), 401, 'Account security changed. Start a fresh Google sign-in.');
            abort_unless(($pending['registered_email'] ?? null) === strtolower(trim($user->email)) && ($pending['google_subject'] ?? null) === $user->google_id, 401, 'Registered email or Google identity changed. Start a fresh Google sign-in.');
            $user = app(DeviceSession::class)->issue($user, $request);
            $userPayload = ['id' => (string) $user->id, 'email' => $user->email, 'user_metadata' => ['name' => $user->name]];

            return ['session' => [...app(DeviceSession::class)->metadata($user), 'access_token' => $user->api_token, 'token_type' => 'bearer', 'user' => $userPayload, 'mfa_required' => config('security.enforce_privileged_mfa') && (in_array($profile->role, config('security.privileged_roles'), true) || (bool) $user->mfa_secret)], 'user' => $userPayload, 'role' => $profile->role];
        });

        return response()->json($payload)->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    private function loginRedirect(string $key, string $value): RedirectResponse
    {
        $frontend = rtrim((string) config('services.google.frontend_url', config('app.url')), '/');

        // Static SPA documents may stay in browser cache across a deployment.
        // A public nonce forces a fresh document; it grants no login access.
        return redirect()->away($frontend.'/login?'.http_build_query([$key => $value, 'v' => Str::random(12)]))->header('Cache-Control', 'no-store');
    }
}
