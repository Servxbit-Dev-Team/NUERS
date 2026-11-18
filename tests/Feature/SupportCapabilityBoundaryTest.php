<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\IsolatedSecurityDatabase;
use Tests\TestCase;

class SupportCapabilityBoundaryTest extends TestCase
{
    use IsolatedSecurityDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->securityDatabase();
        $this->securityMigrate();
        config(['cache.default' => 'array', 'security.enforce_privileged_mfa' => true, 'notifications.dispatch_enabled' => false]);
        Http::preventStrayRequests();
    }

    private function actor(string $role): array
    {
        $token = Str::random(64);
        $device = (string) Str::uuid();
        $u = User::factory()->create(['api_token' => $token]);
        $u->forceFill(['api_device_hash' => hash('sha256', $device), 'mfa_secret' => Crypt::encryptString('SYNTHETICKEY'),
            'api_mfa_verified_at' => now(), 'api_last_activity_at' => now()])->save();
        DB::table('profiles')->insert(['id' => $u->id, 'email' => $u->email, 'role' => $role]);
        return [$u, ['Authorization' => 'Bearer '.$token, 'X-NUERS-Device' => $device]];
    }

    private function thread(): array
    {
        $p = ['id' => (string) Str::uuid(), 'visitor_token' => bin2hex(random_bytes(32)), 'request_id' => (string) Str::uuid(),
            'name' => 'Synthetic visitor', 'email' => 'synthetic@example.test', 'subject' => 'Synthetic security check', 'message' => 'Synthetic question'];
        $this->postJson('/api/support/conversations', $p)->assertCreated();
        return $p;
    }

    public function test_capability_cannot_cross_conversation_or_impersonate_an_agent(): void
    {
        $a = $this->thread();
        $b = $this->thread();
        $url = '/api/support/conversations/'.$b['id'];
        $h = ['X-Chat-Token' => $a['visitor_token']];
        $payload = ['request_id' => (string) Str::uuid(), 'body' => 'Synthetic reply'];
        $this->getJson($url, $h)->assertNotFound();
        $this->postJson($url.'/messages', $payload, $h)->assertNotFound();
        $this->postJson($url.'/read', ['message_id' => 1], $h)->assertNotFound();
        $this->getJson('/api/admin/support', $h)->assertUnauthorized();
        $this->postJson('/api/admin/support/'.$a['id'].'/messages', $payload, $h)->assertUnauthorized();
        $url = '/api/support/conversations/'.$a['id'];
        $foreignMessage = DB::table('support_messages')->where('conversation_id', $b['id'])->value('id');
        $this->postJson($url.'/read', ['message_id' => $foreignMessage], $h)->assertUnprocessable();
        $this->postJson($url.'/messages', $payload + ['sender_type' => 'agent', 'sender_id' => 1, 'conversation_id' => $b['id']], $h)->assertCreated();
        $this->assertDatabaseHas('support_messages', ['conversation_id' => $a['id'], 'request_id' => $payload['request_id'], 'sender_type' => 'visitor']);
        $this->assertSame(1, DB::table('support_messages')->where('conversation_id', $b['id'])->count());
        $this->postJson($url.'/messages', $payload, $h)->assertOk();
        $this->postJson($url.'/messages', array_replace($payload, ['body' => 'Changed replay']), $h)->assertConflict();
        $this->getJson($url, $h)->assertOk()->assertJsonMissingPath('conversation.visitor_email')->assertJsonMissingPath('conversation.visitor_token_hash');
        Http::assertNothingSent();
    }

    public function test_full_session_role_and_device_checks_protect_the_admin_inbox(): void
    {
        $p = $this->thread();
        foreach (['merchant', 'client', 'consumer', 'bir', 'rdo', 'admin', 'super_admin'] as $role) {
            [$u, $h] = $this->actor($role);
            $admin = in_array($role, ['admin', 'super_admin'], true);
            $this->getJson('/api/admin/support', $h)->assertStatus($admin ? 200 : 403);
            $this->getJson('/api/admin/support/'.$p['id'], $h)->assertStatus($admin ? 200 : 403);
            $this->postJson('/api/admin/support/'.$p['id'].'/messages', ['request_id' => (string) Str::uuid(), 'body' => 'Synthetic agent reply'], $h)->assertStatus($admin ? 201 : 403);
            $this->getJson('/api/admin/support', array_replace($h, ['X-NUERS-Device' => (string) Str::uuid()]))->assertUnauthorized();
            // A human session is not a visitor capability, even for an administrator.
            $this->getJson('/api/support/conversations/'.$p['id'], $h)->assertNotFound();
            if ($admin) {
                $u->forceFill(['api_mfa_verified_at' => null])->save();
                $this->getJson('/api/admin/support', $h)->assertForbidden();
            }
        }
        Http::assertNothingSent();
    }

    public function test_expired_and_deleted_capabilities_cannot_read_write_or_recreate(): void
    {
        foreach (['token_expires_at' => now()->subMinute(), 'status' => 'deleted'] as $field => $value) {
            $p = $this->thread();
            DB::table('support_conversations')->where('id', $p['id'])->update([$field => $value]);
            $h = ['X-Chat-Token' => $p['visitor_token']];
            $url = '/api/support/conversations/'.$p['id'];
            $this->getJson($url, $h)->assertNotFound();
            $this->postJson($url.'/messages', ['request_id' => (string) Str::uuid(), 'body' => 'Denied replay'], $h)->assertNotFound();
            $this->postJson($url.'/read', ['message_id' => 1], $h)->assertNotFound();
            $this->postJson('/api/support/conversations', $p)->assertNotFound();
            $this->assertSame(1, DB::table('support_messages')->where('conversation_id', $p['id'])->count());
        }
    }
}
