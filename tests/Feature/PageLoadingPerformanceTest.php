<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AuditIntegritySnapshot;
use App\Services\BirCompliance;
use App\Services\LedgerProjection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PageLoadingPerformanceTest extends TestCase
{
    private User $admin;
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite','database.connections.sqlite.database' => ':memory:','cache.default' => 'array','app.timezone' => 'Asia/Manila']);
        DB::purge('sqlite');
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->admin = User::factory()->create(['api_token' => fake()->uuid()]);
        DB::table('profiles')->insert(['id' => $this->admin->id,'role' => 'super_admin','email' => $this->admin->email]);
        DB::table('merchants')->insert(['id' => 'm','business_name' => 'Synthetic Business','tin' => '123456789000']);
        $this->withToken($this->admin->api_token);
    }
    private function receipts(int $count): void
    {
        for ($i = 0;$i < $count;$i++) {
            DB::table('transaction_receipts')->insert([
                'id' => sprintf('r%05d', $i),'merchant_id' => 'm','receipt_number' => sprintf('INV%05d', $i),'merchant_name' => 'Synthetic Business','merchant_tin' => '123456789000','document_type' => 'vat_invoice','status' => 'issued',
                'issued_at' => '2026-10-20 10:00:00','created_at' => '2026-10-20 11:00:00','total_due' => 112,'vat_amount' => 12,'vatable_sales' => 100,
                'items' => json_encode([['description' => 'Synthetic','quantity' => 1,'unit_price' => 112,'tax_type' => 'vatable']])]);
        }
    }
    private function activate(): void
    {
        while (app(LedgerProjection::class)->refresh()) {
        } app(LedgerProjection::class)->activate();
    }
    public function test_narrow_merchant_counters_match_detailed_path_for_statuses_and_failed_financial_evidence(): void
    {
        $owner = User::factory()->create(['api_token' => fake()->uuid()]);
        DB::table('profiles')->insert(['id' => $owner->id,'role' => 'merchant','email' => $owner->email]);
        DB::table('merchants')->where('id', 'm')->update(['merchant_account_id' => $owner->id,'status' => 'active']);
        $this->receipts(4);
        DB::table('transaction_receipts')->where('id', 'r00001')->update(['total_due' => 999]);
        DB::table('transaction_receipts')->where('id', 'r00002')->update(['status' => 'cancelled']);
        DB::table('transaction_receipts')->where('id', 'r00003')->update(['document_type' => 'payment_receipt']);
        foreach (['completed','failed','pending','cancelled'] as $i => $status) {
            DB::table('merchant_transactions')->insert([
                'id' => 'payment-'.$i,'merchant_id' => 'm','transaction_ref' => 'PAY-'.$i,'status' => $status,'amount' => 112,'created_at' => '2026-10-20 10:00:00']);
        }
        $this->activate();
        $this->withToken($owner->api_token);
        foreach (['documents','transactions'] as $mode) {
            $fast = $this->getJson('/api/merchant/records?mode='.$mode)->assertOk()->assertJsonPath('pagination.total', 4)->json();
            $detailed = $this->getJson('/api/merchant/records?mode='.$mode.'&from=2026-01-01')->assertOk()->json();
            $this->assertEquals($detailed['summary'], $fast['summary']);
            $this->assertEquals($detailed['tabs'], $fast['tabs']);
            $this->assertEquals(112, $fast['summary']['total_amount']);
        }
    }
    public function test_reconciliation_and_transactions_have_bounded_pages_and_reuse_totals(): void
    {
        $this->receipts(120);
        $this->activate();
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });
        $first = $this->getJson('/api/operations-report?mode=reconciliation&per_page=10')->assertOk()->assertJsonPath('query_mode', 'indexed_reconciliation')->assertJsonPath('summary.total', 120)->assertJsonPath('summary.review', 120)->assertJsonCount(10, 'data')->json();
        $this->assertFalse(collect($queries)->contains(fn ($q) => str_contains($q, 'from "transaction_receipts"')), 'Reconciliation listing must not rescan original financial payloads');
        $queries = [];
        $last = $this->getJson('/api/operations-report?mode=reconciliation&per_page=10&page=999999')->assertOk()->assertJsonPath('page', 12)->assertJsonCount(10, 'data')->json();
        $this->assertEmpty(array_intersect(array_column($first['data'], 'id'), array_column($last['data'], 'id')));
        $this->assertFalse(collect($queries)->contains(fn ($q) => str_contains($q, 'as reconciled_today')), 'Page changes reuse aggregates');
        $this->getJson('/api/operations-report?mode=sales')->assertOk()->assertJsonPath('query_mode', 'indexed_sales')->assertJsonPath('total', 120);
        $this->getJson('/api/transaction-workspace?per_page=10')->assertOk()->assertJsonPath('total', 120)->assertJsonCount(10, 'data');
        $queries = [];
        $this->getJson('/api/transaction-workspace?per_page=10&page=999999')->assertOk()->assertJsonPath('page', 12)->assertJsonCount(10, 'data');
        $this->assertFalse(collect($queries)->contains(fn ($q) => str_contains($q, 'as identity_conflicts')));
        DB::table('transaction_receipts')->where('id', 'r00000')->update(['total_due' => 999]);
        $this->getJson('/api/operations-report?mode=reconciliation&review=exceptions')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.exception', 'Source Financial Mismatch');
        $this->getJson('/api/transaction-workspace')->assertOk()->assertJsonPath('summary.recorded_amount', 14327);
        $this->getJson('/api/operations-report?mode=reconciliation&per_page=100000')->assertUnprocessable();
        $merchant = User::factory()->create(['api_token' => fake()->uuid()]);
        DB::table('profiles')->insert(['id' => $merchant->id,'role' => 'merchant','email' => $merchant->email]);
        $this->withToken($merchant->api_token)->getJson('/api/operations-report?mode=reconciliation')->assertForbidden();
        $this->getJson('/api/transaction-workspace')->assertForbidden();
    }
    public function test_eis_register_paginates_and_invalidates_preparation_status_without_sending(): void
    {
        $this->receipts(120);
        $this->activate();
        $first = $this->getJson('/api/admin/eis-center?per_page=10')->assertOk()->assertJsonPath('total', 120)->assertJsonPath('summary.unprepared', 120)->assertJsonCount(10, 'data')->json();
        $last = $this->getJson('/api/admin/eis-center?per_page=10&page=99999')->assertOk()->assertJsonPath('page', 12)->assertJsonCount(10, 'data')->json();
        $this->assertEmpty(array_intersect(array_column($first['data'], 'id'), array_column($last['data'], 'id')));
        $this->getJson('/api/admin/eis-center?status=accepted')->assertOk()->assertJsonPath('total', 0)->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/eis-center?merchant=unrelated')->assertOk()->assertJsonPath('total', 0);
        $this->assertFalse($first['gateway']['can_send']);
        DB::table('transaction_receipts')->where('id', 'r00000')->update(['total_due' => 999]);
        $this->getJson('/api/admin/eis-center')->assertOk()->assertJsonPath('summary.amount', 14327);
    }

    public function test_large_audit_page_does_not_verify_entire_chain_and_background_check_detects_tampering(): void
    {
        $previous = str_repeat('0', 64);
        $rows = [];
        for ($i = 1;$i <= 1100;$i++) {
            $at = '2026-10-28 10:00:00';
            $event = 'lab.audit';
            $details = '{}';
            $actor = 'lab';
            $hash = hash('sha256', json_encode([$previous,$event,null,$actor,$details,$at], JSON_THROW_ON_ERROR));
            $rows[] = ['id' => $i,'event' => $event,'subject_id' => null,'actor_id' => $actor,'details' => $details,'occurred_at' => $at,'previous_hash' => $previous,'entry_hash' => $hash];
            $previous = $hash;
        }
        foreach (array_chunk($rows, 100) as $batch) {
            DB::table('eis_audit_events')->insert($batch);
        }
        DB::table('eis_audit_head')->where('id', 1)->update(['last_hash' => $previous]);
        $this->getJson('/api/live-monitoring/audit')->assertOk()->assertJsonPath('integrity', null)->assertJsonPath('integrity_check.state', 'pending')->assertJsonCount(25, 'events');
        $this->assertTrue(app(AuditIntegritySnapshot::class)->verify()['valid']);
        $this->getJson('/api/live-monitoring/audit?page=2')->assertOk()->assertJsonPath('integrity', true)->assertJsonCount(25, 'events');
        app(BirCompliance::class)->audit('lab.new', null, 'lab');
        $this->getJson('/api/live-monitoring/audit')->assertOk()->assertJsonPath('integrity', null);
        DB::table('eis_audit_events')->where('id', 1)->update(['details' => 'tampered']);
        $this->assertFalse(app(AuditIntegritySnapshot::class)->verify()['valid']);
        $this->getJson('/api/live-monitoring/audit')->assertOk()->assertJsonPath('integrity',false);
        $this->travel(11)->minutes();
        $this->getJson('/api/live-monitoring/audit')->assertOk()->assertJsonPath('integrity',false);
    }
}
