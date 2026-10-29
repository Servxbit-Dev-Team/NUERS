<?php

namespace Tests\Feature;

use App\Http\Middleware\ScopedThrottleRequests;
use App\Models\User;
use App\Services\Totp;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class FormRateLimitTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array', 'notifications.dispatch_enabled' => false]);
        DB::purge('sqlite');
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->admin = $this->actor();
    }

    private function actor(): User
    {
        $u = User::factory()->create(['api_token' => Str::random(64), 'mfa_secret' => Crypt::encryptString(app(Totp::class)->secret())]);
        DB::table('profiles')->insert(['id' => $u->id, 'email' => $u->email, 'role' => 'super_admin']);

        return $u;
    }

    public function test_login_and_other_forms_cannot_consume_mfa_attempts(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/auth/login', [])->assertUnprocessable();
        }
        $this->withToken($this->admin->api_token);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/mfa/enroll', [])->assertUnprocessable();
        }
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/mfa/confirm', [])->assertUnprocessable();
        }
        $secret = Crypt::decryptString($this->admin->mfa_secret);
        $code = app(Totp::class)->code($secret, (int) floor(now()->timestamp / 30));
        $this->postJson('/api/auth/mfa/verify', ['code' => $code])->assertOk()->assertJsonPath('verified', true);
    }

    public function test_mfa_limit_still_blocks_repeated_attempts_with_retry_information_and_recovers(): void
    {
        $this->withToken($this->admin->api_token);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/mfa/verify', [])->assertUnprocessable();
        }
        $r = $this->postJson('/api/auth/mfa/verify', [])->assertTooManyRequests()->assertJsonPath('code', 'ACTION_RATE_LIMITED')->assertHeader('Retry-After');
        $this->assertGreaterThan(0, $r->json('retry_after'));
        $this->assertLessThanOrEqual(60, $r->json('retry_after'));
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        $this->travel(61)->seconds();
        $this->postJson('/api/auth/mfa/verify', [])->assertUnprocessable();
    }

    public function test_accounts_on_same_network_are_isolated_but_changing_ip_does_not_reset_an_accounts_budget(): void
    {
        $this->withToken($this->admin->api_token);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/mfa/verify', [])->assertUnprocessable();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])->postJson('/api/auth/mfa/verify', [])->assertTooManyRequests();
        $other = $this->actor();
        $this->withToken($other->api_token)->postJson('/api/auth/mfa/verify', [])->assertUnprocessable();
    }

    public function test_arbitrary_invalid_tokens_do_not_create_new_attempt_buckets(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->withToken(Str::random(64))->postJson('/api/auth/mfa/verify', [])->assertUnauthorized();
        }
        $this->withToken(Str::random(64))->postJson('/api/auth/mfa/verify', [])->assertUnauthorized();
        $this->withToken($this->admin->api_token)->postJson('/api/auth/mfa/verify', [])->assertUnprocessable();
    }

    public function test_route_parameters_and_queries_cannot_bypass_the_same_action_limit(): void
    {
        Route::middleware(['api', 'throttle:2,1'])->post('/api/auth/mfa/synthetic/{id}', fn () => response()->json(['ok' => true]));
        $this->withToken($this->admin->api_token);
        $this->postJson('/api/auth/mfa/synthetic/one?sort=a')->assertOk();
        $this->postJson('/api/auth/mfa/synthetic/two?sort=b')->assertOk();
        $this->postJson('/api/auth/mfa/synthetic/three?sort=c')->assertTooManyRequests();
    }

    public function test_public_credential_forms_keep_ip_limits_even_when_valid_tokens_change(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->withToken($i % 2 ? $this->admin->api_token : 'invalid-bearer')->postJson('/api/auth/login', [])->assertUnprocessable();
        }
        $other = $this->actor();
        $this->withToken($other->api_token)->postJson('/api/auth/login', [])->assertTooManyRequests();
    }

    public function test_global_network_guard_and_all_numeric_route_limits_remain_installed(): void
    {
        $this->getJson('/api/health')->assertOk();
        $this->assertSame(ScopedThrottleRequests::class, app('router')->getMiddleware()['throttle']);
        RateLimiter::increment('api-ip:'.hash('sha256', '127.0.0.1'), 60, 300);
        $this->getJson('/api/health')->assertTooManyRequests()->assertJsonPath('code', 'NETWORK_RATE_LIMITED')->assertHeader('Retry-After');
    }
}
