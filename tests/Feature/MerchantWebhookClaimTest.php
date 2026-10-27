<?php

namespace Tests\Feature;

use App\Services\MerchantWebhooks;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** How a worker leases merchant webhook deliveries (docs/refactor-plan.md, step 5). Concurrency is covered by loadtest/claimrace.php on MySQL. */
class MerchantWebhookClaimTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite');
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    }

    private function delivery(string $name, string $state, int $dueMinutes, ?int $leaseMinutes = null): string
    {
        $id = (string) Str::uuid();
        DB::table('merchant_webhook_deliveries')->insert([
            'id' => $id, 'endpoint_id' => (string) Str::uuid(), 'merchant_id' => 'm', 'environment' => 'live', 'event_key' => hash('sha256', $name),
            'event_type' => 'invoice.issued', 'subject_id' => $name, 'encrypted_body' => 'x', 'body_hash' => str_repeat('a', 64), 'secret_version' => 1,
            'state' => $state, 'available_at' => now()->addMinutes($dueMinutes), 'retry_deadline_at' => now()->addDay(),
            'lease_token' => $leaseMinutes === null ? null : (string) Str::uuid(), 'lease_until' => $leaseMinutes === null ? null : now()->addMinutes($leaseMinutes),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_claim_leases_due_deliveries_oldest_first_across_states_and_skips_the_rest(): void
    {
        $retryOld = $this->delivery('retry-old', 'retry', -10);
        $pending = $this->delivery('pending', 'pending', -5);
        $abandoned = $this->delivery('abandoned-lease', 'sending', -3, -1); // worker died; lease expired a minute ago
        $this->delivery('not-yet-due', 'pending', 10);
        $this->delivery('live-lease', 'sending', -20, 2); // another worker is delivering it right now
        $this->delivery('finished', 'delivered', -30);
        $service = app(MerchantWebhooks::class);

        $order = [];
        $tokens = [];
        while (($d = $service->claim()) !== null) {
            $order[] = $d->id;
            $tokens[] = $d->lease_token;
        }

        $this->assertSame([$retryOld, $pending, $abandoned], $order, 'Oldest due first, across pending, retry and abandoned leases.');
        $this->assertCount(3, array_unique($tokens), 'Each lease has its own token.');
        foreach ($order as $id) {
            $row = DB::table('merchant_webhook_deliveries')->where('id', $id)->first();
            $this->assertSame('sending', $row->state);
            $this->assertNotNull($row->lease_token);
            $this->assertTrue(now()->lt($row->lease_until), 'The lease runs into the future.');
        }
        $this->assertNull($service->claim(), 'Nothing else is claimable.');
    }

    public function test_claim_returns_null_when_nothing_is_due(): void
    {
        $this->assertNull(app(MerchantWebhooks::class)->claim());
        $this->delivery('later', 'pending', 30);
        $this->assertNull(app(MerchantWebhooks::class)->claim());
    }

    public function test_watch_mode_runs_until_max_runtime_and_the_scheduled_mode_is_unchanged(): void
    {
        $started = microtime(true);
        $this->artisan('merchant-webhooks:work', ['--watch' => true, '--max-runtime' => 1, '--idle-sleep' => 1])
            ->expectsOutputToContain('"mode":"watch"')->assertSuccessful();
        $this->assertLessThan(4.0, microtime(true) - $started, 'The worker stops promptly at --max-runtime.');

        $this->artisan('merchant-webhooks:work', ['--limit' => 5, '--max-seconds' => 5])->expectsOutputToContain('"processed":0')->assertSuccessful();
    }
}
