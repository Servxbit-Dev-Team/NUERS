<?php

use App\Http\Middleware\ApiBoundary;
use App\Http\Middleware\IntegrationApiResponse;
use App\Http\Middleware\ScopedThrottleRequests;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(IntegrationApiResponse::class);
        $middleware->trustProxies(at: require __DIR__.'/../config/trusted_proxies.php', headers: Request::HEADER_X_FORWARDED_FOR);
        $middleware->alias(['throttle' => ScopedThrottleRequests::class]);
        $middleware->prependToPriorityList(ThrottleRequests::class, ApiBoundary::class);
        $middleware->api(append: [ApiBoundary::class]);
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function (\Symfony\Component\HttpFoundation\Response $response) {
            if ($response->getStatusCode() === 429 && ! request()->is('api/*') && ! request()->expectsJson()) {
                $headers = $response->headers->all();
                unset($headers['content-type'], $headers['content-length']);
                return response()->view('errors.429', ['retryAfter' => $response->headers->get('Retry-After')], 429, $headers)
                    ->header('Cache-Control', 'private, no-store');
            }
            return $response;
        });
    })->create();
