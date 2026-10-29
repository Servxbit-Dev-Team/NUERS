<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TenantBoundaryAuditTest extends TestCase
{
    private User $owner;

    private User $other;

    private string $merchant;

    private string $foreign;

    private string $receipt;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'notifications.dispatch_enabled' => false]);
        DB::purge('sqlite');
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->owner = $this->actor();
        $this->other = $this->actor();
        $this->merchant = (string) Str::uuid();
        $this->foreign = (string) Str::uuid();
        foreach ([[$this->merchant, $this->owner, '111222333', 'OWN'], [$this->foreign, $this->other, '999888777', 'FOREIGN_PRIVATE']] as [$id, $owner, $tin, $name]) {
            DB::table('merchants')->insert(['id' => $id, 'merchant_account_id' => $owner->id, 'business_name' => $name, 'tin' => $tin, 'status' => 'active']);
        }
        $this->receipt = (string) Str::uuid();
        DB::table('transaction_receipts')->insert(['id' => $this->receipt, 'merchant_id' => $this->foreign, 'receipt_number' => 'PRIVATE-0001', 'merchant_name' => 'FOREIGN_PRIVATE', 'merchant_tin' => '999888777', 'status' => 'issued', 'total_due' => 731.25, 'gross_amount' => 731.25]);
    }

    private function actor(string $role = 'merchant'): User
    {
        $u = User::factory()->create(['api_token' => Str::random(64)]);
        DB::table('profiles')->insert(['id' => $u->id, 'email' => $u->email, 'role' => $role]);

        return $u;
    }

    public function test_receipt_lookup_requires_authentication_and_never_exposes_another_merchants_record(): void
    {
        $this->getJson('/api/verify-receipt/PRIVATE-0001')->assertUnauthorized();
        $this->withToken($this->owner->api_token)->getJson('/api/verify-receipt/PRIVATE-0001')->assertNotFound();
        $this->getJson('/api/verify-receipt/PRIVATE-0001?merchant_id='.$this->foreign)->assertNotFound();
        $this->assertDatabaseCount('receipt_verifications', 0);
        $this->withToken($this->other->api_token)->getJson('/api/verify-receipt/PRIVATE-0001')->assertOk()->assertJsonPath('receipt.merchant_name', 'FOREIGN_PRIVATE')->assertJsonPath('receipt.signature_valid', null);
        $this->assertDatabaseCount('receipt_verifications', 1);
    }

    public function test_receipt_number_collision_is_resolved_inside_the_authorized_business(): void
    {
        DB::table('transaction_receipts')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $this->merchant, 'receipt_number' => 'PRIVATE-0001', 'merchant_name' => 'OWN', 'merchant_tin' => '111222333', 'status' => 'issued', 'total_due' => 12, 'gross_amount' => 12]);
        $this->withToken($this->owner->api_token)->getJson('/api/verify-receipt/PRIVATE-0001')->assertOk()->assertJsonPath('receipt.merchant_name', 'OWN');
    }

    public function test_tin_lookup_is_not_a_cross_tenant_directory(): void
    {
        $this->withToken($this->owner->api_token)->getJson('/api/business-accounts/lookup?tin=999888777')->assertNotFound();
        $this->getJson('/api/business-accounts/lookup?tin=111222333')->assertOk()->assertJsonPath('data.id', $this->merchant);
        $this->withToken($this->actor('client')->api_token)->getJson('/api/business-accounts/lookup?tin=999888777')->assertNotFound();
        $this->withToken($this->actor('super_admin')->api_token)->getJson('/api/business-accounts/lookup?tin=999888777')->assertOk()->assertJsonPath('data.id', $this->foreign);
    }

    public function test_foreign_document_downloads_exports_and_filters_are_denied_or_empty(): void
    {
        $this->withToken($this->owner->api_token);
        foreach (["/api/invoice-center/receipt/{$this->receipt}", "/api/document-copies/receipt/{$this->receipt}", "/api/document-copies/receipt/{$this->receipt}/pdf", "/api/businesses/{$this->foreign}/portability", "/api/invoice-center/export?merchant_id={$this->foreign}"] as $path) {
            $this->getJson($path)->assertNotFound();
        }
        $this->postJson("/api/document-copies/receipt/{$this->receipt}/email", ['email' => 'nobody@example.test', 'request_id' => (string) Str::uuid()])->assertNotFound();
        foreach (['eq' => json_encode(['merchant_id' => $this->foreign]), 'or' => 'merchant_id.eq.'.$this->foreign, 'in' => json_encode(['merchant_id' => [$this->foreign]])] as $filter => $value) {
            $this->getJson('/api/tables/transaction_receipts?'.http_build_query([$filter => $value, 'count' => 'exact']))->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('count', 0);
        }
        $csv = $this->get('/api/invoice-center/export')->assertOk()->streamedContent();
        $this->assertStringNotContainsString('FOREIGN_PRIVATE', $csv);
        $this->assertDatabaseCount('document_email_deliveries', 0);
    }

    public function test_every_administrative_route_rejects_a_merchant_before_dispatch(): void
    {
        $this->withToken($this->owner->api_token);
        $patterns = ['api/admin/*', 'api/business-registry*', 'api/live-monitoring*', 'api/esp-compliance*', 'api/operations-report*', 'api/operations/readiness', 'api/eis-readiness/coverage', 'api/eis-readiness/*/*/branch-review', 'api/transaction-workspace*', 'api/bir-eis/*', 'api/taxpayer-authorizations*', 'api/taxpayer-devices*', 'api/integration-workspace*', 'api/rdos', 'api/rdo/dashboard', 'api/transactions/overview'];
        $failures = [];
        $checked = 0;
        foreach (app('router')->getRoutes() as $route) {
            if (! Str::is($patterns, $route->uri())) {
                continue;
            }
            $path = preg_replace_callback('/\{([^}]+)\}/', fn ($m) => match ($m[1]) {
                'source' => 'receipt', 'type' => 'requests', default => str_contains($route->uri(), 'subscription-plans') ? '1' : $this->foreign,
            }, $route->uri());
            foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
                Cache::store('array')->flush();
                $response = $this->json($method, '/'.$path);
                $checked++;
                if ($response->status() !== 403) {
                    $failures[] = "$method $path: ".$response->status();
                }
            }
        }
        foreach (['/api/enterprise-dashboards/admin', '/api/dashboards/super-admin', '/api/dashboards/bir', '/api/tax-intelligence/bir', '/api/tax-intelligence/super-admin', '/api/%61dmin/sandbox-records'] as $path) {
            Cache::store('array')->flush();
            $response = $this->getJson($path);
            if ($response->status() !== 403) {
                $failures[] = 'GET '.$path.': '.$response->status();
            }
        }
        $this->assertGreaterThan(90, $checked);
        $this->assertSame([], $failures, implode("\n", $failures));
    }

    public function test_admin_cannot_escalate_or_change_a_super_admin_and_roles_cannot_change_self(): void
    {
        $admin = $this->actor('admin');
        $super = $this->actor('super_admin');
        $path = fn ($u) => '/api/tables/profiles?'.http_build_query(['eq' => json_encode(['id' => $u->id])]);
        $this->withToken($admin->api_token)->patchJson($path($admin), ['values' => ['role' => 'super_admin']])->assertForbidden();
        $this->patchJson($path($this->owner), ['values' => ['role' => 'super_admin']])->assertForbidden();
        $this->patchJson($path($super), ['values' => ['role' => 'client']])->assertForbidden();
        $this->patchJson($path($super), ['values' => ['full_name' => 'Replacement identity']])->assertForbidden();
        $this->withToken($super->api_token)->patchJson($path($super), ['values' => ['role' => 'merchant']])->assertForbidden();
        $this->assertDatabaseHas('profiles', ['id' => $super->id, 'role' => 'super_admin']);
        $this->assertDatabaseHas('profiles', ['id' => $admin->id, 'role' => 'admin']);
    }

    public function test_profile_updates_preserve_login_identity_and_registry_approval_workflow(): void
    {
        $super = $this->actor('super_admin');
        $path = fn ($u) => '/api/tables/profiles?'.http_build_query(['eq' => json_encode(['id' => $u->id])]);
        $this->withToken($super->api_token)->patchJson($path($this->owner), ['values' => ['full_name' => 'Unreviewed owner']])->assertForbidden();
        $client = $this->actor('client');
        $this->patchJson($path($client), ['values' => ['email' => 'replacement@example.test']])->assertForbidden();
        $this->patchJson($path($client), ['values' => ['full_name' => 'Aligned name']])->assertOk();
        $this->assertDatabaseHas('users', ['id' => $client->id, 'name' => 'Aligned name', 'email' => $client->email]);
        $this->postJson('/api/tables/profiles', ['values' => ['id' => '999999', 'role' => 'super_admin']])->assertConflict();
    }

    public function test_profile_pagination_search_and_count_preserve_the_authorized_scope(): void
    {
        $admin = $this->actor('super_admin');
        $rows = [];
        for ($i = 0; $i < 105; $i++) {
            $rows[] = ['id' => User::factory()->create()->id, 'email' => 'directory-'.$i.'@example.test', 'full_name' => 'Directory Fixture '.str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'role' => 'client'];
        }
        DB::table('profiles')->insert($rows);
        $this->withToken($admin->api_token)->getJson('/api/tables/profiles?search=Directory%20Fixture&count=exact&order=full_name&ascending=1&offset=100&limit=10')
            ->assertOk()->assertJsonPath('count', 105)->assertJsonCount(5, 'data')->assertJsonPath('data.0.full_name', 'Directory Fixture 100');
        $this->getJson('/api/tables/profiles?search=Fixture%20104&count=exact')->assertOk()->assertJsonPath('count', 1);
        $this->getJson('/api/tables/profiles?offset=-1')->assertUnprocessable();
        $this->withToken($this->owner->api_token)->getJson('/api/tables/profiles?search=Directory&count=exact&offset=0')->assertOk()->assertJsonPath('count', 0)->assertJsonCount(0, 'data');
    }

    public function test_integration_keys_cannot_read_another_merchant_event_or_select_another_tenant(): void
    {
        $keys = [];
        foreach ([$this->merchant, $this->foreign] as $merchant) {
            $keys[$merchant] = Str::random(64);
            DB::table('merchant_api_keys')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $merchant, 'name' => 'Synthetic isolation key', 'environment' => 'sandbox', 'branch_code' => '00000', 'branch_type' => 'main', 'key_hash' => hash('sha256', $keys[$merchant]), 'key_prefix' => 'synthetic', 'key_last_four' => 'test', 'status' => 'active', 'scopes' => '["transactions:write","transactions:read"]', 'ip_whitelist' => '[]', 'rate_limit_per_day' => 1000, 'expires_at' => now()->addDay()]);
        }
        $payload = ['schema_version' => 'NUERS-ingestion-1', 'source_system' => 'synthetic-pos', 'source_event_id' => 'ISOLATION-1', 'external_reference' => 'ISOLATION-1', 'event_type' => 'invoice.finalized', 'occurred_at' => now()->subMinute()->toIso8601String(), 'branch_code' => '00000', 'receipt_number' => 'ISOLATION-1', 'document_type' => 'non_vat_invoice', 'payment_method' => 'cash', 'gross_amount' => '112.00', 'tax_type' => 'non_vat', 'items' => [['description' => 'Synthetic private item', 'quantity' => 1, 'unit_price' => '112.00', 'tax_type' => 'non_vat']]];
        $event = $this->withHeader('X-NUERS-API-Key', $keys[$this->foreign])->postJson('/api/integrations/transactions', $payload)->assertCreated();
        $path = '/api/integrations/transactions/'.$event->json('id');
        $this->withHeader('X-NUERS-API-Key', $keys[$this->merchant])->getJson($path)->assertNotFound();
        $this->getJson($path.'?merchant_id='.$this->foreign)->assertNotFound();
        $this->postJson('/api/integrations/transactions', $payload + ['merchant_id' => $this->foreign])->assertUnprocessable();
        $this->assertDatabaseCount('integration_sandbox_events', 1);
        $this->assertDatabaseCount('merchant_transactions', 0);
        $this->assertDatabaseCount('merchant_wallet_entries', 0);
        $this->withHeader('X-NUERS-API-Key', $keys[$this->foreign])->getJson($path)->assertOk();
        DB::table('merchant_api_keys')->where('merchant_id', $this->foreign)->update(['status' => 'revoked']);
        $this->getJson($path)->assertForbidden();
    }
}
