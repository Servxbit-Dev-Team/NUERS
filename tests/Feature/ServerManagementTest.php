<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Infrastructure\ActionWorkflow;
use App\Services\Infrastructure\Audit;
use App\Services\Infrastructure\Catalog;
use App\Services\Infrastructure\ControllerGateway;
use App\Services\Infrastructure\Preflight;
use App\Services\Infrastructure\Telemetry;
use App\Services\MfaDevices;
use App\Services\Totp;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\FakeInfrastructureController;
use Tests\TestCase;

class ServerManagementTest extends TestCase
{
    private User $owner;

    private FakeInfrastructureController $gateway;

    private const BASE = '/api/admin/infrastructure';

    private const SECRET = 'JBSWY3DPEHPK3PXP';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array', 'app.timezone' => 'Asia/Manila', 'notifications.dispatch_enabled' => false]);
        DB::purge('sqlite');
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->owner = $this->account('super_admin');
        $this->gateway = new FakeInfrastructureController;
        $this->app->instance(ControllerGateway::class, $this->gateway);
    }

    private function account(string $role): User
    {
        $u = User::factory()->create(['api_token' => Str::random(64), 'password' => Hash::make('Synthetic-control-test-123!')]);
        $u->forceFill(['mfa_secret' => Crypt::encryptString(self::SECRET), 'api_mfa_verified_at' => now()])->save();
        DB::table('profiles')->insert(['id' => $u->id, 'email' => $u->email, 'role' => $role]);

        return $u;
    }

    private function node(string $code = 'WEB-01'): object
    {
        return DB::table('infra_nodes')->where('code', $code)->first();
    }

    private function proof(?User $u = null): string
    {
        $u ??= $this->owner;
        $id = (string) Str::uuid();
        DB::table('infra_proofs')->insert(['id' => $id, 'actor_id' => $u->id, 'binding' => app(MfaDevices::class)->binding($u), 'expires_at' => now('UTC')->addMinutes(5)]);

        return $id;
    }

    private function healthy(): array
    {
        config(['infrastructure.execution_enabled' => true, 'infrastructure.capabilities' => array_keys(Catalog::actions())]);
        $rows = [];
        foreach (DB::table('infra_nodes')->get() as $n) {
            $fingerprint = hash('sha256', $n->code);
            config(['infrastructure.node_pins.'.$n->code => ['fingerprint' => $fingerprint, 'environment' => 'production', 'tier' => $n->tier, 'ha_group' => $n->ha_group]]);
            $rows[] = ['code' => $n->code, 'fingerprint' => $fingerprint, 'environment' => 'production', 'observed_at' => now('UTC')->toIso8601String(), 'reachable' => true, 'healthy' => true, 'hostname' => strtolower($n->code), 'os' => 'Ubuntu 24.04 LTS', 'role' => $n->code === 'DB-02' ? 'replica' : ($n->tier === 'database' ? 'primary' : 'member'), 'cpu_percent' => 14, 'memory_percent' => 32, 'disk_percent' => 28, 'disk_total_bytes' => 960000000000, 'disk_used_bytes' => 268800000000, 'replication_lag_seconds' => 0, 'backup_verified_at' => now('UTC')->subHour()->toIso8601String(), 'services' => array_fill_keys(Catalog::SERVICES[$n->tier], 'running'), 'capabilities' => array_keys(Catalog::actions()), 'checks' => array_fill_keys(Telemetry::CHECKS, true), 'artifacts' => []];
        }
        $payload = ['schema_version' => Catalog::VERSION, 'nodes' => $rows];
        app(Telemetry::class)->ingest($payload);

        return $payload;
    }

    private function window(string $code): void
    {
        DB::table('infra_nodes')->where('code', $code)->update(['maintenance_start' => now('UTC')->subMinute(), 'maintenance_end' => now('UTC')->addHour()]);
    }

    private function body(string $action = 'service.restart', string $code = 'WEB-01'): array
    {
        return ['node_id' => $this->node($code)->id, 'action' => $action, 'reason' => 'Isolated approved synthetic maintenance test', 'parameters' => ['runbook_id' => 'QA-001'] + (str_starts_with($action, 'service.') ? ['service' => $this->node($code)->tier === 'database' ? 'mysql' : 'nginx'] : []), 'idempotency_key' => (string) Str::uuid(), 'proof_id' => $this->proof(), 'confirmation' => $code];
    }

    private function requestAction(array $v): array
    {
        return $this->withToken($this->owner->api_token)->postJson(self::BASE.'/actions', $v)->assertCreated()->json('data');
    }

    public function test_inventory_is_super_admin_only_and_unknown_metrics_are_not_zero(): void
    {
        $this->getJson(self::BASE.'/servers')->assertUnauthorized();
        foreach (['admin', 'merchant', 'bir', 'rdo', 'client'] as $role) {
            $u = $this->account($role);
            foreach (['servers', 'actions', 'events', 'export'] as $path) {
                $this->withToken($u->api_token)->getJson(self::BASE.'/'.$path)->assertForbidden();
            }
            $this->withToken($u->api_token)->postJson(self::BASE.'/actions', $this->body())->assertForbidden();
            $this->postJson(self::BASE.'/servers', [])->assertForbidden();
            $this->postJson(self::BASE.'/step-up', [])->assertForbidden();
        }
        $this->withToken($this->owner->api_token)->getJson(self::BASE.'/servers')->assertOk()->assertJsonCount(9, 'nodes')->assertJsonPath('summary.enrolled', 0)->assertJsonPath('summary.cpu', null)->assertJsonPath('summary.offline', 0)->assertJsonPath('summary.unknown', 9);
    }

    public function test_unconfigured_controls_fail_closed_and_record_blocked_request(): void
    {
        $this->gateway->available = false;
        $v = $this->body();
        $this->withToken($this->owner->api_token)->postJson(self::BASE.'/preflight', $v)->assertOk()->assertJsonPath('passed', false);
        $a = $this->requestAction($v);
        $this->assertSame('blocked', $a['state']);
        $this->assertNull(app(ActionWorkflow::class)->dispatchOne());
        $this->assertCount(0, $this->gateway->jobs);
        $this->assertTrue(app(Audit::class)->verify());
    }

    public function test_telemetry_identity_replay_staleness_and_utc_handling(): void
    {
        $payload = $this->healthy();
        $this->assertTrue(app(Telemetry::class)->fresh($this->node()));
        $this->withToken($this->owner->api_token)->getJson(self::BASE.'/servers')->assertJsonPath('summary.online', 9)->assertJsonPath('summary.cpu', 14);
        $this->assertSame(0, app(Telemetry::class)->ingest($payload));
        $this->assertSame(9, DB::table('infra_samples')->count());
        $this->travel(121)->seconds();
        $this->getJson(self::BASE.'/servers')->assertJsonPath('summary.cpu', null)->assertJsonPath('summary.unknown', 9);
        $payload['nodes'][0]['fingerprint'] = str_repeat('a', 64);
        $this->expectException(ValidationException::class);
        app(Telemetry::class)->ingest($payload);
    }

    public function test_step_up_requires_password_and_non_replayed_authenticator_and_is_single_use(): void
    {
        $this->withToken($this->owner->api_token)->postJson(self::BASE.'/step-up', ['password' => 'wrong', 'code' => '000000'])->assertForbidden();
        $code = app(Totp::class)->code(self::SECRET, intdiv(now()->timestamp, 30));
        $proof = $this->postJson(self::BASE.'/step-up', ['password' => 'Synthetic-control-test-123!', 'code' => $code])->assertOk()->json('proof_id');
        $this->postJson(self::BASE.'/step-up', ['password' => 'Synthetic-control-test-123!', 'code' => $code])->assertUnprocessable();
        $v = $this->body();
        $v['proof_id'] = $proof;
        $this->requestAction($v);
        $v['idempotency_key'] = (string) Str::uuid();
        $this->postJson(self::BASE.'/actions', $v)->assertForbidden();
    }

    public function test_healthy_service_request_dispatches_once_and_scrubs_arbitrary_output(): void
    {
        $this->healthy();
        $v = $this->body();
        $a = $this->requestAction($v);
        $this->assertSame('scheduled', $a['state']);
        $this->gateway->outcome['stdout'] = 'password=SHOULD_NOT_APPEAR';
        $this->assertSame($a['id'], app(ActionWorkflow::class)->dispatchOne());
        $this->assertSame('succeeded', DB::table('infra_actions')->where('id', $a['id'])->value('state'));
        $this->assertSame($a['id'], $this->requestAction($v)['id']);
        $this->assertNull(app(ActionWorkflow::class)->dispatchOne());
        $this->assertCount(1, $this->gateway->jobs);
        $v['reason'] = 'Changed duplicate request should be rejected';
        $this->postJson(self::BASE.'/actions', $v)->assertConflict();
        $this->assertStringNotContainsString('SHOULD_NOT_APPEAR', DB::table('infra_actions')->where('id', $a['id'])->value('result'));
        $this->assertTrue(app(Audit::class)->verify());
    }

    public function test_unhealthy_web_peer_blocks_reboot_and_lb_peer_blocks_failover(): void
    {
        $this->healthy();
        foreach ([['WEB-01', 'WEB-02', 'node.reboot'], ['LB-01', 'LB-02', 'lb.failover']] as [$target,$peer,$action]) {
            $this->window($target);
            DB::table('infra_nodes')->where('code', $peer)->update(['observed_at' => now('UTC')->subMinutes(3)]);
            $check = app(Preflight::class)->inspect($this->node($target), $action, ['runbook_id' => 'QA-001']);
            $this->assertFalse($check['passed']);
            $this->assertFalse(collect($check['checks'])->firstWhere('label', 'All HA peers are fresh, healthy and outside maintenance')['passed']);
        }
    }

    public function test_database_witness_and_ceph_quorum_are_required(): void
    {
        $this->healthy();
        foreach ([['DB-02', 'database.promote', 'witness_healthy'], ['ST-01', 'storage.maintenance', 'quorum_healthy']] as [$code,$action,$check]) {
            $this->window($code);
            $n = $this->node($code);
            $t = json_decode($n->telemetry, true);
            $t['checks'][$check] = false;
            DB::table('infra_nodes')->where('id', $n->id)->update(['telemetry' => json_encode($t)]);
            $this->assertFalse(app(Preflight::class)->inspect($this->node($code), $action, ['runbook_id' => 'QA-001'])['passed']);
        }
    }

    public function test_critical_request_cannot_self_approve_and_independent_review_dispatches(): void
    {
        $this->healthy();
        $this->window('DB-02');
        $a = $this->requestAction($this->body('database.promote', 'DB-02'));
        $this->assertSame('awaiting_approval', $a['state']);
        $this->postJson(self::BASE.'/actions/'.$a['id'].'/review', ['revision' => 1, 'decision' => 'approve', 'note' => 'Cannot approve my own request', 'proof_id' => $this->proof()])->assertForbidden();
        $reviewer = $this->account('super_admin');
        $this->withToken($reviewer->api_token)->postJson(self::BASE.'/actions/'.$a['id'].'/review', ['revision' => 1, 'decision' => 'approve', 'note' => 'Independent reviewed test operation', 'proof_id' => $this->proof($reviewer)])->assertOk()->assertJsonPath('data.state', 'scheduled');
        $this->assertSame($a['id'], app(ActionWorkflow::class)->dispatchOne());
        $this->assertSame('succeeded', DB::table('infra_actions')->where('id', $a['id'])->value('state'));
    }

    public function test_stale_or_revoked_requester_is_rechecked_before_dispatch(): void
    {
        $this->healthy();
        $a = $this->requestAction($this->body());
        DB::table('users')->where('id', $this->owner->id)->update(['api_token_hash' => null]);
        $this->assertNull(app(ActionWorkflow::class)->dispatchOne());
        $this->assertSame('blocked', DB::table('infra_actions')->where('id', $a['id'])->value('state'));
        $this->assertCount(0, $this->gateway->jobs);
    }

    public function test_ambiguous_result_locks_group_until_same_job_reconciliation(): void
    {
        $this->healthy();
        $a = $this->requestAction($this->body());
        $this->gateway->timeout = true;
        app(ActionWorkflow::class)->dispatchOne();
        $this->assertSame('uncertain', DB::table('infra_actions')->where('id', $a['id'])->value('state'));
        $b = $this->requestAction($this->body('service.restart', 'WEB-02'));
        $this->assertSame('blocked', $b['state']);
        $this->assertCount(1, $this->gateway->jobs);
        $this->assertSame(1, app(ActionWorkflow::class)->reconcile());
        $this->assertSame('succeeded', DB::table('infra_actions')->where('id', $a['id'])->value('state'));
    }

    public function test_strict_action_schema_rejects_shell_service_target_and_url_injection(): void
    {
        $this->withToken($this->owner->api_token);
        foreach ([['command' => 'rm -rf /'], ['parameters' => ['service' => 'nginx; shutdown now']], ['parameters' => ['endpoint' => 'http://169.254.169.254']], ['action' => 'execute']] as $attack) {
            $this->postJson(self::BASE.'/preflight', array_replace($this->body(), $attack))->assertUnprocessable();
        }
        $this->assertSame(0, DB::table('infra_actions')->count());
    }

    public function test_enrollment_request_does_not_trust_identity_or_report_online(): void
    {
        $this->withToken($this->owner->api_token)->postJson(self::BASE.'/servers', ['code' => 'WEB-03', 'label' => 'Synthetic standby', 'tier' => 'web', 'ha_group' => 'WEB-HA', 'environment' => 'staging', 'site' => 'Isolated QA', 'reason' => 'Testing inventory enrollment boundaries', 'proof_id' => $this->proof()])->assertCreated();
        $n = $this->node('WEB-03');
        $this->assertSame('pending', $n->enrollment);
        $this->assertNull($n->fingerprint);
        $this->assertNull($n->observed_at);
    }

    public function test_maintenance_revision_is_checked_and_export_is_audited(): void
    {
        $n = $this->node();
        $body = ['start' => now('UTC')->toIso8601String(), 'end' => now('UTC')->addHour()->toIso8601String(), 'reason' => 'Isolated maintenance window review', 'revision' => 1, 'proof_id' => $this->proof()];
        $this->withToken($this->owner->api_token)->postJson(self::BASE.'/servers/'.$n->id.'/maintenance', $body)->assertOk();
        $body['proof_id'] = $this->proof();
        $this->postJson(self::BASE.'/servers/'.$n->id.'/maintenance', $body)->assertConflict();
        $csv = $this->get(self::BASE.'/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('WEB-01', $csv);
        $this->assertStringNotContainsString('token', $csv);
        $this->assertTrue(app(Audit::class)->verify());
    }

    public function test_expired_proof_and_rotated_identity_are_rejected(): void
    {
        $v = $this->body();
        DB::table('infra_proofs')->where('id', $v['proof_id'])->update(['expires_at' => now('UTC')->subSecond()]);
        $this->withToken($this->owner->api_token)->postJson(self::BASE.'/actions', $v)->assertForbidden();
        $this->healthy();
        config(['infrastructure.node_pins.WEB-02.fingerprint' => str_repeat('b', 64)]);
        $this->assertFalse(app(Telemetry::class)->fresh($this->node('WEB-02')));
        $this->assertFalse(app(Preflight::class)->inspect($this->node(), 'service.restart', ['service' => 'nginx'])['passed']);
    }

    public function test_failed_postcheck_never_marks_success_or_releases_ha_group(): void
    {
        $this->healthy();
        $a = $this->requestAction($this->body());
        $this->gateway->outcome = ['status' => 'succeeded', 'code' => 'POSTCHECK_FAILED', 'postcheck_passed' => false];
        app(ActionWorkflow::class)->dispatchOne();
        $this->assertSame('uncertain', DB::table('infra_actions')->where('id', $a['id'])->value('state'));
        $this->assertFalse(app(Preflight::class)->inspect($this->node('WEB-02'), 'service.restart', ['service' => 'nginx'])['passed']);
    }

    public function test_identity_mismatch_and_unapproved_group_cannot_enroll(): void
    {
        $payload = $this->healthy();
        $payload['nodes'][0]['fingerprint'] = str_repeat('a', 64);
        try {
            app(Telemetry::class)->ingest($payload);
            $this->fail('Unpinned identity accepted');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        DB::table('infra_nodes')->where('code', 'WEB-01')->update(['ha_group' => 'FAKE-HA']);
        $this->assertFalse(app(Telemetry::class)->fresh($this->node()));
    }

    public function test_production_restore_needs_two_distinct_independent_approvals(): void
    {
        $this->healthy();
        $this->window('WEB-01');
        $artifact = (string) Str::uuid();
        $n = $this->node();
        $t = json_decode($n->telemetry, true);
        $t['artifacts'] = [$artifact];
        DB::table('infra_nodes')->where('id', $n->id)->update(['telemetry' => json_encode($t)]);
        $v = $this->body('restore.production');
        $v['parameters']['artifact_id'] = $artifact;
        $a = $this->requestAction($v);
        $this->assertSame(2, $a['preflight']['approvals_required']);
        $r1 = $this->account('super_admin');
        $r2 = $this->account('super_admin');
        $review = fn ($r, $rev) => ['revision' => $rev, 'decision' => 'approve', 'note' => 'Isolated recovery evidence independently reviewed', 'proof_id' => $this->proof($r)];
        $this->withToken($r1->api_token)->postJson(self::BASE.'/actions/'.$a['id'].'/review', $review($r1, 1))->assertOk()->assertJsonPath('data.state', 'awaiting_approval');
        $this->postJson(self::BASE.'/actions/'.$a['id'].'/review', $review($r1, 2))->assertConflict();
        $this->withToken($r2->api_token)->postJson(self::BASE.'/actions/'.$a['id'].'/review', $review($r2, 2))->assertOk()->assertJsonPath('data.state', 'scheduled');
        $this->assertSame($a['id'], app(ActionWorkflow::class)->dispatchOne());
    }

    public function test_stale_telemetry_at_dispatch_blocks_previously_approved_request(): void
    {
        $this->healthy();
        $a = $this->requestAction($this->body());
        $this->travel(121)->seconds();
        $this->assertNull(app(ActionWorkflow::class)->dispatchOne());
        $this->assertSame('blocked', DB::table('infra_actions')->where('id', $a['id'])->value('state'));
    }

    public function test_audit_mutation_is_detected(): void
    {
        app(Audit::class)->record('test', null, 'qa', ['safe' => true]);
        $this->assertTrue(app(Audit::class)->verify());
        DB::table('infra_events')->update(['details' => '{"safe":false}']);
        $this->assertFalse(app(Audit::class)->verify());
    }
}
