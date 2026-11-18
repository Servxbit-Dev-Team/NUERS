<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Routing\Route;

abstract class TestCase extends BaseTestCase
{
    protected ?string $auditScenarioRole = null;

    /** Opt-in request metadata for auditing real regression coverage; never records payloads or secrets. */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $response = parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        if (getenv('NUERS_TEST_ROUTE_COVERAGE') === '1' && $this->app->environment('testing')) {
            $request = $response->baseRequest;
            $route = $request?->route();
            if ($route instanceof Route) {
                $directory = base_path('.local/vapt-positive-2026-11-15');
                if (! is_dir($directory)) {
                    mkdir($directory, 0700, true);
                }
                $row = ['test' => static::class.'::'.$this->name(), 'method' => $request->method(),
                    'route' => $route->uri(), 'action' => $route->getActionName(),
                    'role' => $request->attributes->get('api_role'), 'scenario_role' => $this->auditScenarioRole, 'status' => $response->getStatusCode(),
                    'mfa_enforced' => (bool) config('security.enforce_privileged_mfa'),
                    'credential_kind' => $request->hasHeader('X-NUERS-API-Key') ? 'machine-key' :
                        ($request->hasHeader('X-Chat-Token') ? 'visitor-capability' :
                        ($request->hasHeader('datetime') ? 'signed-callback' : ($request->bearerToken() ? 'bearer' : 'none'))),
                    'device_bound' => $request->attributes->get('api_actor')?->api_device_hash !== null];
                file_put_contents($directory.'/request-coverage.jsonl', json_encode($row, JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
            }
        }

        return $response;
    }
}
