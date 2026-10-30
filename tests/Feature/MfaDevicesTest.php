<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MfaDevices;
use App\Services\Totp;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MfaDevicesTest extends TestCase
{
    private User $user;

    private string $secret;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array', 'notifications.dispatch_enabled' => false, 'security.enforce_privileged_mfa' => true]);
        DB::purge('sqlite');
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->travelTo(now()->startOfMinute());
        $this->user = $this->actor();
        $this->token = $this->user->api_token;
        $this->withToken($this->token);
        $this->secret = app(Totp::class)->secret();
        $this->user->forceFill(['mfa_secret' => Crypt::encryptString($this->secret), 'api_mfa_verified_at' => now(), 'mfa_recovery_hashes' => json_encode([hash('sha256', 'synthetic-recovery-code')])])->save();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function actor(): User
    {
        $u = User::factory()->create(['api_token' => Str::random(64), 'password' => 'SyntheticPassword123']);
        DB::table('profiles')->insert(['id' => $u->id, 'email' => $u->email, 'full_name' => 'Synthetic only', 'role' => 'merchant']);

        return $u;
    }

    private function code(?string $secret = null): string
    {
        return app(Totp::class)->code($secret ?? $this->secret, intdiv(now()->timestamp, 30));
    }

    private function proof(): array
    {
        return ['password' => 'SyntheticPassword123', 'current_code' => $this->code()];
    }

    private function start(string $name = 'Work phone'): array
    {
        return $this->postJson('/api/auth/mfa/devices', ['name' => $name, ...$this->proof()])->assertCreated()->json();
    }

    private function add(string $name = 'Work phone'): array
    {
        $d = $this->start($name);
        $this->postJson('/api/auth/mfa/devices/'.$d['id'].'/confirm', ['code' => $this->code($d['secret'])])->assertOk()->assertJsonCount(2, 'devices');

        return $d;
    }

    public function test_existing_primary_remains_usable_without_exposing_or_replacing_secrets(): void
    {
        $before = $this->user->fresh()->getAttributes();
        $r = $this->getJson('/api/auth/mfa/devices')->assertOk()->assertJsonCount(1, 'devices')->assertJsonPath('devices.0.primary', true)->assertJsonPath('maximum', 5)->assertJsonPath('recovery_codes_remaining', 1);
        $r->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringNotContainsString($this->secret, $r->getContent());
        $this->assertStringNotContainsString('encrypted_secret', $r->getContent());
        $this->assertDatabaseCount('account_mfa_devices', 0);
        $this->assertSame($before, $this->user->fresh()->getAttributes());
        $this->postJson('/api/auth/mfa/verify', ['code' => $this->code()])->assertOk();
        $this->assertDatabaseHas('account_mfa_devices', ['is_primary' => true, 'state' => 'active']);
    }

    public function test_new_device_requires_confirmation_and_both_devices_verify_with_replay_protection(): void
    {
        $d = $this->start();
        $stored = DB::table('account_mfa_devices')->where('id', $d['id'])->first();
        $this->assertNotSame($d['secret'], $stored->encrypted_secret);
        $this->postJson('/api/auth/mfa/verify', ['code' => $this->code($d['secret'])])->assertUnprocessable();
        $this->postJson('/api/auth/mfa/devices/'.$d['id'].'/confirm', ['code' => $this->code($d['secret'])])->assertOk()->assertJsonCount(2, 'devices')->assertDontSee($d['secret']);
        $this->postJson('/api/auth/mfa/verify', ['code' => $this->code($d['secret'])])->assertUnprocessable();
        $this->travel(31)->seconds();
        $this->postJson('/api/auth/mfa/verify', ['code' => $this->code($d['secret'])])->assertOk();
        $this->postJson('/api/auth/mfa/verify', ['code' => $this->code($d['secret'])])->assertUnprocessable();
        $this->postJson('/api/auth/mfa/verify', ['code' => $this->code()])->assertOk();
        $this->assertDatabaseHas('eis_audit_events', ['event' => 'security.mfa.device_added']);
    }

    public function test_device_changes_require_verified_session_password_and_existing_factor(): void
    {
        $this->postJson('/api/auth/mfa/devices', ['name' => 'Phone', 'password' => 'bad', 'current_code' => $this->code()])->assertUnprocessable();
        $this->postJson('/api/auth/mfa/devices', ['name' => 'Phone', 'password' => 'SyntheticPassword123', 'current_code' => 'invalid'])->assertUnprocessable();
        DB::table('users')->where('id', $this->user->id)->update(['api_mfa_verified_at' => null]);
        $this->postJson('/api/auth/mfa/devices', ['name' => 'Phone', ...$this->proof()])->assertForbidden();
        $this->getJson('/api/auth/mfa/devices')->assertForbidden();
        $this->assertDatabaseCount('account_mfa_devices', 0);
    }

    public function test_primary_revocation_promotes_verified_device_and_old_secret_stops_working(): void
    {
        $d = $this->add();
        $this->travel(31)->seconds();
        $primary = app(MfaDevices::class)->legacyId($this->user);
        $this->deleteJson('/api/auth/mfa/devices/'.$primary, $this->proof())->assertOk()->assertJsonPath('devices.0.id', $d['id'])->assertJsonPath('devices.0.primary', true);
        $this->assertSame($d['secret'], Crypt::decryptString($this->user->fresh()->mfa_secret));
        $this->assertNull(DB::table('account_mfa_devices')->where('id', $primary)->value('encrypted_secret'));
        $this->travel(31)->seconds();
        $this->postJson('/api/auth/mfa/verify', ['code' => $this->code()])->assertUnprocessable();
        $this->postJson('/api/auth/mfa/verify', ['code' => $this->code($d['secret'])])->assertOk();
        $this->deleteJson('/api/auth/mfa/devices/'.$d['id'], $this->proof())->assertConflict();
    }

    public function test_user_cannot_confirm_cancel_or_revoke_another_users_device(): void
    {
        $d = $this->start();
        $other = $this->actor();
        $other->forceFill(['mfa_secret' => Crypt::encryptString(app(Totp::class)->secret()), 'api_mfa_verified_at' => now()])->save();
        $this->withToken($other->api_token);
        $this->postJson('/api/auth/mfa/devices/'.$d['id'].'/confirm', ['code' => $this->code($d['secret'])])->assertNotFound();
        $this->postJson('/api/auth/mfa/devices/'.$d['id'].'/cancel')->assertNotFound();
        $this->deleteJson('/api/auth/mfa/devices/'.app(MfaDevices::class)->legacyId($this->user), $this->proof())->assertNotFound();
    }

    public function test_cancel_and_expiry_invalidate_and_erase_pending_secrets(): void
    {
        $d = $this->start();
        $this->postJson('/api/auth/mfa/devices/'.$d['id'].'/cancel')->assertOk();
        $this->assertNull(DB::table('account_mfa_devices')->where('id', $d['id'])->value('encrypted_secret'));
        $this->postJson('/api/auth/mfa/devices/'.$d['id'].'/confirm', ['code' => $this->code($d['secret'])])->assertConflict();
        $this->travel(31)->seconds();
        $d = $this->start();
        $this->travel(11)->minutes();
        $this->postJson('/api/auth/mfa/devices/'.$d['id'].'/confirm', ['code' => $this->code($d['secret'])])->assertConflict();
        $this->artisan('security:prune-mfa-setups')->assertSuccessful();
        $this->assertDatabaseHas('account_mfa_devices', ['id' => $d['id'], 'state' => 'expired', 'encrypted_secret' => null]);
    }

    public function test_pending_setup_is_bound_to_login_and_password(): void
    {
        $d = $this->start();
        $next = Str::random(64);
        $this->user->forceFill(['api_token' => $next])->save();
        $this->withToken($next);
        $this->postJson('/api/auth/mfa/devices/'.$d['id'].'/confirm', ['code' => $this->code($d['secret'])])->assertConflict();
        $this->travel(31)->seconds();
        $d = $this->start('Backup phone');
        $this->user->forceFill(['password' => 'ChangedPassword123'])->save();
        $this->postJson('/api/auth/mfa/devices/'.$d['id'].'/confirm', ['code' => $this->code($d['secret'])])->assertConflict();
    }

    public function test_recovery_proof_is_consumed_once_and_device_cap_and_names_are_enforced(): void
    {
        $this->postJson('/api/auth/mfa/devices', ['name' => 'Recovery phone', 'password' => 'SyntheticPassword123', 'current_code' => 'synthetic-recovery-code'])->assertCreated();
        $this->postJson('/api/auth/mfa/devices', ['name' => 'Other phone', 'password' => 'SyntheticPassword123', 'current_code' => 'synthetic-recovery-code'])->assertUnprocessable();
        $this->postJson('/api/auth/mfa/devices', ['name' => 'primary AUTHENTICATOR', ...$this->proof()])->assertUnprocessable();
        foreach (range(1, 4) as $i) {
            DB::table('account_mfa_devices')->insert(['id' => Str::uuid(), 'user_id' => $this->user->id, 'name' => 'Synthetic '.$i, 'state' => 'active', 'encrypted_secret' => Crypt::encryptString(app(Totp::class)->secret()), 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->getJson('/api/auth/mfa/devices')->assertOk()->assertJsonPath('can_add', false)->assertJsonPath('recovery_codes_remaining', 0);
        $this->postJson('/api/auth/mfa/devices', ['name' => 'Sixth phone', ...$this->proof()])->assertConflict();
    }

    public function test_failed_confirmation_is_rate_limited_and_cancel_cannot_remove_active_device(): void
    {
        $d = $this->add();
        $this->postJson('/api/auth/mfa/devices/'.$d['id'].'/cancel')->assertConflict();
        $this->travel(61)->seconds();
        $d = $this->start('Backup phone');
        foreach (range(1, 5) as $i) {
            $this->postJson('/api/auth/mfa/devices/'.$d['id'].'/confirm', ['code' => 'invalid'])->assertUnprocessable();
        }
        $this->postJson('/api/auth/mfa/devices/'.$d['id'].'/confirm', ['code' => 'invalid'])->assertTooManyRequests();
    }
}
