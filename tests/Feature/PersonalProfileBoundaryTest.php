<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\IsolatedSecurityDatabase;
use Tests\TestCase;

class PersonalProfileBoundaryTest extends TestCase
{
    use IsolatedSecurityDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->securityDatabase();
        $this->securityMigrate();
        config(['cache.default' => 'array', 'app.debug' => false, 'security.enforce_privileged_mfa' => true, 'notifications.dispatch_enabled' => false]);
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
    }

    private function actor(string $role): array
    {
        $token = Str::random(64);
        $device = (string) Str::uuid();
        $u = User::factory()->create(['api_token' => $token]);
        $u->forceFill(['api_device_hash' => hash('sha256', $device), 'mfa_secret' => Crypt::encryptString('SYNTHETICKEY'),
            'api_mfa_verified_at' => now(), 'api_last_activity_at' => now()])->save();
        DB::table('profiles')->insert(['id' => $u->id, 'email' => $u->email, 'full_name' => 'Original synthetic name', 'role' => $role]);

        return [$u, ['Authorization' => 'Bearer '.$token, 'X-NUERS-Device' => $device]];
    }

    public function test_minimal_valid_profile_edit_succeeds_only_for_personal_roles_without_cross_account_mutation(): void
    {
        [$foreign] = $this->actor('client');
        foreach (['super_admin', 'admin', 'bir', 'rdo', 'merchant', 'client', 'consumer'] as $role) {
            [$u, $h] = $this->actor($role);
            $before = $u->fresh()->getRawOriginal();
            $body = ['full_name' => 'Updated synthetic '.$role, 'user_id' => $foreign->id, 'id' => $foreign->id,
                'role' => 'super_admin', 'tin' => '999888777', 'email_verified_at' => now()->toIso8601String()];
            $r = $this->putJson('/api/client/profile?user_id='.$foreign->id, $body, $h);
            $allowed = in_array($role, ['client', 'consumer'], true);
            $r->assertStatus($allowed ? 200 : 403);
            $this->assertDatabaseHas('profiles', ['id' => $u->id, 'role' => $role, 'full_name' => $allowed ? $body['full_name'] : 'Original synthetic name']);
            $this->assertDatabaseHas('profiles', ['id' => $foreign->id, 'full_name' => 'Original synthetic name']);
            $this->assertNull(DB::table('profiles')->where('id', $u->id)->value('tin'));
            foreach (['email', 'password', 'mfa_secret', 'email_verified_at', 'api_token_hash'] as $field) {
                $this->assertSame($before[$field] ?? null, $u->fresh()->getRawOriginal($field), $role.' '.$field);
            }
        }
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_omitted_email_preserves_contact_and_explicit_changes_never_change_sign_in_identity(): void
    {
        [$u, $h] = $this->actor('client');
        [$foreign] = $this->actor('consumer');
        DB::table('profiles')->where('id', $u->id)->update(['email' => 'existing-contact@example.test']);
        $this->putJson('/api/client/profile', ['full_name' => 'Name only'], $h)->assertOk()->assertJsonPath('data.profile.email', 'existing-contact@example.test');
        $this->assertDatabaseCount('notification_outbox', 0);
        $this->putJson('/api/client/profile', ['full_name' => 'Conflict', 'email' => $foreign->email], $h)->assertUnprocessable();
        $this->putJson('/api/client/profile', ['full_name' => 'Updated contact', 'email' => 'new-contact@example.test'], $h)->assertOk()->assertJsonPath('data.profile.email', 'new-contact@example.test');
        $this->assertSame($u->email, $u->fresh()->email);
        $this->assertDatabaseCount('notification_outbox', 1);
        $this->putJson('/api/client/profile', ['full_name' => 'Reset contact', 'email' => null], $h)->assertOk()->assertJsonPath('data.profile.email', $u->email);
        Mail::assertNothingSent();
    }
}
