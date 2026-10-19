<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\{BirCompliance, MerchantVerificationDocuments, PlatformMail, PlatformNotifications};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Crypt, DB, Storage};
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class BusinessReviewNotificationTest extends TestCase
{
    private User $admin;
    private User $owner;
    private string $merchant;
    private string $url;
    private $mail;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'app.url' => 'https://nuers.example', 'cache.default' => 'array', 'notifications.dispatch_enabled' => true]);
        DB::purge('sqlite');
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        Storage::fake('local');
        $this->admin = $this->actor('super_admin');
        $this->owner = $this->actor('merchant');
        $this->merchant = (string) Str::uuid();
        $this->url = '/api/admin/businesses/'.$this->merchant.'/review-notifications';
        DB::table('merchants')->insert(['id' => $this->merchant, 'merchant_account_id' => $this->owner->id, 'business_name' => 'Synthetic Review Notification QA', 'email' => 'unverified-contact@example.test', 'tin' => '123456789000', 'status' => 'pending', 'registry_revision' => 1]);
        foreach (array_keys(MerchantVerificationDocuments::KINDS) as $kind) {
            app(MerchantVerificationDocuments::class)->store($this->merchant, null, $kind, UploadedFile::fake()->createWithContent('private.pdf', "%PDF-1.4\nSynthetic ".$kind), (string) $this->owner->id);
        }
        $this->changes();
        $this->mail = Mockery::mock(PlatformMail::class);
        $this->mail->shouldReceive('settings')->andReturn(['enabled' => true]);
        $this->app->instance(PlatformMail::class, $this->mail);
        $this->withToken($this->admin->api_token);
    }

    private function actor(string $role): User
    {
        $u = User::factory()->create(['api_token' => Str::random(64)]);
        DB::table('profiles')->insert(['id' => $u->id, 'email' => $u->email, 'role' => $role]);
        return $u;
    }

    private function changes(): void
    {
        DB::table('merchant_verification_documents')->where('merchant_id', $this->merchant)->where('kind', 'bir_cor')->update(['status' => 'needs_changes', 'reviewed_by' => (string) $this->admin->id, 'reviewed_at' => now(), 'review_encrypted' => Crypt::encryptString('Please upload a readable copy with all pages visible.')]);
    }

    private function verified(bool $active = false): void
    {
        DB::table('merchant_verification_documents')->where('merchant_id', $this->merchant)->update(['status' => 'verified', 'reviewed_by' => (string) $this->admin->id, 'reviewed_at' => now(), 'review_encrypted' => Crypt::encryptString('Independent document review completed.')]);
        if ($active) {
            DB::table('merchants')->where('id', $this->merchant)->update(['status' => 'active', 'registry_details' => json_encode(['stage' => 'onboarded', 'checks' => array_fill_keys(['registration_certificate', 'identity', 'business_information', 'representative_authority', 'contact_verification'], true), 'evidence' => 'Synthetic independent review reference'])]);
        }
    }

    private function preview(string $kind = 'changes_requested', string $message = ''): array
    {
        return $this->postJson($this->url.'/preview', compact('kind', 'message'))->assertOk()->json();
    }

    private function request(array $preview, array $extra = []): array
    {
        return array_replace(['preview_token' => $preview['preview_token'], 'request_id' => (string) Str::uuid(), 'confirmed' => true], $extra);
    }

    public function test_recipient_is_authoritative_and_only_admins_can_read_preview_or_send(): void
    {
        $this->withToken('')->getJson($this->url)->assertUnauthorized();
        foreach ([$this->owner, $this->actor('bir'), $this->actor('client')] as $u) {
            $this->withToken($u->api_token)->getJson($this->url)->assertForbidden();
            $this->postJson($this->url.'/preview', ['kind' => 'changes_requested'])->assertForbidden();
            $this->postJson($this->url, [])->assertForbidden();
        }
        $this->withToken($this->admin->api_token)->getJson($this->url)->assertOk()->assertJsonPath('recipient', $this->owner->email)->assertJsonPath('drafts.changes_requested.eligible', true)->assertJsonPath('drafts.account_approved.eligible', false)->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/api/admin/businesses/'.Str::uuid().'/review-notifications')->assertNotFound();
        $p = $this->preview();
        $this->assertStringContainsString('readable copy', $p['body']);
        $this->assertStringNotContainsString('123456789000', $p['body']);
        $this->assertSame($this->owner->email, $p['recipient']);
        $this->assertDatabaseCount('business_review_notifications', 0);
    }

    public function test_send_is_confirmed_encrypted_atomic_and_idempotent_and_worker_sends_once(): void
    {
        $p = $this->preview(message: 'Please replace the unreadable scan.');
        $v = $this->request($p);
        $this->postJson($this->url, [...$v, 'confirmed' => false])->assertUnprocessable();
        $id = $this->postJson($this->url, $v)->assertStatus(202)->assertJsonPath('duplicate', false)->json('id');
        $this->postJson($this->url, $v)->assertStatus(202)->assertJsonPath('id', $id)->assertJsonPath('duplicate', true);
        $this->postJson($this->url, $this->request($p))->assertStatus(202)->assertJsonPath('id', $id);
        $this->assertDatabaseCount('business_review_notifications', 1);
        $this->assertDatabaseCount('notification_outbox', 1);
        $row = DB::table('business_review_notifications')->first();
        $this->assertStringNotContainsString('unreadable scan', $row->encrypted_snapshot);
        $this->assertStringNotContainsString('unreadable scan', json_encode(DB::table('eis_audit_events')->get()));
        $this->mail->shouldReceive('send')->once()->withArgs(function (...$args) use ($p) {
            return $args[1] === $this->owner->email && $args[2] === $p['subject'] && $args[3] === $p['body'] && ($args[4] ?? null) === null && ($args['actionUrl'] ?? $args[6] ?? null) === 'https://nuers.example/merchant/profile';
        });
        $this->assertTrue(app(PlatformNotifications::class)->runOne());
        $this->assertFalse(app(PlatformNotifications::class)->runOne());
        $this->getJson($this->url)->assertOk()->assertJsonPath('history.0.state', 'smtp_accepted')->assertJsonPath('history.0.recipient', $this->owner->email)->assertJsonPath('history.0.body', $p['body']);
        $this->assertSame('pending', DB::table('merchants')->where('id', $this->merchant)->value('status'));
        $this->assertTrue(app(BirCompliance::class)->auditIntegrity());
    }

    public function test_approval_requires_all_documents_and_saved_activation_and_never_changes_the_decision(): void
    {
        foreach (['documents_verified', 'account_approved'] as $kind) {
            $this->postJson($this->url.'/preview', compact('kind'))->assertConflict();
        }
        $this->verified();
        $this->preview('documents_verified');
        $this->postJson($this->url.'/preview', ['kind' => 'account_approved'])->assertConflict();
        $this->verified(true);
        $p = $this->preview('account_approved');
        $this->assertStringContainsString('approved and activated', $p['body']);
        $this->assertStringContainsString('do not establish BIR approval', $p['body']);
        $this->postJson($this->url, $this->request($p))->assertStatus(202);
        $this->assertSame(1, DB::table('merchants')->where('id', $this->merchant)->value('registry_revision'));
        $this->assertSame(5, DB::table('merchant_verification_documents')->where('status', 'verified')->count());
        DB::table('merchant_verification_documents')->where('merchant_id', $this->merchant)->update(['reviewed_by' => (string) $this->owner->id]);
        $this->postJson($this->url.'/preview', ['kind' => 'account_approved'])->assertConflict();
    }

    public function test_preview_is_actor_session_business_and_expiry_bound_and_rejects_tampering(): void
    {
        $p = $this->preview();
        $v = $this->request($p);
        $other = $this->actor('admin');
        $this->withToken($other->api_token)->postJson($this->url, $v)->assertForbidden();
        $this->withToken($this->admin->api_token)->postJson('/api/admin/businesses/'.Str::uuid().'/review-notifications', $v)->assertForbidden();
        $this->postJson($this->url, [...$v, 'preview_token' => 'tampered'])->assertUnprocessable();
        $this->travel(11)->minutes();
        $this->postJson($this->url, $v)->assertConflict();
        $this->assertDatabaseCount('business_review_notifications', 0);
    }

    public function test_stale_preview_and_changed_recipient_are_rejected_without_queueing(): void
    {
        $p = $this->preview();
        DB::table('merchants')->where('id', $this->merchant)->increment('registry_revision');
        $this->postJson($this->url, $this->request($p))->assertConflict();
        $p = $this->preview();
        DB::table('users')->where('id', $this->owner->id)->update(['email' => 'new-owner@example.test']);
        $this->postJson($this->url, $this->request($p))->assertConflict();
        $this->assertDatabaseCount('notification_outbox', 0);
    }

    public function test_worker_cancels_if_decision_or_sender_permission_changes_and_preferences_are_respected(): void
    {
        $p = $this->preview();
        DB::table('notification_preferences')->insert(['user_id' => (string) $this->owner->id, 'workflow_email' => false]);
        $this->postJson($this->url, $this->request($p))->assertConflict();
        DB::table('notification_preferences')->where('user_id', $this->owner->id)->update(['workflow_email' => true]);
        $this->postJson($this->url, $this->request($p))->assertStatus(202);
        DB::table('profiles')->where('id', $this->admin->id)->update(['role' => 'client']);
        $this->mail->shouldNotReceive('send');
        $this->assertTrue(app(PlatformNotifications::class)->runOne());
        $this->assertDatabaseHas('notification_outbox', ['event' => 'business.review.manual', 'state' => 'cancelled', 'error_code' => 'review_changed']);
    }

    public function test_intentional_resend_requires_reason_and_cooldown_and_uncertain_mail_cannot_be_replayed(): void
    {
        $p = $this->preview();
        $this->postJson($this->url, $this->request($p))->assertStatus(202);
        DB::table('notification_outbox')->update(['state' => 'smtp_accepted']);
        $this->postJson($this->url, $this->request($p))->assertOk()->assertJsonPath('duplicate', true);
        $this->postJson($this->url, $this->request($p, ['resend' => true]))->assertUnprocessable();
        $this->postJson($this->url, $this->request($p, ['resend' => true, 'resend_reason' => 'Merchant requested another copy']))->assertStatus(429);
        $this->travel(6)->minutes();
        $p = $this->preview();
        $this->postJson($this->url, $this->request($p, ['resend' => true, 'resend_reason' => 'Merchant requested another copy']))->assertStatus(202);
        $this->assertDatabaseCount('business_review_notifications', 2);
        DB::table('notification_outbox')->update(['state' => 'uncertain']);
        $this->travel(6)->minutes();
        $p = $this->preview();
        $this->postJson($this->url, $this->request($p, ['resend' => true, 'resend_reason' => 'Merchant requested another copy']))->assertConflict();
        $this->assertDatabaseCount('business_review_notifications', 2);
    }

    public function test_manual_send_accepts_login_mfa_and_rejects_proof_from_a_previous_login(): void
    {
        config(['security.enforce_privileged_mfa' => true]);
        $this->admin->forceFill(['mfa_secret' => 'synthetic-only', 'api_authenticated_at' => now()->subHour(), 'api_mfa_verified_at' => now()->subMinutes(20)])->save();
        $this->getJson($this->url)->assertOk();
        $this->postJson($this->url.'/preview', ['kind' => 'changes_requested'])->assertOk();
        $this->admin->forceFill(['api_authenticated_at' => now()->subMinute()])->save();
        $this->postJson($this->url.'/preview', ['kind' => 'changes_requested'])->assertForbidden()->assertJsonPath('code', 'MFA_REQUIRED');
        $this->assertDatabaseCount('business_review_notifications', 0);
    }

    public function test_worker_cancels_a_notice_when_the_saved_decision_changes_after_queueing(): void
    {
        $p = $this->preview();
        $this->postJson($this->url, $this->request($p))->assertStatus(202);
        DB::table('merchants')->where('id', $this->merchant)->increment('registry_revision');
        $this->mail->shouldNotReceive('send');
        app(PlatformNotifications::class)->runOne();
        $this->assertDatabaseHas('notification_outbox', ['event' => 'business.review.manual', 'state' => 'cancelled', 'error_code' => 'review_changed']);
    }

    public function test_queue_failure_rolls_back_delivery_record_and_does_not_alter_saved_reviews(): void
    {
        $p = $this->preview();
        $notifications = Mockery::mock(PlatformNotifications::class)->makePartial();
        $notifications->shouldReceive('queue')->once()->andReturnNull();
        $this->app->instance(PlatformNotifications::class, $notifications);
        $this->postJson($this->url, $this->request($p))->assertStatus(503);
        $this->assertDatabaseCount('business_review_notifications', 0);
        $this->assertDatabaseCount('notification_outbox', 0);
        $this->assertSame('needs_changes', DB::table('merchant_verification_documents')->where('kind', 'bir_cor')->value('status'));
        $this->assertSame(1, DB::table('merchants')->where('id', $this->merchant)->value('registry_revision'));
    }

    public function test_preview_does_not_survive_a_new_session_for_the_same_administrator(): void
    {
        $p = $this->preview();
        $newToken = Str::random(64);
        $this->admin->forceFill(['api_token' => $newToken])->save();
        $this->withToken($newToken)->postJson($this->url, $this->request($p))->assertForbidden();
        $this->assertDatabaseCount('notification_outbox', 0);
    }
}
