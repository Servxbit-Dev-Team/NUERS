<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MfaSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\IsolatedSecurityDatabase;
use Tests\TestCase;

class EndpointSecurityTest extends TestCase
{
    use IsolatedSecurityDatabase;

    // An explicit review list, intentionally separate from the middleware exemption list.
    private const PUBLIC = ['api/health', 'api/legal/policies', 'api/registration/*', 'api/auth/login', 'api/auth/signup', 'api/auth/forgot-password', 'api/auth/reset-password', 'api/auth/verify-email', 'api/auth/session', 'api/auth/logout', 'api/pricing', 'api/subscription-plans', 'api/developer/*', 'api/locations/*', 'api/team-invitations/preview', 'api/team-invitations/accept', 'api/business-activation/inspect', 'api/business-activation'];

    private const OWN_AUTH = ['api/support/conversations*', 'api/integrations/transactions*', 'api/bir-eis/receive_invoice_result/*'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array', 'queue.default' => 'database', 'app.debug' => false, 'notifications.dispatch_enabled' => false]);
        $this->securityDatabase();
        $this->securityMigrate();
        Http::preventStrayRequests();
        Mail::fake();
    }

    private function path($route): string
    {
        return '/'.preg_replace_callback('/\{([^}?]+)\??\}/', function ($m) use ($route) {
            $defaults = ['table' => 'profiles', 'source' => 'invoice', 'type' => 'requests', 'path' => 'audit-missing', 'file' => '00000000-0000-4000-8000-000000000001.png'];

            return ($route->wheres[$m[1]] ?? '') === '[0-9]+' ? '1' : ($defaults[$m[1]] ?? '00000000-0000-4000-8000-000000000001');
        }, $route->uri());
    }

    public function test_every_registered_route_method_has_a_recorded_negative_or_public_probe(): void
    {
        $evidence = [];
        $protected = 0;
        foreach (app('router')->getRoutes() as $route) {
            $uri = $route->uri();
            $api = in_array('api', $route->gatherMiddleware());
            $category = ! $api ? 'web' : (Str::is(self::PUBLIC, $uri) ? 'public' : (Str::is(self::OWN_AUTH, $uri) ? 'own-auth' : 'bearer'));
            foreach ($route->methods() as $method) {
                Cache::flush();
                $path = $this->path($route);
                config(['bir_eis.callback_enabled' => true]);
                $matched = app('router')->getRoutes()->match(Request::create($path, $method));
                // Record routes shadowed by the SPA catch-all instead of falsely claiming handler coverage.
                $shadowed = $matched->getActionName() !== $route->getActionName();
                $r = $this->json($method, $path, []);
                $status = $r->getStatusCode();
                if ($category === 'bearer') {
                    $this->assertSame(401, $status, "$method $uri");
                    $protected++;
                } else {
                    $this->assertLessThan(500, $status, "$method $uri unexpected server error");
                    if ($category === 'own-auth' && $uri !== 'api/support/conversations') {
                        $this->assertContains($status, [401, 403, 404, 415, 422], "$method $uri accepted an unauthenticated operation");
                    }
                    if ($api && ! in_array($method, ['GET', 'HEAD']) && $uri !== 'api/auth/logout') {
                        $this->assertGreaterThanOrEqual(400, $status, "$method $uri accepted an empty mutation");
                    }
                }
                $evidence[] = ['method' => $method, 'uri' => $uri, 'action' => $route->getActionName(), 'category' => $category, 'status' => $status, 'handler_shadowed' => $shadowed, 'test' => 'anonymous/empty request'];
            }
        }
        $dir = base_path('.local/vapt-remediation-2026-11-14');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents($dir.'/endpoint-checks.json', json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->assertGreaterThan(300, $protected);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('merchant_transactions', 0);
    }

    public function test_all_admin_namespace_methods_deny_nonprivileged_roles(): void
    {
        $count = 0;
        foreach (['merchant', 'client', 'consumer'] as $role) {
            $token = Str::random(64);
            $u = User::factory()->create(['api_token' => $token]);
            DB::table('profiles')->insert(['id' => $u->id, 'email' => $u->email, 'role' => $role]);
            foreach (app('router')->getRoutes() as $route) {
                if (! str_starts_with($route->uri(), 'api/admin/')) {
                    continue;
                }
                foreach ($route->methods() as $method) {
                    Cache::flush();
                    $r = $this->json($method, $this->path($route), [], ['Authorization' => 'Bearer '.$token]);
                    $this->assertSame(403, $r->getStatusCode(), $method.' '.$route->uri().' '.$r->getContent());
                    $count++;
                }
            }
        }
        $this->assertGreaterThan(200, $count);
    }

    public function test_all_privileged_mutations_and_exports_require_recent_mfa_before_handler_execution(): void
    {
        $token = Str::random(64);
        $u = User::factory()->create(['api_token' => $token]);
        DB::table('profiles')->insert(['id' => $u->id, 'email' => $u->email, 'role' => 'super_admin']);
        $count = 0;
        foreach (app('router')->getRoutes() as $route) {
            $uri = $route->uri();
            if (! in_array('api', $route->gatherMiddleware()) || Str::is([...self::PUBLIC, ...self::OWN_AUTH, 'api/auth/mfa/*', 'api/admin/infrastructure/step-up'], $uri)) {
                continue;
            }
            foreach ($route->methods() as $method) {
                $r = Request::create($this->path($route), $method);
                $r->setRouteResolver(fn () => $route);
                if (! app(MfaSession::class)->sensitive($r)) {
                    continue;
                }
                $u->forceFill(['api_authenticated_at' => now()->subMinutes(10), 'api_last_activity_at' => now(), 'api_expires_at' => now()->addMinutes(30), 'mfa_secret' => 'synthetic', 'api_mfa_verified_at' => now()->subMinutes(6), 'api_mfa_challenged_at' => null])->save();
                Cache::flush();
                $response = $this->json($method, $this->path($route), [], ['Authorization' => 'Bearer '.$token]);
                $this->assertSame(403, $response->getStatusCode(), $method.' '.$uri.' '.$response->getContent());
                $this->assertNotNull($u->fresh()->api_mfa_challenged_at,"$method $uri was not challenged");
                $count++;
            }
        }
        $this->assertGreaterThan(150,$count);
    }
}
