<?php

namespace App\Http\Middleware;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Routing\Middleware\ThrottleRequests;

/** Keep configured quotas, but never mix unrelated forms or verified account identities. */
class ScopedThrottleRequests extends ThrottleRequests
{
    protected function resolveRequestSignature($request)
    {
        $route = $request->route();
        if (! $route) {
            return parent::resolveRequestSignature($request);
        }
        $identity = 'ip:'.$request->ip();
        // ApiBoundary runs first: only its verified actor can receive an account-scoped budget.
        // Public and invalid-credential requests stay on the IP budget; raw bearer values never form keys.
        if (str_starts_with($route->uri(), 'api/')) {
            if ($user = $request->attributes->get('api_actor')) {
                $identity = 'api-account:'.$user->id;
            }
        } elseif ($user = $request->user()) {
            $identity = 'web-account:'.$user->getAuthIdentifier();
        }

        return hash('sha256', implode('|', ['form-v2', $route->getDomain() ?? '', ($request->isMethod('HEAD') ? 'GET' : $request->method()), $route->uri(), $identity]));
    }

    protected function buildException($request, $key, $maxAttempts, $responseCallback = null)
    {
        if (! str_starts_with($request->path(), 'api/') && ! $request->expectsJson()) {
            return parent::buildException($request, $key, $maxAttempts, $responseCallback);
        }
        $retry = max(1, $this->getTimeUntilNextRetry($key));
        $headers = $this->getHeaders($maxAttempts, 0, $retry);

        return new HttpResponseException(response()->json([
            'code' => 'ACTION_RATE_LIMITED',
            'message' => 'You have reached the limit for this action. Please wait '.$retry.' seconds before trying again.',
            'retry_after' => $retry,
        ], 429, $headers)->header('Cache-Control', 'no-store'));
    }
}
