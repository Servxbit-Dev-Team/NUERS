<?php

namespace App\Http\Middleware;

use App\Services\IntegrationStatus;
use Closure;
use Illuminate\Http\Request;

/** Normalize even boundary/throttle errors, without changing portal or government APIs. */
class IntegrationApiResponse
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->is('api/integrations/transactions', 'api/integrations/transactions/*')) {
            return $next($request);
        }
        $request->headers->set('Accept', 'application/json');
        $started = microtime(true);
        $response = $next($request);
        $status = $response->getStatusCode();
        $body = json_decode($response->getContent(), true);
        $body = is_array($body) ? $body : [];
        unset($body['bir_acceptance'], $body['bir_connection'], $body['bir_status'], $body['preparation']);
        if ($status >= 400) {
            $code = $body['code'] ?? match ($status) {
                401 => 'UNAUTHENTICATED', 402 => 'INSUFFICIENT_WALLET_BALANCE', 403 => 'ACCESS_DENIED',
                404 => 'NOT_FOUND', 405 => 'METHOD_NOT_ALLOWED', 409 => 'CONFLICT', 413 => 'PAYLOAD_TOO_LARGE',
                415 => 'UNSUPPORTED_MEDIA_TYPE', 422 => 'VALIDATION_FAILED', 429 => 'RATE_LIMITED',
                503 => 'SERVICE_UNAVAILABLE', default => 'REQUEST_FAILED',
            };
            $reason = $status >= 500 ? 'NUERS could not confirm this request. Keep the original event pending and retry unchanged.'
                : ($body['message'] ?? 'The request was not accepted. Check the request and retry policy.');
            // Never expose exception classes, SQL, stack traces or SMTP details, even in debug mode.
            $body = array_intersect_key($body, array_flip(['errors', 'currency', 'available_centavos', 'required_centavos', 'shortage_centavos', 'retry_after_topup', 'transaction_saved', 'retained_by', 'retry_after']));
            if ($status >= 500) {
                unset($body['errors']);
            }
            $body += ['nuers_status' => 'error', 'code' => $code, 'message' => $reason,
                'error' => ['code' => $code, 'reason' => $reason, 'retryable' => $status >= 500 || in_array($status, [408, 429], true),
                    'outcome' => $status >= 500 || $status === 408 ? 'unknown' : 'rejected']];
        } elseif (($body['status'] ?? null) === 'durably_recorded') {
            $body = app(IntegrationStatus::class)->accepted($body);
        } elseif (($body['atomic'] ?? false) && isset($body['results'])) {
            $body['results'] = array_map(fn ($row) => app(IntegrationStatus::class)->accepted($row), $body['results']);
            $body += ['nuers_status' => 'success', 'message' => 'All batch events successfully recorded by NUERS.', 'error' => null];
        }
        $response->setContent(json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $response->headers->set('Content-Type', 'application/json');
        $response->headers->set('Cache-Control', 'private, no-store');
        app(\App\Services\SubmissionAttempts::class)->record($request, $status, $body, $started);

        return $response;
    }
}
