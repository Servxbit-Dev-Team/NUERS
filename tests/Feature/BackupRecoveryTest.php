<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BackupRecovery;
use App\Services\BirCompliance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackupRecoveryTest extends TestCase
{
    private User $author;

    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array', 'app.timezone' => 'Asia/Manila', 'notifications.dispatch_enabled' => false]);
        DB::purge('sqlite');
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->author = $this->account('super_admin');
        $this->reviewer = $this->account('admin');
    }

    private function account(string $role): User
    {
        $u = User::factory()->create(['api_token' => Str::random(64)]);
        DB::table('profiles')->insert(['id' => $u->id, 'email' => $u->email, 'role' => $role]);

        return $u;
    }

    private function body(string $kind, array $payload): array
    {
        return ['kind' => $kind, 'payload' => $payload, 'request_id' => (string) Str::uuid(), 'reason' => 'Controlled recovery evidence QA only', 'evidence_reference' => 'QA-BCDR-EVIDENCE-2026-001'];
    }

    private function backup(): array
    {
        return ['asset_type' => 'database', 'scope' => 'NUERS database and source ledger', 'environment' => 'production', 'started_at' => now()->subHour()->toIso8601String(), 'finished_at' => now()->subMinutes(10)->toIso8601String(), 'result' => 'successful', 'sha256' => str_repeat('a', 64), 'size_bytes' => 1024, 'location_label' => 'Independent backup vault', 'encrypted' => true, 'immutable' => true, 'offsite' => true];
    }

    private function create(string $kind, array $payload, string $type = 'evidence'): array
    {
        return $this->withToken($this->author->api_token)->postJson('/api/admin/backups/'.$type, $this->body($kind, $payload))->assertCreated()->json('data');
    }

    private function review(array $row, string $type = 'evidence', string $decision = 'approve')
    {
        return $this->withToken($this->reviewer->api_token)->postJson('/api/admin/backups/'.$type.'/'.$row['id'].'/review', ['revision' => $row['revision'], 'decision' => $decision, 'note' => 'Independent report and source evidence reviewed', 'evidence_reference' => 'QA-INDEPENDENT-REVIEW-REFERENCE']);
    }

    private function snapshot(): array
    {
        $b = $this->create('backup', $this->backup());
        $this->review($b)->assertOk();
        $s = $this->create('snapshot', ['backup_id' => $b['id'], 'environment' => 'production', 'snapshot_at' => now()->subMinutes(15)->toIso8601String(), 'consistency_marker' => 'QA-LSN-1234', 'sha256' => str_repeat('a', 64)]);
        $this->review($s)->assertOk();

        return $s;
    }

    private function drill(string $snapshot): array
    {
        return ['snapshot_id' => $snapshot, 'test_type' => 'restore', 'environment' => 'staging', 'target_label' => 'Isolated QA restore target', 'result' => 'passed', 'outage_at' => now()->subHours(2)->toIso8601String(), 'recovered_at' => now()->subHour()->toIso8601String(), 'source_commit_at' => now()->subHours(2)->toIso8601String(), 'restored_commit_at' => now()->subHours(2)->subMinutes(5)->toIso8601String(), 'checks' => array_fill_keys(BackupRecovery::CHECKS, true)];
    }

    public function test_dashboard_is_admin_only_and_privileged_mfa_is_preserved(): void
    {
        $this->getJson('/api/admin/backups')->assertUnauthorized();
        foreach (['merchant', 'client', 'bir', 'rdo'] as $role) {
            $u = $this->account($role);
            $this->withToken($u->api_token)->getJson('/api/admin/backups')->assertForbidden();
            $this->getJson('/api/admin/backups/export')->assertForbidden();
            $this->postJson('/api/admin/backups/evidence', [])->assertForbidden();
        }
        config(['security.enforce_privileged_mfa' => true]);
        $this->withToken($this->author->api_token)->getJson('/api/admin/backups')->assertForbidden()->assertJsonPath('code', 'MFA_REQUIRED');
    }

    public function test_empty_dashboard_never_reports_verified_backups_healthy_replication_or_measured_recovery(): void
    {
        $r = $this->withToken($this->author->api_token)->getJson('/api/admin/backups')->assertOk()->assertJsonPath('policy.approved', false)->assertJsonPath('stats.evidence_score', 0)->assertJsonPath('stats.replication_healthy', false)->assertJsonPath('stats.rpo_minutes', null)->assertJsonPath('stats.rto_minutes', null)->assertJsonPath('execution.live_restore', false)->assertJsonCount(5, 'site');
        $this->assertSame(0, $r->json('stats.successful_today'));
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_backup_requests_are_durable_idempotent_and_do_not_execute_or_modify_financial_records(): void
    {
        $before = DB::table('business_invoices')->count();
        $body = $this->body('backup', ['asset_type' => 'database', 'environment' => 'local', 'scope' => 'Full local database', 'target_label' => 'Approved recovery vault']);
        $first = $this->withToken($this->author->api_token)->postJson('/api/admin/backups/requests', $body)->assertCreated()->assertJsonPath('data.status', 'pending_review');
        $this->postJson('/api/admin/backups/requests', $body)->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
        $body['payload']['scope'] = 'Changed scope';
        $this->postJson('/api/admin/backups/requests', $body)->assertConflict();
        $this->assertSame(1, DB::table('bcdr_requests')->count());
        $this->assertSame(0, DB::table('bcdr_evidence')->count());
        $this->assertSame($before, DB::table('business_invoices')->count());
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertTrue(app(BirCompliance::class)->auditIntegrity());
    }

    public function test_evidence_cannot_self_verify_and_reviews_are_revision_protected(): void
    {
        $row = $this->create('backup', $this->backup());
        $body = ['revision' => 1, 'decision' => 'approve', 'note' => 'Independent evidence review completed', 'evidence_reference' => 'QA-REVIEW-EVIDENCE-REFERENCE'];
        $this->withToken($this->author->api_token)->postJson('/api/admin/backups/evidence/'.$row['id'].'/review', $body)->assertForbidden();
        $this->review($row)->assertOk();
        $this->review($row)->assertConflict();
        $this->assertDatabaseHas('bcdr_evidence', ['id' => $row['id'], 'status' => 'verified', 'reviewed_by' => (string) $this->reviewer->id, 'revision' => 2]);
    }

    public function test_successful_job_requires_digest_size_and_valid_chronology(): void
    {
        $b = $this->backup();
        $b['sha256'] = null;
        $this->withToken($this->author->api_token)->postJson('/api/admin/backups/evidence', $this->body('backup', $b))->assertUnprocessable();
        $b = $this->backup();
        $b['finished_at'] = now()->subHours(2)->toIso8601String();
        $this->postJson('/api/admin/backups/evidence', $this->body('backup', $b))->assertUnprocessable();
        $b = $this->backup();
        $b['started_at'] = ['invalid'];
        $this->postJson('/api/admin/backups/evidence', $this->body('backup', $b))->assertUnprocessable();
        $this->postJson('/api/admin/backups/evidence', $this->body('backup', ['unknown' => 'field']))->assertUnprocessable();
        $this->assertSame(0, DB::table('bcdr_evidence')->count());
    }

    public function test_snapshot_digest_and_source_environment_must_match_reviewed_successful_backup(): void
    {
        $backup = $this->create('backup', $this->backup());
        $snapshot = $this->create('snapshot', ['backup_id' => $backup['id'], 'environment' => 'production', 'snapshot_at' => now()->subMinutes(20)->toIso8601String(), 'consistency_marker' => 'QA-LSN-1', 'sha256' => str_repeat('b', 64)]);
        $this->review($snapshot)->assertConflict();
        $this->review($backup)->assertOk();
        $this->review($snapshot)->assertConflict();
        $r = $this->withToken($this->author->api_token)->getJson('/api/admin/backups')->assertOk();
        $this->assertFalse($r->json('snapshots.0.restore_verified'));
    }

    public function test_reviewed_snapshot_is_not_a_verified_restore_until_successful_independent_drill(): void
    {
        $snapshot = $this->snapshot();
        $this->withToken($this->author->api_token)->getJson('/api/admin/backups')->assertJsonPath('snapshots.0.restore_verified', false);
        $drill = $this->create('drill', $this->drill($snapshot['id']));
        $this->getJson('/api/admin/backups')->assertJsonPath('stats.rto_minutes', null);
        $this->review($drill)->assertOk();
        $r = $this->getJson('/api/admin/backups')->assertOk()->assertJsonPath('snapshots.0.verification_current', true)->assertJsonPath('stats.measurement_current', true);
        $this->assertEquals(5, $r->json('stats.rpo_minutes'));
        $this->assertEquals(60, $r->json('stats.rto_minutes'));
        $this->assertStringEndsWith('+08:00', $r->json('observed_at'));
    }

    public function test_failed_checks_impossible_commit_times_and_production_drills_are_rejected(): void
    {
        $snapshot = $this->snapshot();
        $p = $this->drill($snapshot['id']);
        $p['checks']['audit_integrity'] = false;
        $this->withToken($this->author->api_token)->postJson('/api/admin/backups/evidence', $this->body('drill', $p))->assertUnprocessable();
        $p = $this->drill($snapshot['id']);
        $p['environment'] = 'production';
        $this->postJson('/api/admin/backups/evidence', $this->body('drill', $p))->assertUnprocessable();
        $p = $this->drill($snapshot['id']);
        $p['restored_commit_at'] = now()->toIso8601String();
        $this->postJson('/api/admin/backups/evidence', $this->body('drill', $p))->assertUnprocessable();
    }

    public function test_tabletop_exercise_never_establishes_measured_rto_rpo_or_restore_verification(): void
    {
        $p = $this->drill('');
        $p['snapshot_id'] = null;
        $p['test_type'] = 'tabletop';
        $p['source_commit_at'] = null;
        $p['restored_commit_at'] = null;
        $p['checks'] = array_fill_keys(BackupRecovery::CHECKS, false);
        $d = $this->create('drill', $p);
        $this->review($d)->assertOk();
        $this->getJson('/api/admin/backups')->assertJsonPath('stats.rpo_minutes', null)->assertJsonPath('stats.rto_minutes', null)->assertJsonPath('drill_summary.reviewed_total', 0);
    }

    public function test_stale_replication_observations_never_remain_green(): void
    {
        foreach (['database', 'storage', 'queue', 'failover', 'environment'] as $component) {
            $r = $this->create('replication', ['component' => $component, 'primary_status' => 'healthy', 'recovery_status' => 'in_sync', 'observed_at' => now()->toIso8601String()]);
            $this->review($r)->assertOk();
        }
        $this->getJson('/api/admin/backups')->assertJsonPath('stats.replication_healthy', true);
        $this->travel(61)->minutes();
        $this->getJson('/api/admin/backups')->assertJsonPath('stats.replication_healthy', false)->assertJsonPath('site.0.fresh', false);
        $this->travelBack();
    }

    public function test_policy_requires_independent_approval_and_cannot_override_holds_or_schedule_execution(): void
    {
        $p = app(BackupRecovery::class)->policy()['values'];
        $p['policy_revision'] = 0;
        $p['runbook_reference'] = 'QA-RUNBOOK-CONTROLLED-001';
        $p['retention_reference'] = 'Reviewed statutory preservation basis';
        $p['key_custody_reference'] = 'Independent key custody evidence';
        $request = $this->create('policy', $p, 'requests');
        $this->assertSame(0, DB::table('bcdr_policy')->value('revision'));
        $this->review($request, 'requests')->assertOk();
        $this->getJson('/api/admin/backups')->assertJsonPath('policy.approved', true)->assertJsonPath('policy.revision', 1)->assertJsonPath('execution.automatic_backups', false);
        $stale = $this->create('policy', $p, 'requests');
        $this->review($stale, 'requests')->assertConflict();
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_restore_approval_requires_approved_runbook_and_current_reviewed_restore_test(): void
    {
        $snapshot = $this->snapshot();
        $request = $this->create('restore', ['snapshot_id' => $snapshot['id'], 'environment' => 'staging', 'scope' => 'Authorized isolated service recovery', 'target_label' => 'Isolated restore target'], 'requests');
        $this->review($request, 'requests')->assertConflict();
        $policy = app(BackupRecovery::class)->policy()['values'];
        $policy['policy_revision'] = 0;
        $policy['runbook_reference'] = 'QA-Runbook-Approved';
        $policy['retention_reference'] = 'QA-Statutory-Storage-Basis';
        $policy['key_custody_reference'] = 'QA-Key-Recovery-Custody';
        $pr = $this->create('policy', $policy, 'requests');
        $this->review($pr, 'requests')->assertOk();
        $this->review($request, 'requests')->assertConflict();
        $drill = $this->create('drill', $this->drill($snapshot['id']));
        $this->review($drill)->assertOk();
        $this->review($request, 'requests')->assertOk();
        $this->assertDatabaseHas('bcdr_requests', ['id' => $request['id'], 'status' => 'approved']);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_incident_is_linked_to_existing_reporting_register_without_claiming_external_reporting(): void
    {
        $r = $this->create('incident', ['environment' => 'production', 'scope' => 'Critical availability incident QA', 'severity' => 'critical', 'detected_at' => now()->subMinute()->toIso8601String()], 'requests');
        $this->assertSame('open', $r['status']);
        $this->assertDatabaseHas('eis_operational_incidents', ['id' => $r['id'], 'status' => 'open', 'severity' => 'critical', 'reported_at' => null, 'report_reference' => null]);
    }

    public function test_history_pagination_export_and_unknown_payload_fields_are_controlled(): void
    {
        $b = $this->backup();
        $b['shell_command'] = 'DROP DATABASE';
        $this->withToken($this->author->api_token)->postJson('/api/admin/backups/evidence', $this->body('backup', $b))->assertUnprocessable();
        $this->getJson('/api/admin/backups/records?type=users')->assertUnprocessable();
        $this->getJson('/api/admin/backups/records?type=evidence&page=-1')->assertUnprocessable();
        $row = $this->create('backup', $this->backup());
        $this->getJson('/api/admin/backups/records?type=evidence&kind=backup')->assertOk()->assertJsonPath('total', 1)->assertJsonMissingPath('data.0.request_hash');
        $this->getJson('/api/admin/backups/export')->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="NUERS-backup-recovery-report.json"')->assertJsonPath('execution.live_failover', false);
        $this->deleteJson('/api/admin/backups/evidence/'.$row['id'])->assertStatus(404);
        $this->assertTrue(app(BirCompliance::class)->auditIntegrity());
    }

    public function test_newer_failed_recovery_test_invalidates_previous_green_restore_status(): void
    {
        $s = $this->snapshot();
        $pass = $this->create('drill', $this->drill($s['id']));
        $this->review($pass)->assertOk();
        $failed = $this->drill($s['id']);
        $failed['result'] = 'failed';
        $failed['recovered_at'] = now()->subMinutes(20)->toIso8601String();
        $failed['checks']['audit_integrity'] = false;
        $row = $this->create('drill', $failed);
        $this->review($row)->assertOk();
        $this->getJson('/api/admin/backups')->assertOk()->assertJsonPath('stats.measurement_current', false)->assertJsonPath('snapshots.0.restore_verified', false);
    }
}
