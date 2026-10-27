<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BirCompliance;
use App\Services\IssuedDocumentLedger;
use App\Services\MerchantWallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Indexed document-identity lookups and the network budget on merchant intake. */
class IntegrationIntakeScalingTest extends TestCase
{
    private string $m;

    private string $key;

    private string $profile;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array', 'notifications.dispatch_enabled' => false]);
        DB::purge('sqlite');
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        RateLimiter::clear('api-ip:'.hash('sha256', '127.0.0.1'));
        RateLimiter::clear('api-ip-auth:'.hash('sha256', '127.0.0.1'));

        $user = User::factory()->create(['api_token' => Str::random(64)]);
        DB::table('profiles')->insert(['id' => $user->id, 'email' => $user->email, 'role' => 'merchant']);
        $this->m = (string) Str::uuid();
        DB::table('merchants')->insert(['id' => $this->m, 'merchant_account_id' => $user->id, 'business_name' => 'Synthetic scaling QA', 'tin' => '123456789', 'status' => 'active', 'vat_registered' => true]);
        DB::transaction(fn () => app(MerchantWallet::class)->credit($this->m, 100000, (string) Str::uuid(), 'Synthetic funding', (string) $user->id));
        $this->key = Str::random(64);
        DB::table('merchant_api_keys')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $this->m, 'name' => 'Synthetic API key', 'environment' => 'live', 'branch_code' => '00000', 'branch_name' => 'QA', 'branch_type' => 'main', 'key_prefix' => 'qa', 'key_last_four' => 'test', 'key_hash' => hash('sha256', $this->key), 'scopes' => '["transactions:write","transactions:read"]', 'ip_whitelist' => '[]', 'status' => 'active', 'expires_at' => now()->addDay(), 'rate_limit_per_day' => 100000]);
        $this->profile = (string) Str::uuid();
        DB::table('integration_source_profiles')->insert(['id' => $this->profile, 'merchant_id' => $this->m, 'branch_code' => '00000', 'source_system' => 'qa-pos', 'vat_registered' => true, 'seller_name' => 'Synthetic issuer', 'seller_tin' => '123456789000', 'seller_address' => 'Synthetic address', 'authority_reference' => 'Synthetic authority', 'evidence_reference' => 'Synthetic evidence', 'valid_from' => now()->subYear(), 'valid_until' => now()->addYear(), 'status' => 'active', 'reviewed_by' => 'qa', 'reviewed_at' => now(), 'request_id' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function event(int $n, ?string $number = null): array
    {
        return ['schema_version' => 'NUERS-ingestion-1', 'record_mode' => 'source_capture', 'source_profile_id' => $this->profile, 'source_system' => 'qa-pos', 'source_event_id' => 'qa-event-'.$n, 'external_reference' => 'qa-invoice-'.$n, 'event_type' => 'invoice.finalized', 'occurred_at' => now()->subMinute()->format('Y-m-d\TH:i:sP'), 'branch_code' => '00000', 'receipt_number' => $number ?? 'QA-'.str_pad((string) $n, 6, '0', STR_PAD_LEFT), 'document_type' => 'vat_invoice', 'payment_method' => 'cash', 'gross_amount' => '560.00', 'tax_type' => 'vatable', 'vat_inclusive' => true, 'items' => [['description' => 'Synthetic QA item', 'quantity' => 1, 'unit_price' => '560.00', 'tax_type' => 'vatable']]];
    }

    private function intake(array $event)
    {
        return $this->withHeaders(['X-NUERS-API-Key' => $this->key])->postJson('/api/integrations/transactions', $event);
    }

    public function test_generated_key_matches_the_ledger_normalisation(): void
    {
        DB::table('business_invoices')->insert(['id' => 'inv-1', 'merchant_id' => $this->m, 'invoice_number' => '  mixed-Case-7 ', 'merchant_name' => 'Synthetic', 'buyer_name' => 'Buyer', 'status' => 'issued', 'total_amount' => 112]);
        $this->assertSame('MIXED-CASE-7', DB::table('business_invoices')->where('id', 'inv-1')->value('document_number_key'));
        $this->intake($this->event(1, 'Qa-Lower-1'))->assertCreated();
        $this->assertSame('QA-LOWER-1', DB::table('transaction_receipts')->value('document_number_key'));
    }

    public function test_a_replay_is_a_plain_200_without_a_location_header(): void
    {
        // PHP's web SAPIs turn a Location header on any status other than 201 or 3xx into a 302, which this test client cannot see.
        // So guard the cause: only the newly created record carries Location; the replay uses Content-Location.
        $first = $this->intake($this->event(1))->assertCreated();
        $first->assertHeader('Location', $first->json('status_url'));
        $first->assertHeaderMissing('Content-Location');

        $replay = $this->intake($this->event(1))->assertOk()->assertJsonPath('duplicate', true);
        $replay->assertHeaderMissing('Location');
        $replay->assertHeader('Content-Location', $first->json('status_url'));
    }

    public function test_duplicate_number_is_rejected_whatever_the_case_across_receipts_and_invoices(): void
    {
        $this->intake($this->event(1, 'DUP-0001'))->assertCreated();
        $this->intake($this->event(2, 'dup-0001'))->assertStatus(409);

        DB::table('business_invoices')->insert(['id' => 'inv-1', 'merchant_id' => $this->m, 'invoice_number' => 'PORTAL-0001', 'merchant_name' => 'Synthetic', 'buyer_name' => 'Buyer', 'status' => 'issued', 'total_amount' => 112, 'seller_branch_code' => '00000']);
        $this->intake($this->event(3, 'portal-0001'))->assertStatus(409);
        $this->intake($this->event(4, 'FRESH-0001'))->assertCreated();
    }

    public function test_narrowed_ledger_returns_exactly_the_rows_a_full_scan_would(): void
    {
        foreach ([1, 2, 3] as $n) {
            $this->intake($this->event($n))->assertCreated();
        }
        DB::table('business_invoices')->insert(['id' => 'inv-1', 'merchant_id' => $this->m, 'invoice_number' => 'qa-000002', 'merchant_name' => 'Synthetic', 'buyer_name' => 'Buyer', 'status' => 'issued', 'total_amount' => 112, 'seller_branch_code' => '00000']);
        DB::table('business_invoices')->insert(['id' => 'inv-draft', 'merchant_id' => $this->m, 'invoice_number' => 'QA-000003', 'merchant_name' => 'Synthetic', 'buyer_name' => 'Buyer', 'status' => 'draft', 'total_amount' => 112]);

        $ledger = app(IssuedDocumentLedger::class);
        foreach (['QA-000001', 'QA-000002', 'QA-000003', 'QA-NOT-THERE'] as $key) {
            $narrow = $ledger->normalizedCandidates($key)->where('document_number_key', $key)->pluck('id')->sort()->values()->all();
            $full = $ledger->normalizedCandidates()->where('document_number_key', $key)->pluck('id')->sort()->values()->all();
            $this->assertSame($full, $narrow, "Ledger rows differ for $key");
        }
        $this->assertCount(2, $ledger->normalizedCandidates('QA-000002')->where('document_number_key', 'QA-000002')->get(), 'The receipt and the portal invoice share the number.');
    }

    public function test_accepted_intake_does_not_spend_the_shared_network_budget(): void
    {
        $bucket = 'api-ip:'.hash('sha256', '127.0.0.1');
        foreach ([1, 2, 3] as $n) {
            $this->intake($this->event($n))->assertCreated();
        }
        $this->assertSame(0, RateLimiter::attempts($bucket), 'Authenticated intake must not consume the per-IP budget.');
        $this->assertSame(0, RateLimiter::attempts('api-ip-auth:'.hash('sha256', '127.0.0.1')));

        for ($i = 0; $i < 301; $i++) {
            RateLimiter::hit($bucket, 60);
        }
        $this->intake($this->event(4))->assertCreated();
    }

    public function test_failed_credentials_still_spend_the_network_budget_and_then_block_before_lookup(): void
    {
        $failures = 'api-ip-auth:'.hash('sha256', '127.0.0.1');
        foreach ([1, 2, 3] as $n) {
            $this->withHeaders(['X-NUERS-API-Key' => 'wrong-key-'.$n])->postJson('/api/integrations/transactions', $this->event($n))->assertStatus(401);
        }
        $this->assertSame(3, RateLimiter::attempts($failures));

        for ($i = 0; $i < 300; $i++) {
            RateLimiter::hit($failures, 60);
        }
        $this->intake($this->event(9))->assertStatus(429)->assertJsonPath('error.code', 'NETWORK_RATE_LIMITED')->assertHeader('Retry-After');
        $this->assertDatabaseCount('integration_inbox', 0);
    }

    public function test_acceptance_writes_both_audit_entries_last_adjacent_and_in_the_original_order(): void
    {
        $this->intake($this->event(1))->assertCreated();
        $this->intake($this->event(2))->assertCreated();

        $rows = DB::table('eis_audit_events')->whereIn('event', ['eis.document.prepared', 'integration.event.accepted'])->orderBy('id')->get(['id', 'event']);
        $this->assertSame(['eis.document.prepared', 'integration.event.accepted', 'eis.document.prepared', 'integration.event.accepted'], $rows->pluck('event')->all());
        $this->assertSame(1, $rows[1]->id - $rows[0]->id, 'The pair is written back to back under one lock acquisition.');
        $this->assertSame(1, $rows[3]->id - $rows[2]->id);
        $this->assertTrue(app(BirCompliance::class)->auditIntegrity(), 'The hash chain must verify after deferred writes.');
    }

    public function test_an_audit_failure_still_rolls_back_the_whole_acceptance(): void
    {
        $auditBefore = DB::table('eis_audit_events')->count();
        $walletBefore = DB::table('merchant_wallets')->where('merchant_id', $this->m)->value('available_centavos');
        $this->partialMock(BirCompliance::class, fn ($mock) => $mock->shouldReceive('auditMany')->andThrow(new \RuntimeException('Audit storage unavailable')));

        $this->intake($this->event(1))->assertStatus(500);

        foreach (['integration_inbox', 'merchant_transactions', 'transaction_receipts', 'eis_document_outbox'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame($auditBefore, DB::table('eis_audit_events')->count());
        $this->assertSame($walletBefore, DB::table('merchant_wallets')->where('merchant_id', $this->m)->value('available_centavos'), 'The wallet must not be charged.');
    }

    public function test_prepare_still_writes_its_own_audit_unless_the_caller_asks_to_defer_it(): void
    {
        $this->intake($this->event(1))->assertCreated();
        $receipt = DB::table('transaction_receipts')->value('id');
        DB::table('eis_document_outbox')->delete();
        $preparedEntries = fn () => DB::table('eis_audit_events')->where('event', 'eis.document.prepared')->count();
        $before = $preparedEntries();
        $readiness = app(\App\Services\EisDocumentReadiness::class);

        $readiness->prepare('receipt', $receipt, $deferred);
        $this->assertSame('eis.document.prepared', $deferred[0] ?? null, 'The entry is handed back to the caller.');
        $this->assertSame($before, $preparedEntries(), 'Nothing is written while deferred.');

        DB::table('eis_document_outbox')->delete();
        $readiness->prepare('receipt', $receipt);
        $this->assertSame($before + 1, $preparedEntries(), 'Without the argument prepare() writes its own audit entry, as before.');
    }

    public function test_audit_many_chains_entries_in_order_under_one_lock_and_matches_single_audit_semantics(): void
    {
        $bir = app(BirCompliance::class);
        $bir->audit('qa.before', 'subject-0', 'qa', ['n' => 0]);
        $startHead = DB::table('eis_audit_head')->value('last_hash');
        // SQLite has no row locks, so count reads and writes of the head row instead of looking for FOR UPDATE.
        $headReads = $headWrites = 0;
        DB::listen(function ($q) use (&$headReads, &$headWrites) {
            if (str_contains($q->sql, 'eis_audit_head')) {
                str_starts_with(ltrim($q->sql), 'select') ? $headReads++ : (str_starts_with(ltrim($q->sql), 'update') ? $headWrites++ : null);
            }
        });

        $bir->auditMany([['qa.one', 'subject-1', 'qa', ['n' => 1]], ['qa.two', null, 'qa', ['n' => 2]], ['qa.three', 'subject-3', 'qa']]);

        $this->assertSame(1, $headReads, 'Three entries read (and so lock) the audit head once.');
        $this->assertSame(1, $headWrites, 'And update it once.');
        $rows = DB::table('eis_audit_events')->whereIn('event', ['qa.one', 'qa.two', 'qa.three'])->orderBy('id')->get();
        $this->assertSame(['qa.one', 'qa.two', 'qa.three'], $rows->pluck('event')->all());
        $this->assertSame($startHead, $rows[0]->previous_hash, 'The first entry links to the previous head.');
        $this->assertSame($rows[0]->entry_hash, $rows[1]->previous_hash);
        $this->assertSame($rows[1]->entry_hash, $rows[2]->previous_hash);
        $this->assertSame($rows[2]->entry_hash, DB::table('eis_audit_head')->value('last_hash'), 'The head ends at the last entry.');
        $this->assertSame('[]', $rows[2]->details, 'Details default to an empty object list.');
        $this->assertTrue($bir->auditIntegrity(), 'The hash chain verifies.');

        $bir->audit('qa.after', 'subject-4', 'qa', ['n' => 4]);
        $this->assertTrue($bir->auditIntegrity(), 'A single audit after a batch continues the same chain.');
        $before = DB::table('eis_audit_events')->count();
        $bir->auditMany([]);
        $this->assertSame($before, DB::table('eis_audit_events')->count(), 'An empty batch writes nothing.');
    }
}
