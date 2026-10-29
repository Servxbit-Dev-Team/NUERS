<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DeviceSession;
use App\Services\Totp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SecurityRemediationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite');
        foreach (glob(database_path('migrations/*.php')) as $f) {
            (require $f)->up();
        }
    }

    private function actor(string $role = 'super_admin'): User
    {
        $u = User::factory()->create(['api_token' => str_repeat('a', 32).random_int(1, 999999), 'password' => 'SecurePassword123']);
        DB::table('profiles')->insert(['id' => $u->id, 'role' => $role, 'email' => $u->email]);

        return $u;
    }

    public function test_all_legacy_financial_and_provisioning_routes_reject_anonymous_calls_before_writes(): void
    {
        $user = $this->actor();
        $before = DB::table('users')->where('id', $user->id)->first();
        foreach (['/api/transactions/overview', '/api/dashboards/super-admin', '/api/dashboards/bir', '/api/tax-intelligence/bir', '/api/tax-intelligence/super-admin', '/api/rdo/dashboard', '/api/merchant/expenses'] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
        $this->postJson('/api/rdos', ['email' => $user->email, 'password' => 'attacker-password'])->assertUnauthorized();
        $this->postJson('/api/merchant/expenses', [])->assertUnauthorized();
        $this->assertEquals($before, DB::table('users')->where('id', $user->id)->first());
        $this->assertDatabaseCount('merchant_expenses', 0);
    }

    public function test_unverified_name_tin_and_contact_cannot_create_ownership(): void
    {
        $u = $this->actor('client');
        DB::table('merchants')->insert(['id' => 'victim', 'business_name' => 'Victim Corp', 'tin' => '123456789', 'email' => $u->email]);
        DB::table('profiles')->where('id', $u->id)->update(['organization' => 'Victim Corp', 'full_name' => 'Victim Corp', 'tin' => '123456789', 'tin_bound_at' => now()]);
        $this->withToken($u->api_token)->getJson('/api/business-accounts/current')->assertNotFound();
        $this->getJson('/api/enterprise-dashboards/citizen')->assertOk()->assertJsonPath('metrics.0.value', 0);
        $this->postJson('/api/auth/signup', ['email' => 'new@example.test', 'password' => 'SecurePassword123', 'meta' => ['role' => 'client', 'tin' => '123456789']])->assertUnprocessable();
    }

    public function test_token_is_hashed_expiring_device_bound_and_mfa_is_required(): void
    {
        $u = $this->actor();
        $token = $u->api_token;
        $this->assertNull(DB::table('users')->where('id', $u->id)->value('api_token'));
        $this->assertSame(hash('sha256', $token), DB::table('users')->where('id', $u->id)->value('api_token_hash'));
        config(['security.enforce_privileged_mfa' => true]);
        $this->withToken($token)->getJson('/api/transaction-workspace')->assertForbidden()->assertJsonPath('code', 'MFA_REQUIRED');
        $secret = $this->postJson('/api/auth/mfa/enroll', ['password' => 'SecurePassword123'])->assertOk()->json('secret');
        $code = app(Totp::class)->code($secret, intdiv(now()->timestamp, 30));
        $codes = $this->postJson('/api/auth/mfa/confirm', ['code' => $code])->assertOk()->json('recovery_codes');
        $this->getJson('/api/transaction-workspace')->assertOk();
        $this->postJson('/api/auth/mfa/verify', ['code' => $code])->assertUnprocessable();
        $this->postJson('/api/auth/mfa/verify', ['code' => $codes[0]])->assertOk();
        $this->postJson('/api/auth/mfa/verify', ['code' => $codes[0]])->assertUnprocessable();
        DB::table('users')->where('id', $u->id)->update(['api_expires_at' => now()->subMinute()]);
        $this->getJson('/api/transaction-workspace')->assertUnauthorized();
    }

    public function test_rfc_totp_vectors_and_generic_identifiers(): void
    {
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $this->assertSame('94287082', app(Totp::class)->code($secret, 1, 8));
        $this->assertSame('07081804', app(Totp::class)->code($secret, intdiv(1111111109, 30), 8));
        $u = $this->actor();
        $this->withToken($u->api_token)->getJson('/api/tables/profiles?order=invalid_column')->assertUnprocessable();
        $this->getJson('/api/tables/profiles?limit=1000000')->assertUnprocessable();
        $this->postJson('/api/rdos', ['email' => $u->email, 'password' => 'replace-existing'])->assertUnprocessable();
    }

    public function test_all_static_protected_api_aliases_require_authentication_before_dispatch(): void
    {
        $public = ['api/health', 'api/legal/policies', 'api/registration/*', 'api/auth/login', 'api/auth/signup', 'api/auth/forgot-password', 'api/auth/reset-password', 'api/auth/verify-email', 'api/auth/session', 'api/auth/logout', 'api/pricing', 'api/subscription-plans', 'api/developer/*', 'api/locations/*', 'api/team-invitations/preview', 'api/team-invitations/accept', 'api/business-activation/inspect', 'api/business-activation', 'api/support/conversations', 'api/integrations/transactions'];
        $checked = 0;
        foreach (app('router')->getRoutes() as $route) {
            $path = $route->uri();
            if (! in_array('api', $route->gatherMiddleware(), true) || ! str_starts_with($path, 'api/') || str_contains($path, '{') || Str::is($public, $path)) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
                // Each alias is an independent authentication probe, not a cumulative traffic test.
                \Illuminate\Support\Facades\Cache::store('array')->flush();
                $this->withoutToken()->json($method, '/'.$path, [])->assertUnauthorized();
                $checked++;
            }
        }
        $this->assertGreaterThan(50, $checked, 'Protect all registered static aliases, including newly added operations routes.');
    }

    public function test_hashed_sessions_reject_another_device_and_revoke_the_previous_login(): void
    {
        $u = $this->actor();
        $device = fake()->uuid();
        $other = fake()->uuid();
        $request = Request::create('/api/auth/login', 'POST', server: ['HTTP_X_NUERS_DEVICE' => $device]);
        $first = app(DeviceSession::class)->issue($u, $request);
        $token = $first->api_token;
        $this->withToken($token)->withHeaders(['X-NUERS-Device' => $device])->getJson('/api/auth/session')->assertOk()->assertJsonPath('user.id', (string) $u->id);
        $this->withHeaders(['X-NUERS-Device' => $other])->getJson('/api/transaction-workspace')->assertUnauthorized();
        $request = Request::create('/api/auth/login', 'POST', server: ['HTTP_X_NUERS_DEVICE' => $other]);
        $second = app(DeviceSession::class)->issue($u, $request);
        $this->assertNotSame($token, $second->api_token);
        $this->withToken($token)->withHeaders(['X-NUERS-Device' => $device])->getJson('/api/transaction-workspace')->assertUnauthorized();
        $this->withToken($second->api_token)->withHeaders(['X-NUERS-Device' => $other])->getJson('/api/transaction-workspace')->assertOk();
    }

    public function test_an_enrolled_nonprivileged_user_also_requires_mfa(): void
    {
        $u = $this->actor('client');
        $secret = 'JBSWY3DPEHPK3PXP';
        DB::table('users')->where('id', $u->id)->update(['mfa_secret' => Crypt::encryptString($secret), 'api_mfa_verified_at' => null]);
        config(['security.enforce_privileged_mfa' => true]);
        $this->withToken($u->api_token)->getJson('/api/client/profile')->assertForbidden()->assertJsonPath('code', 'MFA_REQUIRED');
        $this->postJson('/api/auth/mfa/verify', ['code' => app(Totp::class)->code($secret, intdiv(now()->timestamp, 30))])->assertOk();
        $this->getJson('/api/client/profile')->assertOk();
    }

    public function test_percent_encoded_administrative_aliases_cannot_bypass_role_authorization(): void
    {
        $u = $this->actor('client');
        foreach (['/api/%74ransactions/overview', '/api/tax-intelligence/%62ir', '/api/dashboards/%73uper-admin'] as $uri) {
            $this->withToken($u->api_token)->getJson($uri)->assertForbidden();
        }
    }

    public function test_role_changes_revoke_the_previous_session_in_the_same_transaction(): void
    {
        $admin = $this->actor();
        $target = $this->actor('client');
        $old = $target->api_token;
        $this->withToken($admin->api_token)->patchJson('/api/tables/profiles?'.http_build_query(['eq' => json_encode(['id' => $target->id])]), ['values' => ['role' => 'merchant']])->assertOk();
        $this->assertNull(DB::table('users')->where('id', $target->id)->value('api_token_hash'));
        $this->withToken($old)->getJson('/api/client/profile')->assertUnauthorized();
    }
}
