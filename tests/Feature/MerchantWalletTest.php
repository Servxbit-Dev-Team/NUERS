<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BirCompliance;
use App\Services\MerchantWallet;
use App\Services\TenantPortability;
use App\Services\TransactionPricing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class MerchantWalletTest extends TestCase
{
    private User $owner;

    private User $admin;

    private User $other;

    private string $m;

    private string $foreign;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array', 'notifications.dispatch_enabled' => false]);
        DB::purge('sqlite');
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        [$this->owner,$this->m] = $this->account();
        [$this->other,$this->foreign] = $this->account();
        [$this->admin] = $this->account('super_admin');
    }

    private function account(string $role = 'merchant'): array
    {
        $u = User::factory()->create(['api_token' => Str::random(64)]);
        DB::table('profiles')->insert(['id' => $u->id, 'email' => $u->email, 'role' => $role]);
        $id = (string) Str::uuid();
        DB::table('merchants')->insert(['id' => $id, 'merchant_account_id' => $u->id, 'business_name' => 'Synthetic Wallet QA', 'tin' => '123456789', 'status' => 'active', 'vat_registered' => true]);

        return [$u, $id];
    }

    private function fund(int $amount = 300): void
    {
        DB::transaction(fn () => app(MerchantWallet::class)->credit($this->m, $amount, (string) Str::uuid(), 'Synthetic funding', (string) $this->admin->id));
    }

    private function key(): string
    {
        $raw = Str::random(64);
        DB::table('merchant_api_keys')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $this->m, 'name' => 'Synthetic API key', 'environment' => 'live', 'branch_code' => '00000', 'branch_name' => 'QA', 'branch_type' => 'main', 'key_prefix' => 'qa', 'key_last_four' => 'test', 'key_hash' => hash('sha256', $raw), 'scopes' => '["transactions:write","transactions:read"]', 'ip_whitelist' => '[]', 'status' => 'active', 'expires_at' => now()->addDay(), 'rate_limit_per_day' => 1000]);

        return $raw;
    }

    private function series(): void
    {
        DB::table('invoice_series')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $this->m, 'branch_code' => '00000', 'document_family' => 'invoice', 'prefix' => 'QA-', 'range_start' => 1, 'range_end' => 100, 'status' => 'active', 'authority_reference' => 'SYNTHETIC ONLY', 'verified_by' => 'QA', 'valid_from' => now()->subDay(), 'valid_until' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        $this->partialMock(BirCompliance::class, fn ($mock) => $mock->shouldReceive('assertCanIssue')->andReturn(['permit_to_issue_number' => 'SYNTHETIC ONLY', 'esp_accreditation_number' => 'SYNTHETIC ONLY']));
    }

    private function event(int $n = 1, string $amount = '560.00'): array
    {
        return ['schema_version' => 'NUERS-ingestion-1', 'source_system' => 'qa-pos', 'source_event_id' => 'qa-event-'.$n, 'external_reference' => 'qa-invoice-'.$n, 'event_type' => 'invoice.finalized', 'occurred_at' => '2026-10-16T12:00:00+08:00', 'branch_code' => '00000', 'receipt_number' => 'QA-'.str_pad((string) $n, 8, '0', STR_PAD_LEFT), 'document_type' => 'vat_invoice', 'payment_method' => 'cash', 'gross_amount' => $amount, 'tax_type' => 'vatable', 'vat_inclusive' => true, 'items' => [['description' => 'Synthetic QA item', 'quantity' => 1, 'unit_price' => $amount, 'tax_type' => 'vatable']]];
    }

    public function test_insufficient_api_intake_saves_no_financial_rows_or_sequence_and_returns_funding_contract(): void
    {
        $key = $this->key();
        $this->series();
        $this->withHeaders(['X-NUERS-API-Key' => $key])->postJson('/api/integrations/transactions', $this->event())->assertStatus(402)->assertJsonPath('code', 'INSUFFICIENT_WALLET_BALANCE')->assertJsonPath('transaction_saved', false)->assertJsonPath('required_centavos', 300);
        foreach (['integration_inbox', 'merchant_transactions', 'transaction_receipts', 'merchant_wallet_entries', 'merchant_wallets'] as $t) {
            $this->assertDatabaseCount($t, 0);
        }$this->assertNull(DB::table('invoice_series')->value('last_number'));
    }

    public function test_exact_balance_retry_is_free_and_hash_change_is_rejected(): void
    {
        $key = $this->key();
        $this->series();
        $this->fund();
        $p = $this->event();
        $r = $this->withHeaders(['X-NUERS-API-Key' => $key])->postJson('/api/integrations/transactions', $p)->assertCreated()->assertJsonPath('wallet.fee_centavos', 300);
        $this->postJson('/api/integrations/transactions', $p)->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('receipt_id', $r->json('receipt_id'));
        $this->assertEquals(0, DB::table('merchant_wallets')->where('merchant_id', $this->m)->value('available_centavos'));
        $this->assertDatabaseCount('merchant_wallet_entries', 2);
        $this->postJson('/api/integrations/transactions', [...$p, 'customer_name' => 'Changed'])->assertConflict();
        $this->assertTrue(app(MerchantWallet::class)->audit($this->m)['valid']);
    }

    public function test_batch_requires_whole_fee_and_rolls_back_everything_on_late_failure(): void
    {
        $key = $this->key();
        $this->series();
        $this->fund();
        $this->withHeaders(['X-NUERS-API-Key' => $key])->postJson('/api/integrations/transactions/batch', ['events' => [$this->event(1), $this->event(2)]])->assertStatus(402)->assertJsonPath('available_centavos', 300)->assertJsonPath('required_centavos', 600);
        $this->assertDatabaseCount('transaction_receipts', 0);
        $this->assertDatabaseCount('merchant_wallet_entries', 1);
        $this->fund();
        $bad = $this->event(2);
        $bad['receipt_number'] = 'WRONG';
        $this->postJson('/api/integrations/transactions/batch', ['events' => [$this->event(1), $bad]])->assertConflict();
        $this->assertDatabaseCount('transaction_receipts', 0);
        $this->assertEquals(600, DB::table('merchant_wallets')->value('available_centavos'));
        $this->assertNull(DB::table('invoice_series')->value('last_number'));
        $this->postJson('/api/integrations/transactions/batch', ['events' => [$this->event(1), $this->event(2)]])->assertCreated()->assertJsonPath('accepted', 2);
        $this->assertEquals(0, DB::table('merchant_wallets')->value('available_centavos'));
        $this->postJson('/api/integrations/transactions/batch', ['events' => [$this->event(1), $this->event(2)]])->assertCreated()->assertJsonPath('results.0.duplicate', true);
        $this->assertDatabaseCount('merchant_wallet_entries', 4);
    }

    public function test_free_threshold_and_failed_authority_do_not_charge(): void
    {
        $key = $this->key();
        $this->series();
        $this->withHeaders(['X-NUERS-API-Key' => $key])->postJson('/api/integrations/transactions', $this->event(1, '499.99'))->assertCreated()->assertJsonPath('wallet.fee_centavos', 0);
        $this->assertDatabaseCount('merchant_wallet_entries', 0);
        $this->fund();
        $this->partialMock(BirCompliance::class, fn ($mock) => $mock->shouldReceive('assertCanIssue')->andThrow(new HttpException(409, 'Synthetic authority block')));
        $this->postJson('/api/integrations/transactions', $this->event(2))->assertConflict();
        $this->assertEquals(300, DB::table('merchant_wallets')->value('available_centavos'));
        $this->assertDatabaseCount('transaction_receipts', 1);
    }

    public function test_funding_requires_independent_super_admin_and_no_double_credit(): void
    {
        $v = ['request_id' => (string) Str::uuid(), 'amount_centavos' => 10000, 'payment_method' => 'bank_transfer', 'payment_reference' => 'SYNTHETIC-BANK-123', 'payment_date' => '2026-10-16'];
        $id = $this->withToken($this->owner->api_token)->postJson('/api/merchant/wallet/topups', $v)->assertCreated()->assertJsonPath('credited', false)->json('topup.id');
        $this->postJson('/api/merchant/wallet/topups', $v)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertDatabaseCount('merchant_wallet_entries', 0);
        $decision = ['decision' => 'approved', 'reason' => 'Synthetic settled funds reviewed', 'settlement_reference' => 'SYNTHETIC-SETTLEMENT-123', 'settlement_confirmed' => true];
        $this->postJson('/api/admin/wallets/topups/'.$id.'/review', $decision)->assertForbidden();
        $this->withToken($this->admin->api_token)->postJson('/api/admin/wallets/topups/'.$id.'/review', [...$decision, 'settlement_confirmed' => false])->assertUnprocessable();
        $this->postJson('/api/admin/wallets/topups/'.$id.'/review', $decision)->assertOk()->assertJsonPath('credited_centavos', 10000);
        $this->postJson('/api/admin/wallets/topups/'.$id.'/review', $decision)->assertConflict();
        $v['request_id'] = (string) Str::uuid();
        $otherId = $this->withToken($this->owner->api_token)->postJson('/api/merchant/wallet/topups', $v)->json('topup.id');
        $this->withToken($this->admin->api_token)->postJson('/api/admin/wallets/topups/'.$otherId.'/review', [...$decision, 'settlement_reference' => 'SYNTHETIC-DIFFERENT-REFERENCE'])->assertConflict();
        $this->assertEquals(10000, DB::table('merchant_wallets')->where('merchant_id', $this->m)->value('available_centavos'));
    }

    public function test_price_is_editable_only_by_super_admin_and_historical_debits_are_immutable(): void
    {
        $this->fund(1000);
        DB::transaction(fn () => app(MerchantWallet::class)->charge($this->m, 50000, 'vat_invoice', 'invoice', 'qa-1', 'QA-1', null, 'portal', 'qa'));
        $p = app(TransactionPricing::class)->offer();
        $v = ['fee_centavos' => 450, 'revision' => $p->revision, 'reason' => 'Synthetic prospective price update'];
        $this->withToken($this->owner->api_token)->putJson('/api/admin/wallets/pricing', $v)->assertForbidden();
        $this->withToken($this->admin->api_token)->putJson('/api/admin/wallets/pricing', $v)->assertOk()->assertJsonPath('pricing.fee_centavos', 450);
        $this->putJson('/api/admin/wallets/pricing', $v)->assertConflict();
        DB::transaction(fn () => app(MerchantWallet::class)->charge($this->m, 50000, 'vat_invoice', 'invoice', 'qa-2', 'QA-2', null, 'portal', 'qa'));
        $this->assertEquals(250, DB::table('merchant_wallets')->where('merchant_id', $this->m)->value('available_centavos'));
        $this->assertEquals([-300, -450], DB::table('merchant_wallet_entries')->where('kind', 'fee')->orderBy('sequence')->pluck('amount_centavos')->all());
        $this->getJson('/api/pricing')->assertJsonPath('pricing.fee_centavos', 450);
    }

    public function test_tenant_settings_export_and_integrity_tampering(): void
    {
        $this->fund(10000);
        $this->getJson('/api/merchant/wallet')->assertUnauthorized();
        $this->withToken($this->other->api_token)->getJson('/api/merchant/wallet')->assertOk()->assertJsonPath('wallet.available_centavos', 0);
        $this->getJson('/api/admin/wallets')->assertForbidden();
        $this->withToken($this->owner->api_token)->putJson('/api/merchant/wallet/settings', ['low_balance_threshold' => 1000, 'alerts_enabled' => true, 'intake_mode' => 'batch', 'revision' => 1])->assertOk();
        $this->putJson('/api/merchant/wallet/settings', ['low_balance_threshold' => 1000, 'alerts_enabled' => true, 'intake_mode' => 'batch', 'revision' => 1])->assertConflict();
        $this->get('/api/merchant/wallet/statement')->assertOk();
        DB::table('merchant_wallet_entries')->where('merchant_id', $this->m)->update(['reference' => 'tampered']);
        $this->assertFalse(app(MerchantWallet::class)->audit($this->m)['valid']);
        try {
            DB::transaction(fn () => app(MerchantWallet::class)->charge($this->m, 50000, 'vat_invoice', 'invoice', 'bad', 'bad', null, 'portal', 'qa'));
            $this->fail('Tampered wallet was spendable');
        } catch (HttpException$e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    private function portal(): array
    {
        return ['request_id' => (string) Str::uuid(), 'invoice_number' => 'QA-PORTAL-1', 'document_type' => 'sales_invoice', 'buyer_name' => 'Synthetic New Buyer', 'buyer_customer_type' => 'retail', 'buyer_email' => 'buyer@example.test', 'issue_date' => now()->toDateString(), 'status' => 'draft', 'line_items' => [['line_number' => 1, 'description' => 'Synthetic sale', 'quantity' => 1, 'unit_price' => 56000, 'line_total' => 56000, 'tax_type' => 'vatable']]];
    }

    public function test_portal_draft_requires_funds_before_customer_creation_and_publish_charges_in_centavos_once(): void
    {
        $body = $this->portal();
        $this->withToken($this->owner->api_token)->postJson('/api/business-invoices', $body)->assertStatus(402)->assertJsonPath('required_centavos', 300);
        $this->assertDatabaseCount('business_invoices', 0);
        $this->assertDatabaseCount('merchant_customers', 0);
        $this->assertDatabaseCount('invoice_create_requests', 0);
        $this->fund();
        $id = $this->postJson('/api/business-invoices', $body)->assertCreated()->json('invoice.id');
        $this->assertEquals(300, DB::table('merchant_wallets')->where('merchant_id', $this->m)->value('available_centavos'));
        $this->postJson('/api/business-invoices', $body)->assertOk();
        $this->assertDatabaseCount('merchant_wallet_entries', 1);
        $this->series();
        $this->postJson('/api/documents/invoice/'.$id.'/publish', ['branch_code' => '00000'])->assertOk();
        $this->assertEquals(0, DB::table('merchant_wallets')->where('merchant_id', $this->m)->value('available_centavos'));
        $this->assertEquals(56000, DB::table('merchant_wallet_entries')->where('kind', 'fee')->value('gross_centavos'));
        $this->postJson('/api/documents/invoice/'.$id.'/publish', ['branch_code' => '00000'])->assertConflict();
        $this->assertDatabaseCount('merchant_wallet_entries', 2);
    }

    public function test_invoice_center_cannot_bypass_funding_and_draft_is_preserved_when_publish_funds_are_spent(): void
    {
        $body = ['request_id' => (string) Str::uuid(), 'merchant_id' => $this->m, 'document_type' => 'sales_invoice', 'buyer_name' => 'Synthetic Buyer', 'issue_date' => now()->toDateString(), 'vat_inclusive' => true, 'tax_type' => 'vatable', 'items' => [['description' => 'Synthetic line', 'quantity' => 1, 'unit_price' => '560.00']]];
        $this->withToken($this->owner->api_token)->postJson('/api/invoice-center', $body)->assertStatus(402);
        $this->assertDatabaseCount('business_invoices', 0);
        $this->fund();
        $this->postJson('/api/invoice-center', $body)->assertCreated();
        DB::transaction(fn () => app(MerchantWallet::class)->charge($this->m, 50000, 'vat_invoice', 'invoice', 'different-sale', 'SYNTHETIC-OTHER', null, 'portal', 'qa'));
        $this->series();
        $this->postJson('/api/documents/invoice/'.$body['request_id'].'/publish', ['branch_code' => '00000'])->assertStatus(402);
        $this->assertDatabaseHas('business_invoices', ['id' => $body['request_id'], 'status' => 'draft']);
        $this->assertNull(DB::table('invoice_series')->value('last_number'));
    }

    public function test_free_receipt_zero_rate_partial_dates_and_admin_role_restrictions(): void
    {
        DB::transaction(fn () => app(MerchantWallet::class)->charge($this->m, 100000, 'payment_receipt', 'invoice', 'payment', 'SYNTHETIC-PAYMENT', null, 'portal', 'qa'));
        $this->assertDatabaseCount('merchant_wallet_entries', 0);
        [$normal] = $this->account('admin');
        $p = app(TransactionPricing::class)->offer();
        $v = ['fee_centavos' => 0, 'revision' => $p->revision, 'reason' => 'Synthetic zero fee policy'];
        $this->withToken($normal->api_token)->putJson('/api/admin/wallets/pricing', $v)->assertForbidden();
        $this->withToken($this->admin->api_token)->putJson('/api/admin/wallets/pricing', $v)->assertOk();
        $this->series();
        $this->withHeaders(['X-NUERS-API-Key' => $this->key()])->postJson('/api/integrations/transactions', $this->event())->assertCreated()->assertJsonPath('wallet.fee_centavos', 0);
        $this->assertDatabaseCount('merchant_wallet_entries', 0);
        $this->withToken($this->owner->api_token)->getJson('/api/merchant/wallet?to=2026-10-17')->assertOk();
    }

    public function test_portable_wallet_export_is_owned_reconciled_and_excludes_other_tenant(): void
    {
        $this->fund();
        $r = $this->withToken($this->owner->api_token)->get('/api/businesses/'.$this->m.'/portability')->assertOk();
        $text = $r->streamedContent();
        $this->assertStringContainsString('merchant_wallet_entries', $text);
        $this->assertStringContainsString('merchant_wallets', $text);
        $this->assertStringNotContainsString($this->foreign, $text);
        $this->withToken($this->other->api_token)->getJson('/api/businesses/'.$this->m.'/portability')->assertNotFound();
        [$regulator] = $this->account('bir');
        $text = $this->withToken($regulator->api_token)->get('/api/businesses/'.$this->m.'/portability')->assertOk()->streamedContent();
        $this->assertStringNotContainsString('merchant_wallet_entries', $text);
    }

    public function test_direct_portal_issuance_charges_php_amount_correctly_and_records_final_number(): void
    {
        $body = [...$this->portal(), 'status' => 'issued'];
        $this->withToken($this->owner->api_token)->postJson('/api/business-invoices', $body)->assertStatus(402);
        $this->assertDatabaseCount('merchant_customers', 0);
        $this->fund();
        $this->series();
        $this->partialMock(BirCompliance::class, function ($mock) {
            $mock->shouldReceive('assertCanIssue')->andReturn(['permit_to_issue_number' => 'SYNTHETIC ONLY', 'esp_accreditation_number' => 'SYNTHETIC ONLY']);
            $mock->shouldReceive('integration')->andReturn((object) ['branch_code' => '00000']);
        });
        $r = $this->postJson('/api/business-invoices', $body)->assertCreated();
        $fee = DB::table('merchant_wallet_entries')->where('kind', 'fee')->first();
        $this->assertEquals(56000, $fee->gross_centavos);
        $this->assertEquals(-300, $fee->amount_centavos);
        $this->assertEquals('QA-00000001', $fee->reference);
        $this->assertEquals('00000', $fee->branch_code);
        $this->postJson('/api/business-invoices', $body)->assertOk();
        $this->assertDatabaseCount('merchant_wallet_entries', 2);
        $this->assertEquals(0, app(MerchantWallet::class)->state($this->m)->available_centavos);
    }

    public function test_api_payment_copy_is_free_and_funding_rejection_never_credits(): void
    {
        $this->series();
        DB::table('invoice_series')->where('merchant_id', $this->m)->update(['document_family' => 'payment']);
        $event = [...$this->event(), 'event_type' => 'payment.recorded', 'document_type' => 'payment_receipt'];
        $this->withHeaders(['X-NUERS-API-Key' => $this->key()])->postJson('/api/integrations/transactions', $event)->assertCreated()->assertJsonPath('wallet.fee_centavos', 0);
        $this->assertDatabaseCount('merchant_wallet_entries', 0);
        $v = ['request_id' => (string) Str::uuid(), 'amount_centavos' => 10000, 'payment_method' => 'bank_transfer', 'payment_reference' => 'SYNTHETIC-REJECTED-123', 'payment_date' => today()->toDateString()];
        $id = $this->withToken($this->owner->api_token)->postJson('/api/merchant/wallet/topups', $v)->assertCreated()->json('topup.id');
        $this->withToken($this->admin->api_token)->postJson('/api/admin/wallets/topups/'.$id.'/review', ['decision' => 'rejected', 'reason' => 'Synthetic bank trace cannot be verified'])->assertOk()->assertJsonPath('credited_centavos', 0);
        $this->assertDatabaseCount('merchant_wallet_entries', 0);
        $this->assertEquals(0, app(MerchantWallet::class)->state($this->m)->available_centavos);
    }

    public function test_wallet_notices_are_targeted_and_failed_acceptance_rolls_back_notification(): void
    {
        [$normal] = $this->account('admin');
        $this->fund();
        $this->series();
        $key = $this->key();
        $bad = $this->event();
        $bad['receipt_number'] = 'WRONG';
        $this->withHeaders(['X-NUERS-API-Key' => $key])->postJson('/api/integrations/transactions', $bad)->assertConflict();
        $this->assertEquals(0, DB::table('notification_outbox')->where('event', 'wallet.balance.low')->count());
        $this->postJson('/api/integrations/transactions', $this->event())->assertCreated();
        $this->assertEquals(1, DB::table('notification_outbox')->where('event', 'wallet.balance.low')->count());
        $v = ['request_id' => (string) Str::uuid(), 'amount_centavos' => 10000, 'payment_method' => 'bank_transfer', 'payment_reference' => 'SYNTHETIC-NOTIFY-123', 'payment_date' => today()->toDateString()];
        $this->withToken($this->owner->api_token)->postJson('/api/merchant/wallet/topups', $v)->assertCreated();
        $this->assertEquals(2, DB::table('notification_outbox')->where('event', 'wallet.topup.requested')->count());
        $this->assertFalse(DB::table('notification_outbox')->where('event', 'wallet.topup.requested')->where('user_id', $normal->id)->exists());
        $this->assertTrue(DB::table('notification_outbox')->where('event', 'wallet.topup.requested')->where('user_id', $this->admin->id)->exists());
    }

    public function test_branch_review_export_scopes_both_source_kind_and_document_owner(): void
    {
        $source = (string) Str::uuid();
        DB::table('business_invoices')->insert(['id' => $source, 'merchant_id' => $this->m, 'invoice_number' => 'OWNED-QA', 'document_type' => 'vat_invoice', 'status' => 'issued', 'merchant_name' => 'Synthetic seller', 'buyer_name' => 'Synthetic buyer', 'issue_date' => today(), 'total_amount' => 1]);
        DB::table('transaction_receipts')->insert(['id' => $source, 'merchant_id' => $this->foreign, 'receipt_number' => 'FOREIGN-QA', 'merchant_name'=>'Foreign synthetic seller','merchant_tin'=>'987654321', 'document_type' => 'vat_invoice', 'status' => 'issued']);
        $owned = (string) Str::uuid();
        $foreign = (string) Str::uuid();
        foreach ([[$owned, 'invoice'], [$foreign, 'receipt']] as [$id,$type]) {
            DB::table('eis_document_branch_reviews')->insert(['id' => $id, 'document_source' => $type, 'document_id' => $source, 'branch_code' => '00000', 'source_fingerprint' => hash('sha256', $id), 'evidence_reference' => 'Synthetic source review', 'reviewed_by' => 'synthetic-reviewer', 'reviewed_at' => now(), 'revision' => 1]);
        }
        $queries = app(TenantPortability::class)->queries($this->m);
        $this->assertEquals([$owned], $queries['eis_document_branch_reviews']->pluck('id')->all());
    }
}
