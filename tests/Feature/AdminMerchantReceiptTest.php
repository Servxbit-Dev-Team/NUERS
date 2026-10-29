<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DocumentPdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminMerchantReceiptTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        foreach (glob(database_path('migrations/*.php')) as $file) (require $file)->up();
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create(['api_token' => Str::random(64)]);
        DB::table('profiles')->insert(['id' => $user->id, 'email' => $user->email, 'role' => $role]);
        return $user;
    }

    private function merchant(string $id, ?User $owner = null): void
    {
        DB::table('merchants')->insert(['id' => $id, 'business_name' => 'Merchant '.$id, 'tin' => '123456789000', 'merchant_account_id' => $owner?->id]);
    }

    private function receipt(string $id, string $merchant, ?string $transaction = null): void
    {
        DB::table('transaction_receipts')->insert(['id' => $id, 'merchant_id' => $merchant, 'transaction_id' => $transaction, 'receipt_number' => 'SAME-000001', 'merchant_name' => 'Merchant '.$merchant, 'merchant_tin' => '123456789000', 'status' => 'issued', 'document_type' => 'vat_invoice', 'issued_at' => now(), 'gross_amount' => 112, 'total_due' => 112, 'vatable_sales' => 100, 'vat_amount' => 12, 'items' => json_encode([['description' => 'Item for '.$merchant, 'quantity' => 1, 'unit_price' => 112, 'line_total' => 112]])]);
    }

    public function test_admins_open_exact_merchant_documents_even_when_numbers_are_shared(): void
    {
        $this->merchant('a'); $this->merchant('b'); $this->receipt('ra', 'a'); $this->receipt('rb', 'b');
        DB::table('business_invoices')->insert(['id' => 'ib', 'merchant_id' => 'b', 'invoice_number' => 'SAME-000001', 'merchant_name' => 'Merchant b', 'merchant_tin' => '123456789000', 'document_type' => 'sales_invoice', 'status' => 'issued', 'buyer_name' => 'Recorded buyer', 'issue_date' => now()->toDateString(), 'total_amount' => 224]);
        foreach (['admin', 'super_admin'] as $role) {
            $user = $this->actor($role);
            $this->withToken($user->api_token)->getJson('/api/invoice-center/receipt/ra')->assertOk()->assertJsonPath('document.business', 'Merchant a')->assertJsonPath('document.items.0.description', 'Item for a');
            $this->getJson('/api/invoice-center/receipt/rb')->assertOk()->assertJsonPath('document.business', 'Merchant b');
            $this->getJson('/api/invoice-center/invoice/ib')->assertOk()->assertJsonPath('document.amount', 224)->assertJsonPath('document.buyer_name', 'Recorded buyer');
        }
    }

    public function test_preview_remains_tenant_scoped_and_admin_pdf_is_not_a_merchant_bypass(): void
    {
        $owner = $this->actor('merchant'); $this->merchant('own', $owner); $this->merchant('other');
        $this->receipt('owned', 'own'); $this->receipt('foreign', 'other');
        $this->getJson('/api/invoice-center/receipt/owned')->assertUnauthorized();
        $this->getJson('/api/invoice-center/receipt/owned/pdf')->assertUnauthorized();
        $this->withToken($owner->api_token)->getJson('/api/invoice-center/receipt/owned')->assertOk();
        $this->getJson('/api/invoice-center/receipt/foreign')->assertNotFound();
        $this->getJson('/api/invoice-center/receipt/foreign/pdf')->assertForbidden();
        $this->getJson('/api/invoice-center/receipt/owned/pdf')->assertForbidden();
        $client = $this->actor('client');
        $this->withToken($client->api_token)->getJson('/api/invoice-center/receipt/owned')->assertForbidden();
        $this->getJson('/api/invoice-center/receipt/owned/pdf')->assertForbidden();
        $admin = $this->actor('admin');
        $this->withToken($admin->api_token)->getJson('/api/invoice-center/transaction/owned/pdf')->assertNotFound();
        $this->getJson('/api/invoice-center/receipt/missing/pdf')->assertNotFound();
    }

    public function test_admin_pdf_uses_recorded_document_and_audits_without_mutating_original(): void
    {
        $admin = $this->actor('admin'); $this->merchant('a'); $this->receipt('ra', 'a');
        $original = json_encode(DB::table('transaction_receipts')->first());
        $this->partialMock(DocumentPdf::class, function ($mock) {
            $mock->shouldReceive('render')->once()->withArgs(fn ($d) => $d['id'] === 'ra' && $d['business'] === 'Merchant a' && $d['number'] === 'SAME-000001' && $d['amount'] === 112.0 && $d['items'][0]['description'] === 'Item for a')->andReturn('%PDF-synthetic-test');
        });
        $response = $this->withToken($admin->api_token)->get('/api/invoice-center/receipt/ra/pdf');
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertSame($original, json_encode(DB::table('transaction_receipts')->first()));
        $this->assertDatabaseHas('eis_audit_events', ['event' => 'document.admin_copy.downloaded', 'subject_id' => 'ra']);
    }

    public function test_recent_receipts_resolve_exact_links_and_never_cross_merchants(): void
    {
        $admin = $this->actor('super_admin'); $this->merchant('a'); $this->merchant('b');
        $this->receipt('direct', 'a'); $this->receipt('reverse', 'a', 't2'); $this->receipt('foreign', 'b');
        $this->receipt('ambiguous1', 'a', 't4'); $this->receipt('ambiguous2', 'a', 't4');
        foreach (['t1' => 'direct', 't2' => null, 't3' => 'foreign', 't4' => null, 't5' => null] as $id => $receipt) {
            DB::table('merchant_transactions')->insert(['id' => $id, 'merchant_id' => 'a', 'receipt_id' => $receipt, 'amount' => 112, 'status' => 'completed', 'created_at' => now()]);
        }
        $rows = collect($this->withToken($admin->api_token)->getJson('/api/enterprise-dashboards/admin')->assertOk()->json('recent'))->keyBy('id');
        $this->assertSame('direct', $rows['t1']['receipt']['id']);
        $this->assertSame('reverse', $rows['t2']['receipt']['id']);
        foreach (['t3', 't4', 't5'] as $id) $this->assertNull($rows[$id]['receipt']);
        $this->assertCount(5, $rows);
    }
}
