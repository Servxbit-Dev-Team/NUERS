<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DocumentPdf;
use App\Services\ElectronicDocumentView;
use App\Services\IntegrationContract;
use App\Services\MerchantInvoiceSettings;
use App\Services\MerchantWallet;
use App\Services\PlatformMail;
use App\Services\PlatformNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApiCustomerReceiptTest extends TestCase
{
    private string $key;

    private string $profile;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array', 'notifications.dispatch_enabled' => true]);
        DB::purge('sqlite');
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $owner = User::factory()->create();
        DB::table('merchants')->insert(['id' => 'receipt-test', 'merchant_account_id' => $owner->id, 'business_name' => 'Synthetic Receipt Shop', 'address' => 'Synthetic address', 'tin' => '123456789000', 'vat_registered' => true, 'status' => 'active', 'logo_url' => '/uploads/business-logos/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee.png', 'invoice_logo_url' => '/public/uploads/invoice-logos/bbbbbbbb-cccc-dddd-eeee-ffffffffffff.png']);
        $this->key = Str::random(64);
        DB::table('merchant_api_keys')->insert(['id' => 'key', 'merchant_id' => 'receipt-test', 'environment' => 'live', 'name' => 'Synthetic', 'branch_code' => '00000', 'branch_type' => 'main', 'key_hash' => hash('sha256', $this->key), 'key_prefix' => 'test', 'key_last_four' => 'test', 'status' => 'active', 'scopes' => '["transactions:write","transactions:read"]', 'rate_limit_per_day' => 1000, 'expires_at' => now()->addDay()]);
        $this->profile = (string) Str::uuid();
        DB::table('integration_source_profiles')->insert(['id' => $this->profile, 'merchant_id' => 'receipt-test', 'branch_code' => '00000', 'source_system' => 'fixture-pos', 'vat_registered' => true, 'seller_name' => 'Synthetic Receipt Shop', 'seller_tin' => '123456789000', 'seller_address' => 'Synthetic address', 'authority_reference' => 'Synthetic authority', 'evidence_reference' => 'Synthetic evidence', 'valid_from' => now()->subYear(), 'valid_until' => now()->addYear(), 'status' => 'active', 'reviewed_by' => 'fixture', 'reviewed_at' => now(), 'request_id' => (string) Str::uuid(), 'request_hash' => str_repeat('b', 64), 'created_at' => now(), 'updated_at' => now()]);
        DB::transaction(fn () => app(MerchantWallet::class)->credit('receipt-test', 900, (string) Str::uuid(), 'Synthetic funds', 'test'));
        DB::table('notification_outbox')->delete();
        $this->withHeader('X-NUERS-API-Key', $this->key);
    }

    private function event(string $n = '1'): array
    {
        return ['schema_version' => 'NUERS-ingestion-1', 'record_mode' => 'source_capture', 'source_profile_id' => $this->profile, 'source_system' => 'fixture-pos', 'source_event_id' => 'event-'.$n, 'external_reference' => 'sale-'.$n, 'event_type' => 'invoice.finalized', 'occurred_at' => now()->subMinute()->toIso8601String(), 'branch_code' => '00000', 'receipt_number' => 'TEST-'.$n, 'document_type' => 'vat_invoice', 'payment_method' => 'cash', 'gross_amount' => '112.00', 'tax_type' => 'vatable', 'vat_inclusive' => true, 'customer_email' => 'customer@example.test', 'items' => [['description' => 'Synthetic item', 'quantity' => 1, 'unit_price' => '112.00', 'tax_type' => 'vatable']]];
    }

    public function test_invoice_numbers_and_registration_are_validated_before_capture_and_printed_unchanged(): void
    {
        foreach ([str_repeat('X', 51), ' INV-001', "INV-001\n"] as $number) {
            $bad = [...$this->event(),'receipt_number' => $number];
            $this->postJson('/api/integrations/transactions', $bad)->assertUnprocessable()->assertJsonValidationErrors('receipt_number');
            $this->postJson('/api/integrations/transactions/batch', ['events' => [$bad]])->assertUnprocessable()->assertJsonValidationErrors('events.0.receipt_number');
        }
        $this->assertDatabaseCount('transaction_receipts', 0);
        $this->assertSame(900, (int)DB::table('merchant_wallets')->value('available_centavos'));
        $event = [...$this->event(),'receipt_number' => str_repeat('A', 50),'customer_type' => 'business','customer_name' => 'Synthetic Buyer','customer_tin' => '98765432100000','customer_address' => 'Synthetic registered buyer address','invoice_reference' => 'ORIGINAL-000001','document_registration' => ['system_type' => 'CAS','ac_number' => 'SYNTHETIC-AC','authority_issued_at' => '2020-01-01','series_start' => 'SYNTHETIC-000001','series_end' => 'SYNTHETIC-999999']];
        $event['items'][0]['unit'] = 'piece';
        $this->postJson('/api/integrations/transactions', $event)->assertCreated();
        $row = DB::table('transaction_receipts')->first();
        $dto = app(ElectronicDocumentView::class)->make('receipt', $row);
        $this->assertSame(str_repeat('A', 50), $dto['number']);
        $this->assertSame('ORIGINAL-000001', $dto['invoice_reference']);
        $this->assertSame('SYNTHETIC-AC', $dto['document_registration']['ac_number']);
        $this->assertSame('piece', $dto['items'][0]['unit']);
        $this->postJson('/api/integrations/transactions', $event)->assertOk()->assertJsonPath('duplicate', true);
        $event['document_registration']['ac_number'] = 'CHANGED';
        $this->postJson('/api/integrations/transactions', $event)->assertConflict();
        $this->assertDatabaseCount('transaction_receipts', 1);
    }

    public function test_one_auto_email_for_one_sale_and_identical_retries_with_current_status(): void
    {
        $this->mock(PlatformMail::class, fn ($m) => $m->shouldNotReceive('send'));
        $event = $this->event();
        $r = $this->postJson('/api/integrations/transactions', $event)->assertCreated()->assertJsonPath('nuers_status', 'success')->assertJsonPath('customer_receipt.status', 'queued')->assertJsonMissingPath('bir_acceptance');
        $this->assertDatabaseHas('transaction_receipts', ['id' => $r['receipt_id'], 'buyer_email' => 'customer@example.test']);
        $this->assertDatabaseCount('document_email_deliveries', 1);
        $this->assertSame(1, DB::table('notification_outbox')->where('event', 'document.customer_copy')->count());
        $this->assertStringNotContainsString('customer@example.test', DB::table('notification_outbox')->value('encrypted_payload'));
        DB::table('document_email_deliveries')->update(['state' => 'smtp_accepted']);
        $this->postJson('/api/integrations/transactions', $event)->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('id', $r['id'])->assertJsonPath('customer_receipt.status', 'smtp_accepted');
        $this->getJson('/api/integrations/transactions/'.$r['id'])->assertOk()->assertJsonPath('customer_receipt.status', 'smtp_accepted')->assertJsonPath('nuers_status', 'success')->assertJsonMissingPath('preparation')->assertJsonMissingPath('bir_acceptance');
        $this->assertDatabaseCount('document_email_deliveries', 1);
        $this->assertSame(600, (int) DB::table('merchant_wallets')->value('available_centavos'));
        $event['customer_email'] = 'different@example.test';
        $this->postJson('/api/integrations/transactions', $event)->assertConflict()->assertJsonPath('nuers_status', 'error')->assertJsonPath('error.code', 'CONFLICT');
    }

    public function test_sandbox_never_queues_customer_email_and_absent_email_is_not_requested(): void
    {
        DB::table('merchant_api_keys')->update(['environment' => 'sandbox']);
        $this->postJson('/api/integrations/transactions', $this->event())->assertCreated()->assertJsonPath('customer_receipt.status', 'suppressed_sandbox')->assertJsonMissingPath('bir_acceptance');
        $this->assertDatabaseCount('document_email_deliveries', 0);
        $this->assertDatabaseCount('notification_outbox', 0);
        DB::table('merchant_api_keys')->update(['environment' => 'live']);
        $event = $this->event();
        unset($event['customer_email']);
        $this->postJson('/api/integrations/transactions', $event)->assertCreated()->assertJsonPath('customer_receipt.status', 'not_requested');
        $this->assertDatabaseCount('document_email_deliveries', 0);
    }

    public function test_invalid_email_rejected_before_financial_or_email_writes(): void
    {
        foreach (['not-an-email', 'a@example.test,b@example.test', "a@example.test\r\nBcc: b@example.test"] as $email) {
            $this->postJson('/api/integrations/transactions', array_replace($this->event(), ['customer_email' => $email]))->assertUnprocessable()->assertJsonPath('nuers_status', 'error')->assertJsonPath('error.code', 'VALIDATION_FAILED')->assertJsonValidationErrors('customer_email');
        }
        $this->assertDatabaseCount('document_email_deliveries', 0);
        $this->assertDatabaseCount('merchant_transactions', 0);
    }

    public function test_atomic_batch_rollback_also_rolls_back_receipt_email(): void
    {
        $first = $this->event();
        $second = $this->event('2');
        $second['gross_amount'] = '113.00';
        $this->postJson('/api/integrations/transactions/batch', ['events' => [$first, $second]])->assertUnprocessable()->assertJsonPath('nuers_status', 'error');
        foreach (['merchant_transactions', 'integration_inbox', 'document_email_deliveries', 'notification_outbox'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame(900, (int) DB::table('merchant_wallets')->value('available_centavos'));
        $this->postJson('/api/integrations/transactions/batch', ['events' => [$first, $this->event('2')]])->assertCreated()->assertJsonPath('nuers_status', 'success')->assertJsonPath('results.0.customer_receipt.status', 'queued')->assertJsonMissingPath('bir_acceptance');
        $this->assertDatabaseCount('document_email_deliveries', 2);
    }

    public function test_worker_uses_same_latest_branding_and_document_as_dashboard(): void
    {
        $r = $this->postJson('/api/integrations/transactions', $this->event())->assertCreated();
        $merchant = DB::table('merchants')->first();
        $settings = app(MerchantInvoiceSettings::class)->get($merchant)['settings'];
        $settings['primary_color'] = '#123456';
        $settings['footer_text'] = 'Synthetic custom footer';
        $settings['invoice_template'] = 'detailed';
        DB::table('merchant_invoice_settings')->insert(['merchant_id' => $merchant->id, 'settings' => json_encode($settings), 'revision' => 1, 'updated_by' => 'fixture', 'created_at' => now(), 'updated_at' => now()]);
        $document = app(ElectronicDocumentView::class)->make('receipt', DB::table('transaction_receipts')->first());
        $this->assertSame('#123456', $document['presentation']['primary_color']);
        $this->assertSame($merchant->invoice_logo_url, $document['presentation']['logo_url']);
        $this->assertNotSame($merchant->logo_url, $document['presentation']['logo_url']);
        $this->assertSame('Synthetic custom footer', $document['footer_note']);
        $this->partialMock(DocumentPdf::class, fn ($m) => $m->shouldReceive('render')->once()->with($document)->andReturn('%PDF-synthetic-receipt'));
        $this->mock(PlatformMail::class, function ($m) {
            $m->shouldReceive('settings')->andReturn(['enabled' => true]);
            $m->shouldReceive('send')->once()->withArgs(fn ($s, $to, $subject, $body, $pdf, $filename) => $to === 'customer@example.test' && $pdf === '%PDF-synthetic-receipt' && str_contains($subject, 'Synthetic Receipt Shop'));
        });
        DB::table('notification_outbox')->where('event', '!=', 'document.customer_copy')->delete();
        $this->assertTrue(app(PlatformNotifications::class)->runOne());
        $this->assertFalse(app(PlatformNotifications::class)->runOne());
        $this->getJson('/api/integrations/transactions/'.$r['id'])->assertOk()->assertJsonPath('nuers_status', 'success')->assertJsonPath('customer_receipt.status', 'smtp_accepted');
    }

    public function test_smtp_uncertainty_does_not_reject_or_repeat_the_sale(): void
    {
        $event = $this->event();
        $r = $this->postJson('/api/integrations/transactions', $event)->assertCreated();
        $this->partialMock(DocumentPdf::class, fn ($m) => $m->shouldReceive('render')->once()->andReturn('%PDF-synthetic'));
        $this->mock(PlatformMail::class, function ($m) {
            $m->shouldReceive('settings')->andReturn(['enabled' => true]);
            $m->shouldReceive('send')->once()->andThrow(new \RuntimeException('PRIVATE SMTP ERROR'));
        });
        DB::table('notification_outbox')->where('event', '!=', 'document.customer_copy')->delete();
        app(PlatformNotifications::class)->runOne();
        $this->postJson('/api/integrations/transactions', $event)->assertOk()->assertJsonPath('nuers_status', 'success')->assertJsonPath('customer_receipt.status', 'uncertain')->assertDontSee('PRIVATE SMTP ERROR');
        $this->assertFalse(app(PlatformNotifications::class)->runOne());
        $this->assertDatabaseCount('merchant_transactions', 1);
    }

    public function test_customer_email_status_is_tenant_scoped_and_legacy_responses_hide_bir_fields(): void
    {
        $r = $this->postJson('/api/integrations/transactions', $this->event())->assertCreated();
        $body = json_decode(DB::table('integration_inbox')->value('response'), true);
        $body['bir_acceptance'] = 'not_verified';
        DB::table('integration_inbox')->update(['response' => json_encode($body)]);
        $this->getJson('/api/integrations/transactions/'.$r['id'])->assertOk()->assertJsonMissingPath('bir_acceptance');
        DB::table('merchants')->insert(['id' => 'foreign', 'business_name' => 'Other', 'status' => 'active']);
        DB::table('merchant_api_keys')->update(['merchant_id' => 'foreign']);
        $this->getJson('/api/integrations/transactions/'.$r['id'])->assertNotFound()->assertJsonPath('nuers_status', 'error')->assertJsonMissingPath('customer_receipt');
    }

    public function test_auth_funding_throttle_and_server_errors_have_safe_reasons(): void
    {
        $this->withHeader('X-NUERS-API-Key', 'bad')->postJson('/api/integrations/transactions', $this->event())->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');
        $this->withHeader('X-NUERS-API-Key', $this->key);
        DB::table('merchant_wallet_entries')->delete();
        DB::table('merchant_wallets')->delete();
        $this->postJson('/api/integrations/transactions', $this->event())->assertStatus(402)->assertJsonPath('error.code', 'INSUFFICIENT_WALLET_BALANCE')->assertJsonPath('transaction_saved', false);
        DB::transaction(fn () => app(MerchantWallet::class)->credit('receipt-test', 900, (string) Str::uuid(), 'Synthetic replenishment', 'test'));
        config(['app.debug' => true]);
        $this->partialMock(IntegrationContract::class, fn ($m) => $m->shouldReceive('validateEvent')->andThrow(new \RuntimeException('PRIVATE DATABASE PASSWORD')));
        $this->postJson('/api/integrations/transactions', $this->event())->assertStatus(500)->assertJsonPath('error.outcome', 'unknown')->assertJsonPath('error.retryable', true)->assertDontSee('PRIVATE DATABASE PASSWORD')->assertJsonMissingPath('trace');
    }

    public function test_boundary_rate_limit_has_a_reason_and_documentation_matches_contract(): void
    {
        $this->getJson('/api/integrations/transactions/wallet')->assertOk()->assertJsonMissingPath('bir_connection');
        $bucket = 'api-ip:'.hash('sha256', '127.0.0.1');
        for ($i = 0; $i < 301; $i++) {
            RateLimiter::hit($bucket, 60);
        }
        $this->postJson('/api/integrations/transactions', $this->event())->assertStatus(429)
            ->assertJsonPath('nuers_status', 'error')->assertJsonPath('error.code', 'NETWORK_RATE_LIMITED')->assertHeader('Retry-After');
        $docs = json_decode(file_get_contents(resource_path('data/merchant-api-receipts.json')), true);
        $this->assertSame('success', $docs['success_example']['nuers_status']);
        $this->assertSame('queued', $docs['success_example']['customer_receipt']['status']);
        $openapi = json_decode(file_get_contents(resource_path('data/developer-openapi.json')), true);
        $fields = $openapi['paths']['/api/integrations/transactions']['post']['requestBody']['content']['application/json']['schema']['properties'];
        $this->assertSame(254, $fields['customer_email']['maxLength']);
        $this->assertSame($openapi['components']['schemas']['IngestionEvent']['properties'], $fields);
        foreach (['withholding_details', 'manual_recovery_id', 'manual_invoice_number'] as $field) {
            $this->assertArrayHasKey($field, $fields);
        }
    }

    public function test_prepared_accreditation_gate_preserves_existing_intake_and_replays(): void
    {
        $event = $this->event('policy-first');
        $r = $this->postJson('/api/integrations/transactions', $event)->assertCreated();
        config(['bir_compliance.source_capture_accreditation_enforced' => true]);
        $this->postJson('/api/integrations/transactions', $event)->assertOk()->assertJsonPath('duplicate', true);
        $this->postJson('/api/integrations/transactions', $this->event('policy-second'))->assertConflict();
        $this->assertDatabaseCount('transaction_receipts', 1);
        $this->assertSame(600, (int)DB::table('merchant_wallets')->value('available_centavos'));
        $this->getJson('/api/integrations/transactions/'.$r['id'])->assertOk();
    }
    public function test_structured_cwt_keeps_gross_and_vat_and_validates_amount(): void
    {
        $event = [...$this->event('cwt'),'withholding_tax' => '1.00','withholding_basis_reference' => 'Synthetic buyer confirmation','withholding_details' => ['withholding_agent' => true,'classification' => 'Buyer-confirmed corporate payor','atc' => 'WC160','rate' => '1.00','base' => '100.00','amount' => '1.00','evidence_reference' => 'Synthetic buyer confirmation']];
        $this->postJson('/api/integrations/transactions', $event)->assertCreated()->assertJsonPath('calculation.gross_amount', 112)->assertJsonPath('calculation.vat_amount', 12)->assertJsonPath('calculation.net_payable', 111);
        $event['withholding_details']['amount'] = '2.00';
        $event['source_event_id'] = 'cwt-invalid';
        $event['external_reference'] = 'cwt-invalid';
        $event['receipt_number'] = 'CWT-INVALID';
        $this->postJson('/api/integrations/transactions', $event)->assertUnprocessable();
        $this->assertDatabaseCount('transaction_receipts', 1);
    }
    public function test_manual_replacement_is_atomic_scoped_and_charged_once(): void
    {
        $ops = app(\App\Services\ComplianceOperations::class);
        $outage = $ops->create('receipt-test', 'fixture', 'outage', ['request_id' => (string)Str::uuid(),'title' => 'Synthetic API outage','started_at' => now()->subHours(3)->toIso8601String(),'evidence_reference' => 'Synthetic outage evidence']);
        $manual = $ops->create('receipt-test', 'fixture', 'manual_invoice', ['request_id' => (string)Str::uuid(),'outage_id' => $outage['id'],'manual_number' => 'MANUAL-API-1','branch_code' => '00000','issued_at' => now()->subHours(2)->toIso8601String(),'amount' => '112.00','buyer_name' => 'Synthetic Buyer','authority_reference' => 'Synthetic ATP evidence','evidence_reference' => 'Synthetic manual evidence']);
        $event = [...$this->event('manual'),'manual_recovery_id' => $manual['id'],'manual_invoice_number' => 'MANUAL-API-1','customer_name' => 'Synthetic Buyer'];
        $this->postJson('/api/integrations/transactions', $event)->assertConflict();
        $this->assertDatabaseCount('transaction_receipts', 0);
        $this->assertSame(900, (int)DB::table('merchant_wallets')->value('available_centavos'));
        $ops->act('receipt-test', $outage['id'], 'fixture', ['action' => 'restore','revision' => 1,'restored_at' => now()->subHour()->toIso8601String(),'evidence_reference' => 'Synthetic restoration evidence']);
        $this->postJson('/api/integrations/transactions', [...$event,'occurred_at' => now()->subHours(2)->toIso8601String()])->assertUnprocessable();
        $this->postJson('/api/integrations/transactions', [...$event,'manual_recovery_id' => (string)Str::uuid()])->assertNotFound();
        $this->assertDatabaseCount('document_email_deliveries', 0);
        $r = $this->postJson('/api/integrations/transactions', $event)->assertCreated();
        $this->postJson('/api/integrations/transactions', $event)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertDatabaseHas('merchant_compliance_records', ['id' => $manual['id'],'status' => 'reconciled','related_id' => $r['receipt_id']]);
        $this->postJson('/api/integrations/transactions', [...$event,'source_event_id' => 'second-manual','external_reference' => 'second-manual','receipt_number' => 'SECOND-MANUAL'])->assertConflict();
        $this->assertDatabaseCount('transaction_receipts', 1);
        $this->assertDatabaseCount('document_email_deliveries', 1);
        $this->assertSame(600, (int)DB::table('merchant_wallets')->value('available_centavos'));
        DB::table('merchant_api_keys')->update(['environment' => 'sandbox']);
        $this->postJson('/api/integrations/transactions',$event)->assertUnprocessable();
    }

}
