<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BirCompliance;
use App\Services\MerchantWallet;
use App\Services\TenantPortability;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminBusinessWalletTest extends TestCase
{
    private User $admin;

    private User $owner;

    private string $merchant;

    private string $foreign;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array', 'notifications.dispatch_enabled' => false]);
        DB::purge('sqlite');
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->admin = $this->user('super_admin');
        $this->owner = $this->user('merchant');
        $this->merchant = (string) Str::uuid();
        $this->foreign = (string) Str::uuid();
        foreach ([$this->merchant, $this->foreign] as $id) {
            DB::table('merchants')->insert(['id' => $id, 'business_name' => 'Synthetic Wallet Test', 'merchant_account_id' => $id === $this->merchant ? $this->owner->id : $this->user('merchant')->id, 'status' => 'active']);
        }
        $this->withToken($this->admin->api_token);
    }

    private function user(string $role): User
    {
        $u = User::factory()->create(['api_token' => Str::random(64)]);
        DB::table('profiles')->insert(['id' => $u->id, 'email' => $u->email, 'role' => $role]);

        return $u;
    }

    private function payload(string $kind = 'topup', int $amount = 10000): array
    {
        $w = app(MerchantWallet::class)->state($this->merchant);

        return ['request_id' => (string) Str::uuid(), 'kind' => $kind, 'amount_centavos' => $amount, 'payment_method' => 'bank_transfer', 'settlement_reference' => 'SYNTHETIC-'.Str::uuid(), 'settled_on' => today()->toDateString(), 'reason' => 'Synthetic settled payment evidence only', 'settlement_confirmed' => true, 'expected_sequence' => (int) $w->sequence, 'expected_balance_centavos' => (int) $w->available_centavos];
    }

    private function adjust(array $v, ?string $merchant = null)
    {
        return $this->postJson('/api/admin/wallets/'.($merchant ?? $this->merchant).'/adjustments', $v);
    }

    public function test_wallet_view_has_no_financial_side_effects_and_only_administrators_can_view(): void
    {
        $this->getJson('/api/admin/wallets/'.$this->merchant)->assertOk()->assertJsonPath('wallet.available_centavos', 0)->assertJsonPath('entries.total', 0)->assertJsonPath('can_adjust', true);
        $this->assertDatabaseCount('merchant_wallets', 0);
        $reader = $this->user('admin');
        $this->withToken($reader->api_token)->getJson('/api/admin/wallets/'.$this->merchant)->assertOk()->assertJsonPath('can_adjust', false);
        $this->adjust($this->payload())->assertForbidden();
        $this->withToken($this->owner->api_token)->getJson('/api/admin/wallets/'.$this->merchant)->assertForbidden();
        $this->adjust($this->payload())->assertForbidden();
        $this->getJson('/api/admin/wallets/'.$this->foreign.'/statement')->assertForbidden();
        $this->assertDatabaseCount('merchant_wallet_entries', 0);
    }

    public function test_manual_topup_is_atomic_recorded_encrypted_and_retried_only_once(): void
    {
        $v = $this->payload();
        $r = $this->adjust($v)->assertCreated()->assertJsonPath('duplicate', false)->assertJsonPath('entry.balance_before', 0)->assertJsonPath('entry.balance_after', 10000)->assertJsonPath('entry.actor_id', (string) $this->admin->id);
        $this->adjust($v)->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('entry.id', $r->json('entry.id'));
        $this->assertDatabaseCount('merchant_wallet_adjustments', 1);
        $this->assertDatabaseCount('merchant_wallet_entries', 1);
        $row = DB::table('merchant_wallet_adjustments')->first();
        $this->assertNotSame($v['reason'], $row->reason_encrypted);
        $this->assertSame($v['reason'], Crypt::decryptString($row->reason_encrypted));
        $this->assertTrue(app(MerchantWallet::class)->audit($this->merchant)['valid']);
        $this->assertSame(1, DB::table('eis_audit_events')->where('event', 'wallet.adjustment.posted')->count());
        $this->assertSame(1, DB::table('notification_outbox')->where('event', 'wallet.adjustment.posted')->where('user_id', $this->owner->id)->count());
    }

    public function test_withdrawal_to_zero_keeps_merchant_chart_and_history_correct(): void
    {
        $this->adjust($this->payload())->assertCreated();
        $v = $this->payload('withdrawal');
        $r = $this->adjust($v)->assertCreated()->assertJsonPath('entry.amount_centavos', -10000)->assertJsonPath('entry.balance_after', 0);
        $this->adjust($v)->assertOk()->assertJsonPath('entry.id', $r->json('entry.id'));
        $this->getJson('/api/admin/wallets/'.$this->merchant.'?kind=withdrawal')->assertOk()->assertJsonPath('entries.total', 1)->assertJsonPath('entries.data.0.settlement_reference', $v['settlement_reference'])->assertJsonPath('summary.withdrawals_centavos', 10000);
        $snapshot = $this->withToken($this->owner->api_token)->getJson('/api/merchant/wallet')->assertOk()->assertJsonPath('wallet.available_centavos', 0);
        $chart = collect($snapshot->json('chart'))->last();
        $this->assertSame(0, $chart['balance_centavos']);
        $this->assertSame(10000, $chart['withdrawals_centavos']);
        $this->getJson('/api/merchant/wallet?kind=withdrawal')->assertOk()->assertJsonPath('entries.total', 1);
        $this->assertTrue(app(MerchantWallet::class)->audit($this->merchant)['valid']);
        $this->assertCount(2, app(TenantPortability::class)->queries($this->merchant)['merchant_wallet_adjustments']->get());
        $this->assertCount(0, app(TenantPortability::class)->queries($this->foreign)['merchant_wallet_adjustments']->get());
    }

    public function test_overdraft_stale_balance_and_invalid_details_never_write(): void
    {
        $this->adjust($this->payload('withdrawal', 100))->assertConflict();
        $this->assertDatabaseCount('merchant_wallets', 0);
        $stale = $this->payload();
        $this->adjust($this->payload())->assertCreated();
        $this->adjust($stale)->assertConflict();
        $this->adjust($this->payload('withdrawal', 10001))->assertConflict();
        foreach ([['amount_centavos' => 0], ['amount_centavos' => 1.23], ['amount_centavos' => -100], ['settlement_confirmed' => false], ['reason' => '          '], ['settled_on' => today()->addDay()->toDateString()]] as $bad) {
            $this->adjust([...$this->payload(), ...$bad])->assertUnprocessable();
        }
        $this->assertDatabaseCount('merchant_wallet_entries', 1);
        $this->assertDatabaseCount('merchant_wallet_adjustments', 1);
    }

    public function test_request_and_settlement_duplicates_across_businesses_cannot_credit_twice(): void
    {
        $v = $this->payload();
        $this->adjust($v)->assertCreated();
        $this->adjust([...$v, 'amount_centavos' => 5000])->assertConflict();
        $this->adjust($v, $this->foreign)->assertConflict();
        $another = [...$this->payload(), 'settlement_reference' => $v['settlement_reference']];
        $this->adjust($another)->assertConflict();
        $this->adjust([...$another, 'expected_sequence' => 0, 'expected_balance_centavos' => 0], $this->foreign)->assertConflict();
        $this->assertDatabaseCount('merchant_wallet_entries', 1);
        $this->assertSame(10000, (int) DB::table('merchant_wallets')->where('merchant_id', $this->merchant)->value('available_centavos'));
    }

    public function test_manual_funding_and_existing_funding_reviews_share_duplicate_guards(): void
    {
        $payment = 'SYNTHETIC-FUNDING-PAYMENT';
        $request = ['request_id' => (string) Str::uuid(), 'amount_centavos' => 10000, 'payment_method' => 'bank_transfer', 'payment_reference' => $payment, 'payment_date' => today()->toDateString()];
        $id = $this->withToken($this->owner->api_token)->postJson('/api/merchant/wallet/topups', $request)->assertCreated()->json('topup.id');
        $this->withToken($this->admin->api_token);
        $this->adjust([...$this->payload(), 'settlement_reference' => $payment])->assertConflict();
        $decision = ['decision' => 'approved', 'reason' => 'Synthetic settlement verified', 'settlement_reference' => 'SYNTHETIC-TRACE-0001', 'settlement_confirmed' => true];
        $this->postJson('/api/admin/wallets/topups/'.$id.'/review', $decision)->assertOk();
        $this->adjust([...$this->payload(), 'settlement_reference' => $decision['settlement_reference']])->assertConflict();
        $this->adjust([...$this->payload(), 'settlement_reference' => $payment])->assertConflict();
        $this->assertDatabaseCount('merchant_wallet_entries', 1);
    }

    public function test_mutations_accept_current_login_mfa_and_cannot_adjust_own_business(): void
    {
        config(['security.enforce_privileged_mfa' => true]);
        $v = $this->payload();
        $this->adjust($v)->assertForbidden()->assertJsonPath('code', 'MFA_REQUIRED');
        DB::table('users')->where('id', $this->admin->id)->update(['mfa_secret' => Crypt::encryptString('SYNTHETICKEY'), 'api_authenticated_at' => now()->subHour(), 'api_mfa_verified_at' => now()->subMinutes(16)]);
        $this->adjust($v)->assertCreated();
        $v = $this->payload();
        DB::table('users')->where('id', $this->admin->id)->update(['api_authenticated_at' => now()->subMinute()]);
        $this->adjust($this->payload())->assertForbidden()->assertJsonPath('code', 'MFA_REQUIRED');
        DB::table('users')->where('id', $this->admin->id)->update(['api_mfa_verified_at' => now()]);
        DB::table('merchants')->where('id', $this->merchant)->update(['merchant_account_id' => $this->admin->id]);
        $this->adjust($v)->assertForbidden();
        DB::table('merchants')->where('id', $this->merchant)->update(['merchant_account_id' => $this->owner->id]);
        $this->adjust($v)->assertCreated();
    }

    public function test_filtered_pagination_and_csv_preserve_full_record_and_escape_formulas(): void
    {
        $v = [...$this->payload(), 'reason' => '=SYNTHETIC formula must be escaped', 'settlement_reference' => '=SYNTHETIC-REFERENCE'];
        $this->adjust($v)->assertCreated();
        $this->adjust($this->payload('withdrawal', 100))->assertCreated();
        $this->getJson('/api/admin/wallets/'.$this->foreign)->assertOk()->assertJsonPath('entries.total', 0)->assertJsonPath('wallet.available_centavos', 0);
        $this->getJson('/api/admin/wallets/'.$this->merchant.'?page=2')->assertOk()->assertJsonPath('entries.data', []);
        $r = $this->get('/api/admin/wallets/'.$this->merchant.'/statement?kind=topup')->assertOk();
        $csv = $r->streamedContent();
        $this->assertStringContainsString("'=SYNTHETIC-REFERENCE", $csv);
        $this->assertStringContainsString("'=SYNTHETIC formula", $csv);
        $this->assertStringContainsString((string) $this->admin->id, $csv);
        $this->assertStringNotContainsString('withdrawal', $csv);
        $this->getJson('/api/admin/wallets/'.$this->merchant.'?from=2026-10-20&to=2026-10-01')->assertUnprocessable();
    }

    public function test_integrity_failure_and_audit_failure_roll_back_entire_adjustment(): void
    {
        $this->adjust($this->payload())->assertCreated();
        DB::table('merchant_wallets')->where('merchant_id', $this->merchant)->update(['available_centavos' => 20000]);
        $this->adjust($this->payload('withdrawal', 100))->assertConflict();
        $this->assertDatabaseCount('merchant_wallet_entries', 1);
        DB::table('merchant_wallets')->where('merchant_id', $this->merchant)->update(['available_centavos' => 10000]);
        $this->partialMock(BirCompliance::class, fn ($m) => $m->shouldReceive('audit')->andThrow(new \RuntimeException('Synthetic audit storage failure')));
        $this->adjust($this->payload('withdrawal', 100))->assertStatus(500);
        $this->assertDatabaseCount('merchant_wallet_entries', 1);
        $this->assertDatabaseCount('merchant_wallet_adjustments', 1);
        $this->assertSame(10000, (int) DB::table('merchant_wallets')->value('available_centavos'));
    }
}
