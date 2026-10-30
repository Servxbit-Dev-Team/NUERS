<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MerchantWallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RegistryMerchantOverviewTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array', 'app.timezone' => 'Asia/Manila', 'notifications.dispatch_enabled' => false]);
        DB::purge('sqlite');
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->admin = $this->actor('super_admin');
        foreach (['alpha' => 'active', 'beta' => 'pending', 'empty' => 'inactive'] as $id => $status) {
            DB::table('merchants')->insert(['id' => $id, 'business_name' => ucfirst($id).' Synthetic Merchant', 'status' => $status,
                'tin' => ['alpha' => '111111111000','beta' => '222222222000','empty' => '333333333000'][$id],
                'created_at' => now(), 'updated_at' => now(), 'registration_date' => now()->toDateString()]);
        }
        DB::table('website_subscription_plans')->where('slug', 'transaction-pricing')->update(['fee_centavos' => 300, 'minimum_transaction_centavos' => 0]);
    }

    private function actor(string $role): User
    {
        $u = User::factory()->create(['api_token' => Str::random(64)]);
        DB::table('profiles')->insert(['id' => $u->id,'email' => $u->email,'role' => $role]);
        return $u;
    }

    private function seedFinances(): void
    {
        $wallet = app(MerchantWallet::class);
        DB::transaction(function () use ($wallet) {
            $wallet->credit('alpha', 100000, (string) Str::uuid(), 'Synthetic funding', (string) $this->admin->id);
            $wallet->credit('beta', 50000, (string) Str::uuid(), 'Synthetic funding', (string) $this->admin->id);
            $wallet->withdraw('alpha', 20000, (string) Str::uuid(), (string) $this->admin->id);
            $wallet->charge('alpha', 4999, 'sales_invoice', 'integration', 'sale-one', 'Synthetic charge', '00000', 'api', (string) $this->admin->id);
            $wallet->charge('alpha', 4999, 'sales_invoice', 'integration', 'sale-one', 'Synthetic charge', '00000', 'api', (string) $this->admin->id);
        });
        foreach ([['alpha',49.99,'completed'],['alpha',100.01,'failed'],['beta',900,'completed']] as $i => $row) {
            DB::table('merchant_transactions')->insert(['id' => 'sale-'.$i, 'merchant_id' => $row[0], 'amount' => $row[1], 'status' => $row[2], 'created_at' => now()]);
        }
        DB::table('merchant_wallet_topups')->insert(['id' => Str::uuid(),'merchant_id' => 'alpha','request_id' => Str::uuid(),
            'request_hash' => str_repeat('a', 64),'amount_centavos' => 70000,'payment_method' => 'bank_transfer',
            'payment_reference_encrypted' => 'synthetic','payment_reference_hash' => str_repeat('b', 64),'payment_date' => now()->toDateString(),
            'status' => 'pending','requested_by' => $this->admin->id,'created_at' => now()]);
        DB::table('integration_sandbox_events')->insert(['id' => Str::uuid(),'merchant_id' => 'alpha','api_key_id' => 'synthetic',
            'branch_code' => '00000','source_system' => 'synthetic','source_event_id' => 'sandbox-event','external_reference' => 'sandbox',
            'receipt_number' => 'sandbox','document_type' => 'sales_invoice','event_type' => 'invoice.finalized',
            'receipt_hash' => str_repeat('c', 64),'namespace_hash' => str_repeat('d', 64),'event_hash' => str_repeat('e', 64),
            'request_hash' => str_repeat('f', 64),'gross_centavos' => 9990000,'details_encrypted' => 'synthetic','occurred_at' => now(),'created_at' => now()]);
        foreach ([['live','active',now()->addDay()],['sandbox','active',now()->addDay()],['live','revoked',now()->addDay()],['live','active',now()->subDay()]] as $i => $key) {
            DB::table('merchant_api_keys')->insert(['id' => Str::uuid(),'merchant_id' => 'alpha','name' => 'Synthetic key',
                'environment' => $key[0],'status' => $key[1],'expires_at' => $key[2],'key_prefix' => 'test','key_last_four' => 'test',
                'key_hash' => hash('sha256', 'fixture-key-'.$i)]);
        }
    }

    public function test_actual_wallet_entries_and_live_transactions_are_separate_from_sandbox_and_pending_funding(): void
    {
        $this->seedFinances();
        $before = DB::table('merchant_wallet_entries')->get()->toJson();
        $r = $this->withToken($this->admin->api_token)->getJson('/api/business-registry?search=Alpha')->assertOk()
            ->assertJsonPath('financial_summary.available_centavos', 79700)
            ->assertJsonPath('financial_summary.transactions', 2)
            ->assertJsonPath('financial_summary.transaction_amount_centavos', 15000)
            ->assertJsonPath('financial_summary.charged_centavos', 300)
            ->assertJsonPath('financial_summary.deposits_centavos', 100000)
            ->assertJsonPath('financial_summary.pending_topups_centavos', 70000)
            ->assertJsonPath('data.0.overview.charged_transactions', 1)
            ->assertJsonPath('data.0.overview.failed_transactions', 1)
            ->assertJsonPath('data.0.overview.withdrawals_centavos', 20000)
            ->assertJsonPath('data.0.overview.live_keys', 1)
            ->assertJsonPath('data.0.overview.sandbox_keys', 1)
            ->assertJsonPath('data.0.overview.sandbox_events', 1);
        $this->assertSame($before, DB::table('merchant_wallet_entries')->get()->toJson());
        $this->assertStringNotContainsString('key_hash', $r->getContent());
        $this->assertStringNotContainsString('payment_reference', $r->getContent());
        $this->assertStringContainsString('+08:00', $r->json('data.0.overview.last_transaction_at'));
        $this->getJson('/api/business-registry/alpha')->assertOk()->assertJsonPath('business.overview.available_centavos', 79700);
    }

    public function test_summary_covers_all_filtered_merchants_not_just_the_page_and_empty_results_are_zero(): void
    {
        $this->seedFinances();
        $this->withToken($this->admin->api_token)->getJson('/api/business-registry?per_page=1&sort=name')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('total', 3)
            ->assertJsonPath('financial_summary.transactions', 3)->assertJsonPath('financial_summary.available_centavos', 129700);
        $this->getJson('/api/business-registry?status=pending')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.id', 'beta')->assertJsonPath('financial_summary.transactions', 1);
        $this->getJson('/api/business-registry?search=nothing-found')->assertOk()
            ->assertJsonCount(0, 'data')->assertJsonPath('financial_summary.transactions', 0)->assertJsonPath('financial_summary.available_centavos', 0);
        $this->getJson('/api/business-registry?search=empty')->assertOk()
            ->assertJsonPath('data.0.overview.wallet_status', 'not_funded')->assertJsonPath('data.0.overview.transactions', 0);
    }

    public function test_committed_changes_invalidate_aggregate_cache_and_pricing_does_not_rewrite_historical_charges(): void
    {
        $this->seedFinances();
        $this->withToken($this->admin->api_token)->getJson('/api/business-registry')->assertOk()->assertJsonPath('financial_summary.charged_centavos', 300);
        DB::table('website_subscription_plans')->where('slug', 'transaction-pricing')->update(['fee_centavos' => 700]);
        DB::transaction(fn () => app(MerchantWallet::class)->charge('alpha', 10000, 'sales_invoice', 'integration', 'next-sale', 'Synthetic charge', '00000', 'api', (string)$this->admin->id));
        $this->getJson('/api/business-registry')->assertOk()->assertJsonPath('financial_summary.charged_centavos', 1000)
            ->assertJsonPath('financial_summary.available_centavos', 129000);
    }

    public function test_financial_data_requires_administrator_access_on_list_details_and_csv(): void
    {
        $this->seedFinances();
        $this->getJson('/api/business-registry')->assertUnauthorized();
        foreach (['merchant','client'] as $role) {
            $u = $this->actor($role);
            $this->withToken($u->api_token)->getJson('/api/business-registry')->assertForbidden();
            $this->getJson('/api/business-registry/alpha')->assertForbidden();
            $this->getJson('/api/business-registry/export')->assertForbidden();
        }
        $bir = $this->actor('bir');
        $this->withToken($bir->api_token)->getJson('/api/business-registry')->assertOk()
            ->assertJsonPath('financial_summary', null)->assertJsonPath('can_view_financial', false)->assertJsonPath('data.0.overview', null);
        $this->getJson('/api/business-registry/alpha')->assertOk()->assertJsonMissingPath('business.overview');
        $csv = $this->get('/api/business-registry/export')->assertOk()->streamedContent();
        $this->assertStringNotContainsString('available_PHP', $csv);
    }

    public function test_sql_pagination_and_stage_filters_are_deterministic_and_queries_are_bounded(): void
    {
        DB::table('merchants')->where('id', 'beta')->update(['registry_details' => json_encode(['stage' => 'rejected','application_type' => 'migration'])]);
        $this->withToken($this->admin->api_token)->getJson('/api/business-registry?stage=rejected&application_type=migration')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.id', 'beta')->assertJsonPath('summary.rejected', 1);
        $this->getJson('/api/business-registry?stage=onboarded')->assertOk()->assertJsonPath('data.0.id', 'alpha');
        $this->getJson('/api/business-registry?per_page=1&page=99&sort=name')->assertOk()->assertJsonPath('page', 3)->assertJsonCount(1, 'data');
        for ($i = 0;$i < 30;$i++) {
            DB::table('merchants')->insert(['id' => 'extra-'.$i,'business_name' => 'Extra '.sprintf('%02d', $i),'created_at' => now()]);
        }
        DB::enableQueryLog();
        $this->getJson('/api/business-registry?per_page=25&sort=name')->assertOk()->assertJsonCount(25, 'data');
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();
        $this->assertCount(0, $queries->filter(fn ($q) => preg_match('/select \\* from [\"`]?eis_integrations/i', $q)));
        $this->assertTrue($queries->contains(fn ($q) => str_contains($q, 'limit 25')));
        $this->assertLessThan(85, $queries->count(), 'Registry query count must not grow with the number of rows.');
    }

    public function test_csv_contains_filtered_finances_with_php_units_and_excludes_unselected_merchants(): void
    {
        $this->seedFinances();
        $csv = $this->withToken($this->admin->api_token)->get('/api/business-registry/export?search=Alpha')->assertOk()->streamedContent();
        $lines = explode("\n", trim(ltrim($csv, "\xEF\xBB\xBF")));
        $data = array_combine(str_getcsv($lines[0]), str_getcsv($lines[1]));
        $this->assertSame('797.00', $data['available_PHP']);
        $this->assertSame('150.00',$data['transaction_amount_PHP']);
        $this->assertSame('3.00',$data['charged_PHP']);
        $this->assertSame('1',$data['sandbox_events']);
        $this->assertCount(2,$lines);
        $this->assertStringNotContainsString('Beta Synthetic',$csv);
    }
}
