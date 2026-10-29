<?php

namespace Tests\Feature;

use App\Services\IssuedDocumentLedger;
use App\Services\LedgerProjection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LedgerProjectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array', 'app.timezone' => 'UTC']);
        DB::purge('sqlite');
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    }

    private function receipt(string $id, string $merchant = 'm1', string $number = 'INV1'): void
    {
        DB::table('transaction_receipts')->insert(['id' => $id, 'merchant_id' => $merchant, 'receipt_number' => $number, 'document_type' => 'vat_invoice', 'merchant_name' => 'Synthetic business', 'merchant_tin' => '123456789', 'seller_branch_code' => '00000', 'issued_at' => '2026-10-19 16:00:00', 'created_at' => '2026-10-19 16:01:00', 'total_due' => 112, 'vatable_sales' => 100, 'vat_amount' => 12, 'items' => json_encode([['description' => 'Synthetic item', 'quantity' => 1, 'unit_price' => 112, 'line_total' => 112, 'tax_type' => 'vatable']])]);
    }

    private function activate(): void
    {
        $projection = app(LedgerProjection::class);
        $projection->enqueueAll();
        while ($projection->refresh()) {}
        $projection->activate();
    }

    public function test_interrupted_additive_migration_can_resume_without_changing_source_records(): void
    {
        $this->receipt('resume');
        $before = DB::table('transaction_receipts')->where('id', 'resume')->first();
        $migration = require database_path('migrations/2026_10_20_000003_create_ledger_read_model.php');
        $migration->up();
        $this->assertEquals($before, DB::table('transaction_receipts')->where('id', 'resume')->first());
        DB::table('transaction_receipts')->where('id', 'resume')->update(['status' => 'voided']);
        $this->activate();
        $this->assertSame('cancelled', app(IssuedDocumentLedger::class)->query('m1')->first()->status);
    }

    public function test_existing_trigger_with_wrong_definition_blocks_activation_migration(): void
    {
        DB::unprepared('DROP TRIGGER ledger_rece_insert');
        DB::unprepared("CREATE TRIGGER ledger_rece_insert AFTER INSERT ON transaction_receipts FOR EACH ROW BEGIN SELECT 1; END");
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ledger_rece_insert differs');
        app(LedgerProjection::class)->installTriggers();
    }

    public function test_projection_preserves_canonical_identity_and_tenant_scope(): void
    {
        $this->receipt('a');
        $this->receipt('b', 'm2'); // Same original identity in a different merchant.
        $this->receipt('c', 'm1', 'INV2');
        $legacy = app(IssuedDocumentLedger::class)->legacyQuery()->orderBy('id')->get()->map(fn ($r) => [$r->id, $r->identity_count])->all();
        $this->activate();
        $indexed = app(IssuedDocumentLedger::class)->query()->orderBy('id')->get()->map(fn ($r) => [$r->id, $r->identity_count])->all();
        $this->assertEquals($legacy, $indexed);
        $own = app(IssuedDocumentLedger::class)->query('m1')->orderBy('id')->get();
        $this->assertCount(2, $own);
        $this->assertEquals([1, 1], $own->pluck('identity_count')->all());
        $this->assertTrue((bool) $own[0]->financial_valid);
        $this->assertEquals('2026-10-20', $own[0]->business_day);
        $this->assertEquals(['m1'], $own->pluck('merchant_id')->unique()->all());
    }

    public function test_raw_writes_invalidate_totals_and_deletions_repair_duplicates(): void
    {
        $this->receipt('a');
        $this->receipt('b');
        $this->activate();
        $this->assertEquals(2, app(IssuedDocumentLedger::class)->query('m1')->first()->identity_count);
        DB::table('transaction_receipts')->where('id', 'a')->delete();
        $this->assertEquals(1, app(IssuedDocumentLedger::class)->query('m1')->first()->identity_count);
        DB::table('transaction_receipts')->where('id', 'b')->update(['total_due' => 113]);
        $this->assertFalse((bool) app(IssuedDocumentLedger::class)->query('m1')->first()->financial_valid);
        DB::table('transaction_receipts')->where('id', 'b')->update(['total_due' => 112, 'status' => 'voided']);
        $this->assertEquals('cancelled', app(IssuedDocumentLedger::class)->query('m1')->first()->status);
    }

    public function test_payment_link_moves_and_rollback_do_not_leave_stale_rows(): void
    {
        $this->receipt('a');
        DB::table('merchant_transactions')->insert(['id' => 't', 'merchant_id' => 'm1', 'receipt_id' => 'a', 'amount' => 112, 'created_at' => now()]);
        $this->activate();
        $this->assertEquals('t', app(IssuedDocumentLedger::class)->query('m1')->first()->id);
        DB::beginTransaction();
        DB::table('transaction_receipts')->where('id', 'a')->update(['total_due' => 999]);
        DB::rollBack();
        $this->assertFalse(DB::table('ledger_dirty_documents')->exists());
        $this->assertTrue((bool) app(IssuedDocumentLedger::class)->query('m1')->first()->financial_valid);
        DB::table('merchant_transactions')->where('id', 't')->update(['receipt_id' => null]);
        $rows = app(IssuedDocumentLedger::class)->query('m1')->get();
        $this->assertCount(2, $rows);
        $this->assertEquals(0, $rows->firstWhere('document_source', 'receipt')->payment_count);
        DB::table('merchant_transactions')->where('id', 't')->update(['receipt_id' => 'a']);
        $this->assertEquals(1, app(IssuedDocumentLedger::class)->query('m1')->count());
    }

    public function test_invoice_item_tampering_is_invalidated_in_same_transaction(): void
    {
        DB::table('business_invoices')->insert(['id' => 'inv', 'merchant_id' => 'm1', 'invoice_number' => 'I1', 'document_type' => 'vat_invoice', 'status' => 'issued', 'merchant_name' => 'Merchant', 'merchant_tin' => '123456789', 'seller_branch_code' => '00000', 'buyer_name' => 'Buyer', 'issue_date' => '2026-10-20', 'vat_registered' => true, 'total_amount' => 112, 'subtotal_amount' => 100, 'taxable_amount' => 100, 'vat_amount' => 12]);
        DB::table('business_invoice_items')->insert(['id' => 'line', 'invoice_id' => 'inv', 'merchant_id' => 'm1', 'description' => 'Item', 'quantity' => 1, 'unit_price' => 112, 'line_total' => 112, 'vat_amount' => 12, 'taxable_amount' => 100, 'tax_type' => 'vatable']);
        $this->activate();
        $this->assertTrue((bool) app(IssuedDocumentLedger::class)->query('m1')->first()->financial_valid);
        DB::table('business_invoice_items')->where('id', 'line')->update(['merchant_id' => 'm2']);
        $this->assertFalse((bool) app(IssuedDocumentLedger::class)->query('m1')->first()->financial_valid);
    }

    public function test_cached_totals_are_tenant_bound_and_invalidated_after_source_or_metadata_changes(): void
    {
        $this->receipt('a');
        $this->activate();
        $cache = app(\App\Services\ReportingSnapshotCache::class);
        $calls = 0;
        $compute = function () use (&$calls) { return ++$calls; };
        $this->assertSame(1, $cache->remember('test', 'm1', [], $compute));
        $this->assertSame(1, $cache->remember('test', 'm1', [], $compute));
        $this->assertSame(2, $cache->remember('test', 'm2', [], $compute));
        DB::table('transaction_receipts')->where('id','a')->update(['status'=>'voided']);
        app(LedgerProjection::class)->catchUp();
        $this->assertSame(3, $cache->remember('test', 'm1', [], $compute));
        // Exercise the same coalesced metadata marker produced by the installed metadata triggers.
        DB::table('ledger_dirty_documents')->insert(['source'=>'metadata','document_id'=>'report-status']);
        app(LedgerProjection::class)->catchUp();
        $this->assertSame(4, $cache->remember('test', 'm1', [], $compute));
        $this->assertEquals(1, DB::table('ledger_documents')->count());
    }
}
