<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\BusinessRegistryController;
use App\Models\User;
use App\Services\BirCompliance;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class BusinessRegistryTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'app.timezone' => 'Asia/Manila', 'cache.default' => 'array']);
        DB::purge('sqlite');
        foreach (['0001_01_01_000000_create_users_table.php', '2026_06_02_000001_create_nuers_tables.php', '2026_06_02_000002_create_enterprise_nuers_tables.php', '2026_06_02_000004_add_client_tin_to_profiles.php', '2026_06_02_000005_add_registration_address_to_merchants.php', '2026_06_02_000008_create_rdo_offices_table.php', '2026_06_02_000010_create_bir_eis_readiness_tables.php', '2026_06_02_000014_create_business_invoices_tables.php', '2026_10_15_000002_add_rmc110_compliance_controls.php', '2026_10_15_000010_add_business_registry_workflows.php', '2026_10_15_000011_create_registration_rdo_mappings.php'] as $f) {
            (require database_path('migrations/'.$f))->up();
        }$this->admin = $this->actor('super_admin');
    }

    private function actor(string $role): User
    {
        $u = User::factory()->create(['api_token' => Str::random(64)]);
        DB::table('profiles')->insert(['id' => $u->id, 'email' => $u->email, 'role' => $role]);

        return $u;
    }

    private function form(array $p = []): array
    {
        return array_replace(['request_id' => (string) Str::uuid(), 'business_name' => 'Example Business', 'tin' => '123-456-789-000', 'owner_name' => 'Example Owner', 'email' => 'owner@example.test', 'phone' => '+639123456789', 'business_type' => 'corporation', 'sector' => 'it', 'address' => 'Example Street', 'city' => 'City of Caloocan', 'region' => 'NCR', 'rdo_code' => '027', 'vat_registered' => true, 'application_type' => 'new'], $p);
    }

    private function create(array $p = []): array
    {
        return $this->withToken($this->admin->api_token)->postJson('/api/business-registry', $this->form($p))->assertCreated()->json('business');
    }

    public function test_roles_and_legacy_paths_cannot_bypass_registry_controls(): void
    {
        $this->getJson('/api/business-registry')->assertUnauthorized();
        $this->getJson('/api/tables/merchants')->assertUnauthorized();
        $client = $this->actor('client');
        $this->withToken($client->api_token)->getJson('/api/business-registry')->assertForbidden();
        $this->postJson('/api/merchant-accounts', [])->assertForbidden();
        $this->withToken($this->admin->api_token)->postJson('/api/merchant-accounts', [])->assertStatus(410);
        $this->patchJson('/api/tables/merchants', ['values' => ['status' => 'active']])->assertConflict();
        $bir = $this->actor('bir');
        $this->withToken($bir->api_token)->getJson('/api/business-registry')->assertOk();
        $this->postJson('/api/business-registry', $this->form())->assertForbidden();
    }

    public function test_create_is_idempotent_and_canonical_tin_is_unique(): void
    {
        $p = $this->form();
        $x = $this->withToken($this->admin->api_token)->postJson('/api/business-registry', $p)->assertCreated()->assertJsonPath('business.tin', '123456789000')->assertJsonPath('business.status', 'pending')->assertJsonPath('business.account_linked', false)->json('business');
        $this->postJson('/api/business-registry', $p)->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('business.id', $x['id']);
        $this->postJson('/api/business-registry', array_replace($p, ['business_name' => 'Changed']))->assertConflict();
        $this->postJson('/api/business-registry', $this->form(['tin' => '123456789']))->assertUnprocessable();
        $this->assertSame(1, DB::table('merchants')->count());
        $this->assertSame(1, DB::table('users')->count());
        $this->getJson('/api/business-registry?search=Example&type=corporation&status=pending&per_page=1')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('summary.connected', 0);
        $this->getJson('/api/business-registry?from=2026-10-16&to=2026-10-15')->assertUnprocessable();
        $this->assertTrue(app(BirCompliance::class)->auditIntegrity());
    }

    public function test_edits_are_revision_checked_and_export_escapes_formula_cells(): void
    {
        $x = $this->create();
        $p = $this->form(['business_name' => '=FORMULA()', 'revision' => 1, 'reason' => 'Updated after checking registration']);
        unset($p['request_id']);
        $this->putJson('/api/business-registry/'.$x['id'], $p)->assertOk()->assertJsonPath('business.registry_revision', 2);
        $this->putJson('/api/business-registry/'.$x['id'], $p)->assertConflict();
        $csv = $this->get('/api/business-registry/export?per_page=1')->assertOk()->streamedContent();
        $this->assertStringNotContainsString('eis_status', strtok($csv, "\n"));
        $this->assertStringContainsString("'=FORMULA()", $csv);
        $this->getJson('/api/business-registry/'.$x['id'])->assertOk()->assertJsonPath('business.documents', 0)->assertJsonMissingPath('business.account.api_token');
    }

    public function test_review_activation_requires_evidence_and_suspension_revokes_access(): void
    {
        $x = $this->create();
        $url = '/api/business-registry/'.$x['id'].'/review';
        $p = ['revision' => 1, 'stage' => 'onboarded', 'status' => 'active', 'reason' => 'Documents reviewed in controlled records'];
        $this->postJson($url, $p)->assertUnprocessable();
        $p['checks'] = array_fill_keys(['registration_certificate', 'identity', 'business_information', 'representative_authority', 'contact_verification'], true);
        $this->postJson($url, $p)->assertUnprocessable();
        $p['evidence'] = 'Review reference EVIDENCE-1';
        $this->postJson($url, $p)->assertOk()->assertJsonPath('business.status', 'active')->assertJsonPath('business.stage', 'onboarded');
        $owner = $this->actor('merchant');
        DB::table('users')->where('id', $owner->id)->update(['password' => Hash::make('OwnerPassword123')]);
        DB::table('merchants')->where('id', $x['id'])->update(['merchant_account_id' => $owner->id]);
        $p['revision'] = 2;
        $p['status'] = 'suspended';
        $this->postJson($url, $p)->assertOk()->assertJsonPath('business.status', 'suspended');
        $this->assertNull(DB::table('users')->where('id', $owner->id)->value('api_token'));
        $login=$this->postJson('/api/auth/login', ['email' => $owner->email, 'password' => 'OwnerPassword123'])->assertOk();
        $this->withToken($login->json('session.access_token'))->withHeaders(['X-NUERS-Device'=>$login->json('session.device_id')??'fixture-device'])->postJson('/api/merchant/expenses',[])->assertForbidden();
    }

    private function csv(array $rows): string
    {
        $f = fopen('php://temp', 'r+');
        fputcsv($f, BusinessRegistryController::CSV);
        foreach ($rows as $row) {
            $p = $this->form($row);
            fputcsv($f, array_map(fn ($k) => $k === 'vat_registered' ? ($p[$k] ? '1' : '0') : ($p[$k] ?? ''), BusinessRegistryController::CSV));
        }rewind($f);

        return stream_get_contents($f);
    }

    public function test_import_preview_is_private_commit_atomic_and_idempotent(): void
    {
        $id = (string) Str::uuid();
        $f = UploadedFile::fake()->createWithContent('business.csv', $this->csv([['tin' => '111111111000'], ['tin' => '222222222000']]));
        $this->withToken($this->admin->api_token)->post('/api/business-registry/import', ['request_id' => $id, 'file' => $f], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('preview.total', 2)->assertJsonCount(0, 'preview.errors');
        $this->assertSame(0, DB::table('merchants')->count());
        $other = $this->actor('admin');
        $this->withToken($other->api_token)->postJson('/api/business-registry/import/'.$id)->assertNotFound();
        $this->withToken($this->admin->api_token)->postJson('/api/business-registry/import/'.$id)->assertOk()->assertJsonPath('imported', 2);
        $this->postJson('/api/business-registry/import/'.$id)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame(2, DB::table('merchants')->count());
        $batch = DB::table('business_registry_requests')->where('id', $id)->first();
        $this->assertStringNotContainsString('Example Business', $batch->payload);
        $bad = (string) Str::uuid();
        $this->post('/api/business-registry/import', ['request_id' => $bad, 'file' => UploadedFile::fake()->createWithContent('invalid.csv', $this->csv([['tin' => '333333333000'], ['tin' => 'bad']]))], ['Accept' => 'application/json'])->assertOk()->assertJsonCount(1, 'preview.errors');
        $this->postJson('/api/business-registry/import/'.$bad)->assertUnprocessable();
        $this->assertSame(2, DB::table('merchants')->count());
    }

    public function test_single_use_invitation_never_overwrites_an_existing_account(): void
    {
        $x = $this->create();
        $id = (string) Str::uuid();
        $url = '/api/business-registry/'.$x['id'].'/invitation';
        $invite = $this->postJson($url, ['request_id' => $id, 'reason' => 'Authorized contact account provisioning'])->assertOk()->json();
        $token = explode('#token=', $invite['url'])[1];
        $this->assertSame(64, strlen($token));
        $this->assertDatabaseHas('business_registry_requests', ['token_hash' => hash('sha256', $token), 'purpose' => 'invitation']);
        $this->withoutToken()->postJson('/api/business-activation/inspect', ['token' => $token])->assertOk()->assertJsonPath('business_name', 'Example Business')->assertJsonMissingPath('email');
        $p = ['token' => $token, 'password' => 'NewPassword123', 'password_confirmation' => 'NewPassword123', 'terms_accepted' => true, 'privacy_accepted' => true, 'notice_version' => '2026-10-17.2'];
        $this->postJson('/api/business-activation', array_replace($p, ['terms_accepted' => false]))->assertUnprocessable();
        $this->postJson('/api/business-activation', $p)->assertOk();
        $u = DB::table('users')->where('email', 'owner@example.test')->first();
        $this->assertTrue(Hash::check('NewPassword123', $u->password));
        $this->assertNull($u->email_verified_at);
        $this->assertNull($u->api_token);
        $this->assertDatabaseHas('profiles', ['id' => $u->id, 'role' => 'merchant']);
        $this->postJson('/api/business-activation', $p)->assertGone();
        $this->withToken($this->admin->api_token)->postJson($url, ['request_id' => (string) Str::uuid(), 'reason' => 'Another account setup request'])->assertConflict();
    }

    public function test_revoked_and_expired_invitations_fail(): void
    {
        $x = $this->create();
        $url = '/api/business-registry/'.$x['id'].'/invitation';
        $first = $this->postJson($url, ['request_id' => (string) Str::uuid(), 'reason' => 'Initial account setup request'])->assertOk()->json('url');
        $second = $this->postJson($url, ['request_id' => (string) Str::uuid(), 'reason' => 'Replace unused setup invitation'])->assertOk()->json('url');
        $this->postJson('/api/business-activation/inspect', ['token' => explode('#token=', $first)[1]])->assertGone();
        $this->travel(49)->hours();
        $this->postJson('/api/business-activation/inspect', ['token' => explode('#token=', $second)[1]])->assertGone();
    }

    public function test_rdo_mapping_validates_hierarchy_revision_and_updates_registration_resolution(): void
    {
        $this->withToken($this->admin->api_token)->getJson('/api/admin/rdo-mappings')->assertOk()->assertJsonCount(124, 'offices');
        $p = ['city_code' => '1380300000', 'barangay_code' => '1380300025', 'rdo_code' => '047', 'source_reference' => 'Official registration jurisdiction review REF-1', 'active' => true, 'revision' => 0];
        $this->postJson('/api/admin/rdo-mappings', $p)->assertOk()->assertJsonPath('mapping.revision', 1);
        $this->getJson('/api/registration/rdo?city_code=1380300000&barangay_code=1380300025')->assertOk()->assertJsonPath('rdo.rdo_code', '047');
        $this->postJson('/api/admin/rdo-mappings', $p)->assertConflict();
        $this->postJson('/api/admin/rdo-mappings', array_replace($p, ['barangay_code' => '1381100001']))->assertUnprocessable();
        $this->postJson('/api/admin/rdo-mappings', array_replace($p, ['revision' => 1, 'active' => false]))->assertOk();
        $this->getJson('/api/registration/rdo?city_code=1380300000&barangay_code=1380300025')->assertOk()->assertJsonPath('automatic', false);
        $this->getJson('/api/registration/rdo?city_code=1380100000&barangay_code=1380100179')->assertOk()->assertJsonPath('rdo.rdo_code', '027');
    }

    public function test_named_locality_rules_are_valid_and_split_cities_are_not_guessed(): void
    {
        $geo = json_decode(file_get_contents(resource_path('data/philippine-addresses.json')), true);
        $ref = json_decode(file_get_contents(resource_path('data/registration-rdos.json')), true);
        foreach ($ref['city_rules'] as $city => $rdo) {
            $this->assertArrayHasKey($city, $geo['cities']);
            $this->assertArrayHasKey($rdo, $ref['offices']);
        }
        $this->withToken($this->admin->api_token)->getJson('/api/registration/rdo?city_code=1230800000')->assertOk()->assertJsonPath('rdo.rdo_code', '110');
        foreach (['1381300000', '0730600000', '1130700000'] as $city) {
            if (isset($geo['cities'][$city])) {
                $this->getJson('/api/registration/rdo?city_code='.$city)->assertOk()->assertJsonPath('automatic', false);
            }
        }
        DB::table('rdo_offices')->insert(['id' => 'legacy-office', 'rdo_code' => '021', 'rdo_name' => 'Legacy San Fernando', 'region' => 'Region III', 'status' => 'active']);
        $this->getJson('/api/admin/rdo-mappings')->assertOk()->assertJsonCount(124, 'offices')->assertJsonPath('legacy_offices.0.rdo_code', '021');
        $this->getJson('/api/business-registry/tin?tin=ABC123456789000')->assertOk()->assertJsonPath('format_valid',false);
    }
}
