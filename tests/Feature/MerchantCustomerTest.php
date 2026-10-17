<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BirCompliance;
use App\Services\ExpenseCreditEvidence;
use App\Services\HistoricalBranchEvidence;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MerchantCustomerTest extends TestCase
{
    private User $owner;

    private User $other;

    private string $merchant;

    private string $otherMerchant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array', 'app.timezone' => 'Asia/Manila', 'notifications.dispatch_enabled' => false]);
        DB::purge('sqlite');
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        [$this->owner,$this->merchant] = $this->account();
        [$this->other,$this->otherMerchant] = $this->account();
    }

    private function account(string $role = 'merchant'): array
    {
        $u = User::factory()->create(['api_token' => Str::random(64)]);
        DB::table('profiles')->insert(['id' => $u->id, 'email' => $u->email, 'role' => $role]);
        $id = (string) Str::uuid();
        DB::table('merchants')->insert(['id' => $id, 'merchant_account_id' => $u->id, 'business_name' => 'Synthetic Merchant', 'tin' => substr(preg_replace('/\D/', '', $u->api_token).'123456789', 0, 9), 'status' => 'active']);

        return [$u, $id];
    }

    private function body(array $v = []): array
    {
        return [...['request_id' => (string) Str::uuid(), 'name' => 'Juan QA Cruz', 'type' => 'retail', 'status' => 'active', 'group_name' => 'Standard', 'tin' => null, 'email' => 'buyer@example.test', 'phone' => '+639171234567', 'address' => 'Synthetic Address', 'reason' => 'Customer profile test fixture'], ...$v];
    }

    private function create(array $v = []): array
    {
        return $this->withToken($this->owner->api_token)->postJson('/api/merchant/customers', $this->body($v))->assertCreated()->json('data');
    }

    private function source(string $name = 'Juan QA Cruz', string $type = 'vat_invoice', string $status = 'issued', string $number = 'QA-INV-1', ?string $merchant = null): string
    {
        $id = (string) Str::uuid();
        DB::table('business_invoices')->insert(['id' => $id, 'merchant_id' => $merchant ?? $this->merchant, 'invoice_number' => $number, 'document_type' => $type, 'status' => $status, 'merchant_name' => 'Synthetic Merchant', 'merchant_tin' => '123456789', 'merchant_address' => 'Synthetic Address', 'buyer_name' => $name, 'issue_date' => now()->toDateString(), 'total_amount' => 112, 'subtotal_amount' => 100, 'taxable_amount' => 100, 'vat_amount' => 12, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    public function test_empty_summary_is_real_and_requires_owned_merchant(): void
    {
        $this->getJson('/api/merchant/customers')->assertUnauthorized();
        $this->withToken($this->owner->api_token)->getJson('/api/merchant/customers')->assertOk()->assertJsonPath('summary.total', 0)->assertJsonPath('summary.active', 0)->assertJsonCount(0, 'data');
        [$u] = $this->account('client');
        $this->withToken($u->api_token)->getJson('/api/merchant/customers')->assertForbidden();
    }

    public function test_customer_details_and_history_are_encrypted_and_save_is_idempotent(): void
    {
        $body = $this->body();
        $a = $this->withToken($this->owner->api_token)->postJson('/api/merchant/customers', $body)->assertCreated()->json('data');
        $this->postJson('/api/merchant/customers', $body)->assertOk()->assertJsonPath('data.id', $a['id']);
        $body['name'] = 'Changed';
        $this->postJson('/api/merchant/customers', $body)->assertConflict();
        $stored = DB::table('merchant_customers')->first();
        $this->assertStringNotContainsString('Juan', $stored->profile_encrypted);
        $this->assertStringContainsString('Juan', Crypt::decryptString($stored->profile_encrypted));
        $history = DB::table('merchant_customer_history')->first();
        $this->assertStringNotContainsString('buyer@example.test', $history->change_encrypted);
        $this->assertSame(1, DB::table('merchant_customer_history')->count());
        $this->assertSame(0, DB::table('notification_outbox')->count());
        $this->assertTrue(app(BirCompliance::class)->auditIntegrity());
    }

    public function test_tenant_isolation_covers_profiles_search_import_export_and_document_links(): void
    {
        $c = $this->create();
        $foreign = $this->source(merchant: $this->otherMerchant);
        $this->withToken($this->other->api_token)->getJson('/api/merchant/customers/'.$c['id'])->assertNotFound();
        $this->patchJson('/api/merchant/customers/'.$c['id'], $this->body(['revision' => 1]))->assertNotFound();
        $this->getJson('/api/merchant/customers?search=Juan')->assertJsonPath('total', 0);
        $this->get('/api/merchant/customers/export')->assertOk()->assertDontSee('Juan QA Cruz');
        $this->withToken($this->owner->api_token)->postJson('/api/merchant/customers/'.$c['id'].'/documents', ['document_source' => 'invoice', 'document_id' => $foreign, 'reason' => 'QA cross tenant link attempt'])->assertNotFound();
        $this->assertSame(0, DB::table('merchant_customer_documents')->count());
    }

    public function test_prefix_search_filters_dates_and_segments_are_consistent(): void
    {
        $a = $this->create(['type' => 'business', 'tin' => '123-456-789', 'review_reference' => 'QA-BUYER-DETAILS']);
        $this->create(['name' => 'Walk-in Customer', 'email' => null, 'phone' => null, 'type' => 'walk_in', 'group_name' => 'VIP']);
        $r = $this->getJson('/api/merchant/customers?search=cru')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $a['id'])->assertJsonPath('summary.total', 2);
        $this->getJson('/api/merchant/customers?search=123456')->assertJsonPath('total', 1);
        $this->getJson('/api/merchant/customers?search=123-456')->assertJsonPath('total', 1);
        $this->getJson('/api/merchant/customers?type=walk_in&group=VIP&status=active')->assertJsonPath('total', 1);
        $this->getJson('/api/merchant/customers?from=2099-01-01')->assertJsonPath('total', 0);
        $this->assertSame(2, collect($r->json('segments'))->sum('total'));
        $this->getJson('/api/merchant/customers?from=2026-10-20&to=2026-10-01')->assertUnprocessable();
        $this->getJson('/api/merchant/customers?search=x')->assertUnprocessable();
    }

    public function test_validation_rejects_bad_tin_email_phone_and_foreign_branch_but_allows_no_tin(): void
    {
        foreach ([['tin' => '123'], ['tin' => 'abc123456789'], ['email' => 'bad'], ['phone' => '-------'], ['branch_code' => 'FOREIGN'], ['type' => 'verified'], ['name' => '  ']] as $bad) {
            $this->withToken($this->owner->api_token)->postJson('/api/merchant/customers', $this->body($bad))->assertUnprocessable();
        }$c = $this->create(['tin' => null, 'type' => 'walk_in']);
        $this->assertNull($c['tin']);
        $this->assertNull($c['details_reviewed_at']);
    }

    public function test_profile_revision_history_and_review_reset_never_rewrite_originals(): void
    {
        $source = $this->source();
        $before = (array) DB::table('business_invoices')->where('id', $source)->first();
        $c = $this->create(['review_reference' => 'QA-REVIEW-BUYER-DATA']);
        $body = $this->body(['name' => 'Updated Buyer Name', 'revision' => 1, 'review_reference' => null]);
        $this->patchJson('/api/merchant/customers/'.$c['id'], $body)->assertOk()->assertJsonPath('data.revision', 2)->assertJsonPath('data.details_reviewed_at', null);
        $this->patchJson('/api/merchant/customers/'.$c['id'], $body)->assertConflict();
        $this->assertSame($before, (array) DB::table('business_invoices')->where('id', $source)->first());
        $this->assertSame(2, DB::table('merchant_customer_history')->count());
        $this->getJson('/api/merchant/customers/'.$c['id'])->assertJsonCount(2, 'history');
    }

    public function test_linked_metrics_exclude_drafts_payments_voids_and_adjustments_and_relationship_is_unique(): void
    {
        $c = $this->create();
        $s = $this->source();
        $draft = $this->source(status: 'draft', number: 'QA-DRAFT');
        $payment = $this->source(type: 'payment_receipt', number: 'QA-PAYMENT');
        $void = $this->source(status: 'cancelled', number: 'QA-VOID');
        $credit = $this->source(type: 'credit_memo', number: 'QA-CREDIT');
        foreach ([$s, $draft, $payment, $void, $credit] as $id) {
            $this->postJson('/api/merchant/customers/'.$c['id'].'/documents', ['document_source' => 'invoice', 'document_id' => $id, 'reason' => 'Reviewed original buyer relationship'])->assertOk();
        }$this->getJson('/api/merchant/customers')->assertOk()->assertJsonPath('data.0.recorded_invoice_total', 112)->assertJsonPath('data.0.document_count', 4)->assertJsonPath('summary.recent', 1);
        $this->postJson('/api/merchant/customers/'.$c['id'].'/documents', ['document_source' => 'invoice', 'document_id' => $s, 'reason' => 'Idempotent relationship confirmation'])->assertOk();
        $other = $this->create(['name' => 'Other Buyer']);
        $this->postJson('/api/merchant/customers/'.$other['id'].'/documents', ['document_source' => 'invoice', 'document_id' => $s, 'reason' => 'Attempt to reassign original relationship'])->assertConflict();
        $this->assertSame(5, DB::table('merchant_customer_documents')->count());
    }

    public function test_source_picker_is_paginated_and_scoped_and_creating_profile_can_link_without_rewriting(): void
    {
        $id = $this->source();
        $this->source('Other business secret', merchant: $this->otherMerchant);
        $r = $this->withToken($this->owner->api_token)->getJson('/api/merchant/customers/source-buyers')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.document_id', $id);
        $this->create(['source' => ['document_source' => 'invoice', 'document_id' => $id]]);
        $this->getJson('/api/merchant/customers/source-buyers')->assertJsonPath('total', 0);
        $this->assertSame('Juan QA Cruz', DB::table('business_invoices')->where('id', $id)->value('buyer_name'));
    }

    public function test_csv_import_is_atomic_validated_deduplicated_and_retry_safe(): void
    {
        $csv = "name,type,tin,email,phone,address,branch_code,group_name,status\nCSV Buyer,business,123456789,csv@example.test,,,,Standard,active\n";
        $id = (string) Str::uuid();
        $r = $this->withToken($this->owner->api_token)->post('/api/merchant/customers/import', ['file' => UploadedFile::fake()->createWithContent('customers.csv', $csv), 'request_id' => $id], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('created', 1);
        $this->post('/api/merchant/customers/import', ['file' => UploadedFile::fake()->createWithContent('customers.csv', $csv), 'request_id' => $id], ['Accept' => 'application/json'])->assertOk();
        $bad = "name,type,email\nGood Buyer,retail,valid@example.test\nBad Buyer,retail,bad-email\n";
        $this->post('/api/merchant/customers/import', ['file' => UploadedFile::fake()->createWithContent('bad.csv', $bad), 'request_id' => (string) Str::uuid()], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertSame(1, DB::table('merchant_customers')->count());
        $duplicate = "name,type,tin,email\nNew Buyer,retail,,new@example.test\nCSV Buyer,business,123456789,csv@example.test\n";
        $this->post('/api/merchant/customers/import', ['file' => UploadedFile::fake()->createWithContent('duplicates.csv', $duplicate), 'request_id' => (string) Str::uuid()], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertSame(1, DB::table('merchant_customers')->count());
    }

    public function test_export_is_filtered_csv_formula_safe_and_audited(): void
    {
        $this->create(['name' => '=FORMULA QA', 'type' => 'business']);
        $this->create(['name' => 'Excluded Buyer', 'type' => 'walk_in']);
        $r = $this->get('/api/merchant/customers/export?type=business')->assertOk();
        $csv = $r->streamedContent();
        $this->assertStringContainsString("'=FORMULA QA", $csv);
        $this->assertStringNotContainsString('Excluded Buyer', $csv);
        $this->assertSame(1, DB::table('eis_audit_events')->where('event', 'customer.exported')->count());
        $this->getJson('/api/merchant/customers?search='.urlencode("' OR 1=1--"))->assertOk()->assertJsonPath('total', 0);
    }

    public function test_create_invoice_with_customer_uses_owned_active_profile_and_links_draft_without_counting_sales(): void
    {
        $c = $this->create();
        $body = ['invoice_number' => 'QA-CUSTOMER-DRAFT', 'document_type' => 'sales_invoice', 'buyer_name' => $c['name'], 'customer_id' => $c['id'], 'issue_date' => now()->toDateString(), 'status' => 'draft', 'line_items' => [['line_number' => 1, 'description' => 'Test item', 'quantity' => 1, 'unit_price' => 10000, 'line_total' => 10000]]];
        $r = $this->postJson('/api/business-invoices', $body)->assertCreated();
        $this->assertDatabaseHas('merchant_customer_documents', ['customer_id' => $c['id'], 'document_id' => $r->json('invoice.id')]);
        $this->getJson('/api/merchant/customers')->assertJsonPath('data.0.recorded_invoice_total', 0);
        $this->withToken($this->other->api_token)->postJson('/api/business-invoices', [...$body, 'invoice_number' => 'FOREIGN-DRAFT'])->assertUnprocessable();
        $this->withToken($this->owner->api_token)->postJson('/api/business-invoices', [...$body, 'invoice_number' => 'WRONG-BUYER', 'buyer_name' => 'Wrong Buyer'])->assertUnprocessable();
    }

    public function test_inactive_business_cannot_mutate_preserved_customer_records(): void
    {
        $c = $this->create();
        DB::table('merchants')->where('id', $this->merchant)->update(['status' => 'suspended']);
        $this->getJson('/api/merchant/customers/'.$c['id'])->assertOk();
        $this->postJson('/api/merchant/customers', $this->body(['name' => 'Blocked Buyer']))->assertForbidden();
        $this->deleteJson('/api/merchant/customers/'.$c['id'])->assertStatus(405);
    }

    public function test_customer_portability_includes_usable_profile_and_history_without_other_tenant_data(): void
    {
        $c = $this->create();
        $r = $this->get('/api/businesses/'.$this->merchant.'/portability')->assertOk();
        $text = $r->streamedContent();
        $this->assertStringContainsString('merchant_customers', $text);
        $this->assertStringContainsString('Juan QA Cruz', $text);
        $this->assertStringContainsString('merchant_customer_history', $text);
        $this->assertStringNotContainsString('identity_hash', $text);
        $this->withToken($this->other->api_token)->get('/api/businesses/'.$this->merchant.'/portability')->assertNotFound();
    }

    public function test_invalid_utf8_csv_is_rejected_without_partial_profiles(): void
    {
        $this->withToken($this->owner->api_token)->post('/api/merchant/customers/import', ['file' => UploadedFile::fake()->createWithContent('bad-encoding.csv', "name\nInvalid \xff Buyer\n"), 'request_id' => (string) Str::uuid()], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertSame(0, DB::table('merchant_customers')->count());
    }

    private function invoiceBody(array $overrides = []): array
    {
        return [...['request_id' => (string) Str::uuid(), 'invoice_number' => 'QA-'.Str::uuid(), 'document_type' => 'sales_invoice', 'status' => 'draft', 'buyer_name' => 'New Buyer QA', 'buyer_tin' => '987-654-321', 'buyer_email' => 'new-buyer@example.test', 'buyer_phone' => '09171234567', 'buyer_address' => 'Original invoice address', 'buyer_customer_type' => 'business', 'issue_date' => now()->toDateString(), 'line_items' => [['line_number' => 1, 'description' => 'Synthetic item', 'quantity' => 1, 'unit_price' => 10000, 'line_total' => 10000]]], ...$overrides];
    }

    public function test_new_invoice_buyer_is_saved_linked_and_reused_without_overwriting_contacts(): void
    {
        $body = $this->invoiceBody();
        $first = $this->withToken($this->owner->api_token)->postJson('/api/business-invoices', $body)->assertCreated()->assertJsonPath('customer.created', true);
        $customer = $first->json('customer.id');
        $this->getJson('/api/merchant/customers/'.$customer)->assertOk()->assertJsonPath('data.name', $body['buyer_name'])->assertJsonPath('data.tin', '987654321')->assertJsonPath('data.email', $body['buyer_email'])->assertJsonPath('data.phone', $body['buyer_phone'])->assertJsonPath('data.type', 'business')->assertJsonPath('drafts.total', 1)->assertJsonPath('drafts.data.0.id', $first->json('invoice.id'));
        $second = $this->postJson('/api/business-invoices', $this->invoiceBody(['buyer_email' => 'different-invoice-email@example.test', 'buyer_address' => 'New invoice address']))->assertCreated()->assertJsonPath('customer.created', false)->assertJsonPath('customer.id', $customer);
        $this->assertSame(1, DB::table('merchant_customers')->count());
        $this->assertSame(2, DB::table('merchant_customer_documents')->count());
        $this->getJson('/api/merchant/customers/'.$customer)->assertJsonPath('data.email', 'new-buyer@example.test')->assertJsonPath('data.address', 'Original invoice address')->assertJsonPath('drafts.total', 2)->assertJsonPath('data.recorded_invoice_total', 0);
        $this->assertSame('Original invoice address', DB::table('business_invoices')->where('id', $first->json('invoice.id'))->value('buyer_address'));
        $this->assertSame('New invoice address', DB::table('business_invoices')->where('id', $second->json('invoice.id'))->value('buyer_address'));
        $this->assertSame('09171234567', DB::table('business_invoices')->where('id', $first->json('invoice.id'))->value('buyer_phone'));
        $this->assertSame(0, DB::table('notification_outbox')->count());
    }

    public function test_walk_in_without_tin_or_contacts_is_saved_and_reused_without_creating_a_login(): void
    {
        $users = DB::table('users')->count();
        $body = $this->invoiceBody(['buyer_name' => 'Walk-in Buyer QA', 'buyer_tin' => null, 'buyer_email' => null, 'buyer_phone' => null, 'buyer_customer_type' => 'walk_in']);
        $a = $this->withToken($this->owner->api_token)->postJson('/api/business-invoices', $body)->assertCreated()->json('customer.id');
        $this->postJson('/api/business-invoices', [...$body, 'invoice_number' => 'WALKIN-2', 'request_id' => (string) Str::uuid()])->assertCreated()->assertJsonPath('customer.id', $a)->assertJsonPath('customer.created', false);
        $this->getJson('/api/merchant/customers/'.$a)->assertJsonPath('data.tin', null)->assertJsonPath('data.type', 'walk_in');
        $this->assertSame(1, DB::table('merchant_customers')->count());
        $this->assertSame($users, DB::table('users')->count());
    }

    public function test_failed_invoice_issuance_and_invalid_totals_leave_no_customer_or_relationship(): void
    {
        $body = $this->invoiceBody(['status' => 'issued']);
        $this->withToken($this->owner->api_token)->postJson('/api/business-invoices', $body)->assertConflict();
        $this->postJson('/api/business-invoices', $this->invoiceBody(['total_amount' => 1]))->assertUnprocessable();
        foreach (['merchant_customers', 'merchant_customer_history', 'merchant_customer_documents', 'merchant_customer_search', 'invoice_create_requests', 'business_invoices'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table.' must roll back');
        }
        $this->assertSame(0, DB::table('eis_audit_events')->where('event', 'customer.created')->count());
    }

    public function test_invoice_save_retry_is_idempotent_and_changed_payload_cannot_reuse_identifier(): void
    {
        $body = $this->invoiceBody();
        $a = $this->withToken($this->owner->api_token)->postJson('/api/business-invoices', $body)->assertCreated();
        $this->postJson('/api/business-invoices', $body)->assertOk()->assertJsonPath('invoice.id', $a->json('invoice.id'))->assertJsonPath('customer.id', $a->json('customer.id'));
        $this->postJson('/api/business-invoices', [...$body, 'buyer_name' => 'Modified request'])->assertConflict();
        $this->assertSame(1, DB::table('business_invoices')->count());
        $this->assertSame(1, DB::table('merchant_customers')->count());
        $this->assertSame(1, DB::table('merchant_customer_documents')->count());
        $this->assertSame(1, DB::table('invoice_create_requests')->count());
    }

    public function test_customer_matching_is_tenant_scoped_even_for_identical_buyer_identity(): void
    {
        $body = $this->invoiceBody();
        $a = $this->withToken($this->owner->api_token)->postJson('/api/business-invoices', $body)->assertCreated();
        $b = $this->withToken($this->other->api_token)->postJson('/api/business-invoices', $body)->assertCreated()->assertJsonPath('customer.created', true);
        $this->assertNotSame($a->json('customer.id'), $b->json('customer.id'));
        $this->getJson('/api/merchant/customers/options')->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $b->json('customer.id'));
        $this->withToken($this->owner->api_token)->postJson('/api/business-invoices', $this->invoiceBody(['customer_id' => $b->json('customer.id')]))->assertUnprocessable();
    }

    public function test_ambiguous_and_inactive_matches_require_explicit_customer_review(): void
    {
        $one = $this->create(['name' => 'Same Buyer', 'tin' => '987654321', 'email' => 'one@example.test']);
        $this->create(['name' => 'Same Buyer', 'tin' => '987654321', 'email' => 'two@example.test']);
        $this->postJson('/api/business-invoices', $this->invoiceBody(['buyer_name' => 'Same Buyer']))->assertUnprocessable();
        $this->postJson('/api/business-invoices', $this->invoiceBody(['buyer_name' => 'Same Buyer', 'customer_id' => $one['id'], 'customer_revision' => $one['revision']]))->assertCreated()->assertJsonPath('customer.id', $one['id']);
        $inactive = $this->create(['name' => 'Inactive Buyer', 'tin' => null, 'email' => null, 'phone' => null, 'status' => 'inactive']);
        $this->postJson('/api/business-invoices', $this->invoiceBody(['buyer_name' => 'Inactive Buyer', 'buyer_tin' => null, 'buyer_email' => null, 'buyer_phone' => null]))->assertUnprocessable();
        $this->getJson('/api/merchant/customers/options?search=Inactive')->assertJsonPath('total', 0);
        $this->assertSame(3, DB::table('merchant_customers')->count());
    }

    public function test_changed_profile_revision_and_conflicting_tin_do_not_silently_link_or_duplicate(): void
    {
        $c = $this->create(['name' => 'Known Buyer', 'tin' => '987654321']);
        $this->patchJson('/api/merchant/customers/'.$c['id'], $this->body(['name' => 'Known Buyer', 'tin' => '987654321', 'revision' => 1, 'address' => 'Updated registry address']))->assertOk();
        $this->postJson('/api/business-invoices', $this->invoiceBody(['buyer_name' => 'Known Buyer', 'customer_id' => $c['id'], 'customer_revision' => 1]))->assertConflict();
        $this->postJson('/api/business-invoices', $this->invoiceBody(['buyer_name' => 'Unrelated Buyer', 'buyer_tin' => '987654321']))->assertUnprocessable();
        $this->postJson('/api/business-invoices', $this->invoiceBody(['buyer_name' => 'Known Buyer', 'buyer_tin' => null, 'buyer_email' => 'buyer@example.test']))->assertUnprocessable();
        $this->assertSame(1, DB::table('merchant_customers')->count());
        $this->assertSame(0, DB::table('business_invoices')->count());
    }

    public function test_options_are_paginated_searchable_active_only_and_hide_matching_hashes(): void
    {
        for ($i = 0; $i < 23; $i++) {
            $this->create(['name' => 'Directory Buyer '.$i, 'email' => 'buyer'.$i.'@example.test']);
        }
        $this->create(['name' => 'Disabled Customer', 'status' => 'inactive']);
        $r = $this->getJson('/api/merchant/customers/options')->assertOk()->assertJsonPath('total', 23)->assertJsonCount(20, 'data')->assertJsonPath('last_page', 2);
        $this->getJson('/api/merchant/customers/options?page=2')->assertJsonCount(3, 'data');
        $this->getJson('/api/merchant/customers/options?search=buyer22')->assertJsonPath('total', 1);
        $this->assertStringNotContainsString('name_hash', $r->getContent());
        $this->assertStringNotContainsString('request_hash', $r->getContent());
        $this->withToken($this->other->api_token)->getJson('/api/merchant/customers/options')->assertJsonPath('total', 0);
    }

    public function test_optional_phone_column_preserves_legacy_review_evidence_but_detects_real_snapshot_changes(): void
    {
        $id = $this->source();
        $row = DB::table('business_invoices')->where('id', $id)->first();
        $oldSnapshot = clone $row;
        unset($oldSnapshot->buyer_phone);
        $oldBranchHash = hash('sha256', json_encode([$oldSnapshot, []], JSON_THROW_ON_ERROR));
        DB::table('eis_document_branch_reviews')->insert(['id' => (string) Str::uuid(), 'document_source' => 'invoice', 'document_id' => $id, 'branch_code' => '00000', 'source_fingerprint' => $oldBranchHash, 'evidence_reference' => 'PRE-UPGRADE-REVIEW-FIXTURE', 'reviewed_by' => $this->owner->id, 'reviewed_at' => now(), 'revision' => 1]);
        $branch = app(HistoricalBranchEvidence::class);
        $this->assertSame($branch->latest('invoice', $id)->source_fingerprint, $branch->fingerprint('invoice', $id));
        $oldExpenseHash = hash('sha256', json_encode(['invoice' => (array) $oldSnapshot, 'lines' => []], JSON_THROW_ON_ERROR));
        $expense = (object) ['claim_status' => 'approved', 'source_invoice_id' => $id, 'buyer_tin' => null, 'gross_amount' => 112, 'input_vat_amount' => 12, 'withholding_tax_amount' => 0, 'metadata' => json_encode(['claim_review' => ['reviewed_by' => $this->owner->id, 'reviewed_at' => now()->toIso8601String(), 'evidence_reference' => 'PRE-UPGRADE-EXPENSE-REVIEW', 'source_hash' => $oldExpenseHash]])];
        $this->assertNotNull(app(ExpenseCreditEvidence::class)->source($expense));
        DB::table('business_invoices')->where('id', $id)->update(['buyer_phone' => '09171234567']);
        $this->assertNotSame($oldBranchHash, $branch->fingerprint('invoice', $id));
        $this->assertNull(app(ExpenseCreditEvidence::class)->source($expense));
    }

    public function test_customer_options_seller_preview_and_invoice_save_use_one_business_selection(): void
    {
        $second = str_repeat('z', 36);
        DB::table('merchants')->insert(['id' => $second, 'merchant_account_id' => $this->owner->id, 'business_name' => 'Other owned business', 'tin' => '222333444', 'status' => 'active', 'updated_at' => now()->addDay()]);
        $customer = $this->create();
        $this->getJson('/api/business-accounts/current')->assertOk()->assertJsonPath('data.id', $this->merchant);
        $this->getJson('/api/merchant/customers/options')->assertOk()->assertJsonPath('data.0.id', $customer['id']);
        $invoice = $this->postJson('/api/business-invoices', $this->invoiceBody(['buyer_name' => $customer['name'], 'buyer_tin' => null, 'customer_id' => $customer['id'], 'customer_revision' => $customer['revision']]))->assertCreated();
        $this->assertSame($this->merchant, DB::table('business_invoices')->where('id', $invoice->json('invoice.id'))->value('merchant_id'));
        $this->assertSame(0, DB::table('merchant_customers')->where('merchant_id', $second)->count());
    }
}
