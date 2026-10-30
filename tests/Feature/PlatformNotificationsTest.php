<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BirCompliance;
use App\Services\NotificationEvents;
use App\Services\PlatformMail;
use App\Services\PlatformNotifications;
use App\Services\SignedWebhook;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Mockery;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class PlatformNotificationsTest extends TestCase
{
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['notifications.dispatch_enabled' => true]);
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'app.url' => 'http://localhost:8001', 'cache.default' => 'array']);
        DB::purge('sqlite');
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->owner = User::factory()->create(['email' => 'owner@example.test', 'password' => 'OriginalPassword2026', 'api_token' => Str::random(64)]);
        DB::table('profiles')->insert(['id' => $this->owner->id, 'role' => 'merchant', 'email' => $this->owner->email]);
        DB::table('merchants')->insert(['id' => 'test-merchant', 'merchant_account_id' => $this->owner->id, 'business_name' => 'Test business', 'status' => 'active']);
        $s = ['host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'starttls', 'username' => 'private-user', 'password' => 'private-smtp-secret', 'from_address' => 'mail@nuers.example', 'from_name' => 'OK Technology', 'enabled' => true];
        DB::table('platform_mail_settings')->insert(['id' => 1, 'revision' => 1, 'encrypted_settings' => Crypt::encryptString(json_encode($s)), 'connection_hash' => app(PlatformMail::class)->connectionHash($s), 'updated_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function payload(object $row): array
    {
        return json_decode(Crypt::decryptString($row->encrypted_payload), true);
    }

    private function n(): PlatformNotifications
    {
        return app(PlatformNotifications::class);
    }

    public function test_encrypted_deduplicated_outbox_is_transactional_and_does_not_send_in_request(): void
    {
        Mail::fake();
        $this->n()->user($this->owner->id, 'security.example', 'same', 'Security alert', 'Private content', 'security');
        $this->n()->user($this->owner->id, 'security.example', 'same', 'Security alert', 'Private content', 'security');
        $this->assertSame(1, DB::table('notification_outbox')->count());
        $raw = DB::table('notification_outbox')->first();
        $this->assertStringNotContainsString('owner@example.test', $raw->encrypted_payload);
        $this->assertStringNotContainsString('Private content', $raw->encrypted_payload);
        try {
            DB::transaction(function () {
                $this->n()->user($this->owner->id, 'account.rollback', 'a', 'Must roll back', 'Private');
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame(1, DB::table('notification_outbox')->count());
        Mail::assertNothingSent();
    }

    public function test_worker_acceptance_purges_payload_and_never_resends(): void
    {
        $mail = Mockery::mock(PlatformMail::class)->makePartial();
        $mail->shouldReceive('send')->once()->withArgs(fn ($s, $email) => $email === 'owner@example.test');
        $this->app->instance(PlatformMail::class, $mail);
        $this->n()->user($this->owner->id, 'account.example', 'a', 'Account update', 'Private');
        $this->assertTrue($this->n()->runOne());
        $this->assertFalse($this->n()->runOne());
        $this->assertDatabaseHas('notification_outbox', ['state' => 'smtp_accepted', 'encrypted_payload' => null, 'attempts' => 1]);
    }

    public function test_review_request_notice_rechecks_admin_role_before_delivery(): void
    {
        DB::table('profiles')->where('id', $this->owner->id)->update(['role' => 'admin']);
        $this->n()->admins('business.profile.review_requested', 'submission', 'Review requested', 'Open the private review workspace.');
        DB::table('profiles')->where('id', $this->owner->id)->update(['role' => 'client']);
        $mail = Mockery::mock(PlatformMail::class)->makePartial();
        $mail->shouldNotReceive('send');
        $this->app->instance(PlatformMail::class, $mail);
        $this->assertTrue($this->n()->runOne());
        $this->assertDatabaseHas('notification_outbox', ['event' => 'business.profile.review_requested', 'state' => 'cancelled', 'encrypted_payload' => null]);
    }

    public function test_smtp_timeout_is_uncertain_and_is_never_automatically_replayed(): void
    {
        $mail = Mockery::mock(PlatformMail::class)->makePartial();
        $mail->shouldReceive('send')->once()->andThrow(new \RuntimeException('private-smtp-secret provider reply'));
        $this->app->instance(PlatformMail::class, $mail);
        $this->n()->user($this->owner->id, 'account.example', 'a', 'Account update', 'Private');
        $this->n()->runOne();
        $this->n()->runOne();
        $this->assertDatabaseHas('notification_outbox', ['state' => 'uncertain', 'error_code' => 'smtp_unconfirmed', 'encrypted_payload' => null]);
        $this->assertStringNotContainsString('private-smtp-secret', json_encode(DB::table('notification_outbox')->get()));
    }

    public function test_opt_out_suppresses_optional_mail_but_cannot_disable_security_and_is_checked_again_on_dispatch(): void
    {
        $this->withToken($this->owner->api_token)->putJson('/api/auth/notification-preferences', ['workflow_email' => false, 'support_email' => false, 'operations_email' => false, 'security_email' => false])->assertOk()->assertJsonPath('security_email', true);
        $this->n()->user($this->owner->id, 'account.optional', 'a', 'Optional', 'Skip');
        $this->assertSame(0, DB::table('notification_outbox')->count());
        $this->n()->user($this->owner->id, 'security.required', 'a', 'Security', 'Send', 'security');
        $this->assertSame(1, DB::table('notification_outbox')->count());
        DB::table('notification_outbox')->delete();
        DB::table('notification_preferences')->delete();
        $this->n()->user($this->owner->id, 'account.optional', 'b', 'Optional', 'Skip');
        DB::table('notification_preferences')->insert(['user_id' => (string) $this->owner->id, 'workflow_email' => false]);
        $mail = Mockery::mock(PlatformMail::class)->makePartial();
        $mail->shouldNotReceive('send');
        $this->app->instance(PlatformMail::class, $mail);
        $this->n()->runOne();
        $this->assertDatabaseHas('notification_outbox', ['state' => 'cancelled']);
    }

    public function test_disabled_smtp_holds_mail_and_expired_links_are_purged(): void
    {
        $settings = app(PlatformMail::class)->settings();
        $settings['enabled'] = false;
        DB::table('platform_mail_settings')->update(['encrypted_settings' => Crypt::encryptString(json_encode($settings))]);
        $this->n()->user($this->owner->id, 'account.example', 'a', 'Update', 'Private');
        $this->assertFalse($this->n()->runOne());
        $this->assertDatabaseHas('notification_outbox', ['state' => 'queued', 'attempts' => 0]);
        DB::table('notification_outbox')->update(['expires_at' => now()->subMinute()]);
        $this->n()->runOne();
        $this->assertDatabaseHas('notification_outbox', ['state' => 'expired', 'encrypted_payload' => null]);
    }

    public function test_interrupted_smtp_lease_is_uncertain_and_missing_payload_preparation_is_bounded(): void
    {
        $mail = Mockery::mock(PlatformMail::class)->makePartial();
        $mail->shouldNotReceive('send');
        $this->app->instance(PlatformMail::class, $mail);
        $this->n()->user($this->owner->id, 'account.interrupted', 'a', 'Update', 'Private');
        DB::table('notification_outbox')->update(['state' => 'sending', 'lease_until' => now()->subMinute()]);
        $this->n()->runOne();
        $this->assertDatabaseHas('notification_outbox', ['state' => 'uncertain', 'error_code' => 'worker_interrupted']);
        DB::table('notification_outbox')->delete();
        $this->n()->user($this->owner->id, 'account.bad', 'a', 'Update', 'Private');
        DB::table('notification_outbox')->update(['encrypted_payload' => 'corrupt']);
        for ($i = 0; $i < 3; $i++) {
            DB::table('notification_outbox')->update(['available_at' => now()->subSecond()]);
            $this->n()->runOne();
        }
        $this->assertDatabaseHas('notification_outbox', ['state' => 'failed', 'attempts' => 3, 'error_code' => 'preparation_failed', 'encrypted_payload' => null]);
    }

    public function test_reset_is_generic_single_use_hash_only_revokes_sessions_and_preserves_mfa(): void
    {
        $this->withHeader('Host', 'untrusted.example')->postJson('/api/auth/forgot-password', ['email' => $this->owner->email])->assertOk();
        $known = $this->postJson('/api/auth/forgot-password', ['email' => $this->owner->email])->assertOk()->json();
        $unknown = $this->postJson('/api/auth/forgot-password', ['email' => 'missing@example.test'])->assertOk()->json();
        $this->assertSame($known, $unknown);
        $p = $this->payload(DB::table('notification_outbox')->first());
        $this->assertStringStartsWith('http://localhost:8001/reset-password#', $p['action_url']);
        parse_str(parse_url($p['action_url'], PHP_URL_FRAGMENT), $fragment);
        $this->assertSame(hash('sha256', $fragment['token']), DB::table('account_password_resets')->value('token_hash'));
        DB::table('users')->where('id', $this->owner->id)->update(['mfa_secret' => 'preserve-existing-authenticator']);
        $body = ['email' => $this->owner->email, 'token' => $fragment['token'], 'password' => 'NewSecurePassword2026', 'password_confirmation' => 'NewSecurePassword2026'];
        $this->postJson('/api/auth/reset-password', $body)->assertOk();
        $this->postJson('/api/auth/reset-password', $body)->assertUnprocessable();
        $u = User::find($this->owner->id);
        $this->assertTrue(Hash::check($body['password'], $u->password));
        $this->assertNull($u->api_token_hash);
        $this->assertSame('preserve-existing-authenticator', $u->mfa_secret);
        $this->assertSame(0, DB::table('account_password_resets')->count());
        $this->assertDatabaseHas('notification_outbox', ['event' => 'security.password.reset', 'state' => 'queued']);
        $this->assertStringNotContainsString($body['password'], json_encode(DB::table('eis_audit_events')->get()));
    }

    public function test_wrong_or_expired_reset_never_changes_password(): void
    {
        $this->postJson('/api/auth/forgot-password', ['email' => $this->owner->email]);
        $p = $this->payload(DB::table('notification_outbox')->first());
        parse_str(parse_url($p['action_url'], PHP_URL_FRAGMENT), $fragment);
        $body = ['email' => $this->owner->email, 'token' => str_repeat('x', 64), 'password' => 'NewSecurePassword2026', 'password_confirmation' => 'NewSecurePassword2026'];
        $this->postJson('/api/auth/reset-password', $body)->assertUnprocessable();
        DB::table('account_password_resets')->update(['expires_at' => now()->subSecond()]);
        $body['token'] = $fragment['token'];
        $this->postJson('/api/auth/reset-password', $body)->assertUnprocessable();
        $this->assertTrue(Hash::check('OriginalPassword2026', User::find($this->owner->id)->password));
    }

    public function test_change_password_requires_current_password_and_notifies_registered_address_only(): void
    {
        $body = ['current_password' => 'wrong', 'password' => 'NewSecurePassword2026', 'password_confirmation' => 'NewSecurePassword2026'];
        $this->withToken($this->owner->api_token)->postJson('/api/auth/change-password', $body)->assertUnprocessable();
        $this->assertSame(0, DB::table('notification_outbox')->count());
        $body['current_password'] = 'OriginalPassword2026';
        $this->postJson('/api/auth/change-password', $body)->assertOk();
        $row = DB::table('notification_outbox')->first();
        $this->assertSame('security.password.changed', $row->event);
        $this->assertSame($this->owner->email, $this->payload($row)['recipient']);
        $this->getJson('/api/auth/notification-preferences')->assertUnauthorized();
        $this->assertTrue(app(BirCompliance::class)->auditIntegrity());
    }

    public function test_notification_history_is_user_scoped_and_admin_coverage_is_role_protected(): void
    {
        $other = User::factory()->create();
        $this->n()->user($other->id, 'account.foreign', 'a', 'Private', 'Private');
        $this->n()->user($this->owner->id, 'account.own', 'a', 'Own', 'Own');
        $this->withToken($this->owner->api_token)->getJson('/api/auth/notification-preferences')->assertOk()->assertJsonCount(1, 'recent')->assertJsonPath('recent.0.event', 'account.own')->assertJsonMissingPath('recent.0.encrypted_payload');
        $this->getJson('/api/admin/configuration/notifications')->assertForbidden();
    }

    public function test_configured_registration_uses_admin_smtp_outbox_without_development_code(): void
    {
        Mail::fake();
        $r = $this->postJson('/api/registration/email-code', ['email' => 'registration@example.test'])->assertOk()->assertJsonPath('development_delivery', false)->assertJsonMissingPath('development_code');
        $p = $this->payload(DB::table('notification_outbox')->first());
        preg_match('/code is: (\d{6})/', $p['body'], $m);
        $this->assertTrue(Hash::check($m[1], DB::table('registration_email_verifications')->value('code_hash')));
        $this->postJson('/api/registration/verify-code', ['challenge_id' => $r->json('challenge_id'), 'code' => $m[1]])->assertOk();
        Mail::assertNothingSent();
    }

    public function test_allowlisted_workflow_router_scopes_api_key_mail_and_ignores_passive_events(): void
    {
        app(BirCompliance::class)->audit('api_key.created', 'key', (string) $this->owner->id, ['merchant_id' => 'test-merchant']);
        app(BirCompliance::class)->audit('security.mfa.verified', (string) $this->owner->id, (string) $this->owner->id, ['recovery_code' => false]);
        app(BirCompliance::class)->audit('integration.event.accepted', 'event', (string) $this->owner->id, ['merchant_id' => 'test-merchant']);
        $this->assertSame(1, DB::table('notification_outbox')->count());
        $this->assertDatabaseHas('notification_outbox', ['user_id' => (string) $this->owner->id, 'merchant_id' => 'test-merchant', 'category' => 'security']);
    }

    public function test_sender_and_multipart_template_are_nuers_and_escape_untrusted_content(): void
    {
        $fake = new class () {
            public $html;

            public $message;

            public function getSymfonyTransport()
            {
                return new class () {
                    public function getStream()
                    {
                        return null;
                    }
                };
            }

            public function html($html, $callback)
            {
                $this->html = $html;
                $this->message = new Message(new Email());
                $callback($this->message);
            }
        };
        $dns = Mockery::mock(SignedWebhook::class);
        $dns->shouldReceive('address')->once()->andReturn(['smtp.example.com', '8.8.8.8']);
        $this->app->instance(SignedWebhook::class, $dns);
        Mail::shouldReceive('build')->once()->andReturn($fake);
        app(PlatformMail::class)->send(app(PlatformMail::class)->settings(), 'recipient@example.test', 'Security update', '<script>unsafe</script>', actionUrl: 'http://localhost:8001/login');
        $mime = $fake->message->getSymfonyMessage();
        $this->assertSame('NUERS', $mime->getFrom()[0]->getName());
        $this->assertSame('NUERS | Security update', $mime->getSubject());
        $this->assertStringContainsString('&lt;script&gt;unsafe&lt;/script&gt;', $fake->html);
        $this->assertStringNotContainsString('OK Technology', $fake->html);
        $this->assertStringContainsString('NUERS', $mime->getTextBody());
    }

    public function test_operational_reminders_do_not_backfill_history_and_are_deduplicated(): void
    {
        app(NotificationEvents::class)->reconcile();
        $this->assertSame(0, DB::table('notification_outbox')->count());
        DB::table('operational_notifications')->insert(['id' => Str::uuid(), 'dedupe_key' => hash('sha256', 'current'), 'merchant_id' => 'test-merchant', 'category' => 'delivery_review', 'subject_id' => 'job', 'severity' => 'high', 'message' => 'Private details stay in app', 'created_at' => now(), 'updated_at' => now()]);
        app(NotificationEvents::class)->reconcile();
        app(NotificationEvents::class)->reconcile();
        $this->assertSame(1, DB::table('notification_outbox')->count());
        $this->assertDatabaseHas('notification_outbox', ['event' => 'operations.merchant_digest']);
    }

    public function test_email_verification_is_expiring_one_use_and_does_not_activate_taxpayer_account(): void
    {
        $this->owner->forceFill(['email_verified_at' => null])->save();
        $this->n()->verification($this->owner);
        $p = $this->payload(DB::table('notification_outbox')->first());
        parse_str(parse_url($p['action_url'], PHP_URL_FRAGMENT), $fragment);
        $this->assertSame(hash('sha256', $fragment['token']), DB::table('account_email_verifications')->value('token_hash'));
        $this->postJson('/api/auth/verify-email', ['email' => $this->owner->email, 'token' => $fragment['token']])->assertOk();
        $this->postJson('/api/auth/verify-email', ['email' => $this->owner->email, 'token' => $fragment['token']])->assertUnprocessable();
        $this->assertNotNull(User::find($this->owner->id)->email_verified_at);
        $this->assertSame('active', DB::table('merchants')->where('id', 'test-merchant')->value('status'));
        $this->assertDatabaseHas('notification_outbox', ['event' => 'security.email.verified']);
    }

    public function test_security_email_prioritizes_reset_over_customer_or_workflow_and_stale_recipient_is_cancelled(): void
    {
        $this->n()->user($this->owner->id, 'workflow.first', 'a', 'Workflow', 'One');
        $this->n()->user($this->owner->id, 'security.next', 'b', 'Security', 'Two', 'security');
        $mail = Mockery::mock(PlatformMail::class)->makePartial();
        $mail->shouldReceive('send')->once()->withArgs(fn ($s, $to, $subject) => $subject === 'Security');
        $this->app->instance(PlatformMail::class, $mail);
        $this->n()->runOne();
        $this->assertDatabaseHas('notification_outbox', ['event' => 'security.next', 'state' => 'smtp_accepted']);
        DB::table('users')->where('id', $this->owner->id)->update(['email' => 'changed@example.test']);
        $this->n()->runOne();
        $this->assertDatabaseHas('notification_outbox', ['event' => 'workflow.first', 'state' => 'cancelled']);
    }

    private function administrator(): User
    {
        $u = User::factory()->create(['api_token' => Str::random(64)]);
        DB::table('profiles')->insert(['id' => $u->id, 'role' => 'super_admin', 'email' => $u->email]);

        return $u;
    }

    public function test_failed_sign_in_alert_is_thresholded_and_deduplicated(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->n()->failedSignIn($this->owner);
        }
        $this->assertSame(1, DB::table('notification_outbox')->where('event', 'security.failed_signins')->count());
    }

    public function test_invitation_queues_once_and_revocation_cancels_delivery(): void
    {
        $admin = $this->administrator();
        DB::table('merchants')->insert(['id' => 'unlinked', 'business_name' => 'Invitation QA', 'email' => 'invite@example.test', 'status' => 'active']);
        $body = ['request_id' => (string) Str::uuid(), 'reason' => 'Authorized business setup invitation'];
        $this->withToken($admin->api_token)->postJson('/api/business-registry/unlinked/invitation', $body)->assertOk();
        $this->postJson('/api/business-registry/unlinked/invitation', $body)->assertOk();
        $this->assertSame(1, DB::table('notification_outbox')->where('event', 'business.invitation')->count());
        DB::table('business_registry_requests')->where('id', $body['request_id'])->update(['revoked_at' => now()]);
        $mail = Mockery::mock(PlatformMail::class)->makePartial();
        $mail->shouldNotReceive('send');
        $this->app->instance(PlatformMail::class, $mail);
        $this->n()->runOne();
        $this->assertDatabaseHas('notification_outbox', ['event' => 'business.invitation', 'state' => 'cancelled']);
    }

    public function test_authenticated_support_emails_one_reply_per_unread_burst_and_status_changes_only(): void
    {
        $id = (string) Str::uuid();
        $this->withToken($this->owner->api_token)->postJson('/api/support/conversations', ['id' => $id, 'visitor_token' => bin2hex(random_bytes(32)), 'request_id' => (string) Str::uuid(), 'subject' => 'QA support', 'message' => 'QA support message'])->assertCreated();
        $admin = $this->administrator();
        $this->withToken($admin->api_token)->postJson('/api/admin/support/'.$id.'/messages', ['request_id' => (string) Str::uuid(), 'body' => 'Private reply'])->assertCreated();
        $this->postJson('/api/admin/support/'.$id.'/messages', ['request_id' => (string) Str::uuid(), 'body' => 'Second reply'])->assertCreated();
        $this->assertSame(1, DB::table('notification_outbox')->where('event', 'support.agent.replied')->count());
        $revision = DB::table('support_conversations')->where('id', $id)->value('revision');
        $this->patchJson('/api/admin/support/'.$id, ['revision' => $revision, 'status' => 'resolved', 'priority' => 'normal'])->assertOk();
        $this->patchJson('/api/admin/support/'.$id, ['revision' => $revision + 1, 'status' => 'resolved', 'priority' => 'high'])->assertOk();
        $this->assertSame(1, DB::table('notification_outbox')->where('event', 'support.conversation.updated')->count());
        foreach (DB::table('notification_outbox')->get() as $row) {
            $this->assertStringNotContainsString('Private reply', $this->payload($row)['body']);
        }
    }

    public function test_admin_health_timestamps_carry_the_configured_timezone_and_do_not_expose_payloads(): void
    {
        config(['app.timezone' => 'Asia/Manila']);
        $admin = $this->administrator();
        $this->n()->user($this->owner->id, 'account.created', 'a', 'Welcome', 'Private');
        $r = $this->withToken($admin->api_token)->getJson('/api/admin/configuration/notifications')->assertOk()->assertJsonMissingPath('recent.0.encrypted_payload');
        $this->assertStringEndsWith('+08:00', $r->json('recent.0.created_at'));
        $this->assertStringEndsWith('+08:00', $r->json('runtime.activated_at'));
    }

    public function test_rollout_pause_prevents_network_delivery_even_when_smtp_is_enabled(): void
    {
        config(['notifications.dispatch_enabled' => false]);
        $this->n()->user($this->owner->id, 'security.paused', 'a', 'Security', 'Private', 'security');
        $mail = Mockery::mock(PlatformMail::class)->makePartial();
        $mail->shouldNotReceive('send');
        $this->app->instance(PlatformMail::class, $mail);
        $this->assertFalse($this->n()->runOne());
        $this->assertDatabaseHas('notification_outbox', ['state' => 'queued', 'attempts' => 0]);
    }
}
