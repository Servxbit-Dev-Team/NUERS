<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LedgerProjection;
use App\Services\SalesExportQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesExportQueueTest extends TestCase
{
    private User $owner;
    private User $other;
    private array $files = [];
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite','database.connections.sqlite.database' => ':memory:','cache.default' => 'array','app.timezone' => 'UTC']);
        DB::purge('sqlite');
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->owner = $this->account('one');
        $this->other = $this->account('two');
        foreach (['one','two'] as $m) {
            DB::table('transaction_receipts')->insert(['id' => 'receipt-'.$m,'merchant_id' => $m,'receipt_number' => $m.'-sale','merchant_name' => 'Synthetic '.$m,'merchant_tin' => $m === 'one' ? '123456789' : '987654321','seller_branch_code' => '00000','buyer_name' => ' =HYPERLINK("evil")','document_type' => 'vat_invoice','status' => 'issued','issued_at' => '2026-10-16 04:00:00','total_due' => 112,'vat_amount' => 12,'vatable_sales' => 100,'items' => json_encode([['description' => 'Item','quantity' => 1,'unit_price' => 112,'line_total' => 112,'tax_type' => 'vatable']])]);
        }
        while (app(LedgerProjection::class)->refresh()) {
        }
        app(LedgerProjection::class)->activate();
    }
    private function account(string $id): User
    {
        $u = User::factory()->create(['api_token' => Str::random(64)]);
        DB::table('profiles')->insert(['id' => $u->id,'email' => $u->email,'role' => 'merchant']);
        DB::table('merchants')->insert(['id' => $id,'merchant_account_id' => $u->id,'business_name' => 'Synthetic '.$id,'tin' => $id === 'one' ? '123456789' : '987654321','status' => 'active']);
        return $u;
    }
    private function queue(): string
    {
        $r = $this->withToken($this->owner->api_token)->postJson('/api/merchant/sales-reports/exports', ['from' => '2026-10-01','to' => '2026-10-31','merchant_id' => 'two'])->assertStatus(202)->assertJsonPath('data.state', 'queued');
        $id = $r->json('data.id');
        $this->files[] = $id;
        return $id;
    }
    public function test_background_csv_is_filtered_formula_safe_private_and_expires(): void
    {
        $this->getJson('/api/merchant/sales-reports/exports')->assertUnauthorized();
        $id = $this->queue();
        $this->get('/api/merchant/sales-reports/exports/'.$id.'/download')->assertStatus(409);
        app(SalesExportQueue::class)->runOne();
        $this->getJson('/api/merchant/sales-reports/exports')->assertOk()->assertJsonPath('data.0.state', 'ready')->assertJsonPath('data.0.records', 1);
        $this->get('/api/merchant/sales-reports/exports/'.$id.'/download')->assertOk()->assertDownload();
        $csv = file_get_contents(app(SalesExportQueue::class)->path($id));
        $this->assertStringContainsString('one-sale', $csv);
        $this->assertStringNotContainsString('two-sale', $csv);
        $this->assertStringContainsString("' =HYPERLINK", $csv);
        $this->withToken($this->other->api_token)->getJson('/api/merchant/sales-reports/exports')->assertJsonCount(0, 'data');
        $this->get('/api/merchant/sales-reports/exports/'.$id.'/download')->assertNotFound();
        DB::table('sales_report_exports')->where('id', $id)->update(['expires_at' => now()->subSecond()]);
        $this->withToken($this->owner->api_token)->get('/api/merchant/sales-reports/exports/'.$id.'/download')->assertStatus(410);
        app(SalesExportQueue::class)->expire();
        $this->assertFileDoesNotExist(app(SalesExportQueue::class)->path($id));
    }
    public function test_queue_budget_and_failed_worker_never_publish_partial_download(): void
    {
        $id = $this->queue();
        $this->queue();
        $this->postJson('/api/merchant/sales-reports/exports', [])->assertStatus(429);
        DB::table('merchants')->where('id', 'one')->delete();
        app(SalesExportQueue::class)->runOne();
        $this->assertSame('failed', DB::table('sales_report_exports')->where('id', $id)->value('state'));
        $this->assertFileDoesNotExist(app(SalesExportQueue::class)->path($id));
        $this->assertFileDoesNotExist(app(SalesExportQueue::class)->path($id).'.partial');
    }
    protected function tearDown(): void
    {
        foreach ($this->files as $id) {
            foreach ([app(SalesExportQueue::class)->path($id),app(SalesExportQueue::class)->path($id).'.partial'] as $p) {
                if (is_file($p)) {
                    unlink($p);
                }
            }
        }
        parent::tearDown();
    }
}
