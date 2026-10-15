<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Models\User;
use App\Services\DeviceSession;
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

        return Socialite::driver('google')->with(['prompt' => 'select_account'])->redirect();
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

        $user = User::where('google_id', $subject)->first();

        if (! $user) {
            // Only Google's own mail or verified Workspace domains can link by email.
            $domain = substr(strrchr($email, '@'), 1);
            $hostedDomain = strtolower((string) ($claims['hd'] ?? ''));
            if ($domain !== 'gmail.com' && ($hostedDomain === '' || $domain !== $hostedDomain)) {
                return $this->loginRedirect('google_error', 'email_link_unavailable');
            }

            $user = User::whereRaw('LOWER(email) = ?', [$email])->first();
            if (! $user) {
                return $this->loginRedirect('google_error', 'account_not_found');
            }
            if ($user->google_id !== null && $user->google_id !== $subject) {
                return $this->loginRedirect('google_error', 'identity_conflict');
            }
        }

        $profile = Profile::find($user->id);
        if (! $profile || ! in_array($profile->role, self::ROLES, true)) {
            return $this->loginRedirect('google_error', 'account_unavailable');
        }

        if ($user->google_id === null) {
            // An atomic update prevents a simultaneous callback replacing a link.
            $linked = User::whereKey($user->id)->whereNull('google_id')->update(['google_id' => $subject]);
            if ($linked !== 1 && User::find($user->id)?->google_id !== $subject) {
                return $this->loginRedirect('google_error', 'identity_conflict');
            }
        }

        $request->session()->regenerate();
        $request->session()->put('google_login', ['user_id' => $user->id, 'expires_at' => now()->addMinutes(2)->timestamp]);

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
            $user = app(DeviceSession::class)->issue($user, $request);
            $userPayload = ['id' => (string) $user->id, 'email' => $user->email, 'user_metadata' => ['name' => $user->name]];

            return ['session' => ['access_token' => $user->api_token, 'token_type' => 'bearer', 'user' => $userPayload], 'user' => $userPayload, 'role' => $profile->role];
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
