<?php

namespace Tests\Feature;

use App\Services\EisDocumentReadiness;
use App\Services\IssuedDocumentLedger;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The scheduled outbox repair must stay bounded as the number of documents grows (docs/refactor-plan.md, step 5). */
class ScheduledJobsTest extends TestCase
{
    /** @var list<array{0: string, 1: string}> */
    private array $prepared = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite');
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        // prepare() itself is covered elsewhere; here only the selection of documents is under test.
        $this->mock(EisDocumentReadiness::class, fn ($m) => $m->shouldReceive('prepare')->andReturnUsing(function (string $source, string $id) {
            $this->prepared[] = [$source, $id];

            return [];
        }));
    }

    private function receipt(string $id, string $age = 'now'): void
    {
        $at = $age === 'now' ? now() : now()->subDays(10);
        DB::table('transaction_receipts')->insert(['id' => $id, 'merchant_id' => 'm', 'receipt_number' => 'R-'.$id, 'merchant_name' => 'Synthetic', 'merchant_tin' => '123456789', 'status' => 'issued', 'created_at' => $at, 'updated_at' => $at]);
    }

    private function invoice(string $id, string $status = 'issued', string $age = 'now'): void
    {
        $at = $age === 'now' ? now() : now()->subDays(10);
        DB::table('business_invoices')->insert(['id' => $id, 'merchant_id' => 'm', 'invoice_number' => 'I-'.$id, 'merchant_name' => 'Synthetic', 'buyer_name' => 'Buyer', 'status' => $status, 'total_amount' => 112, 'created_at' => $at, 'updated_at' => $at]);
    }

    private function outbox(string $source, string $id): void
    {
        DB::table('eis_document_outbox')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'merchant_id' => 'm', 'document_source' => $source, 'document_id' => $id, 'internal_schema' => EisDocumentReadiness::INTERNAL_SCHEMA, 'encrypted_document' => 'x', 'document_hash' => str_repeat('a', 64), 'status' => 'blocked_pending_api', 'blockers' => '[]', 'prepared_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function seedMixedDocuments(): void
    {
        $this->receipt('r-new-missing');
        $this->receipt('r-old-missing', 'old');
        $this->receipt('r-new-done');
        $this->outbox('receipt', 'r-new-done');
        $this->invoice('i-new-missing');
        $this->invoice('i-new-draft', 'draft');
        $this->invoice('i-old-missing', 'issued', 'old');
        $this->invoice('i-new-done');
        $this->outbox('invoice', 'i-new-done');
    }

    private function sorted(array $pairs): array
    {
        $flat = array_map(fn ($p) => $p[0].':'.$p[1], $pairs);
        sort($flat);

        return $flat;
    }

    public function test_the_every_minute_run_prepares_only_recent_documents_that_lack_an_outbox_record(): void
    {
        $this->seedMixedDocuments();

        $this->artisan('eis:prepare-pending')->assertSuccessful();

        $this->assertSame(['invoice:i-new-missing', 'receipt:r-new-missing'], $this->sorted($this->prepared), 'Old, finished and draft documents are left alone.');
    }

    public function test_the_full_sweep_also_reaches_old_documents(): void
    {
        $this->seedMixedDocuments();

        $this->artisan('eis:prepare-pending', ['--all' => true])->assertSuccessful();

        $this->assertSame(['invoice:i-new-missing', 'invoice:i-old-missing', 'receipt:r-new-missing', 'receipt:r-old-missing'], $this->sorted($this->prepared));
    }

    public function test_the_window_is_adjustable_and_the_limit_is_respected(): void
    {
        $this->seedMixedDocuments();
        $this->artisan('eis:prepare-pending', ['--since-hours' => 24 * 30])->assertSuccessful();
        $this->assertCount(4, $this->prepared, 'A 30 day window includes the 10 day old documents.');

        $this->prepared = [];
        $this->artisan('eis:prepare-pending', ['--all' => true, '--limit' => 2])->assertSuccessful();
        $this->assertCount(2, $this->prepared);
    }

    public function test_full_sweep_selects_exactly_the_documents_the_previous_ledger_based_scan_selected(): void
    {
        $this->seedMixedDocuments();
        $this->receipt('r-old-done', 'old');
        $this->outbox('receipt', 'r-old-done');

        $previous = app(IssuedDocumentLedger::class)->candidates()->whereIn('document_source', ['invoice', 'receipt'])
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('eis_document_outbox as o')->where('o.internal_schema', EisDocumentReadiness::INTERNAL_SCHEMA)->whereColumn('o.document_source', 'documents.document_source')->whereColumn('o.document_id', 'documents.document_id');
            })->get()->map(fn ($r) => [$r->document_source, $r->document_id])->all();

        $this->artisan('eis:prepare-pending', ['--all' => true])->assertSuccessful();

        $this->assertNotEmpty($previous);
        $this->assertSame($this->sorted($previous), $this->sorted($this->prepared));
    }

    public function test_nothing_to_do_is_a_clean_no_op(): void
    {
        $this->receipt('r-done');
        $this->outbox('receipt', 'r-done');

        $this->artisan('eis:prepare-pending')->assertSuccessful();

        $this->assertSame([], $this->prepared);
    }
}
