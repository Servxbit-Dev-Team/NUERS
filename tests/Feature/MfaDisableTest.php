<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DeviceSession;
use App\Services\MfaDevices;
use App\Services\MfaSession;
use App\Services\SessionPolicy;
use App\Services\Totp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\IsolatedSecurityDatabase;
use Tests\TestCase;

class MfaDisableTest extends TestCase
{
    use IsolatedSecurityDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->securityDatabase();
        $this->securityMigrate();
        config(['cache.default' => 'array', 'security.enforce_privileged_mfa' => true, 'notifications.dispatch_enabled' => false]);
        Http::preventStrayRequests();
        $this->travelTo(now()->startOfMinute());
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function actor(string $role = 'merchant'): array
    {
        $token = Str::random(64);
        $device = (string) Str::uuid();
        $secret = app(Totp::class)->secret();
        $u = User::factory()->create(['password' => 'SyntheticPassword123', 'api_token' => $token]);
        $u->forceFill(['api_device_hash' => hash('sha256', $device), 'mfa_secret' => Crypt::encryptString($secret),
            'api_mfa_verified_at' => now(), 'api_last_activity_at' => now(),
            'mfa_recovery_hashes' => json_encode([hash('sha256', 'synthetic-unused-recovery')])])->save();
        DB::table('profiles')->insert(['id' => $u->id, 'email' => $u->email, 'role' => $role]);

        return [$u, ['Authorization' => 'Bearer '.$token, 'X-NUERS-Device' => $device], $secret];
    }

    private function proof(string $secret): array
    {
        return ['password' => 'SyntheticPassword123', 'current_code' => app(Totp::class)->code($secret, intdiv(now()->timestamp, 30)), 'confirm' => true];
    }

    private function newSession(User $u, array $headers): array
    {
        $r = Request::create('/api/auth/login', 'POST');
        $r->headers->set('X-NUERS-Device', $headers['X-NUERS-Device']);
        $fresh = app(DeviceSession::class)->issue($u->fresh(), $r);

        return ['Authorization' => 'Bearer '.$fresh->api_token, 'X-NUERS-Device' => $headers['X-NUERS-Device']];
    }

    public function test_every_role_can_disable_own_mfa_with_proof_and_login_again_without_forced_enrollment(): void
    {
        foreach (['merchant', 'admin', 'super_admin', 'bir', 'rdo', 'client', 'consumer'] as $role) {
            Cache::flush();
            [$u, $h, $secret] = $this->actor($role);
            $this->postJson('/api/auth/mfa/disable', $this->proof($secret), $h)->assertOk()->assertJsonPath('disabled', true)->assertHeader('Cache-Control', 'no-store, private');
            $u->refresh();
            $this->assertNull($u->mfa_secret);
            $this->assertNull($u->mfa_recovery_hashes);
            $this->assertNull($u->api_token_hash);
            $this->assertNotNull($u->mfa_disabled_at);
            $this->assertFalse(app(MfaSession::class)->required($u, $role));
            $this->getJson('/api/auth/security-overview', $h)->assertUnauthorized();
            $new = $this->newSession($u, $h);
            $this->getJson('/api/auth/session', $new)->assertOk()->assertJsonPath('session.mfa_required', false);
            $this->putJson('/api/auth/notification-preferences', ['workflow_email' => true, 'support_email' => true, 'operations_email' => false], $new)->assertOk();
            $this->assertDatabaseHas('eis_audit_events', ['event' => 'security.mfa.disabled', 'actor_id' => (string) $u->id, 'subject_id' => (string) $u->id]);
            $this->assertDatabaseHas('notification_outbox', ['event' => 'security.mfa.disabled', 'user_id' => (string) $u->id, 'category' => 'security']);
        }
    }

