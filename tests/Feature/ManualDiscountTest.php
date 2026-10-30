<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\{IntegrationContract,MerchantWallet,EisDocumentReadiness};
use Illuminate\Support\Facades\{DB,Crypt};
use Illuminate\Support\Str;
use Tests\TestCase;

class ManualDiscountTest extends TestCase
{
    private User $owner;
    private User $admin;
    private string $merchant;
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite','database.connections.sqlite.database' => ':memory:','cache.default' => 'array','notifications.dispatch_enabled' => false]);
        DB::purge('sqlite');
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->owner = $this->user('merchant');
        $this->admin = $this->user('super_admin');
        $this->merchant = (string)Str::uuid();
        DB::table('merchants')->insert(['id' => $this->merchant,'merchant_account_id' => $this->owner->id,'business_name' => 'Synthetic Benefits QA','tin' => '123456789000','address' => 'Synthetic Address','status' => 'active','vat_registered' => true]);
        DB::transaction(fn () => app(MerchantWallet::class)->credit($this->merchant, 30000, (string)Str::uuid(), 'Synthetic test funds', (string)$this->admin->id));
    }
    private function user(string $role): User
    {
        $u = User::factory()->create(['api_token' => Str::random(64),'api_mfa_verified_at' => now(),'mfa_secret' => Crypt::encryptString('SYNTHETICKEY')]);
        DB::table('profiles')->insert(['id' => $u->id,'email' => $u->email,'role' => $role]);
        return $u;
    }
    private function benefit(string $c = 'pwd'): array
    {
        return ['category' => $c,'scope' => $c === 'solo_parent' ? 'diapers' : 'restaurant','beneficiary_name' => 'Synthetic Eligible Buyer','id_number' => 'SYNTHETIC-ID-001','evidence_reference' => 'Synthetic identity and eligibility evidence','eligibility_confirmed' => true,...($c === 'solo_parent' ? ['child_birth_date' => now()->subYears(2)->toDateString(),'id_valid_until' => now()->addMonth()->toDateString(),'booklet_reference' => 'Synthetic booklet','income_below_250000' => true] : [])];
    }
    private function key(string $env = 'live'): string
    {
        $k = Str::random(64);
        DB::table('merchant_api_keys')->insert(['id' => (string)Str::uuid(),'merchant_id' => $this->merchant,'name' => 'Synthetic benefit test','environment' => $env,'branch_code' => '00000','branch_type' => 'main','key_hash' => hash('sha256', $k),'key_prefix' => 'test','key_last_four' => 'test','status' => 'active','scopes' => '["transactions:write","transactions:read"]','ip_whitelist' => '[]','rate_limit_per_day' => 50000,'expires_at' => now()->addDay()]);
        return $k;
    }
    private function benefitPayload(string $category = 'pwd', string $ref = 'A'): array
    {
        $profile = DB::table('integration_source_profiles')->value('id');
        if (!$profile) {
            $profile = (string)Str::uuid();
            DB::table('integration_source_profiles')->insert(['id' => $profile,'merchant_id' => $this->merchant,'branch_code' => '00000','source_system' => 'synthetic-benefits','vat_registered' => true,'seller_name' => 'Synthetic Benefits QA','seller_tin' => '123456789000','seller_address' => 'Synthetic address','authority_reference' => 'Synthetic reviewed source','evidence_reference' => 'Synthetic evidence','valid_from' => now()->subYear(),'valid_until' => now()->addYear(),'status' => 'active','reviewed_by' => (string)$this->admin->id,'reviewed_at' => now(),'request_id' => (string)Str::uuid(),'request_hash' => str_repeat('a', 64)]);
        }
        return ['schema_version' => 'NUERS-ingestion-1','record_mode' => 'source_capture','source_profile_id' => $profile,'source_system' => 'synthetic-benefits','source_event_id' => $ref,'external_reference' => $ref,'event_type' => 'invoice.finalized','occurred_at' => now()->subMinute()->toIso8601String(),'branch_code' => '00000','receipt_number' => 'SYNTHETIC-'.$ref,'document_type' => 'sales_invoice','payment_method' => 'cash','tax_type' => 'vatable','vat_inclusive' => true,'gross_amount' => $category === 'national_athlete_coach' ? 896 : ($category === 'solo_parent' ? 900 : 800),'sales_benefit' => $this->benefit($category),'items' => [['description' => 'Synthetic qualifying item','quantity' => 1,'unit_price' => 1120,'tax_type' => 'vatable','benefit_eligible' => true]]];
    }

    private function payload(string $ref = 'MANUAL'): array
    {
        $p = $this->benefitPayload('pwd', $ref);
        unset($p['sales_benefit']);
        $p['items'][0]['benefit_eligible'] = false;
        $p['gross_amount'] = 1008;
        $p['manual_discount'] = ['type' => 'amount','value' => '112.00','reason' => 'Loyalty discount'];
        return $p;
    }
    public function test_document_amount_percentage_and_legacy_line_percentage_have_same_totals(): void
    {
        $contract = app(IntegrationContract::class);
        $p = $this->payload();
        foreach (['amount','percentage','legacy','line_amount','line_percentage'] as $mode) {
            $v = $p;
            if ($mode === 'percentage') {
                $v['manual_discount'] = ['type' => 'percentage','value' => '10.00','reason' => 'Loyalty discount'];
            }
            if (str_starts_with($mode, 'line_')) {
                $v['items'][0]['manual_discount'] = ['type' => substr($mode, 5),'value' => $mode === 'line_amount' ? 112 : 10,'reason' => 'Loyalty discount'];
                unset($v['manual_discount']);
            }
            if ($mode === 'legacy') {
                unset($v['manual_discount']);
                $v['items'][0]['discount_pct'] = 10;
            }
            $r = $contract->calculateDocument($contract->validateEvent($v), true);
            $this->assertEquals(112, $r['manual_discount_amount'], $mode);
            $this->assertEquals(0, $r['statutory_discount_amount']);
            $this->assertEquals(900, $r['vatable_sales']);
            $this->assertEquals(108, $r['vat_amount']);
            $this->assertEquals(1008, $r['gross_amount']);
        }
    }
    public function test_document_allocation_is_exact_stable_and_uses_original_price_basis(): void
    {
        $p = $this->payload();
        $p['manual_discount']['value'] = '0.01';
        $p['tax_type'] = 'non_vat';
        $p['gross_amount'] = '0.02';
        $p['items'] = array_fill(0, 3, ['description' => 'Synthetic rounding item','quantity' => 1,'unit_price' => '0.01','tax_type' => 'non_vat']);
        $r = app(IntegrationContract::class)->calculateDocument($p, false);
        $this->assertSame([0.01,0,0], array_column($r['items'], 'fixed_discount_amount'));
        $p['items'] = [['description' => 'VAT item','quantity' => 2,'unit_price' => 100,'tax_type' => 'vatable'],['description' => 'Exempt item','quantity' => 1,'unit_price' => 100,'tax_type' => 'vat_exempt']];
        $p['tax_type'] = 'vatable';
        $p['vat_inclusive'] = false;
        $p['manual_discount']['value'] = 30;
        $p['gross_amount'] = 291.6;
        $r = app(IntegrationContract::class)->calculateDocument($p, true);
        $this->assertEquals([20,10], array_column($r['items'], 'fixed_discount_amount'));
        $this->assertEquals(21.6, $r['vat_amount']);
        $this->assertEquals(90, $r['vat_exempt_sales']);
        // Allocation product exceeds PHP_INT_MAX; decimal result must remain exact.
        $p['items'] = array_fill(0, 2, ['description' => 'Synthetic large item','quantity' => 50,'unit_price' => 100000000,'tax_type' => 'non_vat']);
        $p['tax_type'] = 'non_vat';
        $p['manual_discount']['value'] = 5000000000;
        $p['gross_amount'] = 5000000000;
        $r = app(IntegrationContract::class)->calculateDocument($p, false);
        $this->assertEquals([2500000000,2500000000], array_column($r['items'], 'fixed_discount_amount'));
    }
    public function test_invalid_discounts_and_conflicting_totals_are_rejected_without_any_write(): void
    {
        $p = $this->payload();
        $cases = [];
        foreach ([['amount',1120.01],['amount',-1],['amount',0.001],['percentage',100.01],['percentage',1.001],['bogus',10]] as [$type,$value]) {
            $v = $p;
            $v['manual_discount'] = ['type' => $type,'value' => $value,'reason' => 'Synthetic reason'];
            $cases[] = $v;
        }
        $v = $p;
        $v['manual_discount']['reason'] = ' ';
        $cases[] = $v;
        $v = $p;
        $v['manual_discount']['unexpected'] = 1;
        $cases[] = $v;
        $v = $p;
        $v['items'][0]['discount_pct'] = 10;
        $cases[] = $v;
        $v = $p;
        $v['gross_amount'] = 1000;
        $cases[] = $v;
        $v = $p;
        unset($v['manual_discount']);
        $v['items'][0]['manual_discount'] = ['type' => 'amount','value' => 1200,'reason' => 'Too much'];
        $cases[] = $v;
        $v = $this->benefitPayload();
        $v['manual_discount'] = $p['manual_discount'];
        $cases[] = $v;
        $v = $this->benefitPayload();
        $v['items'][0]['manual_discount'] = $p['manual_discount'];
        $cases[] = $v;
        $this->withHeader('X-NUERS-API-Key', $this->key());
        foreach ($cases as $v) {
            $this->postJson('/api/integrations/transactions', $v)->assertUnprocessable();
        }
        $this->assertDatabaseCount('transaction_receipts', 0);
        $this->assertSame(30000, (int)DB::table('merchant_wallets')->value('available_centavos'));
    }
    public function test_live_discount_survives_persistence_reconciliation_lists_exports_and_retries(): void
    {
        $p = $this->payload();
        $key = $this->key();
        $this->withHeader('X-NUERS-API-Key', $key)->postJson('/api/integrations/transactions', $p)->assertCreated()->assertJsonPath('calculation.manual_discount_amount', 112);
        $id = DB::table('transaction_receipts')->value('id');
        $this->assertTrue(app(EisDocumentReadiness::class)->inspect('receipt', $id)['financial_validation_passed']);
        $balance = DB::table('merchant_wallets')->value('available_centavos');
        $this->postJson('/api/integrations/transactions', $p)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertEquals($balance, DB::table('merchant_wallets')->value('available_centavos'));
        $p['manual_discount']['reason'] = 'Changed reason';
        $this->postJson('/api/integrations/transactions', $p)->assertConflict();
        $this->assertDatabaseCount('transaction_receipts', 1);
        $this->flushHeaders();
        $this->withToken($this->owner->api_token)->getJson('/api/merchant/records?mode=documents')->assertOk()->assertJsonPath('data.0.manual_discount_amount', 112);
        $this->getJson('/api/merchant/records?mode=transactions')->assertOk()->assertJsonPath('data.0.manual_discount_amount', 112);
        $q = 'from='.now()->subDay()->toDateString().'&to='.now()->addDay()->toDateString();
        $this->getJson('/api/merchant/sales-reports?'.$q)->assertOk()->assertJsonPath('totals.manual_discount', 11200)->assertJsonPath('totals.gross', 100800);
        $this->getJson('/api/invoice-center/receipt/'.$id)->assertOk()->assertJsonPath('document.manual_discount', 112)->assertJsonPath('document.items.0.discount_reason', 'Loyalty discount');
        $csv = $this->get('/api/merchant/records/export?mode=documents')->assertOk()->streamedContent();
        $this->assertStringContainsString('Manual Discount PHP', $csv);
        $this->assertStringContainsString('112.00', $csv);
        $csv = $this->get('/api/merchant/sales-reports/export?format=csv&'.$q)->assertOk()->getContent();
        $this->assertStringContainsString('Manual Discount PHP', $csv);
        $this->withToken($this->admin->api_token)->getJson('/api/transaction-workspace')->assertOk()->assertJsonPath('data.0.manual_discount_amount', 112);
        $this->getJson('/api/invoice-center')->assertOk()->assertJsonPath('data.0.manual_discount_amount', 112);
        foreach (['/api/transaction-workspace/export','/api/invoice-center/export'] as $url) {
            $csv = $this->get($url)->assertOk()->streamedContent();
            $this->assertStringContainsString('Manual Discount PHP', $csv);
            $this->assertStringContainsString('112', $csv);
        }
    }
    public function test_sandbox_discounts_are_visible_but_never_debit_live_wallet_or_expose_encrypted_payload(): void
    {
        $this->withHeader('X-NUERS-API-Key', $this->key('sandbox'))->postJson('/api/integrations/transactions', $this->payload())->assertCreated()->assertJsonPath('calculation.manual_discount_amount', 112);
        $this->assertDatabaseCount('transaction_receipts', 0);
        $this->assertSame(30000, (int)DB::table('merchant_wallets')->value('available_centavos'));
        $this->flushHeaders();
        $r = $this->withToken($this->owner->api_token)->getJson('/api/merchant/sandbox-records')->assertOk()->assertJsonPath('data.0.manual_discount_amount', 112)->assertJsonMissingPath('data.0.details_encrypted');
    }
    public function test_separate_regular_line_can_have_manual_discount_alongside_statutory_sale(): void
    {
        $p = $this->benefitPayload();
        $p['items'][] = ['description' => 'Non-eligible item','quantity' => 1,'unit_price' => 112,'tax_type' => 'vatable','manual_discount' => ['type' => 'amount','value' => 11.2,'reason' => 'Loyalty discount']];
        $p['gross_amount'] = 900.8;
        $r = app(IntegrationContract::class)->calculateDocument($p, true);
        $this->assertEquals(11.2, $r['manual_discount_amount']);
        $this->assertEquals(200, $r['statutory_discount_amount']);
        $this->assertEquals(211.2, $r['discount_amount']);
    }
    public function test_document_copy_and_report_pdf_preserve_manual_discount(): void
    {
        $this->withHeader('X-NUERS-API-Key', $this->key())->postJson('/api/integrations/transactions', $this->payload('PDF'))->assertCreated();
        $id = DB::table('transaction_receipts')->value('id');
        $this->flushHeaders();
        $this->withToken($this->owner->api_token);
        $q = 'from='.now()->subDay()->toDateString().'&to='.now()->addDay()->toDateString();
        $invoice = $this->get('/api/document-copies/receipt/'.$id.'/pdf')->assertOk()->getContent();
        $report = $this->get('/api/merchant/sales-reports/export?format=pdf&'.$q)->assertOk()->getContent();
        $this->assertStringStartsWith('%PDF-', $invoice);
        $this->assertStringStartsWith('%PDF-', $report);
        if (getenv('NUERS_QA_ARTIFACTS') === '1') {
            $this->withToken($this->admin->api_token);
            $dir = base_path('output/manual-discounts');
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($dir.'/sample-invoice.pdf', $invoice);
            file_put_contents($dir.'/sample-sales-report.pdf',$report);
            $fixture = ['workspace' => $this->getJson('/api/transaction-workspace')->json(),'center' => $this->getJson('/api/invoice-center')->json(),'document' => $this->getJson('/api/invoice-center/receipt/'.$id)->json()];
            file_put_contents($dir.'/ui-fixture.json',json_encode($fixture,JSON_PRETTY_PRINT));
        }
    }
}
