<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BirCompliance;
use App\Services\MerchantVerificationDocuments;
use App\Services\NotificationEvents;
use App\Services\PlatformNotifications;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MerchantBusinessProfileTest extends TestCase
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
        Storage::fake('local');
        [$this->owner,$this->merchant] = $this->account();
        [$this->other,$this->foreign] = $this->account();
        $this->withToken($this->owner->api_token);
    }

    private function account(string $role = 'merchant'): array
    {
        $u = User::factory()->create(['api_token' => Str::random(64)]);
        DB::table('profiles')->insert(['id' => $u->id, 'email' => $u->email, 'role' => $role]);
        $id = (string) Str::uuid();
        DB::table('merchants')->insert(['id' => $id, 'merchant_account_id' => $u->id, 'business_name' => 'Synthetic Business Profile QA', 'tin' => '123456789000', 'status' => 'pending', 'email' => $u->email, 'registry_revision' => 1, 'address' => 'Synthetic Address']);

        return [$u, $id];
    }

    private function payload(array $overrides = []): array
    {
        return array_replace(['revision' => 0, 'registry_revision' => 1, 'phone' => '+63 917 123 4567', 'website' => 'https://example.test', 'details' => ['business_description' => 'Synthetic profile description']], $overrides);
    }

    private function uploadAll(): void
    {
        foreach (array_keys(MerchantVerificationDocuments::KINDS) as $kind) {
            $this->post('/api/merchant/verification-documents', ['kind' => $kind, 'file' => UploadedFile::fake()->createWithContent('synthetic.pdf', "%PDF-1.4\nSynthetic evidence ".$kind)], ['Accept' => 'application/json'])->assertOk();
        }
    }

    public function test_owner_projection_uses_real_status_and_never_exposes_another_tenant(): void
    {
        $this->getJson('/api/merchant/business-profile?merchant_id='.$this->foreign)->assertOk()->assertJsonPath('business.id', $this->merchant)->assertJsonPath('documents.verified', 0)->assertJsonPath('documents.total', 5)->assertJsonPath('business.status', 'pending')->assertJsonCount(0, 'permits')->assertJsonCount(0, 'readiness')->assertJsonPath('account_linked', true)->assertHeader('Cache-Control', 'no-store, private');
        $this->withToken($this->other->api_token)->getJson('/api/merchant/business-profile')->assertOk()->assertJsonPath('business.id', $this->foreign);
        $this->withToken('')->getJson('/api/merchant/business-profile')->assertUnauthorized();
    }

    public function test_wrong_roles_and_non_owner_members_cannot_manage_business_profile(): void
    {
        foreach (['client', 'super_admin'] as $role) {
            [$u] = $this->account($role);
            $this->withToken($u->api_token)->getJson('/api/merchant/business-profile')->assertForbidden();
        }
        $staff = User::factory()->create(['api_token' => Str::random(64)]);
        DB::table('profiles')->insert(['id' => $staff->id, 'email' => $staff->email, 'role' => 'merchant']);
        $this->withToken($staff->api_token)->putJson('/api/merchant/business-profile', $this->payload())->assertForbidden();
        $this->assertDatabaseCount('merchant_business_profiles', 0);
    }

    public function test_contact_edits_are_encrypted_revision_checked_and_do_not_grant_authority(): void
    {
        $this->putJson('/api/merchant/business-profile', $this->payload())->assertOk()->assertJsonPath('revision', 1)->assertJsonPath('registry_revision', 2)->assertJsonPath('business.status', 'pending')->assertJsonPath('details.business_description', 'Synthetic profile description');
        $row = DB::table('merchant_business_profiles')->where('merchant_id', $this->merchant)->first();
        $this->assertStringNotContainsString('Synthetic profile description', $row->encrypted_details);
        $this->assertSame('Synthetic profile description', json_decode(Crypt::decryptString($row->encrypted_details), true)['business_description']);
        $this->assertSame('123456789000', DB::table('merchants')->where('id', $this->merchant)->value('tin'));
        $this->assertSame($this->owner->email, DB::table('users')->where('id', $this->owner->id)->value('email'));
        $this->putJson('/api/merchant/business-profile', $this->payload())->assertConflict();
        $this->assertDatabaseCount('merchant_business_profiles', 1);
        $this->assertTrue(app(BirCompliance::class)->auditIntegrity());
    }

    public function test_invalid_inputs_and_protected_identity_are_rejected_atomically(): void
    {
        foreach ([['email' => 'invalid'], ['website' => 'javascript:alert(1)'], ['website' => 'https://user:password@example.test'], ['phone' => 'abc'], ['status' => 'active'], ['tin' => '987654321000'], ['details' => ['unknown' => 'value']], ['details' => ['business_description' => str_repeat('x', 501)]]] as $change) {
            $this->putJson('/api/merchant/business-profile', $this->payload($change))->assertUnprocessable();
        }
        $this->assertDatabaseCount('merchant_business_profiles', 0);
        $this->assertSame('pending', DB::table('merchants')->where('id', $this->merchant)->value('status'));
    }

    public function test_review_requires_all_current_files_and_preserves_pending_status_and_idempotency(): void
    {
        $this->postJson('/api/merchant/business-profile/review', ['revision' => 0, 'registry_revision' => 1])->assertUnprocessable();
        $this->uploadAll();
        $state = $this->getJson('/api/merchant/business-profile')->assertOk()->assertJsonPath('review.can_submit', true)->json();
        $submitted = $this->postJson('/api/merchant/business-profile/review', ['revision' => $state['revision'], 'registry_revision' => $state['registry_revision']])->assertOk()->assertJsonPath('review.current_submission', true)->assertJsonPath('review.can_submit', false)->assertJsonPath('business.status', 'pending')->assertJsonPath('documents.verified', 0)->json();
        $this->postJson('/api/merchant/business-profile/review', ['revision' => $submitted['revision'], 'registry_revision' => $submitted['registry_revision']])->assertOk()->assertJsonPath('revision', $submitted['revision']);
        $this->assertSame(1, DB::table('eis_audit_events')->where('event', 'business.profile.review_requested')->count());
        $this->assertTrue(app(BirCompliance::class)->auditIntegrity());
        $event = collect($submitted['activities'])->firstWhere('action', 'business.profile.review_requested');
        $this->assertStringEndsWith('+00:00', $event['date']);
    }

    public function test_document_replacement_invalidates_submission_without_changing_original_file(): void
    {
        $this->uploadAll();
        $s = $this->getJson('/api/merchant/business-profile')->json();
        $this->postJson('/api/merchant/business-profile/review', ['revision' => $s['revision'], 'registry_revision' => $s['registry_revision']])->assertOk();
        $original = DB::table('merchant_verification_documents')->where('merchant_id', $this->merchant)->where('kind', 'bir_cor')->first();
        $this->post('/api/merchant/verification-documents', ['kind' => 'bir_cor', 'file' => UploadedFile::fake()->createWithContent('replacement.pdf', "%PDF-1.4\nNew synthetic evidence")], ['Accept' => 'application/json'])->assertOk();
        $this->getJson('/api/merchant/business-profile')->assertOk()->assertJsonPath('review.current_submission', false)->assertJsonPath('documents.uploaded', 5);
        $this->assertDatabaseCount('merchant_verification_documents', 6);
        $this->assertSame($original->sha256, DB::table('merchant_verification_documents')->where('id', $original->id)->value('sha256'));
    }

    public function test_review_submission_queues_owner_and_authorized_admins_once_with_private_action_links(): void
    {
        config(['app.url' => 'https://nuers.example']);
        [$admin] = $this->account('admin');
        [$super] = $this->account('super_admin');
        [$regulator] = $this->account('bir');
        DB::table('merchants')->where('id', $this->merchant)->update(['email' => 'unverified-contact@example.test']);
        $this->uploadAll();
        $s = $this->getJson('/api/merchant/business-profile')->json();
        $s = $this->postJson('/api/merchant/business-profile/review', ['revision' => $s['revision'], 'registry_revision' => $s['registry_revision']])->assertOk()->json();
        $this->postJson('/api/merchant/business-profile/review', ['revision' => $s['revision'], 'registry_revision' => $s['registry_revision']])->assertOk();
        // Recovery of this same submission uses the same key, even with a different audit key.
        app(NotificationEvents::class)->recorded('business.profile.review_requested', $this->merchant, (string) $this->owner->id, ['submission_revision' => $s['revision']], 'recovery-key');
        $rows = DB::table('notification_outbox')->where('event', 'business.profile.review_requested')->get();
        $this->assertCount(3, $rows);
        $this->assertEqualsCanonicalizing([(string) $this->owner->id, (string) $admin->id, (string) $super->id], $rows->pluck('user_id')->all());
        foreach ($rows as $row) {
            $p = json_decode(Crypt::decryptString($row->encrypted_payload), true);
            $owner = $row->user_id === (string) $this->owner->id;
            $this->assertSame($owner ? 'workflow' : 'operations', $row->category);
            $this->assertSame($owner ? $this->merchant : null, $row->merchant_id);
            $this->assertSame($owner ? $this->owner->email : User::find($row->user_id)->email, $p['recipient']);
            $this->assertSame('https://nuers.example/'.($owner ? 'merchant/profile' : 'super-admin/merchants'), $p['action_url']);
            $this->assertStringContainsString('Synthetic Business Profile QA', $p['body']);
            $this->assertStringNotContainsString('123456789000', $p['body']);
            $this->assertStringNotContainsString('unverified-contact', $row->encrypted_payload);
            $this->assertSame('queued', $row->state);
            $this->assertSame(0, $row->attempts);
        }
        $this->assertFalse($rows->contains('user_id', (string) $regulator->id));
        $this->assertTrue(app(BirCompliance::class)->auditIntegrity());
    }

    public function test_rejected_submission_has_no_review_email_and_changed_submission_gets_a_new_confirmation(): void
    {
        $event = fn () => DB::table('notification_outbox')->where('event', 'business.profile.review_requested');
        $this->postJson('/api/merchant/business-profile/review', ['revision' => 0, 'registry_revision' => 1])->assertUnprocessable();
        $this->assertSame(0, $event()->count());
        $this->uploadAll();
        $s = $this->getJson('/api/merchant/business-profile')->json();
        $this->postJson('/api/merchant/business-profile/review', ['revision' => $s['revision'], 'registry_revision' => $s['registry_revision']])->assertOk();
        $this->assertSame(1, $event()->count());
        $this->post('/api/merchant/verification-documents', ['kind' => 'bir_cor', 'file' => UploadedFile::fake()->createWithContent('replacement.pdf', "%PDF-1.4\nNew evidence")], ['Accept' => 'application/json'])->assertOk();
        $s = $this->getJson('/api/merchant/business-profile')->json();
        $this->postJson('/api/merchant/business-profile/review', ['revision' => $s['revision'], 'registry_revision' => $s['registry_revision']])->assertOk();
        $this->assertSame(2, $event()->count());
        $this->assertSame(2, $event()->distinct()->count('dedupe_key'));
    }

    public function test_submission_email_respects_workflow_preferences_without_blocking_review(): void
    {
        DB::table('notification_preferences')->insert(['user_id' => (string) $this->owner->id, 'workflow_email' => false]);
        $this->uploadAll();
        $s = $this->getJson('/api/merchant/business-profile')->json();
        $this->postJson('/api/merchant/business-profile/review', ['revision' => $s['revision'], 'registry_revision' => $s['registry_revision']])->assertOk()->assertJsonPath('review.current_submission', true);
        $this->assertSame(0, DB::table('notification_outbox')->where('event', 'business.profile.review_requested')->count());
        $this->assertFalse(app(PlatformNotifications::class)->runOne());
    }

    public function test_missing_private_files_and_needs_changes_block_submission(): void
    {
        $this->uploadAll();
        $doc = DB::table('merchant_verification_documents')->where('merchant_id', $this->merchant)->first();
        DB::table('merchant_verification_documents')->where('id', $doc->id)->update(['status' => 'needs_changes']);
        $s = $this->getJson('/api/merchant/business-profile')->assertJsonPath('review.can_submit', false)->json();
        $this->postJson('/api/merchant/business-profile/review', ['revision' => $s['revision'], 'registry_revision' => $s['registry_revision']])->assertUnprocessable();
        DB::table('merchant_verification_documents')->where('id', $doc->id)->update(['status' => 'pending']);
        Storage::disk('local')->delete($doc->private_path);
        $this->postJson('/api/merchant/business-profile/review', ['revision' => $s['revision'], 'registry_revision' => $s['registry_revision']])->assertConflict();
        $this->assertDatabaseCount('merchant_business_profiles', 0);
    }

    public function test_production_permits_are_tenant_scoped_and_expiry_is_not_hidden(): void
    {
        foreach ([[$this->merchant, 'production', '2000-01-01'], [$this->merchant, 'certification', '2099-01-01'], [$this->foreign, 'production', '2099-01-01']] as [$merchant,$env,$until]) {
            DB::table('taxpayer_authorizations')->insert(['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'merchant_id' => $merchant, 'kind' => 'PTI', 'reference' => (string) Str::uuid(), 'environment' => $env, 'scope' => 'Test only', 'issued_at' => '1999-01-01', 'valid_until' => $until, 'status' => 'active', 'evidence' => 'Synthetic evidence', 'reason' => 'Synthetic test only', 'reviewed_by' => 'independent-qa', 'reviewed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->getJson('/api/merchant/business-profile')->assertOk()->assertJsonCount(1, 'permits')->assertJsonPath('permits.0.status', 'expired')->assertJsonPath('permits.0.reviewed', true);
    }

    public function test_suspended_business_can_read_but_cannot_edit_or_submit(): void
    {
        DB::table('merchants')->where('id', $this->merchant)->update(['status' => 'suspended']);
        $this->getJson('/api/merchant/business-profile')->assertOk();
        $this->putJson('/api/merchant/business-profile', $this->payload())->assertForbidden();
        $this->postJson('/api/merchant/business-profile/review', ['revision' => 0, 'registry_revision' => 1])->assertForbidden();
    }

    private function apiKey(string $merchant, string $environment = 'live', string $status = 'active', string $branch = '00000'): string
    {
        $id = (string) Str::uuid();
        DB::table('merchant_api_keys')->insert(['id' => $id, 'merchant_id' => $merchant, 'name' => 'Synthetic External Connector', 'environment' => $environment, 'key_prefix' => 'qa', 'key_hash' => hash('sha256', $id), 'key_last_four' => '0000', 'status' => $status, 'scopes' => json_encode(['transactions:write']), 'branch_code' => $branch, 'expires_at' => now()->addDay()]);

        return $id;
    }

    private function acceptedSource(string $merchant, string $key, string $branch = '00000'): void
    {
        DB::table('integration_inbox')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $merchant, 'api_key_id' => $key, 'namespace_hash' => hash('sha256', Str::uuid()), 'event_hash' => hash('sha256', Str::uuid()), 'request_hash' => str_repeat('a', 64), 'source_system' => 'SyntheticERP', 'source_event_id' => 'qa-event', 'external_reference' => 'qa-reference', 'branch_code' => $branch, 'schema_version' => 'NUERS-ingestion-1', 'event_type' => 'invoice.finalized', 'occurred_at' => now(), 'received_at' => now(), 'transaction_id' => 'synthetic-transaction', 'receipt_id' => 'synthetic-receipt']);
    }

    public function test_account_link_and_unused_credentials_do_not_establish_system_integration(): void
    {
        $this->getJson('/api/merchant/business-profile')->assertOk()->assertJsonPath('account_linked', true)->assertJsonPath('integration.status', 'not_integrated');
        $key = $this->apiKey($this->merchant);
        DB::table('merchant_api_keys')->where('id', $key)->update(['last_used_at' => now()]);
        $this->getJson('/api/merchant/business-profile')->assertOk()->assertJsonPath('integration.status', 'configured')->assertJsonPath('integration.last_accepted_at', null);
        $this->acceptedSource($this->foreign, $key);
        $this->acceptedSource($this->merchant, $key, '00001');
        $this->getJson('/api/merchant/business-profile')->assertOk()->assertJsonPath('integration.status', 'configured')->assertJsonPath('integration.source_systems', 0);
    }

    public function test_integrated_status_requires_live_current_credentials_and_accepted_matching_source(): void
    {
        DB::table('merchants')->where('id', $this->merchant)->update(['status' => 'active']);
        $sandbox = $this->apiKey($this->merchant, 'sandbox');
        $this->acceptedSource($this->merchant, $sandbox);
        $this->getJson('/api/merchant/business-profile')->assertOk()->assertJsonPath('integration.status', 'not_integrated');
        $key = $this->apiKey($this->merchant);
        $this->acceptedSource($this->merchant, $key);
        $this->getJson('/api/merchant/business-profile')->assertOk()->assertJsonPath('integration.status', 'integrated')->assertJsonPath('integration.source_systems', 1);
        DB::table('merchant_api_keys')->where('id', $key)->update(['status' => 'revoked']);
        $this->getJson('/api/merchant/business-profile')->assertOk()->assertJsonPath('integration.status', 'inactive');
        DB::table('merchant_api_keys')->where('id', $key)->update(['status' => 'active', 'expires_at' => now()->subSecond()]);
        $this->getJson('/api/merchant/business-profile')->assertOk()->assertJsonPath('integration.status', 'inactive');
    }

    public function test_account_review_pauses_existing_integration_without_fabricating_bir_connectivity(): void
    {
        $key = $this->apiKey($this->merchant);
        $this->acceptedSource($this->merchant, $key);
        $this->getJson('/api/merchant/business-profile')->assertOk()->assertJsonPath('integration.status', 'paused')->assertJsonCount(0, 'readiness');
        DB::table('merchant_api_keys')->where('id', $key)->update(['scopes' => json_encode(['transactions:read'])]);
        $this->getJson('/api/merchant/business-profile')->assertOk()->assertJsonPath('integration.status','inactive');
    }
}
