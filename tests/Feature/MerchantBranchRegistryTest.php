<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BirCompliance;
use App\Services\MerchantBranches;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MerchantBranchRegistryTest extends TestCase
{
    private User $owner;

    private User $other;

    private string $merchant;

    private string $foreign;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array', 'notifications.dispatch_enabled' => false]);
        DB::purge('sqlite');
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        [$this->owner,$this->merchant] = $this->account();
        [$this->other,$this->foreign] = $this->account();
        Storage::fake('local');
    }

    private function account(string $role = 'merchant'): array
    {
        $u = User::factory()->create(['api_token' => Str::random(64)]);
        DB::table('profiles')->insert(['id' => $u->id, 'email' => $u->email, 'role' => $role]);
        $id = (string) Str::uuid();
        DB::table('merchants')->insert(['id' => $id, 'merchant_account_id' => $u->id, 'business_name' => 'Synthetic Registry Test', 'tin' => '123456789', 'status' => 'active']);

        return [$u, $id];
    }

    private function body(array $x = []): array
    {
        return [...['request_id' => (string) Str::uuid(), 'name' => 'QA Makati Branch', 'bir_branch_code' => '00001', 'address' => 'Synthetic Test Street, Makati', 'city' => 'Makati', 'region' => 'NCR', 'rdo_code' => '047', 'branch_type' => 'branch', 'status' => 'active', 'manager_name' => 'QA Manager', 'phone' => '+639171234567', 'email' => 'qa@example.test', 'reason' => 'Synthetic registry test fixture'], ...$x];
    }

    private function create(array $x = []): array
    {
        return $this->withToken($this->owner->api_token)->postJson('/api/merchant/branches', $this->body($x))->assertCreated()->json('data');
    }

    private function terminal(string $branch, array $x = []): array
    {
        return [...['request_id' => (string) Str::uuid(), 'branch_id' => $branch, 'identifier' => 'POS-001', 'name' => 'QA Checkout', 'pos_type' => 'pos', 'status' => 'active', 'api_key_id' => null, 'taxpayer_device_id' => null, 'reason' => 'Synthetic terminal registration'], ...$x];
    }

    private function key(string $merchant, string $code, string $status = 'active'): string
    {
        $id = (string) Str::uuid();
        DB::table('merchant_api_keys')->insert(['id' => $id, 'merchant_id' => $merchant, 'name' => 'Synthetic key', 'environment' => 'live', 'branch_code' => $code, 'key_prefix' => 'qa', 'key_hash' => hash('sha256', $id), 'key_last_four' => 'test', 'status' => $status, 'scopes' => '[]', 'ip_whitelist' => '[]', 'rate_limit_per_day' => 100, 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    public function test_tenant_isolation_authorization_and_forged_derived_states(): void
    {
        $this->getJson('/api/merchant/branches')->assertUnauthorized();
        [$client] = $this->account('client');
        $this->withToken($client->api_token)->getJson('/api/merchant/branches')->assertForbidden();
        $b = $this->create();
        $this->withToken($this->other->api_token)->getJson('/api/merchant/branches')->assertOk()->assertJsonPath('summary.total_branches', 0);
        $this->withToken($this->other->api_token)->getJson('/api/merchant/branches?branch_id='.$b['id'])->assertNotFound();
        $this->withToken($this->other->api_token)->putJson('/api/merchant/branches/'.$b['id'], [...$this->body(), 'revision' => 1])->assertNotFound();
        $this->withToken($this->owner->api_token)->postJson('/api/merchant/branches', $this->body(['review_status' => 'verified']))->assertUnprocessable();
        $this->postJson('/api/merchant/terminals', $this->terminal($b['id'], ['connection_status' => 'connected']))->assertUnprocessable();
        $this->getJson('/api/admin/branch-reviews')->assertForbidden();
    }

    public function test_branch_idempotency_duplicate_identity_stale_updates_and_immutable_code(): void
    {
        $v = $this->body();
        $b = $this->withToken($this->owner->api_token)->postJson('/api/merchant/branches', $v)->assertCreated()->json('data');
        $this->postJson('/api/merchant/branches', $v)->assertOk()->assertJsonPath('data.id', $b['id']);
        $this->assertDatabaseCount('merchant_branches', 1);
        $this->postJson('/api/merchant/branches', [...$v, 'name' => 'Conflicting request'])->assertConflict();
        $this->postJson('/api/merchant/branches', $this->body())->assertUnprocessable();
        $this->putJson('/api/merchant/branches/'.$b['id'], [...$v, 'revision' => 1, 'city' => 'Quezon City'])->assertOk()->assertJsonPath('data.revision', 2);
        $this->putJson('/api/merchant/branches/'.$b['id'], [...$v, 'revision' => 1])->assertConflict();
        $this->putJson('/api/merchant/branches/'.$b['id'], [...$v, 'revision' => 2, 'bir_branch_code' => '00002'])->assertUnprocessable();
        $raw = DB::table('merchant_branches')->first();
        $this->assertStringNotContainsString('QA Manager', $raw->contact_encrypted);
        $this->assertSame('QA Manager', json_decode(Crypt::decryptString($raw->contact_encrypted), true)['manager_name']);
        $this->assertDatabaseCount('merchant_branch_events', 2);
    }

    public function test_draft_without_branch_code_and_head_office_uniqueness(): void
    {
        $this->withToken($this->owner->api_token)->postJson('/api/merchant/branches', $this->body(['bir_branch_code' => null]))->assertUnprocessable();
        $this->create(['bir_branch_code' => null, 'status' => 'inactive', 'branch_type' => 'main']);
        $this->postJson('/api/merchant/branches', $this->body(['branch_type' => 'main']))->assertUnprocessable();
        $this->postJson('/api/merchant/branches', $this->body(['bir_branch_code' => '001']))->assertUnprocessable();
        $this->create(['rdo_code' => '093A']);
        $this->postJson('/api/merchant/branches', $this->body(['bir_branch_code' => '00003', 'phone' => '--- () ---']))->assertUnprocessable();
    }

    public function test_terminal_assignments_enforce_tenant_branch_expiry_and_revisions(): void
    {
        $a = $this->create();
        $b = $this->create(['bir_branch_code' => '00002', 'name' => 'QA Second Branch']);
        $key = $this->key($this->merchant, '00001');
        $foreign = $this->key($this->foreign, '00001');
        $this->postJson('/api/merchant/terminals', $this->terminal($a['id'], ['api_key_id' => $foreign]))->assertUnprocessable();
        $this->postJson('/api/merchant/terminals', $this->terminal($b['id'], ['api_key_id' => $key]))->assertUnprocessable();
        $v = $this->terminal($a['id'], ['api_key_id' => $key]);
        $id = $this->postJson('/api/merchant/terminals', $v)->assertCreated()->json('data.id');
        $this->postJson('/api/merchant/terminals', $v)->assertOk()->assertJsonPath('data.id', $id);
        $this->putJson('/api/merchant/terminals/'.$id, [...$v, 'revision' => 1, 'branch_id' => $b['id']])->assertUnprocessable();
        $this->putJson('/api/merchant/terminals/'.$id, [...$v, 'revision' => 1, 'branch_id' => $b['id'], 'api_key_id' => null])->assertOk();
        $this->putJson('/api/merchant/terminals/'.$id, [...$v, 'revision' => 1])->assertConflict();
        $this->withToken($this->other->api_token)->putJson('/api/merchant/terminals/'.$id, [...$v, 'revision' => 2])->assertNotFound();
        DB::table('merchant_api_keys')->where('id', $key)->update(['expires_at' => now()->subMinute()]);
        $this->withToken($this->owner->api_token)->postJson('/api/merchant/terminals', $this->terminal($a['id'], ['identifier' => 'POS-002', 'api_key_id' => $key]))->assertUnprocessable();
    }

    public function test_no_api_activity_or_merchant_claim_can_create_connectivity_or_bir_approval(): void
    {
        $b = $this->create();
        $key = $this->key($this->merchant, '00001');
        $this->postJson('/api/merchant/terminals', $this->terminal($b['id'], ['api_key_id' => $key]))->assertCreated();
        $r = $this->getJson('/api/merchant/branches')->assertOk()->assertJsonPath('terminals.data.0.connection_status', 'not_observed')->assertJsonPath('terminals.data.0.issuance_ready', false)->assertJsonPath('selected.readiness.can_transmit', false)->assertJsonPath('selected.readiness.can_issue', false);
        $this->assertStringNotContainsString('key_hash', $r->getContent());
        $this->assertStringNotContainsString('contact_encrypted', $r->getContent());
        $this->assertStringNotContainsString('private_path', $r->getContent());
    }

    private function evidence(string $branch): void
    {
        foreach (array_keys(MerchantBranches::DOCUMENTS) as $kind) {
            $this->withToken($this->owner->api_token)->post('/api/merchant/branches/'.$branch.'/documents', ['kind' => $kind, 'reason' => 'Synthetic evidence upload for QA', 'file' => UploadedFile::fake()->create('proof.pdf', 5, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated();
        }
    }

    public function test_private_encrypted_upload_download_and_review_separation(): void
    {
        $b = $this->create();
        $this->postJson('/api/merchant/branches/'.$b['id'].'/request-review', ['revision' => 1, 'reason' => 'Please review this identity evidence'])->assertUnprocessable();
        $this->evidence($b['id']);
        $doc = DB::table('merchant_branch_documents')->first();
        $this->assertStringNotContainsString('%PDF', Storage::disk('local')->get($doc->private_path));
        $this->get('/api/merchant/branch-documents/'.$doc->id)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->withToken($this->other->api_token)->getJson('/api/merchant/branch-documents/'.$doc->id)->assertNotFound();
        $this->withToken($this->owner->api_token)->postJson('/api/merchant/branches/'.$b['id'].'/request-review', ['revision' => 6, 'reason' => 'Please independently review the uploaded evidence'])->assertOk();
        $this->postJson('/api/admin/branch-reviews/'.$b['id'], ['revision' => 7, 'decision' => 'verified', 'reference' => 'QA independent reference', 'reason' => 'Synthetic review evidence'])->assertForbidden();
        [$admin] = $this->account('admin');
        $this->withToken($admin->api_token)->getJson('/api/admin/branch-reviews')->assertOk()->assertJsonCount(1, 'data.data');
        $this->getJson('/api/admin/branch-reviews/'.$b['id'])->assertOk()->assertJsonCount(5, 'documents');
        $this->get('/api/admin/branch-documents/'.$doc->id)->assertOk();
        $this->postJson('/api/admin/branch-reviews/'.$b['id'], ['revision' => 7, 'decision' => 'verified', 'reference' => 'QA independent reference', 'reason' => 'Synthetic independent identity review'])->assertOk();
        $this->withToken($this->owner->api_token)->getJson('/api/merchant/branches')->assertJsonPath('selected.branch.review_status', 'verified')->assertJsonPath('selected.readiness.can_transmit', false);
        $this->evidenceReplacement($b['id']);
        $this->getJson('/api/merchant/branches')->assertJsonPath('selected.branch.review_status', 'draft');
    }

    private function evidenceReplacement(string $id): void
    {
        $this->post('/api/merchant/branches/'.$id.'/documents', ['kind' => 'registration', 'reason' => 'Synthetic changed registration evidence', 'file' => UploadedFile::fake()->createWithContent('different.pdf', "%PDF-1.4\nSynthetic replacement evidence\n%%EOF")], ['Accept' => 'application/json'])->assertCreated();
    }

    public function test_suspended_write_block_filters_exports_and_csv_formula_defense(): void
    {
        $a = $this->create(['name' => '=HYPERLINK("bad")', 'city' => 'Makati']);
        $this->create(['bir_branch_code' => '00002', 'city' => 'Cebu', 'name' => 'Other QA branch']);
        $this->getJson('/api/merchant/branches?city=Makati')->assertJsonPath('branches.total', 1)->assertJsonPath('summary.total_branches', 2);
        $csv = $this->get('/api/merchant/branches/export?city=Makati')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString('Other QA branch', $csv);
        $this->getJson('/api/merchant/branches?search='.urlencode("' OR 1=1 --"))->assertJsonPath('branches.total', 0);
        $this->getJson('/api/merchant/branches?per_page=10000')->assertUnprocessable();
        DB::table('merchants')->where('id', $this->merchant)->update(['status' => 'suspended']);
        $this->postJson('/api/merchant/branches', $this->body(['bir_branch_code' => '00003']))->assertForbidden();
        $this->getJson('/api/merchant/branches')->assertOk();
    }

    public function test_inactive_registry_branch_blocks_existing_production_authority_gate(): void
    {
        $b = $this->create(['status' => 'inactive']);
        DB::table('eis_integrations')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $this->merchant, 'branch_code' => '00001', 'environment' => 'production', 'created_at' => now(), 'updated_at' => now()]);
        $i = app(BirCompliance::class)->integration($this->merchant, '00001', 'production');
        $this->assertContains('The registered branch is operationally inactive.', app(BirCompliance::class)->gate($i, false)['blockers']);
    }
    private function structuredAddress(array $changes = []): array
    {
        return [...['region_code' => '1300000000', 'province_code' => '1300000000', 'city_code' => '1380100000', 'barangay_code' => '1380100179', 'street_address' => 'Synthetic Makabud Street', 'zip_code' => '1427', 'rdo_code' => '027'], ...$changes];
    }

    public function test_structured_branch_address_is_canonical_mapped_and_retry_safe(): void
    {
        $payload = $this->body(['structured_address' => $this->structuredAddress(['rdo_code' => null]), 'rdo_code' => null, 'city' => 'Untrusted city label', 'region' => 'Untrusted region label', 'address' => 'Untrusted street label']);
        $r = $this->withToken($this->owner->api_token)->postJson('/api/merchant/branches', $payload)->assertCreated()
            ->assertJsonPath('data.city', 'City of Caloocan')->assertJsonPath('data.region', 'National Capital Region (NCR)')->assertJsonPath('data.rdo_code', '027')
            ->assertJsonPath('data.structured_address.barangay_code', '1380100179')->assertJsonPath('data.structured_address.zip_code', '1427');
        $this->assertStringContainsString('Synthetic Makabud Street, Barangay 179, City of Caloocan', $r->json('data.address'));
        $this->assertStringNotContainsString('Untrusted', $r->getContent());
        $this->postJson('/api/merchant/branches', $payload)->assertOk()->assertJsonPath('data.id', $r->json('data.id'));
        $this->assertDatabaseCount('merchant_branches', 1);
        $this->assertDatabaseCount('merchant_branch_events', 1);
        $id = $r->json('data.id');
        $v = $payload;
        unset($v['request_id']);
        $v['revision'] = 1;
        $v['structured_address']['street_address'] = 'Updated Synthetic Branch Street';
        $this->putJson('/api/merchant/branches/'.$id, $v)->assertOk()->assertJsonPath('data.structured_address.street_address', 'Updated Synthetic Branch Street')->assertJsonPath('data.review_status', 'draft')->assertJsonPath('data.revision', 2);
    }

    public function test_structured_branch_rejects_wrong_rdo_parent_chain_and_incomplete_locations(): void
    {
        $this->withToken($this->owner->api_token);
        $this->postJson('/api/merchant/branches', $this->body(['structured_address' => $this->structuredAddress(['rdo_code' => '047'])]))->assertUnprocessable()->assertJsonValidationErrors('rdo_code');
        $this->postJson('/api/merchant/branches', $this->body(['structured_address' => $this->structuredAddress(['province_code' => 'invalid'])]))->assertUnprocessable();
        $geo = app(\App\Services\RegistrationAddress::class)->geography();
        $foreign = collect($geo['barangays'])->first(fn ($b) => $b['parent'] !== '1380100000');
        $this->postJson('/api/merchant/branches', $this->body(['structured_address' => $this->structuredAddress(['barangay_code' => $foreign['code']])]))->assertUnprocessable();
        $this->postJson('/api/merchant/branches', $this->body(['structured_address' => $this->structuredAddress(['zip_code' => '12'])]))->assertUnprocessable();
        $this->assertDatabaseCount('merchant_branches', 0);
        $this->assertDatabaseCount('merchant_branch_events', 0);
    }

    public function test_business_address_autofill_is_tenant_scoped_and_ignores_outdated_location_snapshots(): void
    {
        $a = $this->structuredAddress();
        $service = app(\App\Services\RegistrationAddress::class);
        $canonical = $service->validate($a);
        DB::table('merchants')->where('id', $this->merchant)->update(['address' => $canonical['address'], 'city' => 'City of Caloocan', 'region' => 'National Capital Region (NCR)', 'rdo_code' => '027', 'registry_details' => json_encode(['address' => $canonical['details']])]);
        DB::table('merchants')->where('id', $this->foreign)->update(['address' => 'Foreign private business address']);
        $this->withToken($this->owner->api_token)->getJson('/api/merchant/branches')->assertOk()->assertJsonPath('merchant.registered_address.city_code', '1380100000')->assertJsonPath('merchant.registered_address.street_address', 'Synthetic Makabud Street')->assertJsonMissing(['address' => 'Foreign private business address']);
        DB::table('business_registrations')->insert(['id' => (string) Str::uuid(), 'user_id' => $this->owner->id, 'merchant_id' => $this->merchant, 'business_tin' => '123456789000', 'contact_name' => 'Synthetic owner', 'contact_position' => 'Owner', 'mobile' => '+639171234567', 'terms_version' => 'synthetic', 'privacy_version' => 'synthetic', 'consented_at' => now(), 'address_details' => json_encode($canonical['details']), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('merchants')->where('id', $this->merchant)->update(['registry_details' => '{}']);
        $this->getJson('/api/merchant/branches')->assertOk()->assertJsonPath('merchant.registered_address.barangay_code', '1380100179')->assertJsonPath('merchant.registered_address.street_address', 'Synthetic Makabud Street');
        DB::table('merchants')->where('id', $this->merchant)->update(['address' => 'New unstructured saved business address', 'city' => 'New city']);
        $this->getJson('/api/merchant/branches')->assertOk()->assertJsonPath('merchant.registered_address.city_code', '')->assertJsonPath('merchant.registered_address.street_address', 'New unstructured saved business address');
    }

    public function test_manual_rdo_is_required_when_maintained_mapping_does_not_resolve(): void
    {
        DB::table('registration_rdo_mappings')->insert(['scope' => 'city:1380100000', 'city_code' => '1380100000', 'rdo_code' => '027', 'active' => false, 'source_reference' => 'Synthetic manual confirmation rule', 'updated_by' => (string) $this->owner->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->withToken($this->owner->api_token);
        $v = $this->body(['structured_address' => $this->structuredAddress(['rdo_code' => null]), 'rdo_code' => null]);
        $this->postJson('/api/merchant/branches', $v)->assertUnprocessable();
        $v['structured_address']['rdo_code'] = '027';
        $this->postJson('/api/merchant/branches', $v)->assertCreated()->assertJsonPath('data.rdo_code', '027');
    }

    public function test_legacy_branch_without_optional_contact_keys_remains_editable(): void
    {
        $branch = $this->create();
        DB::table('merchant_branches')->where('id', $branch['id'])->update(['contact_encrypted' => Crypt::encryptString('{}')]);
        $this->getJson('/api/merchant/branches')->assertOk()->assertJsonPath('branches.data.0.manager_name', null)->assertJsonPath('branches.data.0.email', null)->assertJsonPath('branches.data.0.structured_address', null);
    }

    public function test_json_storage_formatting_does_not_reset_an_unchanged_branch_review(): void
    {
        $payload = $this->body(['structured_address' => $this->structuredAddress(), 'rdo_code' => '027']);
        $r = $this->withToken($this->owner->api_token)->postJson('/api/merchant/branches', $payload)->assertCreated();
        $id = $r->json('data.id');
        $details = json_decode(DB::table('merchant_branches')->where('id', $id)->value('address_details'), true);
        ksort($details);
        DB::table('merchant_branches')->where('id', $id)->update(['review_status' => 'verified', 'address_details' => json_encode($details, JSON_PRETTY_PRINT)]);
        unset($payload['request_id']);
        $payload['revision'] = 1;
        $this->putJson('/api/merchant/branches/'.$id, $payload)->assertOk()->assertJsonPath('data.review_status', 'verified');
    }

}
