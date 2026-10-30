<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class InvoiceCenterAndSubscriptionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite','database.connections.sqlite.database' => ':memory:', 'app.timezone' => 'Asia/Manila']);
        DB::purge('sqlite');
        foreach (glob(database_path('migrations/*.php')) as $migrationFile) {
            (require $migrationFile)->up();
        }
    }
    private function user(string $role): User
    {
        $u = User::factory()->create(['api_token' => Str::random(64)]);
        DB::table('profiles')->insert(['id' => $u->id,'email' => $u->email,'role' => $role]);
        return $u;
    }
    private function business(string $id, ?User $user = null): void
    {
        DB::table('merchants')->insert(['id' => $id,'business_name' => 'Business '.$id,'tin' => '123456789','merchant_account_id' => $user?->id,'vat_registered' => true]);
    }
    private function receipt(string $id, string $merchant, string $status = 'issued', string $date = '2026-10-15 10:00:00'): void
    {
        DB::table('transaction_receipts')->insert(['id' => $id,'merchant_id' => $merchant,'receipt_number' => 'OR-'.$id,'merchant_name' => 'Business '.$merchant,'merchant_tin' => '123456789','document_type' => 'official_receipt','status' => $status,'issued_at' => $date,'gross_amount' => 500,'total_due' => 500,'items' => json_encode([['description' => 'Service','qty' => 2,'unit_price' => 250]])]);
    }
    public function test_document_endpoints_require_role_and_scope_every_lookup(): void
    {
        $this->getJson('/api/invoice-center')->assertUnauthorized();
        $client = $this->user('client');
        $this->withToken($client->api_token)->getJson('/api/invoice-center')->assertForbidden();
        $owner = $this->user('merchant');
        $this->business('own', $owner);
        $this->business('other');
        $this->receipt('owned', 'own');
        $this->receipt('foreign', 'other');
        $this->withToken($owner->api_token)->getJson('/api/invoice-center')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('summary.total', 1)->assertJsonPath('data.0.id', 'owned');
        $this->withToken($owner->api_token)->getJson('/api/invoice-center/receipt/foreign')->assertNotFound();
        $this->withToken($owner->api_token)->getJson('/api/invoice-center/receipt/owned')->assertOk()->assertJsonPath('document.items.0.amount', 500)->assertJsonPath('document.authority_status', 'BIR acceptance not verified');
    }
    public function test_server_filters_pagination_counts_and_empty_results(): void
    {
        $admin = $this->user('admin');
        $this->business('a');
        $this->receipt('a', 'a');
        $this->receipt('b', 'a', 'cancelled');
        $this->receipt('old', 'a', 'issued', '2026-10-14 10:00:00');
        $this->withToken($admin->api_token)->getJson('/api/invoice-center?per_page=1&from=2026-10-15&to=2026-10-15')->assertOk()->assertJsonPath('total', 2)->assertJsonPath('last_page', 2)->assertJsonPath('summary.cancelled', 1)->assertJsonCount(1, 'data');
        $this->withToken($admin->api_token)->getJson('/api/invoice-center?type=cancelled')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.record_status', 'cancelled');
        $this->withToken($admin->api_token)->getJson('/api/invoice-center?search=notfound')->assertOk()->assertJsonPath('total', 0)->assertJsonCount(0, 'data');
        $this->withToken($admin->api_token)->getJson('/api/invoice-center?per_page=1000')->assertUnprocessable();
    }
    public function test_export_filters_exact_business_and_inclusive_dates_across_pages_and_sources(): void
    {
        $admin = $this->user('admin');
        $this->business('a');
        $this->business('b');
        // Both businesses deliberately share a TIN; selection must use the registered business ID.
        for ($n = 0; $n < 12; $n++) {
            $this->receipt('included-'.$n, 'a', 'issued', '2026-10-15 23:59:59');
        }
        $this->receipt('start', 'a', 'issued', '2026-10-15 00:00:00');
        $this->receipt('before', 'a', 'issued', '2026-10-14 23:59:59');
        $this->receipt('after', 'a', 'issued', '2026-10-16 00:00:00');
        $this->receipt('foreign', 'b');
        DB::table('business_invoices')->insert(['id' => 'invoice-a','merchant_id' => 'a','invoice_number' => 'SI-INCLUDED','buyer_name' => 'Test buyer','merchant_name' => 'Old business name','merchant_tin' => 'old-tin','document_type' => 'sales_invoice','status' => 'issued','issue_date' => '2026-10-15','total_amount' => 100]);
        $response = $this->withToken($admin->api_token)->get('/api/invoice-center/export?merchant_id=a&from=2026-10-15&to=2026-10-15&per_page=1&page=2');
        $response->assertOk()->assertHeader('X-Export-Count', '14')->assertDownload('NUERS-documents-2026-10-15-to-2026-10-15.csv');
        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertCount(15, explode("\n", trim($csv)));
        $this->assertStringContainsString('OR-included-11', $csv);
        $this->assertStringContainsString('OR-start', $csv);
        $this->assertStringContainsString('SI-INCLUDED', $csv);
        foreach (['OR-before','OR-after','OR-foreign'] as $excluded) {
            $this->assertStringNotContainsString($excluded, $csv);
        }
        $this->withToken($admin->api_token)->getJson('/api/invoice-center?merchant_id=a&from=2026-10-15&to=2026-10-15')->assertOk()->assertJsonPath('total', 14);
    }

    public function test_combined_filters_and_formatted_tin_search_match_export(): void
    {
        $admin = $this->user('admin');
        $this->business('a');
        $this->business('b');
        foreach (['included','wrong-category','wrong-status','outside-date'] as $id) {
            $this->receipt($id, 'a');
        }
        $this->receipt('foreign', 'b');
        DB::table('transaction_receipts')->update(['merchant_tin' => '404758136000','sale_category' => 'senior_citizen']);
        DB::table('transaction_receipts')->where('id', 'wrong-category')->update(['sale_category' => 'regular']);
        DB::table('transaction_receipts')->where('id', 'wrong-status')->update(['status' => 'cancelled']);
        DB::table('transaction_receipts')->where('id', 'outside-date')->update(['issued_at' => '2026-10-14 23:59:59']);
        $query = http_build_query(['merchant_id' => 'a','search' => '404-758-136-000','sale_category' => 'senior_citizen','status' => 'valid','type' => 'receipts','from' => '2026-10-15','to' => '2026-10-15']);
        $this->withToken($admin->api_token)->getJson('/api/invoice-center?'.$query)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', 'included');
        $csv = $this->get('/api/invoice-center/export?'.$query)->assertOk()->assertHeader('X-Export-Count', '1')->streamedContent();
        $this->assertStringContainsString('OR-included', $csv);
        foreach (['wrong-category','wrong-status','outside-date','foreign'] as $excluded) {
            $this->assertStringNotContainsString('OR-'.$excluded, $csv);
        }
        DB::table('transaction_receipts')->where('id', 'included')->update(['merchant_tin' => '404-758-136-000']);
        $this->getJson('/api/invoice-center?'.str_replace('404-758-136-000', '404758136000', $query))->assertOk()->assertJsonPath('total', 1);
    }

    public function test_export_and_business_dropdown_respect_access_scope(): void
    {
        $this->getJson('/api/invoice-center/export')->assertUnauthorized();
        $client = $this->user('client');
        $this->withToken($client->api_token)->getJson('/api/invoice-center/export')->assertForbidden();
        $owner = $this->user('merchant');
        $this->business('own', $owner);
        $this->business('other');
        $this->business('empty');
        $this->receipt('owned', 'own');
        $this->receipt('foreign', 'other');
        $this->withToken($owner->api_token)->getJson('/api/invoice-center/businesses')->assertOk()->assertJsonCount(1, 'businesses')->assertJsonPath('businesses.0.id', 'own');
        $csv = $this->withToken($owner->api_token)->get('/api/invoice-center/export')->assertOk()->assertHeader('X-Export-Count', '1')->streamedContent();
        $this->assertStringContainsString('OR-owned', $csv);
        $this->assertStringNotContainsString('OR-foreign', $csv);
        $this->withToken($owner->api_token)->getJson('/api/invoice-center/export?merchant_id=other')->assertNotFound();
        $admin = $this->user('admin');
        $this->withToken($admin->api_token)->getJson('/api/invoice-center/businesses')->assertOk()->assertJsonCount(3, 'businesses');
        $this->withToken($admin->api_token)->get('/api/invoice-center/export?merchant_id=empty')->assertOk()->assertHeader('X-Export-Count', '0');
    }

    public function test_export_validates_dates_and_supports_open_ranges_and_type_status_filters(): void
    {
        $admin = $this->user('admin');
        $this->business('a');
        $this->receipt('valid', 'a');
        $this->receipt('cancelled', 'a', 'cancelled');
        $this->withToken($admin->api_token)->getJson('/api/invoice-center/export?from=2026-10-16&to=2026-10-15')->assertUnprocessable();
        $this->withToken($admin->api_token)->getJson('/api/invoice-center/export?from=invalid')->assertUnprocessable();
        $this->withToken($admin->api_token)->getJson('/api/invoice-center/export?merchant_id=unknown')->assertNotFound();
        $this->withToken($admin->api_token)->get('/api/invoice-center/export?to=2026-10-15&type=receipts&status=valid')->assertOk()->assertHeader('X-Export-Count', '1');
        $this->withToken($admin->api_token)->get('/api/invoice-center/export?from=2026-10-15&type=cancelled')->assertOk()->assertHeader('X-Export-Count', '1');
        $empty = $this->withToken($admin->api_token)->get('/api/invoice-center/export?type=invoices')->assertOk()->assertHeader('X-Export-Count', '0')->streamedContent();
        $this->assertCount(1, explode("\n", trim($empty)));
        $this->assertStringContainsString('Document No.', $empty);
    }

    public function test_export_quotes_csv_values_and_neutralizes_spreadsheet_formulas(): void
    {
        $admin = $this->user('admin');
        $this->business('a');
        $this->receipt('formula', 'a');
        DB::table('transaction_receipts')->where('id', 'formula')->update(['receipt_number' => '=1+1','merchant_name' => '  @SUM(1,2)','merchant_tin' => '123,456']);
        $csv = $this->withToken($admin->api_token)->get('/api/invoice-center/export')->assertOk()->streamedContent();
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, substr($csv, 3));
        rewind($stream);
        fgetcsv($stream, 0, ',', '"', '');
        $row = fgetcsv($stream, 0, ',', '"', '');
        fclose($stream);
        $this->assertSame("'=1+1", $row[0]);
        $this->assertSame("'  @SUM(1,2)", $row[3]);
        $this->assertSame('123,456', $row[4]);
    }

    public function test_draft_recomputes_total_preserves_status_and_is_idempotent(): void
    {
        $owner = $this->user('merchant');
        $this->business('own', $owner);
        DB::transaction(fn () => app(\App\Services\MerchantWallet::class)->credit('own', 300, 'synthetic-funding', 'Synthetic test funding', 'test-fixture'));
        $p = ['request_id' => (string)Str::uuid(),'merchant_id' => 'own','document_type' => 'sales_invoice','buyer_name' => 'Buyer','issue_date' => '2026-10-15','vat_inclusive' => true,'items' => [['description' => 'Item','quantity' => 2,'unit_price' => 280]], 'total_amount' => 1,'status' => 'paid'];
        $this->withToken($owner->api_token)->postJson('/api/invoice-center', $p)->assertCreated();
        $row = DB::table('business_invoices')->first();
        $this->assertEquals(560, $row->total_amount);
        $this->assertEquals(60, $row->vat_amount);
        $this->assertSame('draft', $row->status);
        $this->withToken($owner->api_token)->postJson('/api/invoice-center', $p)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame(1, DB::table('business_invoices')->count());
        $p['buyer_name'] = 'Changed';
        $this->withToken($owner->api_token)->postJson('/api/invoice-center', $p)->assertConflict();
        $p['request_id'] = (string)Str::uuid();
        $p['merchant_id'] = 'foreign';
        $this->withToken($owner->api_token)->postJson('/api/invoice-center', $p)->assertNotFound();
        $bir = $this->user('bir');
        $this->withToken($bir->api_token)->postJson('/api/invoice-center', $p)->assertForbidden();
    }
    public function test_one_transaction_offer_is_shared_by_public_and_legacy_endpoints(): void
    {
        foreach (['/api/pricing','/api/subscription-plans'] as $url) {
            $this->getJson($url)->assertOk()->assertJsonCount(1, 'plans')
                ->assertJsonPath('pricing.billing_model', 'per_transaction')->assertJsonPath('pricing.subscription_required', false)
                ->assertJsonPath('pricing.fee_centavos', 300)->assertJsonPath('pricing.minimum_transaction_centavos', 0)
                ->assertJsonPath('pricing.storage_mb', null)->assertJsonPath('pricing.retention_years', 5)
                ->assertJsonPath('pricing.recurring_fee_centavos', 0);
        }
    }
    public function test_legacy_editor_cannot_reintroduce_subscription_tiers(): void
    {
        $this->putJson('/api/admin/subscription-plans/1', [])->assertUnauthorized();
        $merchant = $this->user('merchant');
        $this->withToken($merchant->api_token)->putJson('/api/admin/subscription-plans/1', [])->assertForbidden();
        $bir = $this->user('bir');
        $this->withToken($bir->api_token)->getJson('/api/admin/subscription-plans')->assertForbidden();
        $admin = $this->user('admin');
        $this->withToken($admin->api_token)->putJson('/api/admin/subscription-plans/1', ['fee_centavos' => 200,'storage_mb' => 500])->assertUnprocessable();
        $this->getJson('/api/pricing')->assertJsonPath('pricing.fee_centavos', 300)->assertJsonPath('pricing.storage_mb', null);
    }
    public function test_transaction_fee_boundaries_are_exact_and_flat(): void
    {
        $policy = app(\App\Services\TransactionPricing::class);
        foreach ([0 => 300,1 => 300,49999 => 300,50000 => 300,50001 => 300,500000 => 300] as $amount => $fee) {
            $this->assertSame($fee,$policy->feeForCentavos($amount));
        }
        $this->expectException(\InvalidArgumentException::class);
        $policy->feeForCentavos(-1);
    }
}
