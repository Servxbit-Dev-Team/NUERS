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
        $this->post('/api/registration/documents', [...$v, 'kind' => 'bir_cor', 'file' => UploadedFile::fake()->create('large.pdf', 102401, 'application/pdf')], ['Accept' => 'application/json'])->assertUnprocessable();
        $id = $this->post('/api/registration/documents', [...$v, 'kind' => 'bir_cor', 'file' => $this->file()], ['Accept' => 'application/json'])->assertCreated()->json('id');
        $this->post('/api/registration/documents', [...$v, 'kind' => 'bir_cor', 'file' => $this->file()], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('id', $id);
        $this->assertDatabaseCount('merchant_verification_documents', 1);
        $row = DB::table('merchant_verification_documents')->first();
        $this->assertNull($row->merchant_id);
        $this->assertStringNotContainsString('Synthetic evidence', Storage::disk('local')->get($row->private_path));
        DB::table('registration_email_verifications')->where('id', $v['challenge_id'])->update(['consumed_at' => now()]);
        $this->post('/api/registration/documents', [...$v, 'kind' => 'bir_cor', 'file' => $this->file()], ['Accept' => 'application/json'])->assertUnprocessable();
    }

    public function test_document_notice_follows_saved_account_status_after_onboarding(): void
    {
        foreach (array_keys(Documents::KINDS) as $kind) {
            $id = $this->upload($kind);
            DB::table('merchant_verification_documents')->where('document_id', $id)->update(['status' => 'verified']);
        }
        $endpoint = '/api/admin/businesses/'.$this->merchant.'/verification-documents';
        $pending = $this->withToken($this->admin->api_token)->getJson($endpoint)->assertOk()
            ->assertJsonPath('verified', 5)->assertJsonPath('account.active', false);
        $this->assertStringContainsString('initial onboarding decision', $pending->json('notice'));

        DB::table('merchants')->where('id', $this->merchant)->update(['status' => 'active', 'registry_details' => json_encode(['stage' => 'onboarded'])]);
        $active = $this->getJson($endpoint)->assertOk()->assertJsonPath('account.active', true)->assertJsonPath('account.stage', 'onboarded');
        $this->assertStringContainsString('without another account approval', $active->json('notice'));
        $this->withToken($this->owner->api_token)->getJson('/api/merchant/verification-documents')
            ->assertOk()->assertJsonPath('notice', $active->json('notice'));

        DB::table('merchants')->where('id', $this->merchant)->update(['status' => 'suspended']);
        $suspended = $this->withToken($this->admin->api_token)->getJson($endpoint)->assertOk()
            ->assertJsonPath('verified', 5)->assertJsonPath('account.active', false);
        $this->assertStringContainsString('Account access is suspended', $suspended->json('notice'));
    }

    public function test_registration_can_skip_uploads_but_activation_is_blocked(): void
    {
        $v = $this->challenge();
        $this->postJson('/api/registration/business', $this->payload($v))->assertCreated();
        $reg = DB::table('business_registrations')->where('business_tin', '654321987000')->first();
        $this->assertEquals(1, $reg->documents_required);
        $this->assertSame('pending', DB::table('merchants')->where('id', $reg->merchant_id)->value('status'));
        $registered = User::findOrFail($reg->user_id);
        $registered->api_token = Str::random(64);
        $registered->save();
        $directory = $this->withToken($registered->api_token)->getJson('/api/merchant/team')->assertOk()->assertJsonPath('members.data.0.email', 'registration-qa@example.test')->assertJsonPath('members.data.0.protected', true)->json();
        $adminRole = collect($directory['roles'])->firstWhere('name', 'Merchant Admin');
        $this->assertSame(1, $adminRole['user_count']);
        $this->assertSame($adminRole['id'], $directory['members']['data'][0]['role_id']);
        $this->withToken($registered->api_token)->getJson('/api/merchant/access')->assertOk()->assertJsonPath('role', 'Merchant Admin')->assertJsonPath('owner', true);

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

    public function test_same_content_resubmission_after_needs_changes_creates_a_pending_reviewable_version_and_retries_dedupe(): void
    {
        $old = $this->upload('representative_authority', 'same original');
        $this->withToken($this->admin->api_token);
        $proof = $this->reviewPayload($old, 'Please replace the unreadable proof of authority.');
        $this->postJson('/api/admin/verification-documents/'.$old.'/review', [...$proof, 'decision' => 'needs_changes'])->assertOk();
        $revision = DB::table('merchants')->where('id', $this->merchant)->value('registry_revision');
        $oldRow = DB::table('merchant_verification_documents')->where('document_id', $old)->first();
        $new = $this->upload('representative_authority', 'same original');
        $this->assertNotSame($old, $new);
        $this->assertSame($revision + 1, DB::table('merchants')->where('id', $this->merchant)->value('registry_revision'));
        $this->getJson('/api/merchant/verification-documents')->assertOk()->assertJsonPath('items.3.id', $new)->assertJsonPath('items.3.status', 'pending')->assertJsonPath('items.3.review_note', null)->assertJsonPath('items.3.version', 2);
        $this->assertSame($new, $this->upload('representative_authority', 'same original'));
        $this->assertDatabaseCount('merchant_verification_documents', 2);
        $this->assertSame('needs_changes', DB::table('merchant_verification_documents')->where('document_id', $old)->value('status'));
        $this->assertSame($oldRow->review_encrypted, DB::table('merchant_verification_documents')->where('document_id', $old)->value('review_encrypted'));
        $this->withToken($this->admin->api_token)->postJson('/api/admin/verification-documents/'.$old.'/review', $proof)->assertConflict();
        $this->postJson('/api/admin/verification-documents/'.$new.'/review', $this->reviewPayload($new))->assertOk()->assertJsonPath('items.3.status', 'verified');
        $this->assertTrue(app(\App\Services\BirCompliance::class)->auditIntegrity());
    }

    private function reviewPayload(string $id, string $reason = 'Synthetic document independently reviewed'): array
    {
        $preview = $this->get('/api/admin/verification-documents/'.$id.'/preview')->assertOk();
        return ['decision' => 'verified', 'reason' => $reason, 'confirmed' => true,
            'preview_token' => $preview->headers->get('X-Verification-Preview'), 'expected_sha256' => $preview->headers->get('X-Document-SHA256'),
            'checks' => ['readable' => true, 'identity_match' => true, 'complete' => true]];
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
            $this->withToken($this->admin->api_token)->postJson('/api/admin/verification-documents/'.$id.'/review', $this->reviewPayload($id))->assertOk();
        }
        $this->getJson('/api/admin/businesses/'.$this->merchant.'/verification-documents')->assertOk()->assertJsonPath('ready_for_activation', true);
        $revision = DB::table('merchants')->where('id', $this->merchant)->value('registry_revision');
        $this->postJson('/api/business-registry/'.$this->merchant.'/review', $this->activation($revision))->assertOk()->assertJsonPath('business.status', 'active');
        $id = $this->upload('bir_cor', 'replacement');
        $this->assertSame('pending', DB::table('merchants')->where('id', $this->merchant)->value('status'));
        $this->getJson('/api/merchant/verification-documents')->assertJsonPath('verified', 4)->assertJsonPath('ready_for_activation', false);
        $this->assertDatabaseCount('merchant_verification_documents', 6);
        DB::table('merchants')->where('id', $this->merchant)->update(['merchant_account_id' => $this->admin->id]);
        $this->withToken($this->admin->api_token)->postJson('/api/admin/verification-documents/'.$id.'/review', $this->reviewPayload($id, 'Invalid self review attempt'))->assertForbidden();
    }

    public function test_staging_cleanup_keeps_claimed_account_files(): void
    {
        $id = $this->upload();
        $v = $this->challenge();
        $staged = $this->post('/api/registration/documents', [...$v, 'kind' => 'bir_cor', 'file' => $this->file()], ['Accept' => 'application/json'])->assertCreated()->json('id');
        DB::table('merchant_verification_documents')->update(['created_at' => now()->subDays(2)]);
        $this->artisan('registration:prune-staged-documents')->assertSuccessful();
        $this->assertDatabaseHas('merchant_verification_documents', ['document_id' => $id]);
        $this->assertDatabaseMissing('merchant_verification_documents', ['document_id' => $staged]);
    }

    private function beginUpload(int $bytes, array $extra = [], bool $staged = false): string
    {
        return $this->postJson($staged ? '/api/registration/document-uploads' : '/api/merchant/verification-uploads', [...$extra, 'kind' => 'bir_cor', 'filename' => 'synthetic-evidence.pdf', 'bytes' => $bytes])->assertCreated()->json('id');
    }

    private function part(string $id, int $index, string $raw, array $extra = [], bool $staged = false)
    {
        return $this->post('/api/'.($staged ? 'registration/document-uploads' : 'merchant/verification-uploads').'/'.$id.'/chunks', [...$extra, 'index' => $index, 'file' => UploadedFile::fake()->createWithContent('part.bin', $raw)], ['Accept' => 'application/json']);
    }

    public function test_chunked_same_content_resubmission_is_pending_and_completion_retries_keep_one_new_version(): void
    {
        $old = $this->upload('bir_cor', 'same chunked original');
        $this->withToken($this->admin->api_token);
        $proof = $this->reviewPayload($old, 'Please submit a clear copy for another independent review.');
        $this->postJson('/api/admin/verification-documents/'.$old.'/review', [...$proof, 'decision' => 'needs_changes'])->assertOk();
        $this->withToken($this->owner->api_token);
        $raw = "%PDF-1.4\nSynthetic evidence only same chunked original";
        $upload = $this->beginUpload(strlen($raw));
        $this->part($upload, 0, $raw)->assertOk();
        $url = '/api/merchant/verification-uploads/'.$upload.'/complete';
        $response = $this->postJson($url)->assertOk()->assertJsonPath('items.0.status', 'pending')->assertJsonPath('upload_result.new_version', true);
        $new = $response->json('items.0.id');
        $this->assertNotSame($old, $new);
        $this->postJson($url)->assertOk()->assertJsonPath('items.0.id', $new)->assertJsonPath('items.0.status', 'pending');
        $this->assertDatabaseCount('merchant_verification_documents', 2);
        $this->assertSame('needs_changes', DB::table('merchant_verification_documents')->where('document_id', $old)->value('status'));
    }

    public function test_full_100_mb_document_upload_download_retry_and_independent_review(): void
    {
        $this->withToken($this->owner->api_token);
        $id = $this->beginUpload(Documents::MAX_BYTES);
        $hash = hash_init('sha256');
        for ($i = 0; $i < 100; $i++) {
            $raw = ($i === 0 ? "%PDF-1.4\n" : '').str_repeat('x', 1048576 - ($i === 0 ? 9 : 0));
            hash_update($hash, $raw);
            $this->part($id, $i, $raw)->assertOk()->assertJsonPath('next_index', $i + 1);
            if ($i === 0) {
                $this->part($id, 0, $raw)->assertOk()->assertJsonPath('next_index', 1);
            }
        }
        $url = '/api/merchant/verification-uploads/'.$id.'/complete';
        $document = $this->postJson($url)->assertOk()->json('items.0.id');
        $this->postJson($url)->assertOk()->assertJsonPath('items.0.id', $document);
        $this->assertDatabaseCount('merchant_verification_documents', 1);
        $row = DB::table('merchant_verification_documents')->first();
        $this->assertEquals(Documents::MAX_BYTES, $row->bytes);
        $this->assertSame(hash_final($hash), $row->sha256);
        $this->assertFalse(Storage::disk('local')->exists('verification-uploads/'.$id.'/0.enc'));
        $stream = app(\App\Services\PrivateDocumentStream::class)->verified($row);
        $verifiedHash = hash_init('sha256');
        hash_update_stream($verifiedHash, $stream);
        fclose($stream);
        $this->assertSame($row->sha256, hash_final($verifiedHash));
        $this->get('/api/merchant/verification-documents/'.$document)->assertOk()->assertHeader('Content-Length', (string) Documents::MAX_BYTES);
        $this->withToken($this->admin->api_token)->postJson('/api/admin/verification-documents/'.$document.'/review', $this->reviewPayload($document, 'Synthetic large document independently checked'))->assertOk()->assertJsonPath('verified', 1);
        $this->assertSame('pending', DB::table('merchants')->where('id', $this->merchant)->value('status'));
    }

    public function test_large_uploads_reject_cross_tenant_wrong_length_out_of_order_and_over_limit_files(): void
    {
        $this->postJson('/api/merchant/verification-uploads', [])->assertUnauthorized();
        $this->withToken($this->owner->api_token)->postJson('/api/merchant/verification-uploads', ['kind' => 'bir_cor', 'filename' => 'evidence.pdf', 'bytes' => Documents::MAX_BYTES + 1])->assertUnprocessable();
        $id = $this->beginUpload(1048576 + 10);
        $this->withToken($this->other->api_token);
        $this->part($id, 0, str_repeat('x', 1048576))->assertNotFound();
        $this->postJson('/api/merchant/verification-uploads/'.$id.'/complete')->assertNotFound();
        $this->withToken($this->owner->api_token);
        $this->part($id, 1, str_repeat('x', 10))->assertConflict();
        $this->part($id, 0, 'short')->assertUnprocessable();
        $this->part($id, 0, str_repeat('x', 1048576))->assertOk();
        $this->part($id, 0, str_repeat('y', 1048576))->assertConflict();
        $this->postJson('/api/merchant/verification-uploads/'.$id.'/complete')->assertConflict();
        DB::table('verification_upload_sessions')->where('id', $id)->update(['expires_at' => now()->subSecond()]);
        $this->part($id, 1, str_repeat('x', 10))->assertGone();
        $this->artisan('registration:prune-staged-documents')->assertSuccessful();
        $this->assertDatabaseMissing('verification_upload_sessions', ['id' => $id]);
        $this->assertFalse(Storage::disk('local')->exists('verification-uploads/'.$id.'/0.enc'));
    }

    public function test_staged_chunks_need_current_proof_and_complete_only_after_file_type_validation(): void
    {
        $proof = $this->challenge();
        $id = $this->beginUpload(20, $proof, true);
        $this->part($id, 0, str_repeat('x', 20), [...$proof, 'verification_token' => str_repeat('x', 64)], true)->assertUnprocessable();
        $this->part($id, 0, str_repeat('x', 20), $proof, true)->assertOk();
        $this->postJson('/api/registration/document-uploads/'.$id.'/complete', $proof)->assertUnprocessable();
        $this->assertDatabaseCount('merchant_verification_documents', 0);
        $raw = "%PDF-1.4\nSynthetic staged document";
        $id = $this->beginUpload(strlen($raw), $proof, true);
        $this->part($id, 0, $raw, $proof, true)->assertOk();
        $document = $this->postJson('/api/registration/document-uploads/'.$id.'/complete', $proof)->assertCreated()->json('id');
        $this->postJson('/api/registration/document-uploads/'.$id.'/complete', $proof)->assertCreated()->assertJsonPath('id', $document);
        $this->assertDatabaseHas('merchant_verification_documents', ['document_id' => $document, 'challenge_id' => $proof['challenge_id'], 'merchant_id' => null]);
        DB::table('registration_email_verifications')->where('id', $proof['challenge_id'])->update(['consumed_at' => now()]);
        $this->postJson('/api/registration/document-uploads/'.$id.'/complete', $proof)->assertUnprocessable();
    }

    public function test_legacy_encrypted_documents_remain_readable_and_tampered_frames_are_rejected(): void
    {
        $id = $this->upload();
        $row = DB::table('merchant_verification_documents')->first();
        $raw = "%PDF-1.4\nSynthetic evidence only ";
        Storage::disk('local')->put($row->private_path, Crypt::encryptString(base64_encode($raw)));
        $this->assertSame($raw, app(Documents::class)->contents($row));
        $id = $this->upload('bir_cor', 'new copy');
        $row = DB::table('merchant_verification_documents')->where('document_id', $id)->first();
        $encrypted = Storage::disk('local')->get($row->private_path);
        Storage::disk('local')->put($row->private_path, substr($encrypted, 0, -10));
        $this->get('/api/merchant/verification-documents/'.$id)->assertConflict();
    }

    public function test_admin_preview_and_history_are_private_scoped_and_preserve_prior_versions(): void
    {
        $old = $this->upload('bir_cor', 'version one');
        $new = $this->upload('bir_cor', 'version two');
        $this->withToken($this->other->api_token)->get('/api/admin/verification-documents/'.$new.'/preview')->assertForbidden();
        $this->getJson('/api/admin/businesses/'.$this->merchant.'/verification-document-history?kind=bir_cor')->assertForbidden();
        $this->withToken($this->admin->api_token);
        $preview = $this->get('/api/admin/verification-documents/'.$new.'/preview')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('inline;', $preview->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $preview->headers->get('Cache-Control'));
        $this->assertNotEmpty($preview->headers->get('X-Verification-Preview'));
        $history = $this->getJson('/api/admin/businesses/'.$this->merchant.'/verification-document-history?kind=bir_cor')->assertOk()->assertJsonPath('total', 2)->assertJsonPath('data.0.id', $new)->assertJsonPath('data.0.version', 2)->assertJsonPath('data.0.is_current', true)->assertJsonPath('data.1.id', $old)->assertJsonPath('data.1.is_current', false);
        $this->assertStringNotContainsString('private_path', $history->getContent());
        $this->assertStringNotContainsString('filename_encrypted', $history->getContent());
        $this->getJson('/api/admin/businesses/'.$this->foreign.'/verification-document-history?kind=bir_cor')->assertOk()->assertJsonPath('total', 0);
        $this->get('/api/admin/verification-documents/'.$old.'/preview')->assertOk();
        $this->assertDatabaseHas('eis_audit_events', ['event' => 'business.verification.document_viewed', 'actor_id' => (string) $this->admin->id]);
        $this->getJson('/api/business-registry/'.$this->merchant)->assertOk()->assertJsonPath('business.verification_documents', 1)->assertJsonPath('business.documents', 0);
    }

    public function test_document_review_requires_matching_preview_identity_checks_and_confirmation_and_retries_safely(): void
    {
        $id = $this->upload();
        $this->withToken($this->admin->api_token);
        $payload = $this->reviewPayload($id);
        $url = '/api/admin/verification-documents/'.$id.'/review';
        $this->postJson($url, [...$payload, 'preview_token' => 'tampered'])->assertUnprocessable();
        $this->postJson($url, [...$payload, 'expected_sha256' => str_repeat('0', 64)])->assertConflict();
        $this->postJson($url, [...$payload, 'confirmed' => false])->assertUnprocessable();
        $this->postJson($url, [...$payload, 'checks' => ['readable' => false, 'identity_match' => true, 'complete' => true]])->assertUnprocessable();
        [$differentAdmin] = $this->account('admin');
        $this->withToken($differentAdmin->api_token)->postJson($url, $payload)->assertUnprocessable();
        $this->withToken($this->admin->api_token)->postJson($url, $payload)->assertOk()->assertJsonPath('verified', 1);
        $revision = DB::table('merchants')->where('id', $this->merchant)->value('registry_revision');
        $this->postJson($url, $payload)->assertOk();
        $this->assertSame($revision, DB::table('merchants')->where('id', $this->merchant)->value('registry_revision'));
        $events = DB::table('eis_audit_events')->where('event', 'business.verification.document_reviewed')->get();
        $this->assertCount(1, $events);
        $event = json_decode($events[0]->details, true);
        $this->assertSame('pending', $event['before_status']);
        $this->assertSame('verified', $event['after_status']);
        $this->assertTrue($event['checks']['identity_match']);
        $this->assertStringNotContainsString($payload['preview_token'], $events[0]->details);
        $this->assertTrue(app(\App\Services\BirCompliance::class)->auditIntegrity());
    }

    public function test_expired_preview_replaced_file_and_replayed_session_cannot_authorize_a_review(): void
    {
        $id = $this->upload();
        $this->withToken($this->admin->api_token);
        $payload = $this->reviewPayload($id);
        $proof = json_decode(Crypt::decryptString($payload['preview_token']), true);
        $proof['expires'] = now()->subMinute()->timestamp;
        $this->postJson('/api/admin/verification-documents/'.$id.'/review', [...$payload, 'preview_token' => Crypt::encryptString(json_encode($proof))])->assertUnprocessable();
        $proof['expires'] = now()->addMinutes(10)->timestamp;
        $proof['session'] = str_repeat('0', 64);
        $this->postJson('/api/admin/verification-documents/'.$id.'/review', [...$payload, 'preview_token' => Crypt::encryptString(json_encode($proof))])->assertUnprocessable();
        $new = $this->upload('bir_cor', 'replacement during review');
        $this->withToken($this->admin->api_token)->postJson('/api/admin/verification-documents/'.$id.'/review', $payload)->assertConflict();
        $this->assertDatabaseHas('merchant_verification_documents', ['document_id' => $new, 'status' => 'pending']);
    }

    public function test_broken_private_file_can_only_be_returned_for_changes_and_never_verified(): void
    {
        $id = $this->upload();
        $row = DB::table('merchant_verification_documents')->where('document_id', $id)->first();
        Storage::disk('local')->put($row->private_path, 'corrupted bytes');
        $this->withToken($this->admin->api_token);
        $preview = $this->getJson('/api/admin/verification-documents/'.$id.'/preview')->assertConflict();
        $payload = ['decision' => 'verified', 'reason' => 'Please replace this unreadable document with a clear original.', 'preview_token' => $preview->json('preview_token'), 'expected_sha256' => $row->sha256, 'confirmed' => true, 'checks' => ['readable' => false, 'identity_match' => false, 'complete' => false]];
        $this->postJson('/api/admin/verification-documents/'.$id.'/review', $payload)->assertUnprocessable();
        $this->postJson('/api/admin/verification-documents/'.$id.'/review', [...$payload, 'decision' => 'needs_changes'])->assertOk()->assertJsonPath('items.0.status', 'needs_changes');
        $this->assertSame('pending', DB::table('merchants')->where('id', $this->merchant)->value('status'));
        $this->assertDatabaseHas('eis_audit_events', ['event' => 'business.verification.document_preview_failed']);
    }

}
