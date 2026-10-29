<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BirCompliance;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OperationsWorkspaceTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'app.timezone' => 'Asia/Manila']);
        DB::purge('sqlite');
        foreach (glob(database_path('migrations/*.php')) as $migrationFile) {
            (require $migrationFile)->up();
        }$this->admin = $this->actor('super_admin');
        DB::table('merchants')->insert(['id' => 'm', 'business_name' => 'Example Co', 'tin' => '123456789000']);
    }

    private function actor(string $role): User
    {
        $u = User::factory()->create(['api_token' => fake()->uuid()]);
        DB::table('profiles')->insert(['id' => $u->id, 'role' => $role, 'email' => $u->email]);

        return $u;
    }

    private function row(string $id, string $status = 'completed', int $amount = 112, string $date = '2026-10-15 11:00:00'): void
    {
        DB::table('transaction_receipts')->insert(['id' => 'r'.$id, 'merchant_id' => 'm', 'receipt_number' => 'INV'.$id, 'merchant_name' => 'Example Co', 'merchant_tin' => '123456789000', 'document_type' => 'sales_invoice', 'status' => $status === 'completed' ? 'issued' : $status, 'issued_at' => $date, 'total_due' => $amount, 'vat_amount' => round($amount - $amount / 1.12, 2), 'vatable_sales' => round($amount / 1.12, 2), 'items' => json_encode([['description' => 'Service', 'quantity' => 1, 'unit_price' => $amount, 'tax_type' => 'vatable']])]);
        DB::table('merchant_transactions')->insert(['id' => $id, 'merchant_id' => 'm', 'receipt_id' => 'r'.$id, 'transaction_ref' => 'TX'.$id, 'status' => $status, 'amount' => $amount, 'vat_amount' => 12, 'vatable_sales' => 100, 'payment_method' => 'cash', 'branch' => 'Main', 'created_at' => $date]);
    }

    public function test_access_filters_totals_and_full_export(): void
    {
        $this->getJson('/api/transaction-workspace')->assertUnauthorized();
        $u = $this->actor('merchant');
        $this->withToken($u->api_token)->getJson('/api/operations-report?mode=sales')->assertForbidden();
        $this->row('a');
        $this->row('b', 'failed', 999);
        $this->row('c', 'completed', 224, '2026-10-14 23:59:59');
        $this->withToken($this->admin->api_token)->getJson('/api/transaction-workspace?from=2026-10-15&to=2026-10-15&per_page=1')->assertOk()->assertJsonPath('total', 2)->assertJsonPath('summary.total_amount', 112)->assertJsonPath('last_page', 2);
        $this->withToken($this->admin->api_token)->getJson('/api/transaction-workspace?max=200&branch=Main')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/transaction-workspace?min=200&max=100')->assertUnprocessable();
        $this->getJson('/api/transaction-workspace?to=2026-10-14')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/transaction-workspace?from=2026-10-15&to=2026-10-14')->assertUnprocessable();
        $r = $this->get('/api/transaction-workspace/export?per_page=1');
        $r->assertOk();
        $csv = $r->streamedContent();
        $this->assertStringContainsString('TXa', $csv);
        $this->assertStringContainsString('TXb', $csv);
        $this->assertStringContainsString('TXc', $csv);
    }

    public function test_sales_only_success_and_groups_use_all_pages(): void
    {
        $this->row('a');
        $this->row('b', 'completed', 224);
        $this->row('c', 'pending', 999);
        $this->withToken($this->admin->api_token)->getJson('/api/operations-report?mode=sales&per_page=1&group=business')->assertOk()->assertJsonPath('total', 2)->assertJsonPath('summary.sales', 336)->assertJsonPath('summary.vat', 36)->assertJsonPath('groups.0.count', 2)->assertJsonPath('groups.0.amount', 336)->assertJsonCount(1, 'data');
        DB::table('transaction_receipts')->update(['merchant_name' => '=SENSITIVE_FORMULA()']);
        $csv = $this->get('/api/operations-report/export?mode=sales&per_page=1')->assertOk()->streamedContent();
        $this->assertStringContainsString('TXa', $csv);
        $this->assertStringContainsString('TXb', $csv);
        $this->assertStringNotContainsString('TXc', $csv);
        $this->assertStringContainsString("'=SENSITIVE_FORMULA()", $csv);
    }

    public function test_review_requires_reason_role_revision_and_preserves_source(): void
    {
        $this->row('a');
        $url = '/api/operations-report/a/review';
        $data = ['status' => 'resolved', 'reason' => 'Verified supporting record #123', 'revision' => 0, 'owner_id' => $this->admin->id, 'closure_evidence' => 'Original reviewed evidence #123'];
        $u = $this->actor('bir');
        $this->withToken($u->api_token)->postJson($url, $data)->assertForbidden();
        $this->withToken($this->admin->api_token)->postJson($url, ['status' => 'resolved', 'reason' => 'x', 'revision' => 0])->assertUnprocessable();
        $this->postJson($url, $data)->assertConflict();
        $this->postJson($url, array_replace($data, ['status' => 'in_progress']))->assertOk()->assertJsonPath('revision', 1);
        $data['revision'] = 1;
        $this->postJson($url, $data)->assertForbidden();
        $independent = $this->actor('super_admin');
        $this->withToken($independent->api_token)->postJson($url, $data)->assertOk()->assertJsonPath('revision', 2);
        $this->postJson($url, $data)->assertConflict();
        $this->getJson('/api/operations-report?mode=reconciliation&review=resolved')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('summary.matched', 0)->assertJsonPath('data.0.bir_status', 'not_submitted');
        $this->assertSame('completed', DB::table('merchant_transactions')->value('status'));
        $this->assertTrue(app(BirCompliance::class)->auditIntegrity());
    }

    public function test_certification_ack_is_never_a_production_match(): void
    {
        $this->row('a');
        DB::table('eis_invoice_payloads')->insert(['id' => 'p', 'merchant_id' => 'm', 'receipt_id' => 'ra', 'payload' => '{}', 'payload_hash' => hash('sha256', '{}')]);
        DB::table('eis_transmissions')->insert(['id' => 'e', 'merchant_id' => 'm', 'payload_id' => 'p', 'payload_hash' => 'hash', 'status' => 'acknowledged', 'environment' => 'certification', 'created_at' => now()]);
        $this->withToken($this->admin->api_token)->getJson('/api/transaction-workspace')->assertOk()->assertJsonPath('data.0.bir_status', 'test');
        $this->getJson('/api/operations-report?mode=reconciliation')->assertOk()->assertJsonPath('summary.matched', 0)->assertJsonPath('summary.review', 1);
        $this->getJson('/api/bir-eis/transmissions?per_page=1&environment=production')->assertOk()->assertJsonPath('total', 0)->assertJsonCount(0, 'transmissions');
        $this->getJson('/api/bir-eis/transmissions?per_page=1&environment=certification')->assertOk()->assertJsonPath('total', 1)->assertJsonCount(1, 'transmissions');
    }

    public function test_duplicate_amount_and_payload_integrity_exceptions_are_evidence_based(): void
    {
        $this->row('a');
        $raw = json_encode(['seller_tin' => '123456789000', 'total_due' => 999, 'document_type' => 'sales_invoice']);
        DB::table('eis_invoice_payloads')->insert(['id' => 'p', 'merchant_id' => 'm', 'receipt_id' => 'ra', 'payload' => json_encode(['encrypted' => Crypt::encryptString($raw)]), 'payload_hash' => hash('sha256', $raw)]);
        DB::table('eis_transmissions')->insert(['id' => 'e', 'merchant_id' => 'm', 'payload_id' => 'p', 'payload_hash' => hash('sha256', $raw), 'status' => 'queued', 'environment' => 'certification', 'created_at' => now()]);
        $this->withToken($this->admin->api_token)->getJson('/api/operations-report?mode=reconciliation&review=exceptions')->assertOk()->assertJsonPath('data.0.exception', 'Amount Mismatch')->assertJsonPath('data.0.payload_amount', 999);
        DB::table('eis_invoice_payloads')->where('id', 'p')->update(['payload_hash' => 'altered']);
        $this->getJson('/api/operations-report?mode=reconciliation')->assertOk()->assertJsonPath('data.0.exception', 'Payload Integrity');
        DB::table('eis_transmissions')->delete();
        $this->row('b');
        DB::table('transaction_receipts')->where('id', 'rb')->update(['receipt_number' => 'INVa']);
        $this->getJson('/api/operations-report?mode=reconciliation')->assertOk()->assertJsonPath('summary.exceptions', 1)->assertJsonPath('data.0.source_record_count', 2)->assertJsonPath('data.0.exception', 'Duplicate Receipt Reference');
    }
}
