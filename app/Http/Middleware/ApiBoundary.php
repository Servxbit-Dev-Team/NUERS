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
        $integration = Str::is(['api/integrations/transactions', 'api/integrations/transactions/*'], $path);
        if ($integration) {
            // Merchant systems authenticate with an API key, and each key is already admitted by AtomicQuota. Counting every
            // accepted request against the shared network budget would reject a legitimate system sending from few addresses
            // (300 per minute is 5 per second), so only failed credential attempts spend this budget. Unknown or abusive
            // callers are still stopped before credential lookup once they have failed 300 times in a minute.
            $failures = 'api-ip-auth:'.hash('sha256', $r->ip());
            if (RateLimiter::tooManyAttempts($failures, 300)) {
                return $this->networkLimited($failures);
            }
            $response = $next($r);
            if (in_array($response->getStatusCode(), [401, 403], true)) {
                RateLimiter::hit($failures, 60);
            }

            return $response;
        }
        // Limit rejected traffic before credential lookup. Shared store required across web nodes.
        $uploadPart = $r->isMethod('POST') && Str::is(['api/merchant/verification-uploads/*/chunks', 'api/registration/document-uploads/*/chunks'], $path);
        $key = ($uploadPart ? 'api-upload-ip:' : 'api-ip:').hash('sha256', $r->ip());
        if (RateLimiter::tooManyAttempts($key, $uploadPart ? 600 : 300)) {
            return $this->networkLimited($key);
        }
        RateLimiter::hit($key, 60);
        $public = ['api/health', 'api/legal/policies', 'api/registration/*', 'api/auth/login', 'api/auth/signup', 'api/auth/forgot-password', 'api/auth/reset-password', 'api/auth/verify-email', 'api/auth/session', 'api/auth/logout', 'api/pricing', 'api/subscription-plans', 'api/developer/*', 'api/locations/*', 'api/verify-receipt/*', 'api/team-invitations/preview', 'api/team-invitations/accept'];
        $visitor = Str::is(['api/support/conversations', 'api/support/conversations/*'], $path);
        if (! Str::is($public, $path) && ! $visitor) {
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
                if (! app(\App\Services\MfaSession::class)->verified($u)) {
                    return response()->json(['message' => 'Complete multifactor verification to continue.', 'code' => 'MFA_REQUIRED', 'enrollment_required' => ! $u->mfa_secret], 403);
                }
            }
        }

        return $next($r);
    }

    private function networkLimited(string $key)
    {
        $wait = max(1, RateLimiter::availableIn($key));

        return response()->json(['code' => 'NETWORK_RATE_LIMITED', 'message' => 'This network has reached the request limit. Please wait before trying again.', 'retry_after' => $wait], 429)->header('Retry-After', $wait)->header('Cache-Control', 'no-store');
    }
}
