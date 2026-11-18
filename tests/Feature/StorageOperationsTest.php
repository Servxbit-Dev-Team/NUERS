<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BirCompliance;
use App\Services\OperationsHealth;
use App\Services\RecoveryArchive;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class StorageOperationsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'database', 'queue.default' => 'database', 'app.debug' => false, 'app.url' => 'https://nuers.example.test', 'security.enforce_privileged_mfa' => true]);
        DB::purge('sqlite');
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->root = sys_get_temp_dir().'/nuers-recovery-test-'.Str::uuid();
        mkdir($this->root, 0700);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_fresh_runtime_checks_expire_and_bir_remains_deferred(): void
    {
        $service = app(OperationsHealth::class);
        $this->assertTrue($service->collect()['acceptance_ready']);
        $this->assertTrue($service->report()['runtime']['fresh']);
        $this->assertSame('deferred', $service->report()['bir_transmission']);
        $this->assertSame('not_provisioned', $service->report()['offsite_storage']);
        $this->travel(181)->seconds();
        $this->assertFalse($service->report()['runtime']['fresh']);
        $this->travelBack();
    }

    public function test_worker_staleness_and_failed_delivery_are_observed_without_sending(): void
    {
        config(['notifications.dispatch_enabled' => true, 'security.merchant_webhook_delivery_enabled' => true]);
        DB::table('notification_runtime')->updateOrInsert(['id' => 1], ['activated_at' => now(), 'worker_seen_at' => now()->subMinutes(10)]);
        DB::table('merchant_webhook_runtime')->updateOrInsert(['id' => 'dispatcher'], ['last_seen_at' => now(), 'state' => 'running']);
        DB::table('notification_outbox')->insert(['id' => Str::uuid(), 'dedupe_key' => 'synthetic', 'event' => 'synthetic', 'category' => 'test', 'available_at' => now()->subHour(), 'expires_at' => now()->addHour(), 'state' => 'queued', 'updated_at' => now()]);
        $result = app(OperationsHealth::class)->collect();
        $this->assertSame('stale', $result['workers'][0]['state']);
        $this->assertSame('observed', $result['workers'][1]['state']);
        $this->assertSame(1, $result['queues'][0]['due']);
        $this->assertGreaterThanOrEqual(3600, $result['queues'][0]['oldest_due_seconds']);
        $count = DB::table('operational_notifications')->count();
        app(OperationsHealth::class)->collect();
        $this->assertSame($count, DB::table('operational_notifications')->count());
        $this->assertDatabaseHas('notification_outbox', ['dedupe_key' => 'synthetic', 'state' => 'queued', 'attempts' => 0]);
    }

    public function test_intake_counts_use_separate_live_and_sandbox_storage(): void
    {
        $row = ['id' => 'lab-live', 'merchant_id' => 'lab', 'api_key_id' => 'lab-key', 'namespace_hash' => str_repeat('a', 64), 'event_hash' => str_repeat('b', 64), 'request_hash' => str_repeat('c', 64), 'source_system' => 'lab', 'source_event_id' => 'lab', 'external_reference' => 'lab', 'branch_code' => '00000', 'schema_version' => 'NUERS-ingestion-1', 'event_type' => 'invoice.finalized', 'occurred_at' => now(), 'received_at' => now(), 'response' => '{}', 'created_at' => now(), 'updated_at' => now()];
        DB::table('integration_inbox')->insert($row);
        unset($row['schema_version'], $row['received_at'], $row['response']);
        $row += ['receipt_number' => 'LAB', 'document_type' => 'non_vat_invoice', 'receipt_hash' => str_repeat('d', 64), 'gross_centavos' => 100, 'details_encrypted' => 'synthetic'];
        DB::table('integration_sandbox_events')->insert($row);
        $this->assertSame([['environment' => 'live', 'total' => 1], ['environment' => 'sandbox', 'total' => 1]], app(OperationsHealth::class)->collect()['intake_records']);
    }

    public function test_integrity_detects_tampering_without_repairing_it(): void
    {
        app(BirCompliance::class)->audit('synthetic.check', null, 'synthetic-lab', []);
        $this->assertTrue(app(OperationsHealth::class)->integrity()['valid']);
        DB::table('eis_audit_events')->update(['details' => '{"tampered":true}']);
        $this->assertFalse(app(OperationsHealth::class)->integrity()['valid']);
        $this->assertDatabaseHas('eis_audit_events', ['details' => '{"tampered":true}']);
    }

    public function test_operations_evidence_is_not_available_to_merchants_or_table_api(): void
    {
        config(['security.enforce_privileged_mfa' => false]);
        app(OperationsHealth::class)->record('runtime', ['synthetic_private_observation' => true]);
        $u = User::factory()->create(['api_token' => Str::random(64)]);
        DB::table('profiles')->insert(['id' => $u->id, 'email' => $u->email, 'role' => 'merchant']);
        $this->getJson('/api/operations/readiness')->assertUnauthorized();
        $this->withToken($u->api_token)->getJson('/api/operations/readiness')->assertForbidden();
        $response = $this->getJson('/api/tables/operations_observations');
        $this->assertContains($response->status(), [403, 404]);
        $this->assertStringNotContainsString('synthetic_private_observation', $response->getContent());
    }

    public function test_encrypted_archive_roundtrip_preserves_bytes_and_private_permissions(): void
    {
        $key = random_bytes(32);
        $source = $this->root.'/source';
        $bytes = random_bytes(2100000);
        file_put_contents($source, $bytes);
        $archive = $this->root.'/backup.nuers';
        $result = app(RecoveryArchive::class)->create(['objects/merchant-a/document.bin' => $source], $archive, $key, ['environment' => 'testing']);
        $this->assertTrue($result['encrypted']);
        $this->assertFalse($result['restore_verified']);
        $this->assertSame(0, fileperms($archive) & 0077);
        $manifest = app(RecoveryArchive::class)->restore($archive, $this->root.'/recovered', $key);
        $this->assertSame('testing', $manifest['context']['environment']);
        $this->assertSame(hash('sha256', $bytes), hash_file('sha256', $this->root.'/recovered/objects/merchant-a/document.bin'));
        $this->assertSame(0, fileperms($this->root.'/recovered/objects/merchant-a/document.bin') & 0077);
    }

    public function test_corruption_wrong_key_truncation_and_trailing_data_fail_closed(): void
    {
        $key = random_bytes(32);
        file_put_contents($this->root.'/source', 'SYNTHETIC PRIVATE EVIDENCE');
        app(RecoveryArchive::class)->create(['document.txt' => $this->root.'/source'], $this->root.'/backup.nuers', $key);
        $bytes = file_get_contents($this->root.'/backup.nuers');
        $tampered = $bytes;
        $tampered[50] = chr(ord($tampered[50]) ^ 1);
        foreach ([[$bytes, random_bytes(32)], [$tampered, $key], [substr($bytes, 0, -5), $key], [$bytes.'trailing', $key]] as $i => [$input, $recoveryKey]) {
            file_put_contents($this->root.'/bad-'.$i, $input);
            try {
                app(RecoveryArchive::class)->restore($this->root.'/bad-'.$i, $this->root.'/target-'.$i, $recoveryKey);
                $this->fail('Invalid archive was accepted.');
            } catch (\RuntimeException $e) {
                $this->assertDirectoryDoesNotExist($this->root.'/target-'.$i);
            }
        }
    }

    public function test_restore_never_overwrites_existing_targets_and_unsafe_names_are_rejected(): void
    {
        file_put_contents($this->root.'/source', 'preserve');
        try {
            app(RecoveryArchive::class)->restore('unused', $this->root, random_bytes(32));
            $this->fail('Existing target was accepted.');
        } catch (\RuntimeException $e) {
            $this->assertSame('preserve', file_get_contents($this->root.'/source'));
        }
        foreach (['../escape', '/absolute', 'dir/../escape', 'dir//file', 'manifest.json'] as $name) {
            try {
                app(RecoveryArchive::class)->create([$name => $this->root.'/source'], $this->root.'/invalid', random_bytes(32));
                $this->fail('Unsafe member accepted.');
            } catch (\RuntimeException $e) {
                $this->assertFileDoesNotExist($this->root.'/invalid');
            }
        }
    }
}
