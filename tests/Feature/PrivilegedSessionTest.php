<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DeviceSession;
use App\Services\Totp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\IsolatedSecurityDatabase;
use Tests\TestCase;

class PrivilegedSessionTest extends TestCase
{
    use IsolatedSecurityDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array', 'queue.default' => 'database', 'app.debug' => false]);
        $this->securityDatabase();
        $this->securityMigrate();
        Mail::fake();
        Http::preventStrayRequests();
    }

    private function login(string $role): array
    {
        $u = User::factory()->create();
        DB::table('profiles')->insert(['id' => $u->id, 'email' => $u->email, 'role' => $role]);
        $r = Request::create('/api/auth/login', 'POST');
        $device = (string) Str::uuid();
        $r->headers->set('X-NUERS-Device', $device);
        $u = app(DeviceSession::class)->issue($u, $r);
        $secret = app(Totp::class)->secret();
        $u->forceFill(['mfa_secret' => Crypt::encryptString($secret), 'api_mfa_verified_at' => now()])->save();

        return [$u, ['Authorization' => 'Bearer '.$u->api_token, 'X-NUERS-Device' => $device], $secret];
    }

    public function test_role_specific_lifetimes_and_metadata(): void
    {
        foreach (['admin', 'super_admin', 'bir', 'rdo', 'merchant', 'client'] as $role) {
            [$u] = $this->login($role);
            $priv = in_array($role, config('security.privileged_roles'));
            $this->assertEqualsWithDelta($priv ? 30 : 1440, now()->diffInMinutes($u->api_expires_at), 0.1);
            $meta = app(DeviceSession::class)->metadata($u);
            $this->assertSame($u->api_authenticated_at->copy()->addMinutes($priv ? 480 : 10080)->toIso8601String(), $meta['absolute_expires_at']);
        }
    }

    public function test_idle_expiry_cannot_be_revived_by_activity_and_background_polling_does_not_extend_it(): void
    {
        [$u,$h] = $this->login('admin');
        $this->travel(29)->minutes();
        $this->getJson('/api/auth/session', $h)->assertJsonPath('session.user.id', (string) $u->id);
        $this->assertSame($u->api_expires_at->timestamp, $u->fresh()->api_expires_at->timestamp);
        $this->travel(2)->minutes();
        $this->getJson('/api/auth/session', $h + ['X-NUERS-Activity' => '1'])->assertJsonPath('session', null);
        $this->getJson('/api/tables/merchants', $h)->assertUnauthorized();
    }

    public function test_activity_extends_idle_but_never_absolute_deadline(): void
    {
        [$u,$h] = $this->login('admin');
        $start = now();
        $this->travel(20)->minutes();
        $this->getJson('/api/auth/session', $h + ['X-NUERS-Activity' => '1'])->assertOk();
        $this->assertSame($start->copy()->addMinutes(50)->timestamp, $u->fresh()->api_expires_at->timestamp);
        $u->forceFill(['api_authenticated_at' => now()->subMinutes(479), 'api_last_activity_at' => now()->subMinutes(2), 'api_expires_at' => now()->addMinutes(20)])->save();
        $this->getJson('/api/auth/session', $h + ['X-NUERS-Activity' => '1'])->assertOk();
        $this->assertSame(now()->addMinute()->timestamp, $u->fresh()->api_expires_at->timestamp);
        $this->travel(61)->seconds();
        $this->getJson('/api/tables/merchants', $h)->assertUnauthorized();
    }

    public function test_new_device_login_and_logout_revoke_old_session(): void
    {
        [$u,$old] = $this->login('admin');
        $r = Request::create('/api/auth/login', 'POST');
        $device = (string) Str::uuid();
        $r->headers->set('X-NUERS-Device', $device);
        $new = app(DeviceSession::class)->issue($u, $r);
        $h = ['Authorization' => 'Bearer '.$new->api_token, 'X-NUERS-Device' => $device];
        $this->getJson('/api/auth/session', $old)->assertJsonPath('session', null);
        $this->assertNull($new->api_mfa_verified_at);
        $this->postJson('/api/auth/logout', [], $h)->assertOk();
        $this->getJson('/api/auth/session', $h)->assertJsonPath('session', null);
    }

    public function test_recent_mfa_blocks_writes_exports_and_compatible_screen_requires_verification(): void
    {
        [$u,$h] = $this->login('super_admin');
        $this->travel(6)->minutes();
        $this->getJson('/api/tables/merchants', $h)->assertOk();
        $before = DB::table('profiles')->where('id', $u->id)->value('organization');
        $this->patchJson('/api/tables/profiles', ['eq' => ['id' => $u->id], 'values' => ['organization' => 'Synthetic audit']], $h)
            ->assertForbidden()->assertJsonPath('code', 'MFA_REQUIRED')->assertJsonPath('reason', 'RECENT_MFA_REQUIRED');
        $this->assertSame($before, DB::table('profiles')->where('id', $u->id)->value('organization'));
        $this->getJson('/api/auth/mfa/status', $h)->assertJsonPath('verified', false)->assertJsonPath('fresh', false);
        $this->getJson('/api/auth/session', $h)->assertJsonPath('session.mfa_required', true);
        // Restore a fresh verification as the authenticator endpoint does (tested separately).
        $u->forceFill(['api_mfa_verified_at' => now()])->save();
        $this->getJson('/api/auth/mfa/status', $h)->assertJsonPath('verified', true)->assertJsonPath('fresh', true);
        $this->travel(6)->minutes();
        $this->getJson('/api/invoice-center/export', $h)->assertForbidden()->assertJsonPath('reason', 'RECENT_MFA_REQUIRED');
    }

    public function test_authenticator_step_up_works_and_replay_is_rejected(): void
    {
        [$u,$h,$secret] = $this->login('admin');
        $this->travel(6)->minutes();
        $this->postJson('/api/admin/support/00000000-0000-4000-8000-000000000001/read', [], $h)->assertForbidden();
        $totp = app(Totp::class);
        $code = $totp->code($secret, intdiv(now()->timestamp, 30));
        $this->postJson('/api/auth/mfa/verify', ['code' => $code], $h)->assertOk();
        $this->getJson('/api/auth/mfa/status',$h)->assertJsonPath('verified',true)->assertJsonPath('fresh',true);
        $this->postJson('/api/auth/mfa/verify',['code' => $code],$h)->assertStatus(422);
    }

    public function test_password_changed_after_authentication_cannot_issue_a_session(): void
    {
        [$stale] = $this->login('admin');
        $newPassword = \Illuminate\Support\Facades\Hash::make('Reset-Synthetic-Password-123');
        DB::table('users')->where('id', $stale->id)->update(['password' => $newPassword, 'api_token_hash' => null]);
        $request = Request::create('/api/auth/login', 'POST');
        $request->headers->set('X-NUERS-Device', (string) Str::uuid());
        try {
            app(DeviceSession::class)->issue($stale, $request);
            $this->fail('An authentication result from before password reset must not create a token.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
        $this->assertNull($stale->fresh()->api_token_hash);
        $this->assertSame($newPassword, $stale->fresh()->getRawOriginal('password'));
    }

    public function test_logout_from_an_inflight_old_session_cannot_revoke_a_new_device_login(): void
    {
        [$stale, $oldHeaders] = $this->login('admin');
        $request = Request::create('/api/auth/login', 'POST');
        $request->headers->set('X-NUERS-Device', (string) Str::uuid());
        $replacement = app(DeviceSession::class)->issue($stale, $request);
        // Simulate logout resolving its user immediately before another login commits.
        $this->mock(\App\Services\ApiAccess::class, function ($mock) use ($stale) {
            $mock->makePartial();
            $mock->shouldReceive('user')->once()->andReturn($stale);
        });
        $this->postJson('/api/auth/logout', [], $oldHeaders)->assertOk();
        $this->assertSame($replacement->api_token_hash, $replacement->fresh()->api_token_hash);
    }
}