    public function test_disabled_merchant_can_manage_credentials_and_settings_without_forced_setup(): void
    {
        [$u, $h, $secret] = $this->actor();
        $merchant = (string) Str::uuid();
        DB::table('merchants')->insert(['id' => $merchant, 'merchant_account_id' => $u->id,
            'business_name' => 'Synthetic Optional MFA', 'tin' => '999000001000', 'status' => 'active']);
        $this->postJson('/api/auth/mfa/disable', $this->proof($secret), $h)->assertOk();
        $h = $this->newSession($u, $h);
        $this->getJson('/api/auth/mfa/status', $h)->assertOk()
            ->assertJsonPath('opted_out', true)->assertJsonPath('enrolled', false)->assertJsonPath('verified', false);
        $this->getJson('/api/merchant/api-keys', $h)->assertOk()->assertJsonPath('access.credential_mfa_required', false);
        // Invalid payloads must reach normal validation, never force enrollment.
        foreach (['/api/merchant/api-keys', '/api/merchant/webhooks', '/api/merchant/team/roles'] as $path) {
            $this->postJson($path, [], $h)->assertUnprocessable();
        }
        $settings = $this->getJson('/api/merchant/invoice-settings', $h)->assertOk()->json();
        $this->putJson('/api/merchant/invoice-settings', ['settings' => $settings['settings'], 'revision' => $settings['revision']], $h)->assertOk();
        // Re-enabling MFA must immediately restore the gate even with an old opt-out timestamp.
        $u->refresh()->forceFill(['mfa_secret' => Crypt::encryptString($secret), 'api_mfa_verified_at' => null])->save();
        $this->postJson('/api/merchant/api-keys', [], $h)->assertForbidden()->assertJsonPath('code', 'MFA_REQUIRED');
        $this->getJson('/api/auth/mfa/status', $h)->assertOk()->assertJsonPath('opted_out', false);
    }

    public function test_wrong_missing_replayed_proof_and_unconfirmed_requests_preserve_credentials(): void
    {
        [$u, $h, $secret] = $this->actor('admin');
        $valid = $this->proof($secret);
        foreach ([[], [...$valid, 'password' => 'wrong'], [...$valid, 'current_code' => 'not-a-valid-code'], [...$valid, 'confirm' => false], [...$valid, 'current_code' => ['nested']]] as $body) {
            Cache::flush();
            $this->postJson('/api/auth/mfa/disable', $body, $h)->assertUnprocessable();
            $this->assertNull($u->fresh()->mfa_disabled_at);
            $this->assertNotNull($u->fresh()->mfa_secret);
        }
        Cache::flush();
        $this->postJson('/api/auth/mfa/verify', ['code' => $valid['current_code']], $h)->assertOk();
        $this->postJson('/api/auth/mfa/disable', $valid, $h)->assertUnprocessable();
        $this->assertNull($u->fresh()->mfa_disabled_at);
    }

    public function test_disable_clears_active_pending_legacy_and_recovery_secrets_but_not_other_accounts(): void
    {
        [$u, $h, $secret] = $this->actor();
        [$other] = $this->actor('admin');
        $s = app(MfaDevices::class);
        $s->materialize($u);
        $s->materialize($other);
        foreach (['active', 'pending'] as $state) {
            DB::table('account_mfa_devices')->insert(['id' => (string) Str::uuid(), 'user_id' => $u->id, 'name' => $state,
                'state' => $state, 'encrypted_secret' => Crypt::encryptString(app(Totp::class)->secret()), 'setup_binding' => 'old-binding', 'expires_at' => now()->addMinutes(10)]);
        }
        $u->forceFill(['mfa_pending_secret' => Crypt::encryptString(app(Totp::class)->secret()), 'mfa_pending_at' => now(), 'remember_token' => 'old-remember'])->save();
        DB::table('sessions')->insert(['id' => 'synthetic-mfa-session', 'user_id' => $u->id, 'payload' => '', 'last_activity' => now()->timestamp]);
        $this->postJson('/api/auth/mfa/disable', [...$this->proof($secret), 'user_id' => $other->id], $h)->assertOk();
        foreach (DB::table('account_mfa_devices')->where('user_id', $u->id)->get() as $d) {
            $this->assertSame('revoked', $d->state);
            $this->assertNull($d->encrypted_secret);
            $this->assertNull($d->setup_binding);
            $this->assertFalse((bool) $d->is_primary);
        }
        $u->refresh();
        foreach (['mfa_pending_secret', 'mfa_pending_at', 'mfa_last_counter', 'mfa_recovery_hashes', 'remember_token', 'api_mfa_verified_at', 'api_mfa_challenged_at'] as $field) $this->assertNull($u->$field, $field);
        $this->assertDatabaseMissing('sessions', ['user_id' => $u->id]);
        $this->assertNotNull($other->fresh()->mfa_secret);
        $this->assertNull($other->fresh()->mfa_disabled_at);
        $this->assertSame(1, $s->active($other)->count());
    }

