<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SessionPersistenceTest extends TestCase
{
    private User $user;
    private string $device;
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array', 'security.enforce_privileged_mfa' => false,
            'security.token_minutes' => 1440, 'security.session_absolute_minutes' => 10080]);
        DB::purge('sqlite');
        foreach (glob(database_path('migrations/*.php')) as $file) (require $file)->up();
        $this->user = User::factory()->create(['password' => 'Synthetic-Session-Password-2026']);
        DB::table('profiles')->insert(['id' => $this->user->id, 'role' => 'merchant', 'email' => $this->user->email]);
        $this->device = (string) Str::uuid();
    }
    protected function tearDown(): void { $this->travelBack(); parent::tearDown(); }
    private function login(): string
    {
        return $this->withHeaders(['X-NUERS-Device' => $this->device])->postJson('/api/auth/login', ['email' => $this->user->email, 'password' => 'Synthetic-Session-Password-2026'])->assertOk()->json('session.access_token');
    }
    public function test_session_survives_three_idle_hours_and_activity_renews_it_without_changing_identity_or_mfa(): void
    {
        $token = $this->login(); $initial = $this->user->fresh();
        $this->assertEqualsWithDelta(86400, now()->diffInSeconds($initial->api_expires_at), 2);
        DB::table('users')->where('id', $this->user->id)->update(['api_mfa_verified_at' => now()]); $mfa = $this->user->fresh()->getRawOriginal('api_mfa_verified_at');
        $this->travel(3)->hours();
        $this->withToken($token)->getJson('/api/auth/session')->assertOk()->assertJsonPath('session.user.id', (string) $this->user->id);
        $this->assertSame($initial->getRawOriginal('api_expires_at'), $this->user->fresh()->getRawOriginal('api_expires_at'));
        $response = $this->withHeaders(['X-NUERS-Activity' => '1'])->getJson('/api/auth/session')->assertOk()->assertJsonPath('session.access_token', $token);
        $fresh = $this->user->fresh();
        $this->assertEqualsWithDelta(86400, now()->diffInSeconds($fresh->api_expires_at), 2);
        $this->assertSame($initial->getRawOriginal('api_authenticated_at'), $fresh->getRawOriginal('api_authenticated_at'));
        $this->assertSame($mfa, $fresh->getRawOriginal('api_mfa_verified_at'));
        $this->assertNotEmpty($response->json('session.expires_at')); $this->assertNotEmpty($response->json('session.absolute_expires_at'));
    }
    public function test_background_checks_do_not_extend_expiry_and_expired_session_cannot_be_revived(): void
    {
        $token = $this->login(); $expires = $this->user->fresh()->getRawOriginal('api_expires_at');
        foreach ([3, 6, 12] as $hours) {
            $this->travelTo($this->user->fresh()->api_authenticated_at->copy()->addHours($hours));
            $this->withToken($token)->getJson('/api/auth/session')->assertOk()->assertJsonPath('session.access_token', $token);
            $this->assertSame($expires, $this->user->fresh()->getRawOriginal('api_expires_at'));
        }
        $this->travelTo($this->user->fresh()->api_authenticated_at->copy()->addHours(25));
        $this->withHeaders(['X-NUERS-Activity' => '1'])->getJson('/api/auth/session')->assertOk()->assertJsonPath('session', null);
        $this->assertSame($expires, $this->user->fresh()->getRawOriginal('api_expires_at'));
    }
    public function test_activity_is_capped_at_seven_days_and_cannot_bypass_absolute_expiration(): void
    {
        $token = $this->login(); $authenticated = $this->user->fresh()->api_authenticated_at;
        $this->travelTo($authenticated->copy()->addDays(6)->addHours(12));
        DB::table('users')->where('id', $this->user->id)->update(['api_expires_at' => now()->addHour()]);
        $this->withToken($token)->withHeaders(['X-NUERS-Activity' => '1'])->getJson('/api/auth/session')->assertOk()->assertJsonPath('session.access_token', $token);
        $this->assertTrue($this->user->fresh()->api_expires_at->equalTo($authenticated->copy()->addDays(7)));
        $this->travelTo($authenticated->copy()->addDays(7)->addMinute());
        DB::table('users')->where('id', $this->user->id)->update(['api_expires_at' => now()->addDay()]);
        $this->getJson('/api/auth/session')->assertOk()->assertJsonPath('session', null);
    }
    public function test_wrong_device_replaced_token_and_logged_out_tokens_cannot_renew(): void
    {
        $first = $this->login(); $this->travel(3)->hours();
        $this->withToken($first)->withHeaders(['X-NUERS-Device' => (string) Str::uuid(), 'X-NUERS-Activity' => '1'])->getJson('/api/auth/session')->assertOk()->assertJsonPath('session', null);
        $this->device = (string) Str::uuid(); $second = $this->login(); $expires = $this->user->fresh()->getRawOriginal('api_expires_at');
        $this->withToken($first)->getJson('/api/auth/session')->assertOk()->assertJsonPath('session', null);
        $this->assertSame($expires, $this->user->fresh()->getRawOriginal('api_expires_at'));
        $this->withToken($second)->postJson('/api/auth/logout')->assertOk();
        $this->getJson('/api/auth/session')->assertOk()->assertJsonPath('session', null);
        $this->assertNull($this->user->fresh()->api_token_hash);
    }
    public function test_session_renewal_does_not_clear_a_required_privileged_mfa_check(): void
    {
        $token = $this->login(); DB::table('profiles')->where('id', $this->user->id)->update(['role' => 'super_admin']);
        config(['security.enforce_privileged_mfa' => true]); $this->travel(3)->hours();
        $this->withToken($token)->withHeaders(['X-NUERS-Activity' => '1'])->getJson('/api/auth/session')->assertOk()->assertJsonPath('session.mfa_required', true);
        $this->assertNull($this->user->fresh()->api_mfa_verified_at);
        $this->getJson('/api/admin/configuration')->assertForbidden()->assertJsonPath('code', 'MFA_REQUIRED');
    }

    public function test_verified_merchant_keeps_current_login_assurance_for_twelve_hours_without_rewriting_verification(): void
    {
        config(['security.enforce_privileged_mfa' => true]);
        $token = $this->login();
        DB::table('users')->where('id', $this->user->id)->update(['mfa_secret' => 'synthetic-test-only', 'api_mfa_verified_at' => now()]);
        $verified = $this->user->fresh()->getRawOriginal('api_mfa_verified_at');
        foreach ([16, 180, 720] as $minutes) {
            $this->travelTo($this->user->fresh()->api_authenticated_at->copy()->addMinutes($minutes));
            $this->withToken($token)->getJson('/api/auth/session')->assertOk()->assertJsonPath('session.mfa_required', false);
            $this->getJson('/api/auth/security-overview')->assertOk()->assertJsonPath('mfa.verified', true);
            $this->getJson('/api/auth/mfa/status')->assertOk()->assertJsonPath('verified', true)->assertJsonPath('fresh', true);
            $this->assertSame($verified, $this->user->fresh()->getRawOriginal('api_mfa_verified_at'));
        }
        // A new sign-in must still complete MFA, even on this browser.
        $newToken = $this->login();
        $this->withToken($newToken)->getJson('/api/auth/session')->assertOk()->assertJsonPath('session.mfa_required', true);
        $this->getJson('/api/auth/security-overview')->assertForbidden()->assertJsonPath('code', 'MFA_REQUIRED');
    }

    public function test_admin_browsing_and_changes_keep_login_assurance_until_next_sign_in(): void
    {
        DB::table('profiles')->where('id', $this->user->id)->update(['role' => 'super_admin']);
        config(['security.enforce_privileged_mfa' => true]);
        $token = $this->login();
        DB::table('users')->where('id', $this->user->id)->update(['mfa_secret' => 'synthetic-test-only', 'api_mfa_verified_at' => now()]);
        $this->travel(16)->minutes();
        $this->withToken($token)->getJson('/api/admin/account-profile')->assertOk();
        $this->getJson('/api/auth/session')->assertOk()->assertJsonPath('session.mfa_required', false);
        $this->putJson('/api/admin/configuration/smtp', [])->assertUnprocessable();
        $this->getJson('/api/admin/account-profile')->assertOk();
        // A verification timestamp from an earlier login cannot authenticate this session.
        DB::table('users')->where('id', $this->user->id)->update(['api_mfa_verified_at' => $this->user->fresh()->api_authenticated_at->copy()->subSecond()]);
        $this->getJson('/api/auth/session')->assertOk()->assertJsonPath('session.mfa_required', true);
        $this->getJson('/api/admin/account-profile')->assertForbidden();
        $this->putJson('/api/admin/configuration/smtp', [])->assertForbidden()->assertJsonPath('code', 'MFA_REQUIRED');
    }
    public function test_real_totp_verification_lasts_for_this_login_and_is_reset_by_next_login(): void
    {
        config(['security.enforce_privileged_mfa' => true]);
        DB::table('profiles')->where('id', $this->user->id)->update(['role' => 'super_admin']);
        $secret = 'JBSWY3DPEHPK3PXP'; // Isolated synthetic authenticator only.
        DB::table('users')->where('id', $this->user->id)->update(['mfa_secret' => \Illuminate\Support\Facades\Crypt::encryptString($secret)]);
        $token = $this->login();
        $this->withToken($token)->getJson('/api/admin/account-profile')->assertForbidden()->assertJsonPath('code', 'MFA_REQUIRED');
        $code = app(\App\Services\Totp::class)->code($secret, (int) floor(now()->timestamp / 30));
        $this->postJson('/api/auth/mfa/verify', ['code' => $code])->assertOk()->assertJsonPath('verified', true);
        $verifiedAt = $this->user->fresh()->getRawOriginal('api_mfa_verified_at');
        $this->travel(2)->hours();
        $this->getJson('/api/auth/mfa/status')->assertOk()->assertJsonPath('verified', true);
        $this->putJson('/api/admin/account-profile/notifications', ['revision' => 0, 'workflow_email' => true, 'support_email' => true, 'operations_email' => true])->assertOk();
        $this->assertSame($verifiedAt, $this->user->fresh()->getRawOriginal('api_mfa_verified_at'));
        $next = $this->login();
        $this->withToken($next)->getJson('/api/auth/mfa/status')->assertOk()->assertJsonPath('verified', false);
        $this->putJson('/api/admin/account-profile/notifications', ['revision' => 0, 'workflow_email' => true, 'support_email' => true, 'operations_email' => true])->assertForbidden()->assertJsonPath('code', 'MFA_REQUIRED');
        $this->postJson('/api/auth/mfa/verify', ['code' => $code])->assertUnprocessable();
        $nextCode = app(\App\Services\Totp::class)->code($secret, (int) floor(now()->timestamp / 30));
        $this->postJson('/api/auth/mfa/verify', ['code' => $nextCode])->assertOk();
        $this->postJson('/api/auth/logout')->assertOk();
        $this->getJson('/api/admin/account-profile')->assertUnauthorized();
    }

}
