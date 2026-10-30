<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\{MerchantWebhooks,MerchantWebhookTransport,SignedWebhook,WebhookDestination};
use Illuminate\Support\Facades\{Crypt,DB};
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class MerchantWebhookTest extends TestCase
{
    private User $owner;
    private string $merchant;
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite','database.connections.sqlite.database' => ':memory:','cache.default' => 'array','notifications.dispatch_enabled' => false,'security.enforce_privileged_mfa' => true,'security.merchant_webhook_delivery_enabled' => true]);
        DB::purge('sqlite');
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->owner = User::factory()->create(['api_token' => Str::random(64),'mfa_secret' => Crypt::encryptString('SYNTHETICKEY'),'api_mfa_verified_at' => now()]);
        DB::table('profiles')->insert(['id' => $this->owner->id,'email' => $this->owner->email,'role' => 'merchant']);
        $this->merchant = (string)Str::uuid();
        DB::table('merchants')->insert(['id' => $this->merchant,'business_name' => 'Synthetic Webhook Seller','tin' => '123456789000','address' => 'Synthetic registered address','vat_registered' => false,'merchant_account_id' => $this->owner->id,'status' => 'active']);
        $this->withToken($this->owner->api_token);
        $this->partialMock(WebhookDestination::class, fn ($m) => $m->shouldReceive('dns')->andReturn(['8.8.8.8']));
        $this->mock(MerchantWebhookTransport::class, fn ($m) => $m->shouldReceive('send')->never());
    }
    private function create(string $env = 'live', string $url = 'https://receiver.example.com/nuers'): array
    {
        return $this->postJson('/api/merchant/webhooks', ['request_id' => (string)Str::uuid(),'name' => 'Synthetic ERP','url' => $url,'environment' => $env,'events' => ['transaction.accepted','source.received'],'ownership_confirmed' => true])->assertCreated()->json();
    }
    private function active(string $env = 'live'): object
    {
        $r = $this->create($env);
        DB::table('merchant_webhook_endpoints')->where('id', $r['endpoint']['id'])->update(['status' => 'active','verified_at' => now()]);
        return DB::table('merchant_webhook_endpoints')->where('id', $r['endpoint']['id'])->first();
    }
    private function queue(object $p): string
    {
        return app(MerchantWebhooks::class)->queue($p, 'transaction.accepted', 'record-1', ['id' => 'record-1','status' => 'durably_recorded'], 'record-1');
    }
    private function transport(callable $callback): void
    {
        $this->instance(MerchantWebhookTransport::class, new class ($callback) extends MerchantWebhookTransport {
            public function __construct(private $callback)
            {
            }
            public function send(string $url, array $headers, string $body): array
            {
                return ($this->callback)($url, $headers, $body);
            }
        });
    }
    public function test_owner_mfa_active_status_and_no_external_sends_on_save(): void
    {
        DB::table('users')->where('id', $this->owner->id)->update(['api_mfa_verified_at' => null]);
        $v = ['request_id' => (string)Str::uuid(),'name' => 'ERP','url' => 'https://receiver.example.com/nuers','environment' => 'live','events' => ['transaction.accepted'],'ownership_confirmed' => true];
        $this->postJson('/api/merchant/webhooks', $v)->assertForbidden()->assertJsonPath('code', 'MFA_REQUIRED');
        DB::table('users')->where('id', $this->owner->id)->update(['api_mfa_verified_at' => now()]);
        foreach (['pending','suspended','inactive'] as $s) {
            DB::table('merchants')->where('id', $this->merchant)->update(['status' => $s]);
            $this->postJson('/api/merchant/webhooks', $v)->assertForbidden();
        }
        $this->assertDatabaseCount('merchant_webhook_endpoints', 0);
        DB::table('merchants')->where('id', $this->merchant)->update(['status' => 'active']);
        $r = $this->postJson('/api/merchant/webhooks', $v)->assertCreated();
        $secret = $r->json('secret');
        $this->assertSame($secret, Crypt::decryptString(DB::table('merchant_webhook_endpoints')->value('encrypted_secret')));
        $this->assertStringNotContainsString($secret, json_encode(DB::table('merchant_webhook_endpoints')->first()));
        $this->assertStringNotContainsString($secret, $this->getJson('/api/merchant/webhooks?environment=live')->assertOk()->getContent());
        $this->assertStringNotContainsString($secret, json_encode(DB::table('eis_audit_events')->get()));
        $this->postJson('/api/merchant/webhooks', $v)->assertOk()->assertJsonPath('secret', null)->assertJsonPath('duplicate', true);
        $this->assertDatabaseCount('merchant_webhook_endpoints', 1);
        $this->assertDatabaseCount('merchant_webhook_deliveries', 0);
        $this->withToken('')->getJson('/api/merchant/webhooks?environment=live')->assertUnauthorized();
    }
    public function test_destination_ownership_events_limits_and_immutable_binding(): void
    {
        $a = $this->create();
        $p = $a['endpoint'];
        $this->patchJson('/api/merchant/webhooks/'.$p['id'], ['revision' => 1,'name' => 'ERP','events' => ['transaction.accepted'],'status' => 'active'])->assertConflict();
        $this->patchJson('/api/merchant/webhooks/'.$p['id'], ['revision' => 1,'name' => 'ERP','events' => ['transaction.accepted'],'status' => 'paused','environment' => 'sandbox'])->assertUnprocessable();
        $this->patchJson('/api/merchant/webhooks/'.$p['id'], ['revision' => 2,'name' => 'ERP','events' => ['transaction.accepted'],'status' => 'paused'])->assertConflict();
        $this->postJson('/api/merchant/webhooks', ['request_id' => (string)Str::uuid(),'name' => 'ERP','url' => $p['url'],'environment' => 'live','events' => ['transaction.accepted'],'ownership_confirmed' => false])->assertUnprocessable();
        $this->postJson('/api/merchant/webhooks', ['request_id' => (string)Str::uuid(),'name' => 'ERP','url' => $p['url'],'environment' => 'live','events' => ['transaction.accepted'],'ownership_confirmed' => true])->assertConflict();
        $this->create('sandbox');
        for ($i = 1;$i < 5;$i++) {
            $this->create('live', 'https://receiver.example.com/nuers/'.$i);
        }
        $this->postJson('/api/merchant/webhooks', ['request_id' => (string)Str::uuid(),'name' => 'Sixth','url' => 'https://receiver.example.com/six','environment' => 'live','events' => ['transaction.accepted'],'ownership_confirmed' => true])->assertUnprocessable();
        $this->getJson('/api/merchant/webhooks?environment=sandbox')->assertOk()->assertJsonCount(1, 'endpoints');
    }
    public function test_challenge_signature_echo_then_explicit_enable_and_real_event(): void
    {
        $a = $this->create();
        $id = $a['endpoint']['id'];
        $secret = $a['secret'];
        $this->transport(function ($url, $headers, $body) use ($secret) {
            $h = [];
            foreach ($headers as $header) {
                [$k,$v] = explode(': ', $header, 2);
                $h[$k] = $v;
            }
            $event = json_decode($body, true);
            $this->assertTrue(SignedWebhook::verify($secret, $h['X-NUERS-Event'], $h['X-NUERS-Timestamp'], $body, substr($h['X-NUERS-Signature'], 3), now()->timestamp));
            $this->assertFalse(SignedWebhook::verify($secret, $h['X-NUERS-Event'], $h['X-NUERS-Timestamp'], $body.' ', substr($h['X-NUERS-Signature'], 3), now()->timestamp));
            $this->assertSame('live', $h['X-NUERS-Environment']);
            return ['status' => 200,'error' => null,'body' => json_encode(['nuers_challenge' => $event['data']['challenge'] ?? ''])];
        });
        $this->postJson('/api/merchant/webhooks/'.$id.'/test', ['revision' => 1,'kind' => 'verify'])->assertAccepted();
        $this->assertSame('delivered', app(MerchantWebhooks::class)->runOne());
        $this->getJson('/api/merchant/webhooks?environment=live')->assertOk()->assertJsonPath('endpoints.0.status', 'paused')->assertJsonPath('worker.state', 'responding');
        $this->patchJson('/api/merchant/webhooks/'.$id, ['revision' => 2,'name' => 'ERP','events' => ['transaction.accepted'],'status' => 'active'])->assertOk()->assertJsonPath('endpoint.status', 'active');
        $p = DB::table('merchant_webhook_endpoints')->where('id', $id)->first();
        $event = $this->queue($p);
        $this->assertSame('delivered', app(MerchantWebhooks::class)->runOne());
        $this->getJson('/api/merchant/webhooks/deliveries/'.$event.'?environment=live')->assertOk()->assertJsonPath('delivery.state', 'delivered')->assertJsonCount(1, 'attempts');
        $this->assertDatabaseCount('merchant_transactions', 0);
        $this->assertDatabaseCount('merchant_wallet_entries', 0);
    }
    public function test_challenge_mismatch_cannot_enable_and_newer_challenge_cancels_older(): void
    {
        $p = $this->create()['endpoint'];
        $this->transport(fn () => ['status' => 200,'error' => null,'body' => '{"nuers_challenge":"wrong"}']);
        $this->postJson('/api/merchant/webhooks/'.$p['id'].'/test', ['revision' => 1,'kind' => 'verify'])->assertAccepted();
        $this->assertSame('dead_letter', app(MerchantWebhooks::class)->runOne());
        $this->assertNull(DB::table('merchant_webhook_endpoints')->value('verified_at'));
        $this->travel(61)->seconds();
        $this->postJson('/api/merchant/webhooks/'.$p['id'].'/test', ['revision' => 1,'kind' => 'verify'])->assertAccepted();
        $this->travel(61)->seconds();
        $this->postJson('/api/merchant/webhooks/'.$p['id'].'/test', ['revision' => 1,'kind' => 'verify'])->assertAccepted();
        $this->assertSame('cancelled', app(MerchantWebhooks::class)->runOne());
        $this->assertSame('dead_letter', app(MerchantWebhooks::class)->runOne());
    }
    public function test_callbacks_share_financial_commit_dedup_environment_and_minimize_data(): void
    {
        $live = $this->active();
        $sandbox = $this->active('sandbox');
        $s = app(MerchantWebhooks::class);
        DB::beginTransaction();
        $s->accepted($this->merchant, 'live', 'rollback', ['id' => 'rollback'], 'source.received');
        DB::rollBack();
        $this->assertDatabaseCount('merchant_webhook_deliveries', 0);
        $response = ['id' => 'accepted-1','status' => 'durably_recorded','customer_name' => 'PRIVATE BUYER','gross_amount' => '500.00','plain_key' => 'NEVER SEND'];
        $s->accepted($this->merchant, 'live', 'accepted-1', $response, 'source.received');
        $s->accepted($this->merchant, 'live', 'accepted-1', $response, 'source.received');
        $this->assertDatabaseCount('merchant_webhook_deliveries', 2);
        $this->assertSame(0, DB::table('merchant_webhook_deliveries')->where('endpoint_id', $sandbox->id)->count());
        $raw = Crypt::decryptString(DB::table('merchant_webhook_deliveries')->first()->encrypted_body);
        $this->assertStringNotContainsString('PRIVATE BUYER', $raw);
        $this->assertStringNotContainsString('gross_amount', $raw);
        $this->assertStringNotContainsString('NEVER SEND', $raw);
        $this->assertSame($live->id, DB::table('merchant_webhook_deliveries')->value('endpoint_id'));
    }
    public function test_retry_preserves_body_id_and_attempt_history_and_environment_guard(): void
    {
        $p = $this->active();
        $id = $this->queue($p);
        $bodies = [];
        $this->transport(function ($url, $headers, $body) use (&$bodies) {
            $bodies[] = $body;
            return ['status' => 503,'error' => null,'body' => 'DO NOT STORE RESPONSE'];
        });
        $this->assertSame('retry', app(MerchantWebhooks::class)->runOne());
        $this->assertNull(app(MerchantWebhooks::class)->runOne());
        $this->travel(80)->seconds();
        $this->assertSame('retry', app(MerchantWebhooks::class)->runOne());
        $this->assertSame($bodies[0], $bodies[1]);
        DB::table('merchant_webhook_deliveries')->where('id', $id)->update(['cycle_attempts' => 12,'available_at' => now()]);
        $this->assertSame('dead_letter', app(MerchantWebhooks::class)->runOne());
        $this->postJson('/api/merchant/webhooks/deliveries/'.$id.'/retry', ['environment' => 'sandbox'])->assertNotFound();
        $this->getJson('/api/merchant/webhooks/deliveries/'.$id.'?environment=sandbox')->assertNotFound();
        for ($i = 1;$i <= 3;$i++) {
            $this->postJson('/api/merchant/webhooks/deliveries/'.$id.'/retry', ['environment' => 'live'])->assertAccepted();
            DB::table('merchant_webhook_deliveries')->where('id', $id)->update(['state' => 'dead_letter']);
        }
        $this->postJson('/api/merchant/webhooks/deliveries/'.$id.'/retry', ['environment' => 'live'])->assertStatus(429);
        $this->getJson('/api/merchant/webhooks/deliveries/'.$id.'?environment=live')->assertOk()->assertJsonPath('body.id', $id)->assertJsonCount(2, 'attempts');
        $this->assertStringNotContainsString('DO NOT STORE RESPONSE', json_encode(DB::table('merchant_webhook_attempts')->get()));
    }
    public function test_rotation_revocation_and_suspension_cancel_pending_delivery(): void
    {
        $p = $this->active();
        $id = $this->queue($p);
        $r = $this->postJson('/api/merchant/webhooks/'.$p->id.'/rotate', ['revision' => 1])->assertOk()->assertJsonPath('endpoint.status', 'pending')->assertJsonPath('endpoint.secret_version', 2);
        $this->assertNotEmpty($r->json('secret'));
        $this->assertSame('cancelled', app(MerchantWebhooks::class)->runOne());
        $this->patchJson('/api/merchant/webhooks/'.$p->id, ['revision' => 2,'name' => 'ERP','events' => ['transaction.accepted'],'status' => 'active'])->assertConflict();
        $this->deleteJson('/api/merchant/webhooks/'.$p->id, ['revision' => 2])->assertOk();
        $this->postJson('/api/merchant/webhooks/'.$p->id.'/rotate', ['revision' => 3])->assertConflict();
        $this->patchJson('/api/merchant/webhooks/'.$p->id, ['revision' => 3,'name' => 'ERP','events' => ['transaction.accepted'],'status' => 'active'])->assertConflict();
        $q = $this->active('sandbox');
        $this->queue($q);
        DB::table('merchants')->where('id', $this->merchant)->update(['status' => 'suspended']);
        $this->assertSame('cancelled', app(MerchantWebhooks::class)->runOne());
        $this->assertDatabaseCount('merchant_webhook_endpoints', 2);
        $this->assertDatabaseCount('merchant_webhook_attempts', 0);
    }
    public function test_permanent_errors_redirects_and_dns_rebinding_fail_closed(): void
    {
        $p = $this->active();
        $id = $this->queue($p);
        $this->transport(fn () => ['status' => 302,'body' => '','error' => null]);
        $this->assertSame('dead_letter', app(MerchantWebhooks::class)->runOne());
        $this->assertDatabaseHas('merchant_webhook_deliveries', ['id' => $id,'last_error' => 'redirect_blocked']);
        DB::table('merchant_webhook_deliveries')->where('id', $id)->update(['state' => 'pending','available_at' => now()]);
        $this->transport(function () {
            throw new HttpException(422, 'Private address including secret URL');
        });
        $this->assertSame('dead_letter', app(MerchantWebhooks::class)->runOne());
        $this->assertDatabaseHas('merchant_webhook_deliveries', ['id' => $id,'last_error' => 'destination_blocked']);
        $this->assertStringNotContainsString('secret URL', json_encode(DB::table('merchant_webhook_attempts')->get()));
    }
    public function test_worker_pause_and_lease_recovery_reuse_event_id(): void
    {
        $p = $this->active();
        $id = $this->queue($p);
        config(['security.merchant_webhook_delivery_enabled' => false]);
        $this->assertSame('disabled', app(MerchantWebhooks::class)->runOne());
        $this->assertDatabaseHas('merchant_webhook_deliveries', ['id' => $id,'state' => 'pending','attempts' => 0]);
        config(['security.merchant_webhook_delivery_enabled' => true]);
        DB::table('merchant_webhook_deliveries')->where('id', $id)->update(['state' => 'sending','lease_token' => (string)Str::uuid(),'lease_until' => now()->addMinute()]);
        $this->assertNull(app(MerchantWebhooks::class)->runOne());
        $this->travel(61)->seconds();
        $this->transport(fn () => ['status' => 204,'body' => '','error' => null]);
        $this->assertSame('delivered', app(MerchantWebhooks::class)->runOne());
        $this->assertDatabaseHas('merchant_webhook_deliveries', ['id' => $id,'attempts' => 1]);
    }
    public function test_tenant_isolation_in_configuration_logs_and_retry(): void
    {
        $p = $this->active();
        $id = $this->queue($p);
        $foreign = User::factory()->create(['api_token' => Str::random(64),'mfa_secret' => Crypt::encryptString('SYNTHETICKEY'),'api_mfa_verified_at' => now()]);
        DB::table('profiles')->insert(['id' => $foreign->id,'role' => 'merchant','email' => $foreign->email]);
        DB::table('merchants')->insert(['id' => (string)Str::uuid(),'merchant_account_id' => $foreign->id,'business_name' => 'Other','status' => 'active']);
        $this->withToken($foreign->api_token);
        $this->getJson('/api/merchant/webhooks?environment=live')->assertOk()->assertJsonCount(0, 'endpoints');
        $this->getJson('/api/merchant/webhooks/deliveries?environment=live')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/merchant/webhooks/deliveries/'.$id.'?environment=live')->assertNotFound();
        $this->postJson('/api/merchant/webhooks/deliveries/'.$id.'/retry', ['environment' => 'live'])->assertNotFound();
        $this->deleteJson('/api/merchant/webhooks/'.$p->id, ['revision' => 1])->assertNotFound();
    }
    public function test_delegated_integration_view_cannot_mutate_or_reveal_secrets_and_owner_keeps_own_context(): void
    {
        $p = $this->active();
        $this->queue($p);
        $member = User::factory()->create(['api_token' => Str::random(64),'mfa_secret' => Crypt::encryptString('SYNTHETICKEY'),'api_mfa_verified_at' => now()]);
        DB::table('profiles')->insert(['id' => $member->id,'role' => 'merchant','email' => $member->email]);
        $role = (string)Str::uuid();
        DB::table('merchant_team_roles')->insert(['id' => $role,'merchant_id' => $this->merchant,'name' => 'Integration Reader','description' => 'Synthetic','color' => 'blue','permissions' => '["integrations.view"]','created_at' => now(),'updated_at' => now()]);
        DB::table('merchant_team_members')->insert(['id' => (string)Str::uuid(),'merchant_id' => $this->merchant,'user_id' => $member->id,'role_id' => $role,'profile_encrypted' => Crypt::encryptString('{}'),'email_hash' => hash('sha256', $member->email),'scope' => 'all','branch_ids' => '[]','terminal_scope' => 'all','terminal_ids' => '[]','status' => 'active','invited_by' => $this->owner->id,'created_at' => now(),'updated_at' => now()]);
        $this->withToken($member->api_token)->getJson('/api/merchant/webhooks?environment=live')->assertOk()->assertJsonPath('can_manage', false)->assertJsonPath('endpoints.0.url', 'https://receiver.example.com/…');
        $this->deleteJson('/api/merchant/webhooks/'.$p->id, ['revision' => 1])->assertForbidden();
        DB::table('merchant_team_roles')->where('id', $role)->update(['permissions' => '[]']);
        $this->getJson('/api/merchant/webhooks?environment=live')->assertForbidden();
        DB::table('merchants')->insert(['id' => 'zzzz-owned-business','merchant_account_id' => $member->id,'business_name' => 'Own Account','status' => 'active']);
        $this->getJson('/api/merchant/webhooks?environment=live')->assertOk()->assertJsonCount(0, 'endpoints');
        $this->deleteJson('/api/merchant/webhooks/'.$p->id, ['revision' => 1])->assertNotFound();
    }
    public function test_public_destination_validation_rejects_local_special_and_mixed_dns(): void
    {
        $d = new WebhookDestination();
        foreach (['http://receiver.example.com/h','https://127.0.0.1/h','https://user:pass@receiver.example.com/h','https://receiver.example.com:8443/h','https://receiver.example.com/h?token=secret','https://receiver.example.com/h#x','https://nuers.net/h','https://api.nuers.net/h','https://host.internal/h','https://receiver.example.com/%0d%0aHeader'] as $url) {
            try {
                $d->normalize($url);
                $this->fail('Unsafe URL accepted: '.$url);
            } catch (HttpException $e) {
                $this->assertSame(422, $e->getStatusCode());
            }
        }
        foreach (['127.0.0.1','10.0.0.1','169.254.169.254','100.64.0.1','192.0.2.1','198.18.0.1','::1','::ffff:8.8.8.8','64:ff9b::a00:1','2001:db8::1','2002:0808:0808::1','3fff::1'] as $ip) {
            $bad = new class ($ip) extends WebhookDestination {
                public function __construct(private string $ip)
                {
                }public function dns(string $host): array
                {
                    return ['8.8.8.8',$this->ip];
                }
            };
            try {
                $bad->resolve('https://receiver.example.com/h');
                $this->fail('Unsafe address accepted: '.$ip);
            } catch (HttpException $e) {
                $this->assertSame(422,$e->getStatusCode());
            }
        }
        $good = new class () extends WebhookDestination {
            public function dns(string $host): array
            {
                return ['2606:4700:4700::1111'];
            }
        };
        $this->assertSame(['receiver.example.com','2606:4700:4700::1111'],$good->resolve('https://receiver.example.com/h'));
        $this->assertFalse(SignedWebhook::verify('s','event',(string)now()->subMinutes(6)->timestamp,'{}','bad',now()->timestamp));
    }
}
