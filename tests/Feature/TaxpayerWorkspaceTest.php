<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BirCompliance;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TaxpayerWorkspaceTest extends TestCase
{
    private User $admin;

    private string $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'app.timezone' => 'Asia/Manila', 'cache.default' => 'array']);
        DB::purge('sqlite');
        foreach (glob(database_path('migrations/*.php')) as $migrationFile) {
            (require $migrationFile)->up();
        }$this->admin = $this->actor('super_admin');
        $this->merchant = (string) Str::uuid();
        DB::table('merchants')->insert(['id' => $this->merchant, 'business_name' => 'Example Business', 'tin' => '123456789000', 'owner_name' => 'Owner', 'email' => 'owner@example.test', 'status' => 'active']);
        $this->withToken($this->admin->api_token);
    }

    private function actor(string $role)
    {
        $u = User::factory()->create(['api_token' => Str::random(64)]);
        DB::table('profiles')->insert(['id' => $u->id, 'email' => $u->email, 'role' => $role]);

        return $u;
    }

    private function permit(array $p = [])
    {
        return array_replace(['request_id' => (string) Str::uuid(), 'merchant_id' => $this->merchant, 'kind' => 'PTI', 'reference' => 'PTI-EXAMPLE-1', 'environment' => 'certification', 'branch_code' => '00000', 'scope' => 'Example main branch approved system', 'issued_at' => now()->subDays(30)->toDateString(), 'valid_until' => now()->addDays(20)->toDateString(), 'status' => 'active', 'evidence' => 'Example authority document reference', 'reason' => 'Document checked against taxpayer registration'], $p);
    }

    private function configForm(array $p = [])
    {
        return array_replace(['request_id' => (string) Str::uuid(), 'revision' => 0, 'name' => 'BIR certification configuration', 'provider' => 'bir_eis', 'merchant_id' => $this->merchant, 'environment' => 'certification', 'base_url' => 'https://example.test', 'client_id' => 'Example-client', 'api_version' => 'example-v1', 'timeout_seconds' => 30, 'max_retries' => 3, 'status' => 'configured', 'evidence' => 'Provider specification reviewed', 'reason' => 'Controlled certification configuration'], $p);
    }

    public function test_roles_and_permit_revision_idempotence_and_validity()
    {
        $this->withoutToken()->getJson('/api/taxpayer-authorizations')->assertUnauthorized();
        $client = $this->actor('client');
        $this->withToken($client->api_token)->getJson('/api/integration-workspace')->assertForbidden();
        $bir = $this->actor('bir');
        $this->withToken($bir->api_token)->getJson('/api/taxpayer-authorizations')->assertOk();
        $this->postJson('/api/taxpayer-authorizations', $this->permit())->assertForbidden();
        $this->withToken($this->admin->api_token);
        $p = $this->permit();
        $x = $this->postJson('/api/taxpayer-authorizations', $p)->assertCreated()->json('record');
        $this->postJson('/api/taxpayer-authorizations', $p)->assertOk()->assertJsonPath('duplicate', true);
        $this->postJson('/api/taxpayer-authorizations', array_replace($p, ['scope' => 'different scope']))->assertConflict();
        $this->getJson('/api/taxpayer-authorizations?kind=PTI&validity=expiring')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.effective_status', 'expiring_soon');
        $edit = array_replace($p, ['revision' => 1, 'status' => 'suspended']);
        $this->putJson('/api/taxpayer-authorizations/'.$x['id'], $edit)->assertOk()->assertJsonPath('record.revision', 2);
        $this->putJson('/api/taxpayer-authorizations/'.$x['id'], $edit)->assertConflict();
        $this->postJson('/api/taxpayer-authorizations', $this->permit(['reference' => 'BadDates', 'valid_until' => now()->subDays(60)->toDateString()]))->assertUnprocessable();
        $this->postJson('/api/taxpayer-authorizations', $this->permit(['reference' => 'PTI-EXAMPLE-1']))->assertUnprocessable();
        $this->assertTrue(app(BirCompliance::class)->auditIntegrity());
    }

    public function test_devices_require_current_ptt_and_observations_cannot_claim_connected()
    {
        $pti = $this->postJson('/api/taxpayer-authorizations', $this->permit())->json('record');
        $ptt = $this->postJson('/api/taxpayer-authorizations', $this->permit(['kind' => 'PTT', 'reference' => 'PTT-1']))->assertCreated()->json('record');
        $d = ['request_id' => (string) Str::uuid(), 'revision' => 0, 'authorization_id' => $pti['id'], 'name' => 'Main POS', 'serial_number' => 'ASSET-1', 'branch' => 'Main', 'device_type' => 'pos', 'status' => 'registered', 'reason' => 'Device inventory checked'];
        $this->postJson('/api/taxpayer-devices', $d)->assertUnprocessable();
        $d['authorization_id'] = $ptt['id'];
        $x = $this->postJson('/api/taxpayer-devices', $d)->assertCreated()->json('record');
        $o = ['request_id' => (string) Str::uuid(), 'status' => 'connected', 'evidence' => 'Manual observation evidence', 'reason' => 'Checking local connection', 'observed_at' => now()->subMinute()->toIso8601String()];
        $this->postJson('/api/taxpayer-devices/'.$x['id'].'/observations', $o)->assertUnprocessable();
        $o['status'] = 'disconnected';
        $this->postJson('/api/taxpayer-devices/'.$x['id'].'/observations', $o)->assertCreated();
        $this->postJson('/api/taxpayer-devices/'.$x['id'].'/observations', $o)->assertOk()->assertJsonPath('duplicate', true);
        $this->getJson('/api/taxpayer-authorizations?tab=devices')->assertJsonPath('data.0.connection_status', 'unverified');
        $this->getJson('/api/taxpayer-authorizations?tab=logs')->assertJsonPath('total', 1);
        DB::table('taxpayer_authorizations')->where('id', $ptt['id'])->update(['valid_until' => now()->subDay()->toDateString()]);
        $d['serial_number'] = 'ASSET-2';
        $d['request_id'] = (string) Str::uuid();
        $this->postJson('/api/taxpayer-devices', $d)->assertUnprocessable();
    }

    public function test_expired_maintained_permit_blocks_matching_integration()
    {
        $p = $this->permit(['valid_until' => now()->subDay()->toDateString()]);
        $this->postJson('/api/taxpayer-authorizations', $p)->assertCreated();
        $i = DB::table('eis_integrations')->first();
        DB::table('eis_integrations')->where('id', $i->id)->update(['merchant_id' => $this->merchant, 'permit_to_issue_number' => $p['reference'], 'pti_status' => 'approved']);
        $gate = app(BirCompliance::class)->gate(DB::table('eis_integrations')->find($i->id), false);
        $this->assertContains('PTI maintained authority is not current for this taxpayer and environment.', $gate['blockers']);
        $this->assertFalse($gate['can_issue']);
    }

    public function test_csv_preview_commit_is_atomic_and_actor_scoped()
    {
        $template = $this->get('/api/taxpayer-authorizations/template')->assertOk()->streamedContent();
        $row = [$this->merchant, 'PTT', 'PTT-IMPORT', 'certification', 'Main branch scope', now()->subDay()->toDateString(), '', 'Imported source document reference'];
        $f = fopen('php://temp', 'r+');
        fputcsv($f, $row);
        rewind($f);
        $csv = $template.stream_get_contents($f);
        $preview = $this->post('/api/taxpayer-authorizations/import', ['file' => UploadedFile::fake()->createWithContent('permits.csv', $csv)])->assertOk()->assertJsonPath('errors', [])->json();
        $other = $this->actor('admin');
        $this->withToken($other->api_token)->postJson('/api/taxpayer-authorizations/import/'.$preview['id'])->assertNotFound();
        $this->withToken($this->admin->api_token)->postJson('/api/taxpayer-authorizations/import/'.$preview['id'])->assertOk()->assertJsonPath('imported', 1);
        $this->postJson('/api/taxpayer-authorizations/import/'.$preview['id'])->assertJsonPath('duplicate', true);
        $this->assertSame('pending', DB::table('taxpayer_authorizations')->first()->status);
        $this->get('/api/taxpayer-authorizations/export')->assertOk();
    }

    public function test_integration_secrets_encrypted_masked_revision_checked_and_revoked()
    {
        $p = $this->configForm();
        $c = $this->postJson('/api/integration-workspace/connections', $p)->assertCreated()->json('connection');
        $secret = 'Example-provider-secret-only-for-test-123';
        $this->postJson('/api/integration-workspace/connections/'.$c['id'].'/credential', ['revision' => 1, 'secret' => $secret, 'revoke' => false, 'reason' => 'Provider credential replaced for test'])->assertOk();
        $stored = DB::table('integration_connections')->find($c['id']);
        $this->assertNotSame($secret, $stored->encrypted_secret);
        $this->assertSame($secret, Crypt::decryptString($stored->encrypted_secret));
        $json = $this->getJson('/api/integration-workspace')->assertOk()->assertJsonPath('connections.0.credential_configured', true)->getContent();
        $this->assertStringNotContainsString($secret, $json);
        $this->assertStringNotContainsString($secret, DB::table('eis_audit_events')->get()->toJson());
        $this->putJson('/api/integration-workspace/connections/'.$c['id'], array_replace($p, ['revision' => 1]))->assertConflict();
        $this->postJson('/api/integration-workspace/connections/'.$c['id'].'/diagnostics')->assertOk()->assertJsonPath('connected', false)->assertJsonPath('external_request', false);
        $this->postJson('/api/integration-workspace/connections/'.$c['id'].'/credential', ['revision' => 2, 'revoke' => true, 'reason' => 'Revoke credential during testing'])->assertOk();
        $this->assertNull(DB::table('integration_connections')->find($c['id'])->encrypted_secret);
    }

    public function test_configuration_rejects_unsafe_urls_and_clears_secret_when_destination_changes()
    {
        foreach (['http://example.test', 'https://localhost', 'https://127.0.0.1', 'https://user:password@example.test', 'https://example.test?token=example'] as $url) {
            $this->postJson('/api/integration-workspace/connections', $this->configForm(['base_url' => $url]))->assertUnprocessable();
        }$p = $this->configForm();
        $c = $this->postJson('/api/integration-workspace/connections', $p)->assertCreated()->json('connection');
        $this->postJson('/api/integration-workspace/connections/'.$c['id'].'/credential', ['revision' => 1, 'secret' => 'Example-secret-for-testing', 'revoke' => false, 'reason' => 'Test provider secret replacement'])->assertOk();
        $this->putJson('/api/integration-workspace/connections/'.$c['id'], array_replace($p, ['revision' => 2, 'base_url' => 'https://new.example.test']))->assertOk()->assertJsonPath('connection.credential_configured', false);
        $bir = $this->actor('bir');
        $this->withToken($bir->api_token)->postJson('/api/integration-workspace/connections', $p)->assertForbidden();
    }

    public function test_endpoint_webhook_configuration_and_redacted_logs()
    {
        $c = $this->postJson('/api/integration-workspace/connections', $this->configForm())->json('connection');
        $e = ['connection_id' => $c['id'], 'revision' => 0, 'name' => 'Example endpoint', 'method' => 'POST', 'path' => '/example/invoices', 'description' => 'From controlled provider specification', 'enabled' => true, 'reason' => 'Configured from provider specification'];
        $endpoint = $this->postJson('/api/integration-workspace/endpoints', $e)->assertOk()->json('record');
        $this->postJson('/api/integration-workspace/endpoints', $e)->assertUnprocessable();
        $this->putJson('/api/integration-workspace/endpoints/'.$endpoint['id'], array_replace($e, ['revision' => 1, 'enabled' => false]))->assertOk();
        $this->putJson('/api/integration-workspace/endpoints/'.$endpoint['id'], array_replace($e, ['revision' => 1]))->assertConflict();
        $h = ['connection_id' => $c['id'], 'revision' => 0, 'url' => 'https://hooks.example.test/nuers', 'events' => ['transmission.acknowledged'], 'status' => 'configured', 'secret' => str_repeat('example-signing-secret-', 2), 'evidence' => 'Destination authorization confirmed', 'reason' => 'Controlled webhook configuration'];
        $this->postJson('/api/integration-workspace/webhooks', $h)->assertOk();
        $response = $this->getJson('/api/integration-workspace')->assertOk();
        $this->assertArrayNotHasKey('encrypted_secret', $response->json('webhooks.0'));
        $i = DB::table('eis_integrations')->first();
        DB::table('eis_api_request_logs')->insert(['id' => (string) Str::uuid(), 'integration_id' => $i->id, 'method' => 'POST', 'endpoint' => 'https://example.test/invoices?token=secret-query', 'status_code' => 422, 'error_message' => 'token=secret-log rejected', 'created_at' => now(), 'updated_at' => now()]);
        $j = $this->getJson('/api/integration-workspace?status=failed')->assertOk();
        $this->assertStringNotContainsString('secret-query', $j->getContent());
        $this->assertStringNotContainsString('secret-log', $j->getContent());
        $csv = $this->get('/api/integration-workspace/export?status=failed')->assertOk()->streamedContent();
        $this->assertStringNotContainsString('secret-query', $csv);
        $this->assertStringNotContainsString('secret-log', $csv);
        $this->assertTrue(app(BirCompliance::class)->auditIntegrity());
    }
}
