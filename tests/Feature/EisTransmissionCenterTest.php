<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\{EisTransmissionCenter};
use Illuminate\Support\Facades\{Cache,DB};
use Tests\TestCase;

class EisTransmissionCenterTest extends TestCase
{
    private User $admin;
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite','database.connections.sqlite.database' => ':memory:','app.timezone' => 'Asia/Manila']);
        DB::purge('sqlite');
        foreach (glob(database_path('migrations/*.php')) as $file) {
            (require $file)->up();
        }
        $this->admin = $this->user('super_admin');
        foreach (['a','b'] as $id) {
            DB::table('merchants')->insert(['id' => $id,'business_name' => 'Company '.$id,'tin' => $id === 'a' ? '123456789' : '987654321','status' => 'active','vat_registered' => true]);
        }
        $this->invoice('one', 'a');
        $this->invoice('two', 'a');
        $this->invoice('other', 'b');
    }
    private function user(string $role): User
    {
        $u = User::factory()->create(['api_token' => fake()->uuid()]);
        DB::table('profiles')->insert(['id' => $u->id,'role' => $role,'email' => $u->email]);
        return $u;
    }
    private function invoice(string $id, string $merchant, string $status = 'sent'): void
    {
        DB::table('business_invoices')->insert(['id' => $id,'merchant_id' => $merchant,'invoice_number' => 'INV-'.$id,'merchant_name' => 'Company '.$merchant,'merchant_tin' => $merchant === 'a' ? '123456789' : '987654321','merchant_address' => 'Original address','seller_branch_code' => '00000','seller_rdo_code' => '027','document_type' => 'vat_invoice','status' => $status,'issue_date' => '2026-10-20','created_at' => '2026-10-20 09:00:00','total_amount' => 112,'vat_amount' => 12,'taxable_amount' => 100,'buyer_name' => 'Buyer','vat_registered' => true]);
        DB::table('business_invoice_items')->insert(['id' => 'item-'.$id,'invoice_id' => $id,'merchant_id' => $merchant,'description' => 'Service','quantity' => 1,'unit_price' => 112,'line_total' => 112,'vat_amount' => 12,'taxable_amount' => 100,'tax_type' => 'vatable']);
    }
    private function preview(array $extra = [])
    {
        return $this->withToken($this->admin->api_token)->postJson('/api/admin/eis-center/preview', $extra + ['merchant' => 'a','scope' => 'selected','documents' => ['invoice:one','invoice:two']]);
    }
    public function test_filters_counts_money_pagination_and_csv_use_same_company_scope(): void
    {
        $this->invoice('draft', 'a', 'draft');
        $r = $this->withToken($this->admin->api_token)->getJson('/api/admin/eis-center?merchant=a&from=2026-10-20&to=2026-10-20&branch=00000&rdo=027&min=112&max=112&per_page=1')->assertOk()->assertJsonPath('total', 2)->assertJsonPath('last_page', 2)->assertJsonPath('summary.amount', 224)->assertJsonPath('gateway.can_send', false);
        $this->assertStringEndsWith('+08:00', $r->json('data.0.received_at'));
        $this->getJson('/api/admin/eis-center?merchant=a&search=123-456-789')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/admin/eis-center?min=113')->assertOk()->assertJsonPath('total', 0);
        $csv = $this->get('/api/admin/eis-center/export?merchant=b')->assertOk()->streamedContent();
        $this->assertStringContainsString('INV-other', $csv);
        $this->assertStringNotContainsString('INV-one', $csv);
        $this->getJson('/api/admin/eis-center?from=2026-10-20&to=2026-10-19')->assertUnprocessable();
        $this->getJson('/api/admin/eis-center?min=200&max=100')->assertUnprocessable();
    }
    public function test_prepare_company_batch_is_durable_idempotent_and_never_sends(): void
    {
        $p = $this->preview(['scope' => 'filtered','documents' => []])->assertOk()->assertJsonPath('total', 2)->assertJsonPath('valid', 2)->json();
        $body = ['preview_id' => $p['preview_id'],'reason' => 'Reviewed synthetic documents'];
        $this->postJson('/api/admin/eis-center/batches', $body)->assertStatus(202);
        $this->postJson('/api/admin/eis-center/batches', $body)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame(2, app(EisTransmissionCenter::class)->work());
        $this->assertSame(0, app(EisTransmissionCenter::class)->work());
        $this->getJson('/api/admin/eis-center/batches/'.$p['preview_id'])->assertOk()->assertJsonPath('batch.status', 'completed')->assertJsonCount(2, 'items')->assertJsonPath('items.0.status', 'prepared');
        $this->assertSame(2, DB::table('eis_document_outbox')->count());
        $this->assertSame(2, DB::table('eis_delivery_jobs')->where('state', 'blocked')->count());
        $this->assertSame(0, DB::table('eis_transmissions')->count());
        $this->assertSame(0, DB::table('eis_delivery_attempts')->count());
        $this->postJson('/api/admin/eis-center/batches/'.$p['preview_id'].'/release', ['reason' => 'Release test batch only'])->assertConflict();
        $this->getJson('/api/admin/eis-center?merchant=a&status=prepared')->assertOk()->assertJsonPath('total', 2);
        $next = $this->preview()->assertOk()->json();
        $this->postJson('/api/admin/eis-center/batches', ['preview_id' => $next['preview_id'],'reason' => 'Repeat preparation is safe'])->assertStatus(202);
        app(EisTransmissionCenter::class)->work();
        $this->assertSame(2, DB::table('eis_document_outbox')->count());
    }
    public function test_company_mismatch_forged_target_and_unauthorized_roles_are_rejected(): void
    {
        $this->preview(['documents' => ['invoice:one','invoice:other']])->assertUnprocessable();
        $this->preview(['documents' => ['invoice:missing']])->assertUnprocessable();
        $this->preview(['documents' => ['invoice:one','invoice:one']])->assertUnprocessable();
        $p = $this->preview()->assertOk()->json();
        $other = $this->user('admin');
        $this->withToken($other->api_token)->postJson('/api/admin/eis-center/batches', ['preview_id' => $p['preview_id'],'reason' => 'Wrong actor test batch'])->assertConflict();
        foreach (['merchant','citizen','rdo'] as $role) {
            $u = $this->user($role);
            $this->withToken($u->api_token)->getJson('/api/admin/eis-center')->assertForbidden();
            $this->postJson('/api/admin/eis-center/preview', ['merchant' => 'a','scope' => 'filtered'])->assertForbidden();
            $this->getJson('/api/admin/eis-center/documents/invoice/one')->assertForbidden();
        }
        $bir = $this->user('bir');
        $this->withToken($bir->api_token)->getJson('/api/admin/eis-center')->assertOk()->assertJsonPath('can_manage', false);
        $this->postJson('/api/admin/eis-center/preview', ['merchant' => 'a','scope' => 'filtered'])->assertForbidden();
    }
    public function test_source_change_after_review_is_recorded_and_does_not_prepare_stale_document(): void
    {
        $p = $this->preview()->assertOk()->json();
        $this->postJson('/api/admin/eis-center/batches', ['preview_id' => $p['preview_id'],'reason' => 'Immutable snapshot test'])->assertStatus(202);
        DB::table('business_invoices')->where('id', 'one')->update(['merchant_address' => 'Changed address']);
        app(EisTransmissionCenter::class)->work();
        $this->assertSame('failed', DB::table('eis_preparation_batch_items')->where('document_id', 'one')->value('status'));
        $this->assertSame('prepared', DB::table('eis_preparation_batch_items')->where('document_id', 'two')->value('status'));
        $this->assertSame(1, DB::table('eis_document_outbox')->count());
    }
    public function test_batch_limit_expiry_and_detail_hide_encrypted_payload_and_keys(): void
    {
        for ($i = 0;$i < 99;$i++) {
            $this->invoice('limit-'.$i, 'a');
        }
        $this->preview(['scope' => 'filtered'])->assertUnprocessable();
        $p = $this->preview()->assertOk()->json();
        Cache::forget('eis-center-preview:'.$p['preview_id']);
        $this->postJson('/api/admin/eis-center/batches', ['preview_id' => $p['preview_id'],'reason' => 'Expired batch review'])->assertConflict();
        $d = $this->getJson('/api/admin/eis-center/documents/invoice/one')->assertOk()->assertJsonPath('validation.local_validation_passed', true)->json();
        $this->assertArrayNotHasKey('canonical', $d['validation']);
        $this->assertArrayNotHasKey('model', $d['validation']);
        $this->assertArrayNotHasKey('encrypted_document', $d['document']);
    }
    public function test_source_errors_and_cancelled_documents_are_visible_but_not_sent(): void
    {
        DB::table('business_invoice_items')->where('invoice_id', 'one')->delete();
        DB::table('business_invoices')->where('id', 'two')->update(['status' => 'cancelled']);
        $p = $this->preview()->assertOk()->assertJsonPath('valid', 0)->json();
        $this->postJson('/api/admin/eis-center/batches', ['preview_id' => $p['preview_id'],'reason' => 'Preserve invalid document evidence'])->assertStatus(202);
        app(EisTransmissionCenter::class)->work();
        $this->assertSame(2, DB::table('eis_preparation_batch_items')->where('status', 'needs_review')->count());
        $this->assertSame(0, DB::table('eis_delivery_attempts')->count());
    }
    public function test_deadline_filters_include_date_only_values_and_completion_recovers_after_interruption(): void
    {
        DB::table('business_invoices')->where('merchant_id', 'a')->update(['issue_date' => now('Asia/Manila')->subDays(3)->toDateString()]);
        $this->withToken($this->admin->api_token)->getJson('/api/admin/eis-center?merchant=a&deadline=due_today')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/admin/eis-center?merchant=a&deadline=overdue')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/admin/eis-center?merchant=a&deadline=upcoming')->assertOk()->assertJsonPath('total', 0);
        $p = $this->preview()->assertOk()->json();
        $this->postJson('/api/admin/eis-center/batches', ['preview_id' => $p['preview_id'],'reason' => 'Interrupted batch completion test'])->assertStatus(202);
        app(EisTransmissionCenter::class)->work();
        DB::table('eis_preparation_batches')->where('id', $p['preview_id'])->update(['status' => 'pending','completed_at' => null]);
        $this->assertSame(0,app(EisTransmissionCenter::class)->work());
        $this->assertSame('completed',DB::table('eis_preparation_batches')->where('id',$p['preview_id'])->value('status'));
    }
}
