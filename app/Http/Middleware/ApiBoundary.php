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
        $public = ['api/health', 'api/legal/policies', 'api/registration/*', 'api/auth/login', 'api/auth/signup', 'api/auth/forgot-password', 'api/auth/reset-password', 'api/auth/verify-email', 'api/auth/session', 'api/auth/logout', 'api/pricing', 'api/subscription-plans', 'api/developer/*', 'api/locations/*', 'api/team-invitations/preview', 'api/team-invitations/accept', 'api/business-activation/inspect', 'api/business-activation'];
        // Limit rejected traffic before credential lookup. Shared store required across web nodes.
        $uploadPart = $r->isMethod('POST') && Str::is(['api/merchant/verification-uploads/*/chunks', 'api/registration/document-uploads/*/chunks'], $path);
        $heartbeat = $r->isMethod('GET') && $path === 'api/dashboard-revision';
        $publicPath = Str::is($public, $path) && ! $uploadPart;
        $key = ($heartbeat ? 'api-heartbeat-ip:' : ($uploadPart ? 'api-upload-ip:' : ($publicPath ? 'api-public-ip:' : 'api-ip:'))).hash('sha256', $r->ip());
        $limit = $publicPath
            ? (int) config('security.api_public_per_minute', 300)
            : (int) config('security.api_network_per_minute', 1200);
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return response()->json(['code' => 'NETWORK_RATE_LIMITED', 'message' => 'This network has reached the request limit. Please wait before trying again.', 'retry_after' => max(1, RateLimiter::availableIn($key))], 429)->header('Retry-After', max(1, RateLimiter::availableIn($key)))->header('Cache-Control', 'no-store');
        }
        RateLimiter::hit($key, 60);
        $visitor = Str::is(['api/support/conversations', 'api/support/conversations/*'], $path);
        $integration = Str::is(['api/integrations/transactions', 'api/integrations/transactions/*'], $path);
        $birCallback = $r->isMethod('PUT') && preg_match('~^api/bir-eis/receive_invoice_result/[0-9a-f-]{36}$~D', $path);
        if (! Str::is($public, $path) && ! $visitor && ! $integration && ! $birCallback) {
            $access = app(ApiAccess::class);
            $u = $access->require($r);
            app(\App\Services\RequestQuota::class)->admit('portal-account:'.$u->id, (int) config('security.api_account_per_minute', 600));
            $role = $r->attributes->get('api_role');
            // The administrative namespace is never a merchant or citizen entry point.
            // Controllers continue to enforce narrower roles and operation permissions.
            if (Str::is('api/admin/*', $path)) {
                $access->require($r, ['admin', 'super_admin', 'bir', 'rdo']);
            }
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
            // The table API accepts JSON and bracket-form filters. Neither nested values
            // nor malformed input may crash or broaden the own-profile MFA exception.
            $ownProfile = false;
            if ($path === 'api/tables/profiles' && $r->isMethod('GET')) {
                $filter = $r->query('eq', '{}');
                $filter = is_string($filter) ? json_decode($filter, true) : $filter;
                $id = is_array($filter) ? ($filter['id'] ?? null) : null;
                $ownProfile = is_scalar($id) && (string) $id === (string) $u->id;
            }
            if (app(\App\Services\MfaSession::class)->enforced($u, $role) && ! Str::is(['api/auth/mfa/*'], $path) && ! $ownProfile) {
                if (! app(\App\Services\MfaSession::class)->verified($u)) {
                    return response()->json(['message' => 'Complete multifactor verification to continue.', 'code' => 'MFA_REQUIRED', 'enrollment_required' => ! $u->mfa_secret], 403);
                }
            }
            $mfa = app(\App\Services\MfaSession::class);
            if ($mfa->enforced($u, $role) && in_array($role, config('security.privileged_roles'), true)
                && $mfa->sensitive($r) && ! $mfa->recent($u)) {
                $mfa->challenge($u);

                return response()->json(['message' => 'Verify your authenticator again before changing protected records or downloading data. Then retry your action.',
                    'code' => 'MFA_REQUIRED', 'reason' => 'RECENT_MFA_REQUIRED', 'enrollment_required' => ! $u->mfa_secret], 403);
            }
        }

        $response = $next($r);
        if (!in_array($r->method(), ['GET', 'HEAD', 'OPTIONS'], true) && $response->getStatusCode() < 400) {
            app(\App\Services\DashboardRevision::class)->publishIfChanged();
        }
        return $response;
    }
}
