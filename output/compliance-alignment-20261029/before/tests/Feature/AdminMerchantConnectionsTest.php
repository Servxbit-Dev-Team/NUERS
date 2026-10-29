<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminMerchantConnectionsTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array', 'notifications.dispatch_enabled' => false]);
        DB::purge('sqlite');
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->admin = $this->user('super_admin');
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['api_token' => Str::random(64)]);
        DB::table('profiles')->insert(['id' => $user->id, 'email' => $user->email, 'role' => $role]);
        return $user;
    }

    private function merchant(string $name = 'Connection QA', string $status = 'active'): string
    {
        $id = (string) Str::uuid();
        DB::table('merchants')->insert(['id' => $id, 'business_name' => $name, 'tin' => '404758136000', 'status' => $status]);
        return $id;
    }

    private function key(string $merchant, string $env = 'live', array $overrides = []): string
    {
        $id = (string) Str::uuid();
        DB::table('merchant_api_keys')->insert(array_merge(['id' => $id, 'merchant_id' => $merchant, 'name' => 'Synthetic only', 'environment' => $env, 'key_prefix' => 'PRIVATE-PREFIX', 'key_hash' => hash('sha256', $id), 'key_last_four' => 'HIDE', 'status' => 'active', 'expires_at' => now()->addDay(), 'scopes' => '["transactions:write"]', 'ip_whitelist' => '[]'], $overrides));
        return $id;
    }

    private function log(string $merchant, string $key, int $status, array $extra = []): void
    {
        DB::table('merchant_api_request_logs')->insert(array_merge(['id' => (string) Str::uuid(), 'merchant_id' => $merchant, 'merchant_api_key_id' => $key, 'endpoint' => '/api/integrations/transactions', 'method' => 'POST', 'status_code' => $status, 'created_at' => now(), 'ip_address' => '192.0.2.1', 'error_message' => 'Private failure detail'], $extra));
    }

    private function getOverview(string $query = '')
    {
        return $this->withToken($this->admin->api_token)->getJson('/api/admin/merchant-connections'.$query);
    }

    public function test_aggregates_keys_once_per_merchant_environment_and_requires_success_evidence(): void
    {
        $m = $this->merchant();
        $live = $this->key($m); $this->key($m); $sandbox = $this->key($m, 'sandbox');
        $this->log($m, $live, 422);
        $this->log($m, $sandbox, 201);
        $r = $this->getOverview()->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('summary.merchants', 1)->assertJsonPath('summary.connections', 2)
            ->assertJsonPath('summary.connected_merchants', 1)->assertJsonPath('summary.active_keys', 3);
        $rows = collect($r->json('data'))->keyBy('environment');
        $this->assertSame('connected', $rows['sandbox']['connection_status']);
        $this->assertSame('awaiting_connection', $rows['live']['connection_status']);
        $this->assertSame(2, $rows['live']['active_keys']);
        $this->assertNull($rows['live']['last_success_at']);
        $this->getOverview('?environment=live')->assertJsonPath('summary.connected_merchants', 0)->assertJsonCount(1, 'data');
    }

    public function test_expired_revoked_and_inactive_accounts_are_not_connected(): void
    {
        foreach (['expired', 'revoked', 'no_expiry', 'inactive'] as $type) {
            $m = $this->merchant($type, $type === 'inactive' ? 'suspended' : 'active');
            $k = $this->key($m, 'live', match ($type) { 'expired' => ['expires_at' => now()->subSecond()], 'revoked' => ['status' => 'revoked'], 'no_expiry' => ['expires_at' => null], default => [] });
            $this->log($m, $k, 201);
        }
        $r = $this->getOverview()->assertJsonPath('summary.connected_merchants', 0);
        foreach ($r->json('data') as $row) $this->assertSame($row['business_name'] === 'inactive' ? 'account_inactive' : 'no_active_keys', $row['connection_status']);
    }

    public function test_ignores_foreign_merchant_logs_and_non_intake_requests(): void
    {
        $m = $this->merchant(); $other = $this->merchant('Other'); $k = $this->key($m);
        $this->log($other, $k, 201);
        $this->log($m, $k, 200, ['method' => 'GET']);
        $this->log($m, $k, 200, ['endpoint' => '/unrelated']);
        $this->getOverview()->assertJsonPath('data.0.requests', 0)->assertJsonPath('data.0.connection_status', 'awaiting_connection');
    }

    public function test_filtered_pagination_and_formatted_tin_search(): void
    {
        foreach (['Alpha', 'Beta', 'Gamma'] as $name) $this->key($this->merchant($name));
        $this->getOverview('?search=404-758-136-000&per_page=1&page=2')->assertOk()
            ->assertJsonPath('pagination.total', 3)->assertJsonPath('pagination.page', 2)->assertJsonPath('data.0.business_name', 'Beta');
        $this->getOverview('?search=Gamma&page=99')->assertJsonPath('summary.merchants', 1)->assertJsonPath('pagination.page', 1)->assertJsonPath('data.0.business_name', 'Gamma');
        $this->getOverview('?search=NoMatch')->assertJsonCount(0, 'data')->assertJsonPath('pagination.total', 0);
        $this->getOverview('?environment=production')->assertUnprocessable();
        $this->getOverview('?per_page=999')->assertUnprocessable();
    }

    public function test_does_not_expose_credentials_private_logs_or_mutate_records(): void
    {
        $m = $this->merchant(); $key = $this->key($m); $this->log($m, $key, 201);
        $before = DB::table('merchant_api_keys')->get()->toJson();
        $r = $this->getOverview()->assertOk();
        foreach (['key_hash', 'key_prefix', 'key_last_four', 'ip_address', 'error_message', 'scopes', 'PRIVATE-PREFIX', '192.0.2.1'] as $secret) $this->assertStringNotContainsString($secret, $r->getContent());
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        $this->assertSame($before, DB::table('merchant_api_keys')->get()->toJson());
        $this->assertDatabaseCount('merchant_api_request_logs', 1);
    }

    public function test_admin_only_and_existing_mfa_boundary_is_preserved(): void
    {
        $this->getJson('/api/admin/merchant-connections')->assertUnauthorized();
        foreach (['merchant', 'client', 'bir', 'rdo'] as $role) $this->withToken($this->user($role)->api_token)->getJson('/api/admin/merchant-connections')->assertForbidden();
        $this->withToken($this->user('admin')->api_token)->getJson('/api/admin/merchant-connections')->assertOk();
        config(['security.enforce_privileged_mfa' => true]);
        $this->getOverview()->assertForbidden()->assertJsonPath('code', 'MFA_REQUIRED');
    }

    public function test_database_activity_dates_include_the_application_timezone(): void
    {
        config(['app.timezone' => 'Asia/Manila']);
        $m = $this->merchant(); $key = $this->key($m);
        $this->log($m, $key, 201, ['created_at' => '2026-10-18 22:24:00']);
        $this->getOverview()->assertOk()->assertJsonPath('data.0.last_success_at', '2026-10-18T22:24:00+08:00')
            ->assertJsonPath('data.0.last_request_at', '2026-10-18T22:24:00+08:00');
    }
}
