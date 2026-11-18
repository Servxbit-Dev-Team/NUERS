<?php

namespace App\Services;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\RateLimiter;

class RequestQuota
{
    public function admit(string $identity, int $limit, int $seconds = 60, string $code = 'ACCOUNT_RATE_LIMITED'): void
    {
        $key = 'request-quota:'.hash('sha256', $identity);
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $retry = max(1, RateLimiter::availableIn($key));
            throw new HttpResponseException(response()->json([
                'code' => $code,
                'message' => 'Request limit reached. Please wait before trying again.',
                'retry_after' => $retry,
            ], 429)->header('Retry-After', $retry)->header('Cache-Control', 'private, no-store'));
        }
        RateLimiter::hit($key, $seconds);
    }

    public function clear(string $identity): void
    {
        RateLimiter::clear('request-quota:'.hash('sha256', $identity));
    }
}
