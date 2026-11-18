<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ApiAccess;
use App\Services\ReportCsv;
use App\Services\SignedWebhook;
use App\Services\WebhookDestination;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\IsolatedSecurityDatabase;
use Tests\TestCase;

class SecurityBoundaryTest extends TestCase
{
    use IsolatedSecurityDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array', 'queue.default' => 'database', 'app.debug' => false, 'security.enforce_privileged_mfa' => true, 'notifications.dispatch_enabled' => false]);
        $this->securityDatabase();
        $this->securityMigrate();
        Http::preventStrayRequests();
        Mail::fake();
    }

    private function actor(string $role): array
    {
        $token = Str::random(64);
        $device = (string) Str::uuid();
        $u = User::create(['name' => 'Synthetic audit', 'email' => Str::uuid().'@example.test', 'password' => 'Synthetic-Only-Password7', 'api_token' => $token]);
        $u->forceFill(['api_device_hash' => hash('sha256', $device)])->save();
        DB::table('profiles')->insert(['id' => $u->id, 'email' => $u->email, 'role' => $role]);

        return [$u, ['Authorization' => 'Bearer '.$token, 'X-NUERS-Device' => $device]];
    }

    public function test_anonymous_cannot_read_sensitive_api_routes(): void
    {
        foreach (['/api/tables/profiles', '/api/tables/merchants', '/api/operations/readiness', '/api/transactions/overview'] as $path) {
            $this->getJson($path)->assertUnauthorized();
        }
    }

    public function test_device_mismatch_expiry_and_revocation_are_rejected(): void
    {
        [$u,$h] = $this->actor('client');
        $this->getJson('/api/tables/profiles', $h)->assertOk();
        $wrong = $h;
        $wrong['X-NUERS-Device'] = (string) Str::uuid();
        $this->getJson('/api/tables/profiles', $wrong)->assertUnauthorized();
        $u->forceFill(['api_expires_at' => now()->subSecond()])->save();
        $this->getJson('/api/tables/profiles', $h)->assertUnauthorized();
        $u->forceFill(['api_expires_at' => now()->addHour(), 'api_authenticated_at' => now()->subMinutes(config('security.session_absolute_minutes') + 1)])->save();
        $this->getJson('/api/tables/profiles', $h)->assertUnauthorized();
        $u->forceFill(['api_authenticated_at' => now()] + app(ApiAccess::class)->revocation())->save();
        $this->getJson('/api/tables/profiles', $h)->assertUnauthorized();
    }

    public function test_client_profile_scope_and_privilege_escalation(): void
    {
        [$a,$h] = $this->actor('client');
        [$b] = $this->actor('client');
        $r = $this->getJson('/api/tables/profiles', $h)->assertOk();
        $this->assertSame([$a->id], array_column($r->json('data'), 'id'));
        $this->getJson('/api/tables/profiles?eq='.urlencode(json_encode(['id' => $b->id])), $h)->assertJsonPath('data', []);
        $this->patchJson('/api/tables/profiles', ['values' => ['role' => 'super_admin']], $h)->assertForbidden();
        $this->getJson('/api/transactions/overview', $h)->assertForbidden();
        $this->assertDatabaseHas('profiles', ['id' => $a->id, 'role' => 'client']);
    }

    public function test_merchant_tenant_scope_and_readonly_generic_registry(): void
    {
        [$a,$h] = $this->actor('merchant');
        [$b] = $this->actor('merchant');
        foreach ([[$a, 'audit-a'], [$b, 'audit-b']] as [$u,$id]) {
            DB::table('merchants')->insert(['id' => $id, 'merchant_account_id' => $u->id, 'business_name' => $id, 'status' => 'active']);
        }
        $r = $this->getJson('/api/tables/merchants', $h)->assertOk();
        $this->assertSame(['audit-a'], array_column($r->json('data'), 'id'));
        $this->getJson('/api/tables/merchants?eq='.urlencode('{"id":"audit-b"}'), $h)->assertJsonPath('data', []);
        $this->patchJson('/api/tables/merchants', ['values' => ['status' => 'active']], $h)->assertStatus(409);
        $this->getJson('/api/operations/readiness', $h)->assertForbidden();
    }

    public function test_privileged_mfa_gate_and_own_profile_exception(): void
    {
        [$u,$h] = $this->actor('super_admin');
        $this->getJson('/api/tables/merchants', $h)->assertForbidden()->assertJsonPath('code', 'MFA_REQUIRED');
        $this->getJson('/api/tables/profiles?eq='.urlencode(json_encode(['id' => $u->id])), $h)->assertOk();
    }

    public function test_query_identifier_injection_and_unlisted_table_rejected(): void
    {
        [$u,$h] = $this->actor('client');
        $this->getJson('/api/tables/profiles?order='.urlencode('id desc; DROP TABLE users;--'), $h)->assertStatus(422);
        $this->getJson('/api/tables/users', $h)->assertNotFound();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_private_network_ssrf_and_dns_rebinding_are_rejected(): void
    {
        foreach (['127.0.0.1', '169.254.169.254', '10.0.0.1', '100.64.0.1', '::1', 'fc00::1', '2001:db8::1'] as $ip) {
            $d = new class($ip) extends WebhookDestination
            {
                public function __construct(private string $ip) {}

                public function dns(string $host): array
                {
                    return ['8.8.8.8', $this->ip];
                }
            };
            try {
                $d->resolve('https://callbacks.example.com/hook');
                $this->fail('Accepted '.$ip);
            } catch (HttpException $e) {
                $this->assertSame(422, $e->getStatusCode());
            }
        }
    }

    public function test_webhook_signature_binds_body_event_and_timestamp(): void
    {
        $t = (string) time();
        $sig = SignedWebhook::signature('synthetic-secret', 'e1', $t, '{"test":1}');
        $this->assertTrue(SignedWebhook::verify('synthetic-secret', 'e1', $t, '{"test":1}', $sig, (int) $t));
        $this->assertFalse(SignedWebhook::verify('synthetic-secret', 'e1', $t, '{"test":2}', $sig, (int) $t));
        $this->assertFalse(SignedWebhook::verify('synthetic-secret', 'e2', $t, '{"test":1}', $sig, (int) $t));
        $this->assertFalse(SignedWebhook::verify('synthetic-secret', 'e1', $t, '{"test":1}', $sig, (int) $t + 301));
    }

    public function test_csv_formula_prefixes_are_neutralized(): void
    {
        foreach (['=1+1', '+SUM(A1)', '@SUM(A1)', "\t=1+1", '-1+1'] as $v) {
            $this->assertSame("'".$v, ReportCsv::cell($v));
        }
        $this->assertSame('ordinary', ReportCsv::cell('ordinary'));
    }

    public function test_registration_otp_locks_after_five_errors_and_rejects_replay(): void
    {
        $id = (string) Str::uuid();
        DB::table('registration_email_verifications')->insert(['id' => $id, 'email' => 'synthetic@example.test', 'code_hash' => Hash::make('123456'), 'attempts' => 0, 'expires_at' => now()->addMinutes(10), 'development_delivery' => false, 'created_at' => now(), 'updated_at' => now()]);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/registration/verify-code', ['challenge_id' => $id, 'code' => '000000'])->assertStatus(422);
        }
        $this->postJson('/api/registration/verify-code', ['challenge_id' => $id, 'code' => '123456'])->assertStatus(422)->assertJsonPath('code', 'REGISTRATION_CODE_LOCKED');
        DB::table('registration_email_verifications')->where('id', $id)->update(['attempts' => 0]);
        $this->postJson('/api/registration/verify-code', ['challenge_id' => $id, 'code' => '123456'])->assertOk();
        $this->postJson('/api/registration/verify-code', ['challenge_id' => $id, 'code' => '123456'])->assertStatus(422)->assertJsonPath('code', 'REGISTRATION_CODE_USED');
    }

    public function test_old_privileged_session_is_rejected(): void
    {
        [$u,$h] = $this->actor('super_admin');
        $u->forceFill(['api_authenticated_at' => now()->subDays(6), 'api_expires_at' => now()->addHour(), 'mfa_secret' => 'synthetic-placeholder', 'api_mfa_verified_at' => now()->subDays(6)->addMinute()])->save();
        $this->getJson('/api/tables/merchants', $h)->assertUnauthorized();
    }

    public function test_integration_key_scope_revocation_and_tenant_boundaries(): void
    {
        [$u] = $this->actor('merchant');
        foreach (['audit-a', 'audit-b'] as $id) {
            DB::table('merchants')->insert(['id' => $id, 'merchant_account_id' => $u->id, 'business_name' => $id, 'status' => 'active']);
        }
        $key = Str::random(64);
        $kid = (string) Str::uuid();
        DB::table('merchant_api_keys')->insert(['id' => $kid, 'merchant_id' => 'audit-a', 'name' => 'synthetic', 'key_prefix' => 'test', 'key_hash' => hash('sha256', $key), 'key_last_four' => 'test', 'environment' => 'live', 'status' => 'active', 'scopes' => '["transactions:read"]', 'branch_code' => '00000', 'expires_at' => now()->addDay()]);
        $id = (string) Str::uuid();
        DB::table('integration_inbox')->insert(['id' => $id, 'merchant_id' => 'audit-b', 'api_key_id' => $kid, 'namespace_hash' => str_repeat('a', 64), 'event_hash' => str_repeat('b', 64), 'request_hash' => str_repeat('c', 64), 'source_system' => 'synthetic', 'source_event_id' => 'audit', 'external_reference' => 'audit', 'branch_code' => '00000', 'schema_version' => 'NUERS-ingestion-1', 'event_type' => 'invoice.finalized', 'occurred_at' => now(), 'received_at' => now(), 'response' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $h = ['X-NUERS-API-Key' => $key];
        $path = '/api/integrations/transactions/'.$id;
        $this->assertDatabaseHas('integration_inbox', ['id' => $id, 'merchant_id' => 'audit-b']);
        $this->getJson($path, $h)->assertNotFound();
        DB::table('merchant_api_keys')->where('id', $kid)->update(['scopes' => '["transactions:write"]']);
        $this->getJson($path, $h)->assertForbidden();
        DB::table('merchant_api_keys')->where('id', $kid)->update(['scopes' => '["transactions:read"]', 'expires_at' => now()->subSecond()]);
        $this->getJson($path,$h)->assertForbidden();
        DB::table('merchant_api_keys')->where('id',$kid)->update(['expires_at' => now()->addDay(), 'status' => 'revoked']);
        $this->getJson($path,$h)->assertForbidden();
    }
}
