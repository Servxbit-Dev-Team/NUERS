<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MerchantVerificationDocuments as Documents;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MerchantVerificationDocumentTest extends TestCase
{
    private User $owner;

    private User $other;

    private User $admin;

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
        [$this->admin] = $this->account('super_admin');
    }

    private function account(string $role = 'merchant'): array
    {
        $u = User::factory()->create(['api_token' => Str::random(64)]);
        DB::table('profiles')->insert(['id' => $u->id, 'email' => $u->email, 'role' => $role]);
        $id = (string) Str::uuid();
        DB::table('merchants')->insert(['id' => $id, 'merchant_account_id' => $u->id, 'business_name' => 'Synthetic Verification QA', 'tin' => '123456789000', 'status' => 'pending']);

        return [$u, $id];
    }

    private function file(string $suffix = ''): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('synthetic-evidence.pdf', "%PDF-1.4\nSynthetic evidence only ".$suffix);
    }

    private function challenge(): array
    {
        $id = (string) Str::uuid();
        $proof = Str::random(64);
        DB::table('registration_email_verifications')->insert(['id' => $id, 'email' => 'registration-qa@example.test', 'code_hash' => Hash::make('123456'), 'proof_hash' => hash('sha256', $proof), 'attempts' => 1, 'verified_at' => now(), 'expires_at' => now()->addMinutes(10), 'development_delivery' => false, 'created_at' => now(), 'updated_at' => now()]);

        return ['challenge_id' => $id, 'verification_token' => $proof];
    }

    private function payload(array $proof): array
    {
        return [...$proof, 'email' => 'registration-qa@example.test', 'password' => 'SyntheticPassword123', 'password_confirmation' => 'SyntheticPassword123', 'tin' => '654321987000', 'business_name' => 'Synthetic Registration QA', 'street_address' => 'Unit 1 Example Street', 'zip_code' => '1229', 'region_code' => '1300000000', 'province_code' => '1300000000', 'city_code' => '1380300000', 'barangay_code' => '1380300025', 'business_type' => 'corporation', 'industry' => 'it', 'rdo_code' => '047', 'contact_name' => 'QA Contact', 'contact_position' => 'Owner', 'mobile' => '+639123456789', 'vat_registered' => true, 'terms_accepted' => true, 'privacy_accepted' => true, 'notice_version' => '2026-10-17.2'];
    }

    private function upload(string $kind = 'bir_cor', string $suffix = ''): string
    {
        return $this->withToken($this->owner->api_token)->post('/api/merchant/verification-documents', ['kind' => $kind, 'file' => $this->file($suffix)], ['Accept' => 'application/json'])->assertOk()->json('items.'.array_search($kind, array_keys(Documents::KINDS)).'.id');
    }

    public function test_public_staging_requires_verified_unexpired_unconsumed_proof_and_valid_files(): void
    {
        $v = $this->challenge();
        $this->post('/api/registration/documents', [...$v, 'verification_token' => str_repeat('x', 64), 'kind' => 'bir_cor', 'file' => $this->file()], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post('/api/registration/documents', [...$v, 'kind' => 'unknown', 'file' => $this->file()], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post('/api/registration/documents', [...$v, 'kind' => 'bir_cor', 'file' => UploadedFile::fake()->createWithContent('attack.php', '<?php echo 1;')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post('/api/registration/documents', [...$v, 'kind' => 'bir_cor', 'file' => UploadedFile::fake()->create('large.pdf', 2049, 'application/pdf')], ['Accept' => 'application/json'])->assertUnprocessable();
        $id = $this->post('/api/registration/documents', [...$v, 'kind' => 'bir_cor', 'file' => $this->file()], ['Accept' => 'application/json'])->assertCreated()->json('id');
        $this->post('/api/registration/documents', [...$v, 'kind' => 'bir_cor', 'file' => $this->file()], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('id', $id);
        $this->assertDatabaseCount('merchant_verification_documents', 1);
        $row = DB::table('merchant_verification_documents')->first();
        $this->assertNull($row->merchant_id);
        $this->assertStringNotContainsString('Synthetic evidence', Storage::disk('local')->get($row->private_path));
        DB::table('registration_email_verifications')->where('id', $v['challenge_id'])->update(['consumed_at' => now()]);
        $this->post('/api/registration/documents', [...$v, 'kind' => 'bir_cor', 'file' => $this->file()], ['Accept' => 'application/json'])->assertUnprocessable();
    }

    public function test_registration_can_skip_uploads_but_activation_is_blocked(): void
    {
        $v = $this->challenge();
        $this->postJson('/api/registration/business', $this->payload($v))->assertCreated();
        $reg = DB::table('business_registrations')->where('business_tin', '654321987000')->first();
        $this->assertEquals(1, $reg->documents_required);
        $this->assertSame('pending', DB::table('merchants')->where('id', $reg->merchant_id)->value('status'));
        $this->withToken($this->admin->api_token)->postJson('/api/business-registry/'.$reg->merchant_id.'/review', $this->activation(1))->assertUnprocessable();
        $this->assertSame('pending', DB::table('merchants')->where('id', $reg->merchant_id)->value('status'));
    }

    public function test_registration_claims_only_its_selected_uploads_and_rejects_foreign_files_atomically(): void
    {
        $v = $this->challenge();
        $id = $this->post('/api/registration/documents', [...$v, 'kind' => 'bir_cor', 'file' => $this->file()], ['Accept' => 'application/json'])->assertCreated()->json('id');
        $foreign = $this->upload();
        $this->postJson('/api/registration/business', [...$this->payload($v), 'uploaded_document_ids' => [$foreign]])->assertUnprocessable();
        $this->assertDatabaseMissing('users', ['email' => 'registration-qa@example.test']);
        $this->assertNull(DB::table('registration_email_verifications')->where('id', $v['challenge_id'])->value('consumed_at'));
        $this->postJson('/api/registration/business', [...$this->payload($v), 'uploaded_document_ids' => [$id]])->assertCreated();
        $reg = DB::table('business_registrations')->first();
        $this->assertSame($reg->merchant_id, DB::table('merchant_verification_documents')->where('document_id', $id)->value('merchant_id'));
        $this->assertSame($this->merchant, DB::table('merchant_verification_documents')->where('document_id', $foreign)->value('merchant_id'));
    }

    public function test_authenticated_uploads_are_private_tenant_scoped_and_not_self_verified(): void
    {
        $this->getJson('/api/merchant/verification-documents')->assertUnauthorized();
        $id = $this->upload();
        $this->get('/api/merchant/verification-documents/'.$id)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->withToken($this->other->api_token)->get('/api/merchant/verification-documents/'.$id)->assertNotFound();
        $this->getJson('/api/merchant/verification-documents')->assertOk()->assertJsonPath('uploaded', 0);
        $this->withToken($this->owner->api_token)->postJson('/api/admin/verification-documents/'.$id.'/review', ['decision' => 'verified', 'reason' => 'Synthetic evidence reviewed'])->assertForbidden();
        $this->getJson('/api/admin/businesses/'.$this->foreign.'/verification-documents')->assertForbidden();
        $row = DB::table('merchant_verification_documents')->where('document_id', $id)->first();
        Storage::disk('local')->put($row->private_path, Crypt::encryptString(base64_encode('tampered')));
        $this->get('/api/merchant/verification-documents/'.$id)->assertConflict();
    }

    private function activation(int $revision): array
    {
        return ['revision' => $revision, 'stage' => 'onboarded', 'status' => 'active', 'checks' => array_fill_keys(['registration_certificate', 'identity', 'business_information', 'representative_authority', 'contact_verification'], true), 'evidence' => 'Synthetic review reference QA-ONLY', 'reason' => 'Synthetic account onboarding review'];
    }

    public function test_all_documents_need_independent_review_before_activation_and_replacements_invalidate_it(): void
    {
        DB::table('business_registrations')->insert(['id' => (string) Str::uuid(), 'user_id' => $this->owner->id, 'merchant_id' => $this->merchant, 'business_tin' => '123456789000', 'contact_name' => 'Synthetic QA', 'contact_position' => 'Owner', 'mobile' => '+639123456789', 'status' => 'pending_review', 'terms_version' => '2026-10-17.2', 'privacy_version' => '2026-10-17.2', 'consented_at' => now(), 'email_verified' => true, 'documents_required' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach (array_keys(Documents::KINDS) as $kind) {
            $id = $this->upload($kind);
            $this->withToken($this->admin->api_token)->postJson('/api/admin/verification-documents/'.$id.'/review', ['decision' => 'verified', 'reason' => 'Synthetic document independently reviewed'])->assertOk();
        }
        $this->getJson('/api/admin/businesses/'.$this->merchant.'/verification-documents')->assertOk()->assertJsonPath('ready_for_activation', true);
        $revision = DB::table('merchants')->where('id', $this->merchant)->value('registry_revision');
        $this->postJson('/api/business-registry/'.$this->merchant.'/review', $this->activation($revision))->assertOk()->assertJsonPath('business.status', 'active');
        $id = $this->upload('bir_cor', 'replacement');
        $this->assertSame('pending', DB::table('merchants')->where('id', $this->merchant)->value('status'));
        $this->getJson('/api/merchant/verification-documents')->assertJsonPath('verified', 4)->assertJsonPath('ready_for_activation', false);
        $this->assertDatabaseCount('merchant_verification_documents', 6);
        DB::table('merchants')->where('id', $this->merchant)->update(['merchant_account_id' => $this->admin->id]);
        $this->withToken($this->admin->api_token)->postJson('/api/admin/verification-documents/'.$id.'/review', ['decision' => 'verified', 'reason' => 'Invalid self review attempt'])->assertForbidden();
    }

    public function test_staging_cleanup_keeps_claimed_account_files(): void
    {
        $id = $this->upload();
        $v = $this->challenge();
        $staged = $this->post('/api/registration/documents', [...$v, 'kind' => 'bir_cor', 'file' => $this->file()], ['Accept' => 'application/json'])->assertCreated()->json('id');
        DB::table('merchant_verification_documents')->update(['created_at' => now()->subDays(2)]);
        $this->artisan('registration:prune-staged-documents')->assertSuccessful();
        $this->assertDatabaseHas('merchant_verification_documents',['document_id' => $id]);
        $this->assertDatabaseMissing('merchant_verification_documents',['document_id' => $staged]);
    }
}
