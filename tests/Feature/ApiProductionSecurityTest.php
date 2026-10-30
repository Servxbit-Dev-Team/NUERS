<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\{BirCompliance, IntegrationContract};
use Illuminate\Support\Facades\{Crypt, DB};
use Illuminate\Support\Str;
use Tests\TestCase;

class ApiProductionSecurityTest extends TestCase
{
    private User $owner;
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite','database.connections.sqlite.database' => ':memory:','cache.default' => 'array','notifications.dispatch_enabled' => false,'security.enforce_privileged_mfa' => true]);
        DB::purge('sqlite');
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->owner = User::factory()->create(['api_token' => Str::random(64),'mfa_secret' => Crypt::encryptString('SYNTHETICKEY'),'api_mfa_verified_at' => now()]);
        DB::table('profiles')->insert(['id' => $this->owner->id,'email' => $this->owner->email,'role' => 'merchant']);
        DB::table('merchants')->insert(['id' => 'audit','business_name' => 'Synthetic audit','address' => 'Synthetic registered address','tin' => '123456789','merchant_account_id' => $this->owner->id,'status' => 'active','vat_registered' => true]);
        DB::table('merchant_branches')->insert(['id' => (string)Str::uuid(),'merchant_id' => 'audit','code' => 'SYNTHETIC','bir_branch_code' => '00000','name' => 'Synthetic branch','address' => 'Synthetic address','city' => 'Synthetic city','region' => 'Synthetic region','rdo_code' => '001','branch_type' => 'main','status' => 'active','contact_encrypted' => Crypt::encryptString('{}'),'request_id' => (string)Str::uuid(),'request_hash' => str_repeat('a', 64)]);
    }
    private function payload(string $tax = 'vatable', string $doc = 'vat_invoice'): array
    {
        return ['schema_version' => 'NUERS-ingestion-1','source_system' => 'audit-pos','source_event_id' => 'event-1','external_reference' => 'source-1','event_type' => 'invoice.finalized','occurred_at' => now()->subMinute()->toIso8601String(),'branch_code' => '00000','receipt_number' => 'AUD-00000001','document_type' => $doc,'payment_method' => 'cash','gross_amount' => $tax === 'vatable' ? '112.00' : '100.00','tax_type' => $tax,'vat_inclusive' => true,'items' => [['description' => 'Synthetic','quantity' => 1,'unit_price' => $tax === 'vatable' ? '112.00' : '100.00','tax_type' => $tax]]];
    }
    private function key(string $env = 'live'): string
    {
        $raw = Str::random(64);
        DB::table('merchant_api_keys')->insert(['id' => (string)Str::uuid(),'merchant_id' => 'audit','name' => 'Synthetic','environment' => $env,'branch_code' => '00000','branch_type' => 'main','key_hash' => hash('sha256', $raw),'key_prefix' => 'test','key_last_four' => 'test','status' => 'active','scopes' => '["transactions:write","transactions:read"]','rate_limit_per_day' => 1000,'expires_at' => now()->addDay()]);
        return $raw;
    }
    private function issue(): void
    {
        DB::table('invoice_series')->insert(['document_registration' => json_encode(['system_type' => 'CAS','ac_number' => 'SYNTHETIC-AC-ONLY','authority_issued_at' => '2020-01-01']),'id' => 'audit-series','merchant_id' => 'audit','branch_code' => '00000','document_family' => 'invoice','prefix' => 'AUD-','range_start' => 1,'range_end' => 100,'status' => 'active','authority_reference' => 'SYNTHETIC','verified_by' => 'fixture','valid_from' => now()->subDay(),'valid_until' => now()->addDay(),'created_at' => now(),'updated_at' => now()]);
        $this->partialMock(BirCompliance::class, fn ($m) => $m->shouldReceive('assertCanIssue')->andReturn(['permit_to_issue_number' => 'SYNTHETIC','esp_accreditation_number' => 'SYNTHETIC']));
    }
    private function profile(): string
    {
        $id = (string)Str::uuid();
        DB::table('integration_source_profiles')->insert(['id' => $id,'merchant_id' => 'audit','branch_code' => '00000','source_system' => 'audit-pos','vat_registered' => true,'seller_name' => 'Original Synthetic Issuer','seller_tin' => '123456789','seller_address' => 'Original source address','authority_reference' => 'Synthetic reviewed source authority','evidence_reference' => 'Synthetic review evidence','valid_from' => now()->subYear(),'valid_until' => now()->addYear(),'status' => 'active','reviewed_by' => 'separate-synthetic-admin','reviewed_at' => now(),'request_id' => (string)Str::uuid(),'request_hash' => str_repeat('a', 64),'created_at' => now(),'updated_at' => now()]);
        return $id;
    }
    public function test_vat_seller_cannot_masquerade_as_non_vat(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(IntegrationContract::class)->calculateDocument($this->payload('non_vat', 'non_vat_invoice'), true);
    }
    public function test_non_vat_exempt_and_sspt_sales_remain_separate_and_invoice_family_is_non_vat(): void
    {
        $c = app(IntegrationContract::class);
        $p = $this->payload('vat_exempt', 'non_vat_invoice');
        $tax = $c->calculateDocument($p, false);
        $this->assertSame('non_vat_invoice', $c->resolveDocument($p, $tax)['type']);
        $this->assertEquals([100,0], [$tax['vat_exempt_sales'],$tax['sspt_sales']]);
        $tax = $c->calculateDocument($this->payload('non_vat', 'non_vat_invoice'), false);
        $this->assertEquals([0,100], [$tax['vat_exempt_sales'],$tax['sspt_sales']]);
    }
    public function test_buyer_withholding_and_tax_basis_are_preserved_and_part_of_identity_hash(): void
    {
        // Synthetic funds satisfy the configurable minimum-zero wallet gate.
        DB::transaction(fn () => app(\App\Services\MerchantWallet::class)->credit('audit', 600, (string) \Illuminate\Support\Str::uuid(), 'Synthetic settled test funds', 'test-fixture'));
        $this->issue();
        $p = $this->payload() + ['customer_address' => 'Synthetic buyer address','withholding_tax' => '2.00','withholding_basis_reference' => 'Synthetic CWT basis','tax_basis_reference' => 'Synthetic classification basis'];
        $raw = $this->key();
        $r = $this->withHeader('X-NUERS-API-Key', $raw)->postJson('/api/integrations/transactions', $p)->assertCreated()->assertJsonPath('calculation.net_payable', 110)->assertJsonPath('calculation.withholding_tax', 2);
        $this->assertDatabaseHas('transaction_receipts', ['id' => $r->json('receipt_id'),'buyer_address' => 'Synthetic buyer address','withholding_tax' => 2,'total_due' => 112]);
        $this->postJson('/api/integrations/transactions', $p)->assertOk()->assertJsonPath('duplicate', true);
        $p['customer_address'] = 'Changed address';
        $this->postJson('/api/integrations/transactions', $p)->assertConflict();
        $this->assertDatabaseCount('transaction_receipts', 1);
    }
    public function test_sandbox_key_cannot_read_production_status(): void
    {
        // Synthetic funds satisfy the configurable minimum-zero wallet gate.
        DB::transaction(fn () => app(\App\Services\MerchantWallet::class)->credit('audit', 600, (string) \Illuminate\Support\Str::uuid(), 'Synthetic settled test funds', 'test-fixture'));
        $this->issue();
        $r = $this->withHeader('X-NUERS-API-Key', $this->key())->postJson('/api/integrations/transactions', $this->payload())->assertCreated();
        $this->withHeader('X-NUERS-API-Key', $this->key('sandbox'))->getJson('/api/integrations/transactions/'.$r->json('id'))->assertNotFound();
    }
    public function test_key_creation_and_revocation_rollback_when_audit_storage_fails(): void
    {
        $this->partialMock(BirCompliance::class, fn ($m) => $m->shouldReceive('audit')->andThrow(new \RuntimeException('Synthetic audit storage failure')));
        $this->withToken($this->owner->api_token)->postJson('/api/merchant/api-keys', ['name' => 'Synthetic','environment' => 'live','branch_code' => '00000','scopes' => ['transactions:write']])->assertStatus(500);
        $this->assertDatabaseCount('merchant_api_keys', 0);
        $this->key();
        $id = DB::table('merchant_api_keys')->value('id');
        $this->deleteJson('/api/merchant/api-keys/'.$id)->assertStatus(500);
        $this->assertDatabaseHas('merchant_api_keys', ['id' => $id,'status' => 'active']);
    }
    public function test_key_requires_registered_active_branch_and_only_supported_nonempty_scopes(): void
    {
        $this->withToken($this->owner->api_token);
        $p = ['name' => 'Synthetic','environment' => 'live','branch_code' => '99999','scopes' => ['transactions:write']];
        $this->postJson('/api/merchant/api-keys', $p)->assertUnprocessable();
        $p['branch_code'] = '00000';
        $p['scopes'] = [];
        $this->postJson('/api/merchant/api-keys', $p)->assertUnprocessable();
        $p['scopes'] = ['reports:read'];
        $this->postJson('/api/merchant/api-keys', $p)->assertUnprocessable();
        $this->assertDatabaseCount('merchant_api_keys', 0);
    }
    public function test_unknown_financial_fields_and_conflicting_aliases_are_rejected(): void
    {
        $this->withHeader('X-NUERS-API-Key', $this->key());
        $this->postJson('/api/integrations/transactions', $this->payload() + ['other_charges' => 5])->assertUnprocessable();
        $p = $this->payload();
        $p['items'][0]['invented_tax'] = 99;
        $this->postJson('/api/integrations/transactions', $p)->assertUnprocessable();
        $this->postJson('/api/integrations/transactions', $this->payload() + ['amount' => 99])->assertUnprocessable();
        $p = $this->payload();
        $p['items'][0]['line_total'] = '112.00';
        $p['items'][0]['amount'] = '111.00';
        $this->postJson('/api/integrations/transactions', $p)->assertUnprocessable();
        $p = $this->payload();
        $p['items'][0]['qty'] = 2;
        $this->postJson('/api/integrations/transactions', $p)->assertUnprocessable();
        $this->assertDatabaseCount('transaction_receipts', 0);
    }
    public function test_source_copy_preserves_external_number_date_and_does_not_issue_or_allocate_sequence(): void
    {
        // Synthetic funds satisfy the configurable minimum-zero wallet gate.
        DB::transaction(fn () => app(\App\Services\MerchantWallet::class)->credit('audit', 600, (string) \Illuminate\Support\Str::uuid(), 'Synthetic settled test funds', 'test-fixture'));
        $p = $this->payload() + ['record_mode' => 'source_capture','source_profile_id' => $this->profile()];
        $p['receipt_number'] = 'ERP-2025-EXTERNAL-000921';
        $p['occurred_at'] = now()->subMonth()->toIso8601String();
        $r = $this->withHeader('X-NUERS-API-Key', $this->key())->postJson('/api/integrations/transactions', $p)->assertCreated()->assertJsonPath('record_mode', 'source_capture');
        $this->assertDatabaseCount('invoice_series', 0);
        $this->assertDatabaseHas('transaction_receipts', ['id' => $r->json('receipt_id'),'receipt_number' => $p['receipt_number'],'record_mode' => 'source_capture','merchant_name' => 'Original Synthetic Issuer','series_number' => null,'permit_to_issue_number' => null]);
        $this->assertStringContainsString('External issuer source copy', DB::table('eis_document_outbox')->value('blockers'));
        $this->postJson('/api/integrations/transactions', $p)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertDatabaseCount('transaction_receipts', 1);
        DB::table('integration_source_profiles')->update(['status' => 'revoked']);
        // Already accepted immutable originals remain replayable after profile revocation.
        $this->postJson('/api/integrations/transactions', $p)->assertOk()->assertJsonPath('duplicate', true);
        $p['source_event_id'] = 'new';
        $p['external_reference'] = 'new';
        $p['receipt_number'] = 'new';
        $this->postJson('/api/integrations/transactions', $p)->assertConflict();
    }
    public function test_unreviewed_wrong_period_or_wrong_source_profiles_cannot_capture(): void
    {
        $this->withHeader('X-NUERS-API-Key', $this->key());
        $p = $this->payload() + ['record_mode' => 'source_capture','source_profile_id' => (string)Str::uuid()];
        $this->postJson('/api/integrations/transactions', $p)->assertConflict();
        $p['source_profile_id'] = $this->profile();
        $p['source_system'] = 'foreign-pos';
        $this->postJson('/api/integrations/transactions', $p)->assertConflict();
        $p['source_system'] = 'audit-pos';
        $p['occurred_at'] = now()->subYears(2)->toIso8601String();
        $this->postJson('/api/integrations/transactions', $p)->assertConflict();
        $this->assertDatabaseCount('transaction_receipts', 0);
    }
    public function test_profile_approval_requires_separate_admin_and_is_idempotent_and_immutable(): void
    {
        $v = ['request_id' => (string)Str::uuid(),'merchant_id' => 'audit','source_system' => 'audit-pos','branch_code' => '00000','vat_registered' => true,'seller_name' => 'Synthetic Issuer','seller_tin' => '123456789','seller_address' => 'Synthetic address','authority_reference' => 'Synthetic source registration evidence','evidence_reference' => 'Synthetic independent review evidence','valid_from' => now()->subDay()->toIso8601String(),'valid_until' => now()->addYear()->toIso8601String()];
        $this->withToken($this->owner->api_token)->postJson('/api/admin/integration-source-profiles', $v)->assertForbidden();
        $admin = User::factory()->create(['api_token' => Str::random(64),'mfa_secret' => Crypt::encryptString('SYNTHETICKEY'),'api_mfa_verified_at' => now()]);
        DB::table('profiles')->insert(['id' => $admin->id,'role' => 'super_admin','email' => $admin->email]);
        $r = $this->withToken($admin->api_token)->postJson('/api/admin/integration-source-profiles', $v)->assertCreated();
        $this->postJson('/api/admin/integration-source-profiles', $v)->assertOk()->assertJsonPath('duplicate', true);
        $v['evidence_reference'] = 'Changed review evidence';
        $this->postJson('/api/admin/integration-source-profiles', $v)->assertConflict();
        $this->deleteJson('/api/admin/integration-source-profiles/'.$r->json('id'), ['reason' => 'Synthetic source mapping revoked'])->assertOk();
        $this->assertDatabaseHas('integration_source_profiles', ['id' => $r->json('id'),'status' => 'revoked']);
    }
    public function test_active_account_does_not_bypass_original_issuance_authority(): void
    {
        // Synthetic funds satisfy the configurable minimum-zero wallet gate.
        DB::transaction(fn () => app(\App\Services\MerchantWallet::class)->credit('audit', 600, (string) \Illuminate\Support\Str::uuid(), 'Synthetic settled test funds', 'test-fixture'));
        $this->withHeader('X-NUERS-API-Key', $this->key())->postJson('/api/integrations/transactions', $this->payload())->assertConflict();
        $this->assertDatabaseCount('transaction_receipts', 0);
    }
    public function test_policy_revocation_between_authentication_and_acceptance_blocks_financial_writes(): void
    {
        $raw = $this->key();
        $this->partialMock(IntegrationContract::class, function ($mock) {
            $mock->shouldReceive('validateEvent')->once()->andReturnUsing(function ($payload) {
                $validated = (new IntegrationContract())->validateEvent($payload);
                DB::table('merchant_api_keys')->update(['status' => 'revoked']);
                return $validated;
            });
        });
        $this->withHeader('X-NUERS-API-Key', $raw)->postJson('/api/integrations/transactions', $this->payload())->assertForbidden();
        $this->assertDatabaseCount('transaction_receipts', 0);
        $this->assertDatabaseCount('integration_inbox', 0);
    }
    public function test_non_vat_mixed_source_copy_reports_sspt_and_exempt_without_double_subtraction(): void
    {
        // Synthetic funds satisfy the configurable minimum-zero wallet gate.
        DB::transaction(fn () => app(\App\Services\MerchantWallet::class)->credit('audit', 600, (string) \Illuminate\Support\Str::uuid(), 'Synthetic settled test funds', 'test-fixture'));
        DB::table('merchants')->where('id', 'audit')->update(['vat_registered' => false]);
        $profile = $this->profile();
        DB::table('integration_source_profiles')->where('id', $profile)->update(['vat_registered' => false]);
        $p = $this->payload('non_vat', 'mixed_sales_invoice') + ['record_mode' => 'source_capture','source_profile_id' => $profile];
        $p['items'][] = ['description' => 'Synthetic exempt supply','quantity' => 1,'unit_price' => '100.00','tax_type' => 'vat_exempt','tax_basis_reference' => 'Synthetic applicable exemption'];
        $p['gross_amount'] = '200.00';
        $r = $this->withHeader('X-NUERS-API-Key', $this->key())->postJson('/api/integrations/transactions', $p)->assertCreated()->assertJsonPath('calculation.sspt_sales', 100)->assertJsonPath('calculation.vat_exempt_sales', 100);
        $inspection = app(\App\Services\EisDocumentReadiness::class)->inspect('receipt', $r->json('receipt_id'));
        $this->assertTrue($inspection['financial_validation_passed']);
        $report = app(\App\Services\MerchantSalesReport::class)->generate(DB::table('merchants')->where('id', 'audit')->first(), ['from' => now()->subDay()->toDateString(),'to' => now()->addDay()->toDateString(),'branch' => 'all','type' => 'all','customer_type' => 'all','bir' => 'all','view' => 'sales','search' => '','period' => 'custom']);
        $this->assertEquals(10000, $report['totals']['non_vat']);
        $this->assertEquals(10000, $report['totals']['exempt']);
    }

    public function test_source_copy_cannot_be_transmitted_even_if_a_provider_is_later_enabled(): void
    {
        // Synthetic funds satisfy the configurable minimum-zero wallet gate.
        DB::transaction(fn () => app(\App\Services\MerchantWallet::class)->credit('audit', 600, (string) \Illuminate\Support\Str::uuid(), 'Synthetic settled test funds', 'test-fixture'));
        $p = $this->payload() + ['record_mode' => 'source_capture','source_profile_id' => $this->profile()];
        $r = $this->withHeader('X-NUERS-API-Key', $this->key())->postJson('/api/integrations/transactions', $p)->assertCreated();
        $provider = \Mockery::mock(\App\Contracts\EisProvider::class);
        $provider->shouldReceive('ready')->once()->andReturn(true);
        $provider->shouldNotReceive('send');
        $provider->shouldNotReceive('inquire');
        $this->assertSame('blocked',(new \App\Services\EisDelivery($provider))->runOne()['state']);
        $this->assertDatabaseHas('eis_delivery_jobs',['outbox_id' => $r->json('outbox_id'),'state' => 'blocked','attempts' => 0]);
    }

}
