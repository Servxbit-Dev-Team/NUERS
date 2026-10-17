<?php

namespace App\Http\Middleware;

use App\Services\ApiAccess;
use App\Services\MerchantTeamAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class ApiBoundary
{
    public function handle(Request $r, Closure $next)
    {
        abort_if((int) $r->server('CONTENT_LENGTH', 0) > config('security.max_request_bytes') || strlen($r->getContent()) > config('security.max_request_bytes'), 413, 'Request body exceeds the supported limit.');
        $path = $r->decodedPath();
        // Limit rejected traffic before credential lookup. Shared store required across web nodes.
        $key = 'api-ip:'.hash('sha256', $r->ip());
        if (RateLimiter::tooManyAttempts($key, 300)) {
            return response()->json(['code' => 'NETWORK_RATE_LIMITED', 'message' => 'This network has reached the request limit. Please wait before trying again.', 'retry_after' => max(1, RateLimiter::availableIn($key))], 429)->header('Retry-After', max(1, RateLimiter::availableIn($key)))->header('Cache-Control', 'no-store');
        }
        RateLimiter::hit($key, 60);
        $public = ['api/health', 'api/legal/policies', 'api/registration/*', 'api/auth/login', 'api/auth/signup', 'api/auth/forgot-password', 'api/auth/reset-password', 'api/auth/verify-email', 'api/auth/session', 'api/auth/logout', 'api/pricing', 'api/subscription-plans', 'api/developer/*', 'api/locations/*', 'api/verify-receipt/*', 'api/team-invitations/preview', 'api/team-invitations/accept'];
        $visitor = Str::is(['api/support/conversations', 'api/support/conversations/*'], $path);
        $integration = Str::is(['api/integrations/transactions', 'api/integrations/transactions/*'], $path);
        if (! Str::is($public, $path) && ! $visitor && ! $integration) {
            $access = app(ApiAccess::class);
            $u = $access->require($r);
            $role = $r->attributes->get('api_role');
            app(MerchantTeamAccess::class)->enforce($r);
            if ($role === 'merchant' && ! Str::is(['api/auth/change-password', 'api/auth/notification-preferences', 'api/auth/resend-verification', 'api/auth/mfa/*'], $path) && ! $r->isMethod('GET') && DB::table('merchants')->where('merchant_account_id', $u->id)->whereIn('status', ['suspended', 'inactive'])->exists()) {
                abort(403, 'This business is suspended. Preserved documents and portability remain available for authorized retrieval.');
            }
            if (Str::is(['api/transactions/overview', 'api/dashboards/super-admin', 'api/dashboards/bir', 'api/tax-intelligence/bir', 'api/tax-intelligence/super-admin'], $path)) {
                $access->require($r, ['admin', 'super_admin', 'bir']);
            }
            if (Str::is(['api/rdos', 'api/rdo/dashboard'], $path)) {
                $access->require($r, ['admin', 'super_admin', 'bir', 'rdo']);
            }
            if (config('security.enforce_privileged_mfa') && (in_array($role, config('security.privileged_roles'), true) || (bool) $u->mfa_secret) && ! Str::is(['api/auth/mfa/*'], $path) && ! ($path === 'api/tables/profiles' && $r->isMethod('GET') && (string) (json_decode($r->query('eq', '{}'), true)['id'] ?? '') === (string) $u->id)) {
                if (! $u->mfa_secret || ! $u->api_mfa_verified_at || now()->diffInMinutes($u->api_mfa_verified_at, true) > config('security.privileged_step_up_minutes')) {
                    return response()->json(['message' => 'Complete multifactor verification to continue.', 'code' => 'MFA_REQUIRED', 'enrollment_required' => ! $u->mfa_secret], 403);
                }
            }
        }

        return $next($r);
    }
}
