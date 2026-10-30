<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BirCompliance;
use App\Services\EisDocumentReadiness;
use App\Services\IntegrationContract;
use App\Services\InvoiceCalculator;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EisReadinessAlignmentTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'app.timezone' => 'Asia/Manila']);
        DB::purge('sqlite');
        foreach (glob(database_path('migrations/*.php')) as $file) {
            (require $file)->up();
        }
        $this->admin = $this->user('super_admin');
        DB::table('merchants')->insert(['id' => 'm', 'business_name' => 'Current name', 'tin' => '123456789', 'vat_registered' => true]);
    }

    private function user(string $role): User
    {
        $u = User::factory()->create(['api_token' => fake()->uuid()]);
        DB::table('profiles')->insert(['id' => $u->id, 'role' => $role, 'email' => $u->email]);

        return $u;
    }

    private function receipt(string $id = 'a', string $number = 'INV-1'): void
    {
        DB::table('transaction_receipts')->insert(['id' => 'r'.$id, 'merchant_id' => 'm', 'receipt_number' => $number, 'merchant_name' => 'Original name', 'merchant_tin' => '123456789', 'merchant_address' => 'Original address', 'seller_branch_code' => '00000', 'document_type' => 'sales_invoice', 'status' => 'issued', 'issued_at' => '2026-10-01 09:00:00', 'total_due' => 112, 'vat_amount' => 12, 'vatable_sales' => 100, 'items' => json_encode([['description' => 'Service', 'quantity' => 1, 'unit_price' => 112, 'line_total' => 112, 'tax_type' => 'vatable']])]);
        DB::table('merchant_transactions')->insert(['id' => $id, 'merchant_id' => 'm', 'receipt_id' => 'r'.$id, 'transaction_ref' => 'TX-'.$id, 'status' => 'completed', 'amount' => 112, 'created_at' => '2026-10-15 11:00:00']);
    }

    private function invoice(string $id = 'i', string $number = 'INV-2', string $type = 'vat_invoice'): void
    {
        DB::table('business_invoices')->insert(['id' => $id, 'merchant_id' => 'm', 'invoice_number' => $number, 'merchant_name' => 'Original name', 'merchant_tin' => '123456789', 'merchant_address' => 'Original address', 'seller_branch_code' => '00000', 'document_type' => $type, 'status' => 'sent', 'issue_date' => '2026-10-01', 'total_amount' => 112, 'vat_amount' => 12, 'taxable_amount' => 100, 'buyer_name' => 'Buyer', 'vat_registered' => true, 'original_invoice_id' => $type === 'credit_memo' ? 'i' : null]);
        DB::table('business_invoice_items')->insert(['id' => 'item-'.$id, 'invoice_id' => $id, 'merchant_id' => 'm', 'description' => 'Service', 'quantity' => 1, 'unit_price' => 112, 'line_total' => 112, 'vat_amount' => 12, 'taxable_amount' => 100, 'tax_type' => 'vatable']);
    }

    public function test_both_document_sources_are_complete_and_snapshot_identity_survives_registry_edits(): void
    {
        $this->receipt();
        $this->invoice();
        DB::table('merchants')->where('id', 'm')->update(['business_name' => 'Changed', 'tin' => '999999999']);
        $r = $this->withToken($this->admin->api_token)->getJson('/api/transaction-workspace')->assertOk()->assertJsonPath('total', 2)->assertJsonPath('summary.total_amount', 224);
        foreach ($r->json('data') as $row) {
            $this->assertSame('Original name', $row['business']);
            $this->assertSame('123456789', $row['tin']);
        }
        $this->getJson('/api/transaction-workspace?from=2026-10-01&to=2026-10-01')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/transaction-workspace?from=2026-10-15')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/operations-report?mode=sales')->assertOk()->assertJsonPath('summary.sales', 224);
    }

    public function test_split_payments_do_not_double_count_and_duplicate_identities_are_excluded(): void
    {
        $this->receipt();
        DB::table('merchant_transactions')->where('id', 'a')->update(['amount' => 56]);
        DB::table('merchant_transactions')->insert(['id' => 'second-payment', 'merchant_id' => 'm', 'receipt_id' => 'ra', 'transaction_ref' => 'PAY-2', 'status' => 'completed', 'amount' => 56]);
        $this->withToken($this->admin->api_token)->getJson('/api/transaction-workspace')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.payment_count', 2)->assertJsonPath('data.0.amount', 112)->assertJsonPath('data.0.payment_amount', 112);
        $this->getJson('/api/operations-report?mode=sales')->assertOk()->assertJsonPath('summary.sales', 112);
        $this->receipt('b', 'INV-1');
        $this->getJson('/api/transaction-workspace')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.duplicate_reference', true)->assertJsonPath('data.0.source_record_count', 2);
        $this->getJson('/api/operations-report?mode=sales')->assertOk()->assertJsonPath('summary.sales', 0)->assertJsonPath('summary.excluded_count', 1);
        DB::table('transaction_receipts')->where('id', 'rb')->update(['seller_branch_code' => '00001']);
        $this->getJson('/api/operations-report?mode=sales')->assertOk()->assertJsonPath('summary.sales', 224);
    }

    public function test_credit_notes_reduce_sales_and_supplementary_payment_receipts_do_not_repeat_sales(): void
    {
        $this->invoice();
        $this->invoice('credit', 'CM-1', 'credit_memo');
        $this->receipt();
        DB::table('transaction_receipts')->where('id', 'ra')->update(['document_type' => 'payment_receipt']);
        $this->withToken($this->admin->api_token)->getJson('/api/operations-report?mode=sales')->assertOk()->assertJsonPath('total', 2)->assertJsonPath('summary.sales', 0)->assertJsonPath('summary.vat', 0)->assertJsonPath('summary.excluded_count', 1);
    }

    public function test_deadline_requires_coverage_and_uses_original_business_date(): void
    {
        $this->receipt();
        $this->withToken($this->admin->api_token)->getJson('/api/transaction-workspace')->assertOk()->assertJsonPath('data.0.original_transmission_target', '2026-10-04T23:59:59+08:00')->assertJsonPath('data.0.transmission_target', null)->assertJsonPath('data.0.overdue', false)->assertJsonPath('data.0.submission_applicability', 'unknown');
        $body = ['merchant_id' => 'm', 'branch_code' => '00000', 'coverage_status' => 'required', 'effective_from' => '2026-10-01', 'evidence_reference' => 'Dummy test authority reference', 'revision' => 0];
        $this->postJson('/api/eis-readiness/coverage', $body)->assertOk()->assertJsonPath('revision', 1);
        $this->postJson('/api/eis-readiness/coverage', $body)->assertConflict();
        $this->getJson('/api/transaction-workspace')->assertOk()->assertJsonPath('data.0.overdue', true)->assertJsonPath('data.0.transmission_target', '2026-10-04T23:59:59+08:00');
        $body['revision'] = 1;
        $body['effective_from'] = '2026-10-02';
        $this->postJson('/api/eis-readiness/coverage', $body)->assertOk();
        $this->getJson('/api/transaction-workspace')->assertOk()->assertJsonPath('data.0.overdue', false)->assertJsonPath('data.0.submission_applicability', 'outside_effective_period');
    }

    public function test_production_submission_survives_newer_certification_and_fake_acceptance(): void
    {
        $this->receipt();
        DB::table('eis_invoice_payloads')->insert(['id' => 'p', 'merchant_id' => 'm', 'receipt_id' => 'ra', 'payload' => '{}', 'payload_hash' => 'hash']);
        foreach ([['old', 'production', 'acknowledged', '2026-10-01'], ['prod', 'production', 'submitted', '2026-10-02'], ['cert', 'certification', 'acknowledged', '2026-10-03']] as [$id,$environment,$status,$date]) {
            DB::table('eis_transmissions')->insert(['id' => $id, 'merchant_id' => 'm', 'payload_id' => 'p', 'payload_hash' => 'hash', 'environment' => $environment, 'status' => $status, 'created_at' => $date]);
        }
        $this->withToken($this->admin->api_token)->getJson('/api/transaction-workspace?bir=processing')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.transmission_id', 'prod');
        $this->getJson('/api/transaction-workspace/a')->assertOk()->assertJsonCount(3, 'transaction.submission_history');
        $this->postJson('/api/bir-eis/transmissions/prod/acknowledge', ['is_test' => true])->assertConflict();
    }

    public function test_shared_calculator_handles_inclusive_vat_discount_and_mixed_classification(): void
    {
        $result = app(InvoiceCalculator::class)->calculate([
            ['unit_price' => 11200, 'quantity' => 2, 'discount_pct' => 10, 'tax_type' => 'vatable'],
            ['unit_price' => 5000, 'quantity' => 0.5, 'tax_type' => 'vat_exempt'],
            ['unit_price' => 1000, 'quantity' => 1, 'tax_type' => 'zero_rated'],
        ], true);
        $this->assertSame(25900, $result['subtotal_amount']);
        $this->assertSame(2240, $result['discount_amount']);
        $this->assertSame(23660, $result['total_amount']);
        $this->assertSame(2160, $result['vat_amount']);
        $this->assertSame(18000, $result['taxable_amount']);
        foreach (['line_total', 'vat_amount', 'discount_amount', 'taxable_amount'] as $field) {
            try {
                app(InvoiceCalculator::class)->calculate([['unit_price' => 11200, 'quantity' => 1, 'tax_type' => 'vatable', $field => 1]], true);
                $this->fail('Tampered '.$field.' was accepted.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('line_items.0.'.$field, $e->errors());
            }
        }
    }

    public function test_legacy_endpoint_recomputes_and_rejects_total_tampering_without_pseudo_signature(): void
    {
        // Synthetic funds satisfy the configurable minimum-zero wallet gate.
        DB::transaction(fn () => app(\App\Services\MerchantWallet::class)->credit('m', 600, (string) \Illuminate\Support\Str::uuid(), 'Synthetic settled test funds', 'test-fixture'));
        $owner = $this->user('merchant');
        DB::table('merchants')->where('id', 'm')->update(['merchant_account_id' => $owner->id]);
        $body = ['invoice_number' => 'NEW-1', 'document_type' => 'sales_invoice', 'status' => 'draft', 'buyer_name' => 'Buyer', 'issue_date' => '2026-10-16', 'line_items' => [['line_number' => 1, 'description' => 'Service', 'quantity' => 2, 'unit_price' => 11200, 'line_total' => 22400, 'tax_type' => 'vatable']], 'total_amount' => 1];
        $this->withToken($owner->api_token)->postJson('/api/business-invoices', $body)->assertUnprocessable()->assertJsonValidationErrors(['total_amount']);
        $this->assertSame(0, DB::table('business_invoices')->count());
        $body['total_amount'] = 22400;
        $body['digital_signature'] = 'client-forged-digest';
        $body['validation_hash'] = 'untrusted';
        $this->postJson('/api/business-invoices', $body)->assertCreated();
        $row = DB::table('business_invoices')->first();
        $this->assertEquals(224, $row->total_amount);
        $this->assertEquals(24, $row->vat_amount);
        $this->assertNull($row->digital_signature);
        $this->assertNull($row->signed_at);
        $this->assertNotSame('untrusted', $row->validation_hash);
        $this->assertSame(0, DB::table('eis_document_outbox')->count());
    }

    public function test_outbox_is_encrypted_idempotent_blocked_and_rejects_changed_source(): void
    {
        $this->invoice();
        $service = app(EisDocumentReadiness::class);
        $first = $service->prepare('invoice', 'i');
        $this->assertTrue($first['local_validation_passed']);
        $this->assertFalse($first['can_transmit']);
        $second = $service->prepare('invoice', 'i');
        $this->assertSame($first['id'], $second['id']);
        $this->assertTrue($second['duplicate']);
        $this->assertSame(1, DB::table('eis_document_outbox')->count());
        $row = DB::table('eis_document_outbox')->first();
        $this->assertSame('blocked_pending_api', $row->status);
        $this->assertStringNotContainsString('Original name', $row->encrypted_document);
        $this->assertSame($row->document_hash, hash('sha256', Crypt::decryptString($row->encrypted_document)));
        $this->assertSame(0, DB::table('eis_transmissions')->count());
        DB::table('business_invoices')->where('id', 'i')->update(['total_amount' => 999]);
        $this->withToken($this->admin->api_token)->postJson('/api/eis-readiness/invoice/i/prepare')->assertConflict();
        $this->assertSame($row->document_hash, DB::table('eis_document_outbox')->value('document_hash'));
    }

    public function test_readiness_is_scoped_and_invalid_sources_need_review(): void
    {
        $this->invoice();
        $owner = $this->user('merchant');
        $this->getJson('/api/eis-readiness')->assertUnauthorized();
        $this->withToken($owner->api_token)->getJson('/api/eis-readiness')->assertOk()->assertJsonPath('total', 0);
        $this->postJson('/api/eis-readiness/invoice/i/prepare')->assertNotFound();
        $this->postJson('/api/eis-readiness/coverage', [])->assertForbidden();
        DB::table('business_invoices')->where('id', 'i')->update(['seller_branch_code' => null]);
        $this->withToken($this->admin->api_token)->postJson('/api/eis-readiness/invoice/i/prepare')->assertOk()->assertJsonPath('local_validation_passed', false)->assertJsonPath('can_transmit', false);
        $this->assertSame('needs_source_review', DB::table('eis_document_outbox')->value('status'));
    }

    public function test_generic_financial_and_identity_api_is_authenticated_scoped_and_preserves_accounts(): void
    {
        $this->receipt();
        $one = $this->user('client');
        $two = $this->user('client');
        DB::table('client_accounts')->insert(['id' => 'client1', 'user_id' => $one->id, 'account_number' => 'C1', 'full_name' => 'Client1', 'wallet_balance' => 0]);
        DB::table('client_accounts')->insert(['id' => 'client2', 'user_id' => $two->id, 'account_number' => 'C2', 'full_name' => 'Client2', 'wallet_balance' => 0]);
        foreach (['profiles', 'client_accounts', 'merchant_transactions', 'transaction_receipts', 'notifications'] as $table) {
            $this->getJson('/api/tables/'.$table)->assertUnauthorized();
        }
        $this->withToken($one->api_token)->getJson('/api/tables/client_accounts')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', 'client1');
        $this->getJson('/api/tables/merchant_transactions')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/tables/profiles')->assertOk()->assertJsonCount(1, 'data');
        $url = '/api/tables/client_accounts?eq='.urlencode(json_encode(['id' => 'client2']));
        $this->patchJson($url, ['values' => ['full_name' => 'Changed']])->assertOk()->assertJsonPath('count', 0);
        $this->patchJson($url, ['values' => ['wallet_balance' => 999]])->assertForbidden();
        $this->deleteJson($url)->assertConflict();
        $this->assertDatabaseHas('client_accounts', ['id' => 'client2', 'full_name' => 'Client2', 'wallet_balance' => 0]);
        $this->assertDatabaseHas('users', ['id' => $two->id]);
    }

    public function test_formatted_tins_case_and_whitespace_cannot_bypass_document_identity_controls(): void
    {
        $this->receipt();
        $this->invoice('i', ' inv-1 ');
        DB::table('business_invoices')->where('id', 'i')->update(['merchant_tin' => '123-456-789-00000']);
        $this->withToken($this->admin->api_token)->getJson('/api/transaction-workspace')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.source_record_count', 2);
        $this->getJson('/api/operations-report?mode=sales')->assertOk()->assertJsonPath('summary.sales', 0);
        $result = app(EisDocumentReadiness::class)->inspect('invoice', 'i');
        $this->assertFalse($result['local_validation_passed']);
        $this->assertSame('123456789', $result['model']['seller']['taxpayer_tin']);
    }

    public function test_integration_line_calculations_reconcile_inclusive_exclusive_and_tampered_amounts(): void
    {
        $contract = app(IntegrationContract::class);
        $body = ['amount' => 224, 'tax_type' => 'vatable', 'items' => [['description' => 'Service', 'quantity' => 2, 'unit_price' => 112]]];
        $this->assertSame(24, $contract->calculateDocument($body, true)['vat_amount']);
        $body['vat_inclusive'] = false;
        $body['amount'] = 200;
        $body['items'][0]['unit_price'] = 100;
        $result = $contract->calculateDocument($body, true);
        $this->assertSame(224, $result['gross_amount']);
        $this->assertSame(200, $result['vatable_sales']);
        $body['items'][0]['amount'] = 1;
        try {
            $contract->calculateDocument($body, true);
            $this->fail('Tampered API item was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('line_items.0.line_total', $e->errors());
        }
        $this->assertSame($contract->canonicalHash(['a' => 1, 'b' => 2]), $contract->canonicalHash(['b' => 2, 'a' => 1]));
    }

    public function test_withholding_is_computed_on_the_tax_exclusive_base_and_tampering_is_rejected(): void
    {
        // Synthetic funds satisfy the configurable minimum-zero wallet gate.
        DB::transaction(fn () => app(\App\Services\MerchantWallet::class)->credit('m', 600, (string) \Illuminate\Support\Str::uuid(), 'Synthetic settled test funds', 'test-fixture'));
        $owner = $this->user('merchant');
        DB::table('merchants')->where('id', 'm')->update(['merchant_account_id' => $owner->id]);
        $body = ['invoice_number' => 'WITHHOLD-1', 'document_type' => 'sales_invoice', 'status' => 'draft', 'buyer_name' => 'Buyer', 'issue_date' => '2026-10-16', 'line_items' => [['line_number' => 1, 'description' => 'Service', 'quantity' => 2, 'unit_price' => 11200, 'line_total' => 22400, 'tax_type' => 'vatable']], 'withholding_pct' => 2, 'withholding_tax' => 448];
        $this->withToken($owner->api_token)->postJson('/api/business-invoices', $body)->assertUnprocessable()->assertJsonValidationErrors(['withholding_tax']);
        $body['withholding_tax'] = 400;
        $this->postJson('/api/business-invoices', $body)->assertCreated();
        $this->assertDatabaseHas('business_invoices', ['withholding_tax' => 4, 'amount_due' => 220, 'total_amount' => 224]);
    }

    public function test_internal_schema_upgrade_preserves_previous_preparation_evidence(): void
    {
        $this->invoice();
        DB::table('eis_document_outbox')->insert(['id' => 'old', 'merchant_id' => 'm', 'document_source' => 'invoice', 'document_id' => 'i', 'internal_schema' => 'NUERS-normalized-document-1', 'encrypted_document' => Crypt::encryptString('original-evidence'), 'document_hash' => hash('sha256', 'original-evidence'), 'status' => 'blocked_pending_api', 'blockers' => '[]', 'prepared_at' => now()]);
        app(EisDocumentReadiness::class)->prepare('invoice', 'i');
        $this->assertSame(2, DB::table('eis_document_outbox')->count());
        $this->assertSame('original-evidence', Crypt::decryptString(DB::table('eis_document_outbox')->where('id', 'old')->value('encrypted_document')));
        $this->assertSame(0, DB::table('eis_transmissions')->count());
    }

    public function test_issuance_rolls_back_if_durable_preparation_cannot_commit(): void
    {
        // Synthetic funds satisfy the configurable minimum-zero wallet gate.
        DB::transaction(fn () => app(\App\Services\MerchantWallet::class)->credit('m', 600, (string) \Illuminate\Support\Str::uuid(), 'Synthetic settled test funds', 'test-fixture'));
        $owner = $this->user('merchant');
        DB::table('merchants')->where('id', 'm')->update(['merchant_account_id' => $owner->id]);
        $this->partialMock(BirCompliance::class, function ($mock) {
            $mock->shouldReceive('assertCanIssue')->once()->andReturn(['permit_to_issue_number' => 'SYNTHETIC-PTI', 'esp_accreditation_number' => 'SYNTHETIC-ESP']);
        });
        $this->mock(\App\Services\InvoiceSequence::class, function ($m) {
            $m->shouldReceive('reserve')->once()->andReturn(['number' => 'ATOMIC-1','document_registration' => ['system_type' => 'CAS','ac_number' => 'SYNTHETIC-AC','authority_issued_at' => '2020-01-01']]);
        });
        $this->mock(EisDocumentReadiness::class, function ($mock) {
            $mock->shouldReceive('prepare')->once()->andThrow(new \RuntimeException('Synthetic outbox failure'));
        });
        $body = ['invoice_number' => 'ATOMIC-1', 'document_type' => 'sales_invoice', 'status' => 'issued', 'buyer_name' => 'Buyer', 'issue_date' => '2026-10-16', 'line_items' => [['line_number' => 1, 'description' => 'Service', 'quantity' => 1, 'unit_price' => 11200, 'line_total' => 11200, 'tax_type' => 'vatable']]];
        $this->withToken($owner->api_token)->postJson('/api/business-invoices', $body)->assertStatus(500);
        $this->assertSame(0, DB::table('business_invoices')->count());
        $this->assertSame(0, DB::table('business_invoice_items')->count());
        $this->assertSame(0, DB::table('eis_document_outbox')->count());
    }

    public function test_legacy_items_without_line_totals_are_derived_only_when_all_document_tax_totals_reconcile(): void
    {
        $this->receipt();
        DB::table('transaction_receipts')->where('id', 'ra')->update(['items' => json_encode([['description' => 'Service', 'qty' => 1, 'unit_price' => 100, 'tax_type' => 'vatable']])]);
        $result = app(EisDocumentReadiness::class)->inspect('receipt', 'ra');
        $this->assertTrue($result['local_validation_passed']);
        $this->assertSame('vat_exclusive_reconciled', $result['model']['line_price_basis']);
        $this->assertSame(11200, $result['model']['items'][0]['line_total_centavos']);
        DB::table('transaction_receipts')->where('id', 'ra')->update(['items' => json_encode([['description' => 'Service', 'qty' => 1, 'unit_price' => 112, 'tax_type' => 'vatable']])]);
        $result = app(EisDocumentReadiness::class)->inspect('receipt', 'ra');
        $this->assertTrue($result['local_validation_passed']);
        $this->assertSame('vat_inclusive_reconciled', $result['model']['line_price_basis']);
        DB::table('transaction_receipts')->where('id', 'ra')->update(['items' => json_encode([['description' => 'Service', 'qty' => 1, 'unit_price' => 1, 'tax_type' => 'vatable']])]);
        $this->assertFalse(app(EisDocumentReadiness::class)->inspect('receipt', 'ra')['local_validation_passed']);
    }

    public function test_same_taxpayer_document_cannot_be_counted_twice_through_duplicate_registry_accounts(): void
    {
        $this->receipt();
        $this->invoice('i', 'INV-1');
        DB::table('merchants')->insert(['id' => 'duplicate-registry', 'business_name' => 'Another registry record', 'tin' => '123456789']);
        DB::table('business_invoices')->where('id', 'i')->update(['merchant_id' => 'duplicate-registry']);
        $this->withToken($this->admin->api_token)->getJson('/api/transaction-workspace')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.source_record_count', 2);
        $this->getJson('/api/operations-report?mode=sales')->assertOk()->assertJsonPath('summary.sales', 0);
        $this->assertFalse(app(EisDocumentReadiness::class)->inspect('invoice', 'i')['local_validation_passed']);
    }

    public function test_legacy_profile_cannot_change_registered_taxpayer_identity(): void
    {
        $owner = $this->user('merchant');
        DB::table('merchants')->where('id', 'm')->update(['merchant_account_id' => $owner->id]);
        $this->withToken($owner->api_token)->putJson('/api/business-accounts/current', ['business_name' => 'Current name', 'tin' => '999999999'])->assertUnprocessable();
        $this->assertDatabaseHas('merchants', ['id' => 'm', 'tin' => '123456789']);
    }

    public function test_inconsistent_legacy_financial_records_are_excluded_from_the_sales_draft(): void
    {
        $this->receipt();
        $this->invoice();
        DB::table('business_invoices')->where('id', 'i')->update(['total_amount' => 999]);
        $this->withToken($this->admin->api_token)->getJson('/api/operations-report?mode=sales')->assertOk()->assertJsonPath('summary.sales', 112)->assertJsonPath('summary.excluded_count', 1);
        $result = app(EisDocumentReadiness::class)->inspect('invoice', 'i');
        $this->assertFalse($result['financial_validation_passed']);
        $this->assertFalse($result['can_transmit']);
    }

    public function test_evidence_can_resolve_a_missing_legacy_branch_without_modifying_the_original_invoice(): void
    {
        $this->invoice();
        DB::table('business_invoices')->where('id', 'i')->update(['seller_branch_code' => null]);
        $before = DB::table('business_invoices')->where('id', 'i')->first();
        app(EisDocumentReadiness::class)->prepare('invoice', 'i');
        $body = ['branch_code' => '00000', 'evidence_reference' => 'Synthetic original COR evidence #1', 'revision' => 0];
        $this->withToken($this->admin->api_token)->postJson('/api/eis-readiness/invoice/i/branch-review', $body)->assertOk()->assertJsonPath('local_validation_passed', true)->assertJsonPath('can_transmit', false)->assertJsonPath('source_review_revision', 1);
        $this->assertEquals($before, DB::table('business_invoices')->where('id', 'i')->first());
        $this->assertSame(2, DB::table('eis_document_outbox')->count());
        $this->assertSame(1, DB::table('eis_document_branch_reviews')->count());
        $this->postJson('/api/eis-readiness/invoice/i/branch-review', $body)->assertConflict();
        DB::table('business_invoices')->where('id', 'i')->update(['total_amount' => 999]);
        $this->getJson('/api/transaction-workspace')->assertOk()->assertJsonPath('data.0.submission_applicability', 'source_evidence_unverified');
        $this->assertFalse(app(EisDocumentReadiness::class)->inspect('invoice', 'i')['local_validation_passed']);
    }

    public function test_branch_review_rejects_evidence_conflicting_with_an_embedded_original_branch(): void
    {
        $this->invoice();
        DB::table('business_invoices')->where('id', 'i')->update(['seller_branch_code' => null, 'merchant_tin' => '12345678900001']);
        $this->withToken($this->admin->api_token)->postJson('/api/eis-readiness/invoice/i/branch-review', ['branch_code' => '00002', 'evidence_reference' => 'Synthetic conflicting evidence', 'revision' => 0])->assertUnprocessable();
        $this->assertDatabaseCount('eis_document_branch_reviews', 0);
    }
}
