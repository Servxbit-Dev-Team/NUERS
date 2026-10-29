<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BirCompliance;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminAccountProfileTest extends TestCase
{
    private User $admin;

    private string $temp;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array', 'security.enforce_privileged_mfa' => false, 'notifications.dispatch_enabled' => false]);
        DB::purge('sqlite');
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->admin = $this->actor('super_admin');
        $this->withToken($this->admin->api_token);
        $this->temp = sys_get_temp_dir().'/nuers-admin-profile-test-'.Str::uuid();
        $this->app->useStoragePath($this->temp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temp);
        parent::tearDown();
    }

    private function actor(string $role): User
    {
        $u = User::factory()->create(['password' => 'SyntheticProfile123!', 'api_token' => Str::random(64)]);
        DB::table('profiles')->insert(['id' => $u->id, 'email' => $u->email, 'full_name' => 'Original Admin', 'role' => $role]);

        return $u;
    }

    private function payload(): array
    {
        return ['revision' => 0, 'profile' => ['full_name' => 'Updated NUERS Admin', 'organization' => 'NUERS', 'mobile' => '+63 917 123 4567', 'position' => 'Platform administrator', 'department' => 'Operations', 'office_address' => 'Synthetic private office address', 'telephone' => '+63 2 8888 0000', 'alternate_email' => 'alternate@example.test', 'timezone' => 'Asia/Manila', 'time_format' => '24', 'density' => 'comfortable']];
    }

    public function test_read_is_private_real_and_does_not_seed_profile_or_fabricate_sessions(): void
    {
        $r = $this->getJson('/api/admin/account-profile')->assertOk()->assertJsonPath('profile.full_name', 'Original Admin')->assertJsonPath('account.role', 'super_admin')->assertJsonPath('account.revision', 0)->assertJsonPath('security.mfa_enrolled', false)->assertJsonPath('security.password_changed_at', null)->assertJsonPath('session.current', true);
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        $this->assertSame(0, DB::table('admin_account_profiles')->count());
        foreach (['api_token', 'api_token_hash', 'mfa_secret', 'mfa_pending_secret', 'mfa_recovery_hashes'] as $field) {
            $this->assertArrayNotHasKey($field, $r->json());
        }
    }

    public function test_profile_edits_are_self_only_encrypted_audited_and_do_not_change_credentials_or_roles(): void
    {
        $other = $this->actor('admin');
        $password = $this->admin->password;
        $p = $this->payload();
        $p['user_id'] = $other->id;
        $this->putJson('/api/admin/account-profile', $p)->assertOk()->assertJsonPath('account.revision', 1)->assertJsonPath('profile.full_name', 'Updated NUERS Admin');
        $this->assertSame('Original Admin', DB::table('profiles')->where('id', $other->id)->value('full_name'));
        $this->assertSame('super_admin', DB::table('profiles')->where('id', $this->admin->id)->value('role'));
        $this->assertSame($password, $this->admin->fresh()->password);
        $encrypted = DB::table('admin_account_profiles')->value('encrypted_details');
        $this->assertStringNotContainsString('Synthetic private', $encrypted);
        $this->assertSame('Operations', json_decode(Crypt::decryptString($encrypted), true)['department']);
        $event = DB::table('eis_audit_events')->where('event', 'account.profile.updated')->first();
        $this->assertNotNull($event);
        $this->assertStringNotContainsString('Synthetic private', $event->details);
        $this->assertTrue(app(BirCompliance::class)->auditIntegrity());
        $this->getJson('/api/admin/account-profile')->assertJsonPath('activity.0.at', fn ($value) => str_ends_with($value, '+00:00'));
    }

    public function test_optimistic_revision_prevents_lost_updates_and_unknown_profile_fields_are_rejected(): void
    {
        $this->putJson('/api/admin/account-profile', $this->payload())->assertOk();
        $this->putJson('/api/admin/account-profile', $this->payload())->assertConflict();
        $p = $this->payload();
        $p['revision'] = 1;
        $p['profile']['role'] = 'super_admin';
        $this->putJson('/api/admin/account-profile', $p)->assertUnprocessable();
        unset($p['profile']['role']);
        $p['profile']['email'] = 'replacement@example.test';
        $this->putJson('/api/admin/account-profile', $p)->assertUnprocessable();
        $this->assertSame($this->admin->email, $this->admin->fresh()->email);
    }

    public function test_validation_bounds_contact_fields_and_preserves_literal_sql_as_data(): void
    {
        $p = $this->payload();
        $p['profile']['timezone'] = 'Fake/Zone';
        $this->putJson('/api/admin/account-profile', $p)->assertUnprocessable();
        $p = $this->payload();
        $p['profile']['mobile'] = 'not-a-phone';
        $this->putJson('/api/admin/account-profile', $p)->assertUnprocessable();
        $p = $this->payload();
        $p['profile']['full_name'] = "Robert'); DROP TABLE users;--";
        $this->putJson('/api/admin/account-profile', $p)->assertOk()->assertJsonPath('profile.full_name', $p['profile']['full_name']);
        $this->assertSame(1, DB::table('users')->count());
    }

    public function test_non_admins_and_missing_or_prior_login_mfa_are_denied(): void
    {
        foreach (['merchant', 'client', 'bir'] as $role) {
            $u = $this->actor($role);
            $this->withToken($u->api_token)->getJson('/api/admin/account-profile')->assertForbidden();
        }
        $this->withToken($this->admin->api_token);
        config(['security.enforce_privileged_mfa' => true]);
        $this->getJson('/api/admin/account-profile')->assertForbidden()->assertJsonPath('code', 'MFA_REQUIRED');
        DB::table('users')->where('id', $this->admin->id)->update(['mfa_secret' => Crypt::encryptString('SYNTHETICQA'), 'api_authenticated_at' => now()->subHour(), 'api_mfa_verified_at' => now()->subMinutes(16)]);
        $this->putJson('/api/admin/account-profile', $this->payload())->assertOk();
        DB::table('users')->where('id', $this->admin->id)->update(['api_authenticated_at' => now()->subMinute()]);
        $this->putJson('/api/admin/account-profile', $this->payload())->assertForbidden()->assertJsonPath('code', 'MFA_REQUIRED');
    }

    public function test_notification_preferences_are_audited_self_scoped_and_cannot_disable_security(): void
    {
        $other = $this->actor('admin');
        $this->putJson('/api/admin/account-profile/notifications', ['revision' => 0, 'security_email' => false, 'workflow_email' => false, 'support_email' => true, 'operations_email' => false, 'user_id' => $other->id])->assertOk()->assertJsonPath('notifications.security_email', true)->assertJsonPath('notifications.workflow_email', false)->assertJsonPath('account.revision', 1);
        $this->assertDatabaseHas('eis_audit_events', ['event' => 'account.preferences.updated', 'actor_id' => (string) $this->admin->id]);
        $this->assertSame(0, DB::table('notification_preferences')->where('user_id', (string) $other->id)->count());
        $this->putJson('/api/admin/account-profile/notifications', ['revision' => 0, 'workflow_email' => true, 'support_email' => true, 'operations_email' => true])->assertConflict();
    }

    public function test_avatar_is_reencoded_private_and_other_admin_cannot_fetch_it(): void
    {
        $this->post('/api/admin/account-profile/avatar', ['avatar' => UploadedFile::fake()->image('face.jpg', 40, 40), 'revision' => 0], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('account.has_avatar', true);
        $this->get('/api/admin/account-profile/avatar')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertFileExists($this->temp.'/app/private/admin-avatars/'.DB::table('admin_account_profiles')->value('avatar_path'));
        $other = $this->actor('admin');
        $this->withToken($other->api_token)->get('/api/admin/account-profile/avatar')->assertNotFound();
    }

    public function test_avatar_rejects_executable_uploads_and_stale_revision(): void
    {
        $this->post('/api/admin/account-profile/avatar', ['avatar' => UploadedFile::fake()->createWithContent('photo.svg', '<svg onload="alert(1)"></svg>'), 'revision' => 0], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->putJson('/api/admin/account-profile', $this->payload())->assertOk();
        $this->post('/api/admin/account-profile/avatar',['avatar' => UploadedFile::fake()->image('face.png',30,30), 'revision' => 0],['Accept' => 'application/json'])->assertConflict();
    }
}
