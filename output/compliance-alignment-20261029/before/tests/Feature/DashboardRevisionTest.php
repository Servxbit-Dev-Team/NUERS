<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DashboardRevision;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardRevisionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite');
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->travelTo(now()->startOfMinute());
    }

    private function account(string $role): array
    {
        $raw = Str::random(64);
        $user = User::factory()->create(['api_token_hash' => hash('sha256', $raw), 'api_expires_at' => now()->addHour()]);
        DB::table('profiles')->insert(['id' => $user->id, 'email' => $user->email, 'role' => $role]);
        return [$user, $raw];
    }

    public function test_heartbeat_requires_session_and_enforced_mfa(): void
    {
        $this->getJson('/api/dashboard-revision')->assertUnauthorized();
        [$admin, $raw] = $this->account('super_admin');
        config(['security.enforce_privileged_mfa' => true]);
        $this->withToken($raw)->getJson('/api/dashboard-revision')->assertForbidden()->assertJsonPath('code', 'MFA_REQUIRED');
    }

    public function test_revision_is_opaque_actor_bound_and_does_not_return_foreign_data(): void
    {
        [$one, $a] = $this->account('merchant');
        [$two, $b] = $this->account('merchant');
        $first = $this->withToken($a)->getJson('/api/dashboard-revision')->assertOk()->assertExactJsonStructure(['revision']);
        $second = $this->withToken($b)->getJson('/api/dashboard-revision?merchant_id='.$one->id)->assertOk()->assertExactJsonStructure(['revision']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first->json('revision'));
        $this->assertNotSame($first->json('revision'), $second->json('revision'));
        $this->withToken($a)->getJson('/api/admin/wallets')->assertForbidden();
        $this->withToken($b)->getJson('/api/admin/merchant-connections')->assertForbidden();
    }

    public function test_unchanged_reads_keep_revision_and_no_source_table_scans(): void
    {
        [$actor, $raw] = $this->account('merchant');
        $first = $this->withToken($raw)->getJson('/api/dashboard-revision')->assertOk()->json('revision');
        DB::enableQueryLog();
        $this->travel(1)->seconds();
        $second = $this->withToken($raw)->getJson('/api/dashboard-revision')->assertOk()->json('revision');
        $queries = DB::getQueryLog();
        $this->assertSame($first, $second);
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/\b(?:count|sum)\s*\(|from\s+["`]?\b(?:transaction_receipts|business_invoices|merchant_transactions|ledger_documents)\b/i', $query['query']);
        }
        $this->assertLessThan(35, count($queries));
    }

    public function test_worker_mutations_publish_after_commit_and_not_rollback(): void
    {
        [$actor] = $this->account('merchant');
        $service = app(DashboardRevision::class);
        $before = $service->token($actor->id);
        DB::beginTransaction();
        DB::table('profiles')->where('id', $actor->id)->update(['full_name' => 'Pending change']);
        $this->assertSame($before, $service->token($actor->id));
        DB::rollBack();
        $this->assertSame($before, $service->token($actor->id));
        DB::transaction(fn () => DB::table('profiles')->where('id', $actor->id)->update(['full_name' => 'Committed change']));
        $this->assertNotSame($before, $service->token($actor->id));
    }

    public function test_dirty_source_hint_and_clock_expiry_change_revision(): void
    {
        [$actor] = $this->account('merchant');
        $service = app(DashboardRevision::class);
        $before = $service->token($actor->id);
        DB::table('ledger_dirty_documents')->insert(['source' => 'receipt', 'document_id' => (string) Str::uuid()]);
        $after = $service->token($actor->id);
        $this->assertNotSame($before, $after);
        $this->travel(1)->minutes();
        $this->assertNotSame($after, $service->token($actor->id));
    }

    public function test_cache_writes_do_not_trigger_refresh_loop(): void
    {
        [$actor] = $this->account('merchant');
        $service = app(DashboardRevision::class);
        $before = $service->token($actor->id);
        Cache::put('arbitrary-widget-cache', 'value', 60);
        $service->publishIfChanged();
        $this->assertSame($before, $service->token($actor->id));
    }
}