    public function test_unused_recovery_code_is_supported_but_reenrollment_invalidates_old_codes_and_restores_policy(): void
    {
        [$u, $h, $oldSecret] = $this->actor('super_admin');
        $this->postJson('/api/auth/mfa/disable', [...$this->proof($oldSecret), 'current_code' => 'synthetic-unused-recovery'], $h)->assertOk();
        $new = $this->newSession($u, $h);
        $enroll = $this->postJson('/api/auth/mfa/enroll', ['password' => 'SyntheticPassword123'], $new)->assertOk()->json();
        $this->postJson('/api/auth/mfa/confirm', ['code' => $this->proof($enroll['secret'])['current_code']], $new)->assertOk();
        $this->assertNull($u->fresh()->mfa_disabled_at);
        $this->getJson('/api/auth/mfa/devices', $new)->assertOk()->assertJsonCount(1, 'devices');
        $this->travel(31)->seconds();
        $this->postJson('/api/auth/mfa/verify', ['code' => 'synthetic-unused-recovery'], $new)->assertUnprocessable();
        $this->postJson('/api/auth/mfa/verify', ['code' => $this->proof($oldSecret)['current_code']], $new)->assertUnprocessable();
        $this->postJson('/api/auth/mfa/verify', ['code' => $this->proof($enroll['secret'])['current_code']], $new)->assertOk();
        $this->travel(6)->minutes();
        $this->putJson('/api/auth/notification-preferences', ['workflow_email' => true, 'support_email' => true, 'operations_email' => false], $new)->assertForbidden()->assertJsonPath('reason', 'RECENT_MFA_REQUIRED');
    }

    public function test_unenrolled_admin_still_requires_mfa_and_opt_out_cannot_be_mass_assigned(): void
    {
        [$u, $h] = $this->actor('admin');
        $u->forceFill(['mfa_secret' => null, 'api_mfa_verified_at' => null])->save();
        $this->assertTrue(app(MfaSession::class)->required($u->fresh(), 'admin'));
        $this->getJson('/api/auth/security-overview', $h)->assertForbidden()->assertJsonPath('enrollment_required', true);
        $this->postJson('/api/auth/mfa/disable', ['password' => 'SyntheticPassword123', 'current_code' => '123456', 'confirm' => true], $h)->assertConflict();
        $this->assertNull($u->fresh()->mfa_disabled_at);
        $this->assertNotContains('mfa_disabled_at', $u->getFillable());
    }

    public function test_opted_out_admin_keeps_short_idle_expiry_and_cannot_access_infrastructure_without_mfa(): void
    {
        [$u, $h, $secret] = $this->actor('super_admin');
        $this->postJson('/api/auth/mfa/disable', $this->proof($secret), $h)->assertOk();
        $new = $this->newSession($u, $h);
        $u->refresh();
        $limits = app(SessionPolicy::class)->limits($u);
        $this->assertSame(30, $limits['idle_minutes']);
        $this->assertSame(480, $limits['absolute_minutes']);
        $this->assertNull($limits['step_up_minutes']);
        $this->assertFalse(app(MfaSession::class)->verified($u));
        $this->postJson('/api/admin/infrastructure/step-up', ['password' => 'SyntheticPassword123', 'code' => $this->proof($secret)['current_code']], $new)->assertForbidden();
        // Even an accidentally extended token cannot override privileged idle expiry.
        $u->forceFill(['api_expires_at' => now()->addHours(7)])->save();
        $this->travel(31)->minutes();
        $this->assertFalse(app(SessionPolicy::class)->valid($u->fresh()));
        $this->getJson('/api/auth/security-overview', $new)->assertUnauthorized();
    }

    public function test_invalid_replaced_and_wrong_device_sessions_cannot_disable_mfa(): void
    {
        [$u, $h, $secret] = $this->actor();
        $this->postJson('/api/auth/mfa/disable', $this->proof($secret))->assertUnauthorized();
        $this->postJson('/api/auth/mfa/disable', $this->proof($secret), [...$h, 'X-NUERS-Device' => (string) Str::uuid()])->assertUnauthorized();
        $this->newSession($u, $h);
        $this->postJson('/api/auth/mfa/disable', $this->proof($secret), $h)->assertUnauthorized();
        $this->assertNull($u->fresh()->mfa_disabled_at);
    }

    public function test_disable_attempts_are_rate_limited(): void
    {
        [$u, $h, $secret] = $this->actor();
        for ($i = 0; $i < 5; $i++) $this->postJson('/api/auth/mfa/disable', [...$this->proof($secret), 'password' => 'wrong'], $h)->assertUnprocessable();
        $this->postJson('/api/auth/mfa/disable', $this->proof($secret), $h)->assertStatus(429);
        $this->assertNull($u->fresh()->mfa_disabled_at);
    }
}
