<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BirCompliance;
use App\Services\DocumentPdf;
use App\Services\HistoricalBranchEvidence;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class MerchantSalesReportTest extends TestCase
{
    private User $owner;

    private string $merchant;

    private string $otherMerchant;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array', 'app.timezone' => 'UTC', 'notifications.dispatch_enabled' => false]);
        DB::purge('sqlite');
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        [$this->owner, $this->merchant] = $this->account('123456789');
        [$this->other, $this->otherMerchant] = $this->account('987654321');
    }

    private function account(string $tin, string $role = 'merchant'): array
    {
        $u = User::factory()->create(['api_token' => Str::random(64)]);
        DB::table('profiles')->insert(['id' => $u->id, 'email' => $u->email, 'role' => $role]);
        $m = (string) Str::uuid();
        DB::table('merchants')->insert(['id' => $m, 'merchant_account_id' => $u->id, 'business_name' => 'Synthetic Sales Merchant', 'tin' => $tin, 'status' => 'active']);

        return [$u, $m];
    }

    private function invoice(string $number = 'QA-INV-1', array $v = [], ?string $merchant = null): string
    {
        $id = (string) Str::uuid();
        $m = $merchant ?? $this->merchant;
        $r = [...['id' => $id, 'merchant_id' => $m, 'invoice_number' => $number, 'document_type' => 'vat_invoice', 'vat_registered' => true, 'status' => 'issued', 'merchant_name' => 'Source Name', 'merchant_tin' => $m === $this->merchant ? '123456789' : '987654321', 'merchant_address' => 'Original Address', 'seller_branch_code' => '00000', 'buyer_name' => 'Original Buyer', 'buyer_tin' => '112233445', 'issue_date' => '2026-10-16', 'total_amount' => 112, 'subtotal_amount' => 100, 'taxable_amount' => 100, 'vat_amount' => 12, 'created_at' => now(), 'updated_at' => now()], ...$v];
        DB::table('business_invoices')->insert($r);
        DB::table('business_invoice_items')->insert(['id' => (string) Str::uuid(), 'invoice_id' => $id, 'merchant_id' => $m, 'description' => 'Synthetic Service', 'quantity' => 1, 'unit_price' => 112, 'line_total' => 112, 'vat_amount' => 12, 'taxable_amount' => 100, 'tax_type' => 'vatable']);

        return $id;
    }

    private function receipt(string $number, string $at, string $type = 'sales_invoice'): string
    {
        $id = (string) Str::uuid();
        DB::table('transaction_receipts')->insert(['id' => $id, 'merchant_id' => $this->merchant, 'receipt_number' => $number, 'merchant_name' => 'Source Name', 'merchant_tin' => '123456789', 'merchant_address' => 'Address', 'seller_branch_code' => '00000', 'document_type' => $type, 'status' => 'issued', 'issued_at' => $at, 'total_due' => 112, 'vat_amount' => 12, 'vatable_sales' => 100, 'items' => json_encode([['description' => 'Synthetic Service', 'quantity' => 1, 'unit_price' => 112, 'line_total' => 112, 'tax_type' => 'vatable']])]);

        return $id;
    }

    private function url(string $extra = ''): string
    {
        return '/api/merchant/sales-reports?from=2026-10-01&to=2026-10-31'.$extra;
    }

    public function test_report_is_owned_authenticated_and_uses_historical_buyer_snapshots(): void
    {
        $this->getJson($this->url())->assertUnauthorized();
        $this->invoice();
        $this->invoice('FOREIGN', merchant: $this->otherMerchant);
        $this->withToken($this->owner->api_token)->getJson($this->url())->assertOk()->assertJsonPath('count', 1)->assertJsonPath('totals.gross', 11200)->assertJsonPath('totals.net', 10000)->assertJsonPath('totals.vat', 1200)->assertJsonPath('data.0.customer', 'Original Buyer')->assertJsonPath('data.0.tin', '112233445')->assertJsonPath('data.0.customer_type', 'unclassified')->assertHeader('Cache-Control', 'no-store, private');
        [$u] = $this->account('555666777', 'client');
        $this->withToken($u->api_token)->getJson($this->url())->assertForbidden();
        $this->withToken($this->other->api_token)->getJson($this->url('&search=QA-INV'))->assertJsonPath('count', 0);
    }

    public function test_sales_exclude_payment_receipts_drafts_cancelled_invalid_sources_and_duplicate_identities(): void
    {
        $this->invoice();
        $this->invoice('DRAFT', ['status' => 'draft']);
        $this->invoice('VOID', ['status' => 'voided']);
        $this->invoice('BROKEN', ['total_amount' => 113]);
        $this->invoice('DUP');
        $this->receipt('DUP', '2026-10-16 04:00:00');
        $this->receipt('PAYMENT', '2026-10-16 04:00:00', 'payment_receipt');
        $this->withToken($this->owner->api_token)->getJson($this->url())->assertOk()->assertJsonPath('count', 1)->assertJsonPath('excluded_count', 4)->assertJsonPath('totals.gross', 11200);
        $r = $this->getJson($this->url('&view=review'))->assertOk()->assertJsonCount(4, 'data');
        foreach ($r->json('data') as $row) {
            $this->assertFalse($row['included']);
            $this->assertSame(0, $row['gross']);
            $this->assertNotEmpty($row['exclusion_reason']);
        }
    }

    public function test_linked_adjustments_are_signed_and_foreign_unlinked_or_invalid_originals_are_excluded(): void
    {
        $original = $this->invoice('ORIGINAL');
        $this->invoice('CREDIT', ['document_type' => 'credit_memo', 'original_invoice_id' => $original]);
        $this->invoice('DEBIT', ['document_type' => 'debit_memo', 'original_invoice_id' => $original]);
        $this->invoice('UNLINKED', ['document_type' => 'credit_memo']);
        $foreign = $this->invoice('FOREIGN', merchant: $this->otherMerchant);
        $this->invoice('FOREIGN-CREDIT', ['document_type' => 'credit_memo', 'original_invoice_id' => $foreign]);
        $invalid = $this->invoice('INVALID-ORIGINAL', ['total_amount' => 113]);
        $this->invoice('INVALID-CREDIT', ['document_type' => 'credit_memo', 'original_invoice_id' => $invalid]);
        $r = $this->withToken($this->owner->api_token)->getJson($this->url())->assertOk()->assertJsonPath('count', 3)->assertJsonPath('totals.gross', 11200)->assertJsonPath('totals.vat', 1200)->assertJsonPath('totals.net', 10000)->assertJsonPath('excluded_count', 4);
        $credit = collect($r->json('data'))->firstWhere('number', 'CREDIT');
        $this->assertSame(-11200, $credit['gross']);
        $this->assertSame(-1200, $credit['vat']);
    }

    public function test_philippine_date_bounds_do_not_shift_invoice_dates_or_use_import_timestamps(): void
    {
        $this->invoice('DATE-ONLY', ['issue_date' => '2026-10-16', 'created_at' => '2026-10-20 16:00:00']);
        $this->invoice('PREVIOUS', ['issue_date' => '2026-10-15']);
        $this->receipt('AT-START', '2026-10-15 16:00:00');
        $this->receipt('BEFORE-START', '2026-10-15 15:59:59');
        $this->receipt('AT-END', '2026-10-16 15:59:59');
        $this->receipt('AFTER-END', '2026-10-16 16:00:00');
        $r = $this->withToken($this->owner->api_token)->getJson('/api/merchant/sales-reports?from=2026-10-16&to=2026-10-16')->assertOk()->assertJsonPath('count', 3)->assertJsonPath('totals.gross', 33600);
        $this->assertEqualsCanonicalizing(['DATE-ONLY', 'AT-START', 'AT-END'], array_column($r->json('data'), 'number'));
        foreach ($r->json('data') as $row) {
            $this->assertSame('2026-10-16', $row['date']);
        }
    }

    public function test_filters_are_literal_validated_and_scoped_to_source_branch_and_current_linked_customer_type(): void
    {
        $id = $this->invoice('A%_1', ['buyer_name' => 'Historic Customer', 'seller_branch_code' => '00001']);
        $this->invoice('OTHER');
        $r = $this->withToken($this->owner->api_token)->postJson('/api/merchant/customers', ['request_id' => (string) Str::uuid(), 'name' => 'Current Profile Name', 'type' => 'business', 'status' => 'active', 'group_name' => 'Standard', 'reason' => 'Synthetic report classification test'])->assertCreated();
        $this->postJson('/api/merchant/customers/'.$r->json('data.id').'/documents', ['document_source' => 'invoice', 'document_id' => $id, 'reason' => 'Synthetic reviewed source linkage'])->assertSuccessful();
        $this->getJson($this->url('&branch=00001&customer_type=business&search='.rawurlencode('%_')))->assertOk()->assertJsonPath('count', 1)->assertJsonPath('data.0.customer', 'Historic Customer');
        $this->getJson($this->url('&customer_type=unclassified'))->assertJsonPath('count', 1)->assertJsonPath('data.0.number', 'OTHER');
        $this->getJson($this->url('&search='.rawurlencode("' OR 1=1 --")))->assertJsonPath('count', 0);
        foreach (['from=2026-10-20&to=2026-10-01', 'from=2020-01-01&to=2026-10-01', 'per_page=999', 'bir=accepted', 'type[]=vat_invoice', 'customer_type=verified'] as $v) {
            $this->getJson('/api/merchant/sales-reports?'.$v)->assertUnprocessable();
        }
    }

    public function test_fake_bir_acknowledgment_is_never_inferred_from_local_issuance(): void
    {
        $id = $this->invoice();
        // A local queue / response label cannot manufacture verified production evidence.
        $this->withToken($this->owner->api_token)->getJson($this->url('&bir=acknowledged'))->assertOk()->assertJsonPath('count', 0)->assertJsonPath('bir.acknowledged', 0);
        $this->getJson($this->url())->assertJsonPath('count', 1)->assertJsonPath('data.0.bir_status', 'not_verified');
        $this->assertSame(0, DB::table('eis_invoice_payloads')->count());
        $this->assertSame(0, DB::table('eis_transmissions')->count());
    }

    public function test_pagination_does_not_change_totals_and_exports_include_all_matching_records_and_exclusions(): void
    {
        for ($n = 1; $n <= 12; $n++) {
            $this->invoice('REPORT-'.$n);
        }
        $this->invoice('EXCLUDED', ['total_amount' => 113]);
        $this->invoice('NOT-IN-PERIOD', ['issue_date' => '2026-09-01']);
        $this->invoice('FOREIGN', merchant: $this->otherMerchant);
        $r = $this->withToken($this->owner->api_token)->getJson($this->url('&page=2'))->assertOk()->assertJsonPath('count', 12)->assertJsonPath('totals.gross', 134400)->assertJsonCount(2, 'data');
        $csv = $this->get('/api/merchant/sales-reports/export?from=2026-10-01&to=2026-10-31&format=csv')->assertOk()->assertDownload('NUERS-Sales-Report-2026-10-01-to-2026-10-31.csv')->getContent();
        $this->assertStringContainsString('REPORT-12', $csv);
        $this->assertStringContainsString('EXCLUDED', $csv);
        $this->assertStringNotContainsString('NOT-IN-PERIOD', $csv);
        $this->assertStringNotContainsString('FOREIGN', $csv);
        $this->assertSame($r->json('snapshot_hash'), DB::table('eis_audit_events')->where('event', 'merchant.sales_report.exported')->get()->map(fn ($r) => json_decode($r->details, true))->last()['snapshot_hash']);
        $this->assertTrue(app(BirCompliance::class)->auditIntegrity());
    }

    public function test_csv_and_excel_protect_against_formula_injection_and_preserve_tin_as_text(): void
    {
        $this->invoice('FORMULA', ['buyer_name' => ' =HYPERLINK("https://example.test")', 'buyer_tin' => '001234567']);
        $this->withToken($this->owner->api_token);
        $csv = $this->get('/api/merchant/sales-reports/export?from=2026-10-01&to=2026-10-31&format=csv')->assertOk()->getContent();
        $this->assertStringContainsString("' =HYPERLINK", $csv);
        $xlsx = $this->get('/api/merchant/sales-reports/export?from=2026-10-01&to=2026-10-31&format=xlsx')->assertOk()->getContent();
        $this->assertStringStartsWith('PK', $xlsx);
        $path = tempnam(sys_get_temp_dir(), 'sales-test-');
        file_put_contents($path, $xlsx);
        $zip = new ZipArchive();
        $zip->open($path);
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($path);
        $this->assertStringContainsString('001234567', $xml);
        $this->assertStringNotContainsString('<f>', $xml);
        $this->assertStringContainsString('t="inlineStr"', $xml);
        $this->assertNotFalse(simplexml_load_string($xml));
    }

    public function test_pdf_uses_authorized_filtered_rows_and_escapes_source_html(): void
    {
        $this->invoice('SAFE', ['buyer_name' => '<script>bad()</script>']);
        $this->mock(DocumentPdf::class, function ($mock) {
            $mock->shouldReceive('renderHtml')->once()->withArgs(fn ($html) => str_contains($html, '&lt;script&gt;bad()&lt;/script&gt;') && ! str_contains($html, '<script>bad()') && str_contains($html, 'SAFE') && str_contains($html, 'Internal sales report'))->andReturn('%PDF-synthetic-export-test');
        });
        $this->withToken($this->owner->api_token)->get('/api/merchant/sales-reports/export?from=2026-10-01&to=2026-10-31&format=pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_non_vat_exempt_zero_rated_and_mixed_bases_remain_distinct_to_the_centavo(): void
    {
        foreach (['non_vat', 'vat_exempt', 'zero_rated'] as $tax) {
            $id = $this->invoice('TAX-'.$tax, ['document_type' => $tax === 'non_vat' ? 'non_vat_invoice' : ($tax === 'vat_exempt' ? 'vat_exempt_invoice' : 'zero_rated_invoice'), 'vat_registered' => $tax !== 'non_vat', 'total_amount' => 112, 'vat_amount' => 0, 'taxable_amount' => 0, 'vat_exempt_amount' => $tax === 'zero_rated' ? 0 : 112, 'zero_rated_amount' => $tax === 'zero_rated' ? 112 : 0]);
            DB::table('business_invoice_items')->where('invoice_id', $id)->update(['tax_type' => $tax, 'vat_amount' => 0, 'taxable_amount' => 0]);
        }
        $r = $this->withToken($this->owner->api_token)->getJson($this->url())->assertOk()->assertJsonPath('count', 3)->assertJsonPath('totals.gross', 33600)->assertJsonPath('totals.non_vat', 11200)->assertJsonPath('totals.exempt', 11200)->assertJsonPath('totals.zero', 11200)->assertJsonPath('totals.vat', 0)->assertJsonPath('totals.net', 33600);
        foreach ($r->json('data') as $row) {
            $this->assertSame($row['gross'], $row['vatable'] + $row['zero'] + $row['exempt'] + $row['non_vat'] + $row['vat']);
        }
    }

    public function test_stale_historical_branch_evidence_is_not_reported_as_verified_source_branch(): void
    {
        $id = $this->invoice('NO-BRANCH', ['seller_branch_code' => null]);
        app(HistoricalBranchEvidence::class)->record('invoice', $id, '00009', 'Synthetic original branch evidence', 0, $this->owner->id);
        $this->withToken($this->owner->api_token)->getJson($this->url('&branch=00009'))->assertOk()->assertJsonPath('count', 1)->assertJsonPath('data.0.branch', '00009');
        DB::table('business_invoices')->where('id', $id)->update(['buyer_name' => 'Changed original snapshot']);
        $this->getJson($this->url('&branch=00009'))->assertOk()->assertJsonPath('count', 0);
        $this->getJson($this->url('&branch=unrecorded'))->assertOk()->assertJsonPath('count', 1)->assertJsonPath('data.0.branch', null);
    }

    public function test_oversized_reports_require_narrower_filters_instead_of_silent_truncation(): void
    {
        $rows = [];
        for ($n = 0; $n < 2001; $n++) {
            $rows[] = ['id' => 'bounded-'.$n, 'merchant_name' => 'Synthetic bounded report', 'buyer_name' => 'Synthetic Buyer', 'merchant_id' => $this->merchant, 'invoice_number' => 'BOUNDED-'.$n, 'issue_date' => '2026-10-16', 'document_type' => 'vat_invoice', 'status' => 'issued'];
        }
        foreach (array_chunk($rows, 200) as $batch) {
            DB::table('business_invoices')->insert($batch);
        }
        $this->withToken($this->owner->api_token)->getJson($this->url())->assertUnprocessable()->assertSee('2,000 source documents');
        $this->getJson('/api/merchant/sales-reports/export?from=2026-10-01&to=2026-10-31&format=csv')->assertUnprocessable();
    }

    public function test_receipt_with_multiple_payment_events_keeps_one_sales_row_and_correct_preview_identifier(): void
    {
        $id = $this->receipt('SOURCE-INVOICE', '2026-10-16 04:00:00');
        foreach (['payment-1', 'payment-2'] as $n) {
            DB::table('merchant_transactions')->insert(['id' => $n, 'merchant_id' => $this->merchant, 'receipt_id' => $id, 'transaction_ref' => $n, 'status' => 'completed', 'amount' => 56, 'created_at' => now()]);
        }
        $r = $this->withToken($this->owner->api_token)->getJson($this->url())->assertOk()->assertJsonPath('count', 1)->assertJsonPath('totals.gross', 11200)->assertJsonPath('data.0.document_id', $id);
        $this->getJson('/api/merchant/records/'.rawurlencode($r->json('data.0.id')).'?mode=documents')->assertOk()->assertJsonPath('document.number', 'SOURCE-INVOICE');
        $this->withToken($this->other->api_token)->getJson('/api/merchant/records/'.rawurlencode($r->json('data.0.id')).'?mode=documents')->assertNotFound();
    }

    public function test_export_budget_persists_when_default_cache_is_request_local_array(): void
    {
        $this->invoice();
        $this->withToken($this->owner->api_token);
        for ($n = 0; $n < 5; $n++) {
            $this->get('/api/merchant/sales-reports/export?from=2026-10-01&to=2026-10-31&format=csv')->assertOk();
        }
        $this->get('/api/merchant/sales-reports/export?from=2026-10-01&to=2026-10-31&format=csv')->assertStatus(429);
        $this->assertSame(5, DB::table('eis_audit_events')->where('event', 'merchant.sales_report.exported')->count());
        $this->assertSame(5, Cache::store('database')->get('merchant-sales-export:'.hash('sha256', $this->merchant)));
    }

    public function test_another_business_with_same_seller_identity_cannot_hide_owned_sales_or_preview(): void
    {
        $id = $this->invoice('SHARED-NUMBER');
        $other = $this->invoice('SHARED-NUMBER', ['merchant_tin' => '123456789'], $this->otherMerchant);
        DB::table('business_invoice_items')->where('invoice_id', $other)->update(['invoice_id' => '00000000-0000-4000-8000-000000000000']);
        DB::table('business_invoices')->where('id', $other)->update(['id' => '00000000-0000-4000-8000-000000000000']);
        $r = $this->withToken($this->owner->api_token)->getJson($this->url())->assertOk()->assertJsonPath('count', 1)->assertJsonPath('data.0.document_id', $id);
        $this->getJson('/api/merchant/records/'.rawurlencode($r->json('data.0.id')).'?mode=documents')->assertOk()->assertJsonPath('record.record_id', $id);
    }

    public function test_hundreds_of_source_receipts_use_bounded_queries_and_preserve_report_totals(): void
    {
        for ($n = 0; $n < 341; $n++) {
            $this->receipt('BATCH-'.$n, '2026-10-16 04:00:00');
        }
        $this->invoice('BROKEN-BATCH', ['total_amount' => 113]);
        $this->invoice('FOREIGN-BATCH', merchant: $this->otherMerchant);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $r = $this->withToken($this->owner->api_token)->getJson($this->url())
            ->assertOk()->assertJsonPath('count', 341)->assertJsonPath('excluded_count', 1)
            ->assertJsonPath('totals.gross', 3819200)->assertJsonPath('totals.vat', 409200)
            ->assertJsonPath('pagination.total', 341)->assertJsonCount(10, 'data');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThan(150, $queries, 'Report queries must not grow once per receipt.');
        $this->assertStringNotContainsString('FOREIGN-BATCH', $r->getContent());
    }

    public function test_dashboard_batches_more_than_500_receipts_without_losing_rows_or_tenant_scope(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-16 04:00:00', 'UTC'));
        for ($n = 0; $n < 541; $n++) {
            $this->receipt('DASHBOARD-'.$n, '2026-10-16 04:00:00');
        }
        $this->invoice('FOREIGN-DASHBOARD', merchant: $this->otherMerchant);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $r = $this->withToken($this->owner->api_token)->getJson('/api/merchant/overview?days=7&merchant_id='.$this->otherMerchant)
            ->assertOk()->assertJsonPath('account.id', $this->merchant)
            ->assertJsonPath('documents.issued', 541)->assertJsonPath('current.sales', 60592)
            ->assertJsonPath('current.invoices', 541)->assertJsonCount(8, 'recent');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThan(150, $queries, 'Dashboard validation must not query each source separately.');
        $this->assertStringNotContainsString('FOREIGN-DASHBOARD', $r->getContent());
    }

    public function test_batch_financial_validation_matches_full_inspection_and_reads_fresh_owned_items(): void
    {
        $id = $this->invoice();
        $receipt = $this->receipt('BATCH-RECEIPT', '2026-10-16 04:00:00');
        $rows = app(\App\Services\IssuedDocumentLedger::class)->query($this->merchant)->get();
        $service = app(\App\Services\EisDocumentReadiness::class);
        $batch = $service->financialBatch($rows, $this->merchant);
        foreach ($rows as $row) {
            $full = $service->inspect($row->document_source, $row->document_id);
            $item = $batch[$row->document_source.':'.$row->document_id];
            $this->assertSame($full['financial_validation_passed'], $item['financial_validation_passed']);
            $this->assertSame($full['model']['items'], $item['items']);
        }
        DB::table('business_invoice_items')->where('invoice_id', $id)->update(['merchant_id' => $this->otherMerchant]);
        $fresh = $service->financialBatch($rows, $this->merchant);
        $this->assertFalse($fresh['invoice:'.$id]['financial_validation_passed']);
        $this->assertTrue($fresh['receipt:'.$receipt]['financial_validation_passed']);
        $foreign = $this->invoice('FOREIGN-FINANCIAL', merchant: $this->otherMerchant);
        $foreignRows = app(\App\Services\IssuedDocumentLedger::class)->query($this->otherMerchant)->get();
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $service->financialBatch($foreignRows, $this->merchant);
    }
}
