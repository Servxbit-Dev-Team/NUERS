<?php

namespace Tests\Recovered\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Recovered\RecoveredDomainTestCase as TestCase;

class DeviceSessionTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite');
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->user = User::factory()->create(['password' => 'example-password-123']);
        DB::table('profiles')->insert(['id' => $this->user->id, 'email' => $this->user->email, 'role' => 'admin']);
    }

    private function login(string $device)
    {
        return $this->withHeaders(['X-NUERS-Device' => $device])->postJson('/api/auth/login', ['email' => $this->user->email, 'password' => 'example-password-123']);
    }

    public function test_same_device_login_preserves_session_but_different_device_replaces_it()
    {
        $a = (string) Str::uuid();
        $b = (string) Str::uuid();
        $first = $this->login($a)->assertOk()->json('session.access_token');
        $this->withToken($first);
        $this->assertSame($first, $this->login($a)->assertOk()->json('session.access_token'));
        $second = $this->login($b)->assertOk()->json('session.access_token');
        $this->assertNotSame($first, $second);
        $this->withToken($first)->getJson('/api/auth/session')->assertOk()->assertJsonPath('session', null);
        $this->withToken($first)->postJson('/api/auth/logout')->assertOk();
        $this->assertSame(hash('sha256', $second), $this->user->fresh()->api_token_hash);
        $this->withToken($second)->getJson('/api/auth/session')->assertOk()->assertJsonPath('session.user.id', (string) $this->user->id);
        $this->postJson('/api/auth/logout')->assertOk();
        $this->getJson('/api/auth/session')->assertJsonPath('session', null);
    }

    public function test_failed_login_and_repeated_validation_do_not_revoke_valid_session()
    {
        $device = (string) Str::uuid();
        $token = $this->login($device)->json('session.access_token');
        $this->postJson('/api/auth/login', ['email' => $this->user->email, 'password' => 'incorrect'])->assertUnprocessable();
        $this->assertSame(hash('sha256', $token), $this->user->fresh()->api_token_hash);
        for ($i = 0; $i < 3; $i++) {
            $this->withToken($token)->getJson('/api/auth/session')->assertOk()->assertJsonPath('session.access_token', $token);
        }$this->assertSame(hash('sha256', $token), $this->user->fresh()->api_token_hash);
        $this->assertArrayNotHasKey('api_device_hash', $this->user->fresh()->toArray());
    }

    public function test_device_identifiers_are_validated_without_destroying_session()
    {
        $token = $this->login((string) Str::uuid())->json('session.access_token');
        $this->login('invalid-device')->assertUnprocessable();
        $this->assertSame(hash('sha256', $token), $this->user->fresh()->api_token_hash);
        $response = $this->withToken($token)->getJson('/api/auth/session')->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }
}
