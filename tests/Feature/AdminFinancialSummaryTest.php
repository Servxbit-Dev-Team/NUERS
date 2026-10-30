<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MerchantWallet;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminFinancialSummaryTest extends TestCase
{
    private User $admin;
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array', 'app.timezone' => 'Asia/Manila', 'notifications.dispatch_enabled' => false]);
        DB::purge('sqlite');
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->travelTo(Carbon::parse('2026-10-18 22:30:00', 'Asia/Manila'));
        $this->admin = $this->user('super_admin');
        DB::table('merchants')->insert(['id' => 'merchant', 'business_name' => 'Synthetic merchant', 'status' => 'active']);
    }
    private function user(string $role): User
    {
        $u = User::factory()->create(['api_token' => Str::random(64)]);
        DB::table('profiles')->insert(['id' => $u->id, 'email' => $u->email, 'role' => $role]);
        return $u;
    }
    private function transaction(string $id, float $amount, ?string $at = null): void
    {
        DB::table('merchant_transactions')->insert(['id' => $id, 'merchant_id' => 'merchant', 'amount' => $amount, 'status' => 'completed', 'created_at' => $at ?? now()]);
    }
    private function credit(int $amount, string $merchant = 'merchant'): void
    {
        DB::transaction(fn () => app(MerchantWallet::class)->credit($merchant, $amount, (string) Str::uuid(), 'Synthetic deposit', (string) $this->admin->id));
    }
    private function charge(string $id, int $gross = 50000): void
    {
        DB::transaction(fn () => app(MerchantWallet::class)->charge('merchant', $gross, 'sales_invoice', 'integration', $id, 'Synthetic fee', '00000', 'api', (string) $this->admin->id));
    }
    private function dashboard(): \Illuminate\Testing\TestResponse
    {
        return $this->withToken($this->admin->api_token)->getJson('/api/enterprise-dashboards/admin')->assertOk();
    }
    public function test_totals_use_actual_debits_and_credited_deposits_across_wallets_without_retry_double_counting(): void
    {
        $this->credit(100000);
        DB::table('merchants')->insert(['id' => 'second', 'business_name' => 'Second synthetic merchant']);
        $this->credit(50000, 'second');
        DB::transaction(fn () => app(MerchantWallet::class)->withdraw('merchant', 20000, (string) Str::uuid(), (string) $this->admin->id));
        foreach ([67200, 56000, 558.88, 11200, 558.88, 11200] as $i => $amount) {
            $this->transaction('sale-'.$i, $amount);
            if ($i > 0) {
                $this->charge('sale-'.$i);
                $this->charge('sale-'.$i);
            }
        }
        $before = DB::table('merchant_wallet_entries')->get()->toJson();
        $this->dashboard()->assertJsonPath('metrics.0.value', 6)->assertJsonPath('metrics.3.value', 2)
            ->assertJsonPath('financial_summary.transaction_amount_centavos', 14671776)
            ->assertJsonPath('financial_summary.nuers_charged_centavos', 1500)
            ->assertJsonPath('financial_summary.charged_transactions', 5)
            ->assertJsonPath('financial_summary.merchant_wallet_centavos', 128500)
            ->assertJsonPath('financial_summary.merchant_deposit_centavos', 150000);
        $this->assertSame($before, DB::table('merchant_wallet_entries')->get()->toJson());
        $this->assertTrue(app(MerchantWallet::class)->audit('merchant')['valid']);
        $this->withToken($this->admin->api_token)->getJson('/api/live-monitoring')->assertOk()
            ->assertJsonPath('financial_summary.nuers_charged_centavos', 1500)
            ->assertJsonPath('financial_summary.transaction_amount_centavos', 14671776)
            ->assertJsonMissingPath('financial_summary.merchant_wallet_centavos');
    }
    public function test_financial_totals_do_not_depend_on_recent_row_limit_filters_or_current_pricing(): void
    {
        $this->credit(10000);
        for ($i = 0; $i < 12; $i++) {
            $this->transaction('page-'.$i, 500.01);
        }
        DB::table('website_subscription_plans')->where('slug', 'transaction-pricing')->update(['fee_centavos' => 200]);
        $this->charge('old-rate');
        DB::table('website_subscription_plans')->where('slug', 'transaction-pricing')->update(['fee_centavos' => 300]);
        $this->charge('new-rate');
        $this->dashboard()->assertJsonCount(5, 'recent')->assertJsonPath('financial_summary.transaction_amount_centavos', 600012)->assertJsonPath('financial_summary.nuers_charged_centavos', 500);
        $this->withToken($this->admin->api_token)->getJson('/api/live-monitoring?search=no-match&status=failed&page=2')->assertOk()
            ->assertJsonCount(0, 'transactions')->assertJsonPath('financial_summary.transaction_amount_centavos', 600012)
            ->assertJsonPath('financial_summary.nuers_charged_centavos', 500);
    }
    public function test_monitor_uses_manila_midnight_and_excludes_future_records_and_fees(): void
    {
        $this->credit(10000);
        foreach (['old-fee', 'today-fee', 'future-fee'] as $id) {
            $this->charge($id);
        }
        foreach (['Asia/Manila', 'UTC'] as $zone) {
            config(['app.timezone' => $zone]);
            DB::table('merchant_transactions')->delete();
            $at = fn (string $date) => Carbon::parse($date, 'Asia/Manila')->setTimezone($zone)->format('Y-m-d H:i:s');
            foreach (['old-fee' => '2026-10-17 23:59:59', 'today-fee' => '2026-10-18 00:00:00', 'future-fee' => '2026-10-18 23:59:59'] as $id => $date) {
                DB::table('merchant_wallet_entries')->where('source_id', $id)->update(['created_at' => $at($date)]);
            }
            $this->transaction('old', 10.11, $at('2026-10-17 23:59:59'));
            $this->transaction('today', 20.22, $at('2026-10-18 00:00:00'));
            $this->transaction('future', 30.33, $at('2026-10-18 23:59:59'));
            $this->withToken($this->admin->api_token)->getJson('/api/live-monitoring')->assertOk()
                ->assertJsonPath('today.total', 1)->assertJsonPath('financial_summary.transaction_amount_centavos', 2022)
                ->assertJsonPath('financial_summary.period', 'today')->assertJsonPath('financial_summary.nuers_charged_centavos', 300);
        }
    }
    public function test_pending_deposits_and_sandbox_records_never_inflate_live_finances(): void
    {
        DB::table('merchant_wallet_topups')->insert(['id' => Str::uuid(), 'merchant_id' => 'merchant', 'request_id' => Str::uuid(), 'request_hash' => str_repeat('a', 64), 'amount_centavos' => 9990000, 'payment_method' => 'bank_transfer', 'payment_reference_encrypted' => 'synthetic', 'payment_reference_hash' => str_repeat('b', 64), 'payment_date' => '2026-10-18', 'status' => 'pending', 'requested_by' => $this->admin->id, 'created_at' => now()]);
        DB::table('integration_sandbox_events')->insert(['id' => Str::uuid(), 'merchant_id' => 'merchant', 'api_key_id' => 'synthetic', 'branch_code' => '00000', 'source_system' => 'test', 'source_event_id' => 'test', 'external_reference' => 'test', 'receipt_number' => 'test', 'document_type' => 'sales_invoice', 'event_type' => 'invoice.finalized', 'receipt_hash' => str_repeat('a', 64), 'namespace_hash' => str_repeat('b', 64), 'event_hash' => str_repeat('c', 64), 'request_hash' => str_repeat('d', 64), 'gross_centavos' => 9990000, 'details_encrypted' => 'synthetic', 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->dashboard()->assertJsonPath('financial_summary.transaction_amount_centavos', 0)->assertJsonPath('financial_summary.nuers_charged_centavos', 0)->assertJsonPath('financial_summary.merchant_wallet_centavos', 0)->assertJsonPath('financial_summary.merchant_deposit_centavos', 0);
        $this->withToken($this->admin->api_token)->getJson('/api/live-monitoring')->assertOk()->assertJsonPath('financial_summary.transaction_amount_centavos', 0);
    }
    public function test_financial_summary_is_not_exposed_to_merchants_citizens_or_bir_monitoring_users(): void
    {
        foreach (['merchant' => 'merchant', 'client' => 'citizen'] as $role => $portal) {
            $u = $this->user($role);
            $this->withToken($u->api_token)->getJson('/api/enterprise-dashboards/'.$portal)->assertOk()->assertJsonPath('financial_summary', null);
            $this->getJson('/api/enterprise-dashboards/admin')->assertForbidden();
            $this->getJson('/api/live-monitoring')->assertForbidden();
        }
        $bir = $this->user('bir');
        $this->withToken($bir->api_token)->getJson('/api/live-monitoring')->assertOk()->assertJsonPath('financial_summary', null);
    }
}
