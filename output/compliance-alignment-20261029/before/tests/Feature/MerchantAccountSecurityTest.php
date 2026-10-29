<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BirCompliance;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MerchantAccountSecurityTest extends TestCase
{
    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array', 'notifications.dispatch_enabled' => false]);
        DB::purge('sqlite');
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->merchant = $this->actor();
        $this->withToken($this->merchant->api_token);
    }

    private function actor(): User
    {
        $u = User::factory()->create(['api_token' => Str::random(64)]);
        DB::table('profiles')->insert(['id' => $u->id, 'email' => $u->email, 'full_name' => 'Synthetic Merchant', 'role' => 'merchant']);

        return $u;
    }

    public function test_security_overview_reports_only_real_self_scoped_facts_and_never_discloses_secrets(): void
    {
        $other = $this->actor();
        DB::table('users')->where('id', $this->merchant->id)->update(['mfa_secret' => Crypt::encryptString('PRIVATESETUPKEY'), 'mfa_recovery_hashes' => json_encode([hash('sha256', 'PRIVATEBACKUPCODE')]), 'api_mfa_verified_at' => now()]);
        app(BirCompliance::class)->audit('security.mfa.enrolled', (string) $this->merchant->id, (string) $this->merchant->id, ['secret_marker' => 'DONOTEXPOSE']);
        app(BirCompliance::class)->audit('security.password.changed', (string) $other->id, (string) $other->id);
        app(BirCompliance::class)->audit('security.email.verified', (string) $this->merchant->id, (string) $other->id);
        $before = DB::table('users')->where('id', $this->merchant->id)->first();
        $r = $this->withHeader('User-Agent', 'Mozilla/5.0 (Macintosh) Chrome/140.0')->getJson('/api/auth/security-overview?user_id='.$other->id)->assertOk()
            ->assertJsonPath('email', $this->merchant->email)->assertJsonPath('mfa.enrolled', true)->assertJsonPath('mfa.fresh', true)->assertJsonPath('mfa.recovery_codes_remaining', 1)
            ->assertJsonPath('password_changed_at', null)->assertJsonPath('session.current', true)->assertJsonPath('session.browser', 'Chrome')->assertJsonPath('session.device', 'macOS')
            ->assertJsonPath('policy.single_browser', true)->assertJsonPath('policy.sms_available', false)->assertJsonPath('policy.login_pin_available', false)->assertJsonCount(1, 'activity')->assertJsonPath('activity.0.event', 'security.mfa.enrolled');
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        foreach (['PRIVATESETUPKEY', 'PRIVATEBACKUPCODE', 'DONOTEXPOSE', $this->merchant->api_token, 'mfa_secret', 'api_token_hash', 'mfa_recovery_hashes', 'details'] as $secret) {
            $this->assertStringNotContainsString($secret, $r->getContent());
        }
        $this->assertEquals($before, DB::table('users')->where('id', $this->merchant->id)->first());
        $this->assertSame(3, DB::table('eis_audit_events')->count());
    }

    public function test_missing_mfa_and_password_history_are_not_claimed_as_verified_or_strong(): void
    {
        $r = $this->getJson('/api/auth/security-overview')->assertOk()->assertJsonPath('mfa.enrolled', false)->assertJsonPath('mfa.fresh', false)->assertJsonPath('mfa.recovery_codes_remaining', 0)->assertJsonPath('password_changed_at', null)->assertJsonCount(0, 'activity')
            ->assertJsonPath('policy.password_min', 12)->assertJsonPath('policy.step_up_minutes', null)->assertJsonPath('policy.mfa_challenge', 'login_session')->assertJsonPath('policy.security_email_required', true);
        $this->assertArrayNotHasKey('location', $r->json('session'));
        $this->assertArrayNotHasKey('password_strong', $r->json());
        app(BirCompliance::class)->audit('security.password.changed', (string) $this->merchant->id, (string) $this->merchant->id);
        $this->getJson('/api/auth/security-overview')->assertOk()->assertJsonPath('password_changed_at', fn ($v) => str_ends_with($v, '+00:00'));
    }

    public function test_expired_missing_or_replaced_sessions_cannot_read_security_history(): void
    {
        $this->withToken('unknown-token')->getJson('/api/auth/security-overview')->assertUnauthorized();
        $this->withToken($this->merchant->api_token);
        DB::table('users')->where('id', $this->merchant->id)->update(['api_expires_at' => now()->subSecond()]);
        $this->getJson('/api/auth/security-overview')->assertUnauthorized();
    }
}
