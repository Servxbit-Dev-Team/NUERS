<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\{MerchantWallet,TransactionPricing,BirCompliance};
use Illuminate\Support\Facades\{Crypt,DB};
use Illuminate\Support\Str;
use Tests\TestCase;

class ApiEnvironmentIsolationTest extends TestCase
{
    private User $owner;
    private User $admin;
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite','database.connections.sqlite.database' => ':memory:','cache.default' => 'array','notifications.dispatch_enabled' => false,'security.enforce_privileged_mfa' => true]);
        DB::purge('sqlite');
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->owner = $this->user('merchant');
        $this->admin = $this->user('super_admin');
        DB::table('merchants')->insert(['id' => 'environment-test','business_name' => 'Synthetic Environment QA','address' => 'Synthetic registered address','tin' => '123456789000','merchant_account_id' => $this->owner->id,'status' => 'active','vat_registered' => true]);
    }
    private function user(string $role): User
    {
        $u = User::factory()->create(['api_token' => Str::random(64),'mfa_secret' => Crypt::encryptString('SYNTHETICKEY'),'api_mfa_verified_at' => now()]);
        DB::table('profiles')->insert(['id' => $u->id,'email' => $u->email,'role' => $role]);
        return $u;
    }
    private function key(string $env = 'sandbox', string $merchant = 'environment-test', string $branch = '00000'): string
    {
        $raw = Str::random(64);
        DB::table('merchant_api_keys')->insert(['id' => (string)Str::uuid(),'merchant_id' => $merchant,'name' => 'Synthetic '.$env,'environment' => $env,'branch_code' => $branch,'branch_type' => 'main','key_hash' => hash('sha256', $raw),'key_prefix' => 'synthetic','key_last_four' => 'test','status' => 'active','scopes' => '["transactions:write","transactions:read"]','ip_whitelist' => '[]','rate_limit_per_day' => 1000,'expires_at' => now()->addDay(),'created_at' => now(),'updated_at' => now()]);
        return $raw;
    }
    private function payload(string $ref = '1', string $gross = '560.00'): array
    {
        return ['schema_version' => 'NUERS-ingestion-1','source_system' => 'synthetic-pos','source_event_id' => 'TEST-EVENT-'.$ref,'external_reference' => 'TEST-REF-'.$ref,'event_type' => 'invoice.finalized','occurred_at' => now()->subMinute()->toIso8601String(),'branch_code' => '00000','receipt_number' => 'TEST-DOC-'.$ref,'document_type' => 'vat_invoice','payment_method' => 'cash','gross_amount' => $gross,'tax_type' => 'vatable','vat_inclusive' => true,'items' => [['description' => 'Synthetic test supply','quantity' => 1,'unit_price' => $gross,'tax_type' => 'vatable']]];
    }
    private function capture(array $p): array
    {
        $id = DB::table('integration_source_profiles')->value('id');
        if (!$id) {
            $id = (string)Str::uuid();
            DB::table('integration_source_profiles')->insert(['id' => $id,'merchant_id' => 'environment-test','branch_code' => '00000','source_system' => 'synthetic-pos','vat_registered' => true,'seller_name' => 'Synthetic ERP issuer','seller_tin' => '123456789000','seller_address' => 'Synthetic address','authority_reference' => 'Synthetic original authority','evidence_reference' => 'Synthetic reviewed evidence','valid_from' => now()->subYear(),'valid_until' => now()->addYear(),'status' => 'active','reviewed_by' => (string)$this->admin->id,'reviewed_at' => now(),'request_id' => (string)Str::uuid(),'request_hash' => str_repeat('a', 64),'created_at' => now(),'updated_at' => now()]);
        }return $p + ['record_mode' => 'source_capture','source_profile_id' => $id];
    }
    private function fund(int $amount): void
    {
        DB::transaction(fn () => app(MerchantWallet::class)->credit('environment-test', $amount, (string)Str::uuid(), 'Synthetic settled funds', (string)$this->admin->id));
    }
    public function test_sandbox_persists_encrypted_duplicate_safe_test_only_records_without_financial_writes(): void
    {
        $key = $this->key();
        $p = $this->payload();
        $r = $this->withHeader('X-NUERS-API-Key', $key)->postJson('/api/integrations/transactions', $p)->assertCreated()->assertJsonPath('environment', 'sandbox')->assertJsonPath('test_only', true)->assertJsonPath('wallet.charged', false)->assertJsonPath('wallet.simulated_fee_centavos', 300);
        $this->postJson('/api/integrations/transactions', $p)->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('id', $r->json('id'));
        $this->getJson('/api/integrations/transactions/'.$r->json('id'))->assertOk()->assertJsonPath('environment', 'sandbox');
        $this->assertDatabaseCount('integration_sandbox_events', 1);
        foreach (['transaction_receipts','merchant_transactions','merchant_wallets','merchant_wallet_entries','integration_inbox','invoice_series','eis_document_outbox'] as $t) {
            $this->assertDatabaseCount($t, 0);
        }$stored = DB::table('integration_sandbox_events')->value('details_encrypted');
        $this->assertStringNotContainsString('Synthetic test supply', $stored);
        $this->assertStringContainsString('Synthetic test supply', Crypt::decryptString($stored));
        $p['customer_address'] = 'Changed after acceptance';
        $this->postJson('/api/integrations/transactions', $p)->assertConflict();
        $this->assertDatabaseCount('integration_sandbox_events', 1);
    }
    public function test_same_original_can_exist_once_in_each_environment_and_status_is_not_cross_visible(): void
    {
        $p = $this->capture($this->payload('shared', '112.00'));
        $sandbox = $this->key();
        $live = $this->key('live');
        $s = $this->withHeader('X-NUERS-API-Key', $sandbox)->postJson('/api/integrations/transactions', $p)->assertCreated();
        $this->fund(300);
        $l = $this->withHeader('X-NUERS-API-Key', $live)->postJson('/api/integrations/transactions', $p)->assertCreated()->assertJsonPath('environment', 'live');
        $this->getJson('/api/integrations/transactions/'.$s->json('id'))->assertNotFound();
        $this->withHeader('X-NUERS-API-Key', $sandbox)->getJson('/api/integrations/transactions/'.$l->json('id'))->assertNotFound();
        $this->getJson('/api/integrations/transactions/wallet')->assertOk()->assertJsonPath('environment', 'sandbox')->assertJsonPath('available_centavos', null);
        $this->assertDatabaseCount('integration_sandbox_events', 1);
        $this->assertDatabaseCount('transaction_receipts', 1);
        $this->assertDatabaseCount('integration_inbox', 1);
    }
    public function test_live_threshold_boundary_and_insufficient_wallet_leave_no_records_and_retries_do_not_recharge(): void
    {
        $key = $this->key('live');
        $p = $this->capture($this->payload('charged', '500.00'));
        $this->withHeader('X-NUERS-API-Key', $key)->postJson('/api/integrations/transactions', $p)->assertStatus(402)->assertJsonPath('transaction_saved', false);
        foreach (['transaction_receipts','merchant_transactions','integration_inbox','merchant_wallet_entries','eis_document_outbox'] as $t) {
            $this->assertDatabaseCount($t, 0);
        }$this->fund(300);
        $r = $this->postJson('/api/integrations/transactions', $p)->assertCreated()->assertJsonPath('wallet.fee_centavos', 300);
        $this->assertEquals(0, DB::table('merchant_wallets')->value('available_centavos'));
        $this->postJson('/api/integrations/transactions', $p)->assertOk()->assertJsonPath('id', $r->json('id'))->assertJsonPath('duplicate', true);
        $this->assertDatabaseCount('merchant_wallet_entries', 2);
        $this->postJson('/api/integrations/transactions', $this->capture($this->payload('small', '499.99')))->assertStatus(402)->assertJsonPath('transaction_saved', false);
        $this->assertDatabaseCount('merchant_wallet_entries', 2);
    }
    public function test_admin_threshold_and_fee_are_versioned_and_enforced_prospectively(): void
    {
        $before = app(TransactionPricing::class)->offer();
        $v = ['minimum_transaction_centavos' => 75000,'fee_centavos' => 450,'revision' => $before->revision,'reason' => 'Synthetic prospective threshold test'];
        $this->withToken($this->owner->api_token)->putJson('/api/admin/wallets/pricing', $v)->assertForbidden();
        $this->withToken($this->admin->api_token)->putJson('/api/admin/wallets/pricing', $v)->assertOk()->assertJsonPath('pricing.minimum_transaction_centavos', 75000);
        $this->putJson('/api/admin/wallets/pricing', $v)->assertConflict();
        $this->assertDatabaseHas('wallet_pricing_history', ['previous_minimum_transaction_centavos' => 0,'minimum_transaction_centavos' => 75000,'previous_fee_centavos' => 300,'fee_centavos' => 450]);
        $this->flushHeaders();
        $this->withHeader('X-NUERS-API-Key', $this->key('live'))->postJson('/api/integrations/transactions', $this->capture($this->payload('below', '749.99')))->assertCreated()->assertJsonPath('wallet.fee_centavos', 0);
        $p = $this->capture($this->payload('at', '750.00'));
        $this->postJson('/api/integrations/transactions', $p)->assertStatus(402)->assertJsonPath('required_centavos', 450);
        $this->fund(450);
        $this->postJson('/api/integrations/transactions', $p)->assertCreated()->assertJsonPath('wallet.fee_centavos', 450);
        $this->assertDatabaseCount('wallet_pricing_history', 2);
    }
    public function test_admin_can_restore_zero_minimum_without_recharging_previously_free_sales(): void
    {
        $pricing = app(TransactionPricing::class)->offer();
        $this->withToken($this->admin->api_token)->putJson('/api/admin/wallets/pricing', [
            'minimum_transaction_centavos' => 50000, 'fee_centavos' => 300, 'revision' => $pricing->revision,
            'reason' => 'Synthetic configurable minimum verification',
        ])->assertOk();
        $this->flushHeaders();
        $key = $this->key('live');
        $free = $this->capture($this->payload('formerly-free', '0.01'));
        $this->withHeader('X-NUERS-API-Key', $key)->postJson('/api/integrations/transactions', $free)->assertCreated()->assertJsonPath('wallet.fee_centavos', 0);
        $this->flushHeaders();
        $this->withToken($this->admin->api_token)->putJson('/api/admin/wallets/pricing', [
            'minimum_transaction_centavos' => 0, 'fee_centavos' => 300, 'revision' => app(TransactionPricing::class)->offer()->revision,
            'reason' => 'Synthetic zero minimum verification',
        ])->assertOk()->assertJsonPath('pricing.minimum_transaction_centavos', 0);
        $this->flushHeaders();
        $this->withHeader('X-NUERS-API-Key', $key)->postJson('/api/integrations/transactions', $free)->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('wallet.fee_centavos', 0);
        $small = $this->capture($this->payload('now-charged', '0.01'));
        $this->postJson('/api/integrations/transactions', $small)->assertStatus(402)->assertJsonPath('transaction_saved', false);
        $this->fund(300);
        $this->postJson('/api/integrations/transactions', $small)->assertCreated()->assertJsonPath('wallet.fee_centavos', 300);
        $this->postJson('/api/integrations/transactions', $small)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertDatabaseCount('merchant_wallet_entries', 2);
        $this->assertEquals(0, DB::table('merchant_wallets')->value('available_centavos'));
    }

    public function test_sandbox_batch_conflicts_roll_back_all_new_test_records_and_duplicate_numbers_are_guarded(): void
    {
        $this->withHeader('X-NUERS-API-Key', $this->key());
        $p = $this->payload('existing');
        $this->postJson('/api/integrations/transactions', $p)->assertCreated();
        $conflict = $p;
        $conflict['customer_address'] = 'Conflicting test copy';
        $this->postJson('/api/integrations/transactions/batch', ['events' => [$this->payload('new'),$conflict]])->assertConflict();
        $this->assertDatabaseCount('integration_sandbox_events', 1);
        $another = $this->payload('different');
        $another['receipt_number'] = $p['receipt_number'];
        $this->postJson('/api/integrations/transactions', $another)->assertConflict();
        $this->postJson('/api/integrations/transactions/batch', ['events' => [$this->payload('a'),$this->payload('b')]])->assertCreated()->assertJsonPath('environment', 'sandbox')->assertJsonPath('atomic', true);
        $this->assertDatabaseCount('integration_sandbox_events', 3);
        $this->assertDatabaseCount('merchant_wallet_entries', 0);
    }
    public function test_live_batch_rolls_back_if_funds_do_not_cover_every_eligible_sale(): void
    {
        $this->fund(300);
        $this->withHeader('X-NUERS-API-Key', $this->key('live'))->postJson('/api/integrations/transactions/batch', ['events' => [$this->capture($this->payload('a')),$this->capture($this->payload('b'))]])->assertStatus(402);
        $this->assertDatabaseCount('transaction_receipts', 0);
        $this->assertDatabaseCount('integration_inbox', 0);
        $this->assertDatabaseCount('merchant_wallet_entries', 1);
        $this->assertEquals(300, DB::table('merchant_wallets')->value('available_centavos'));
    }
    public function test_sandbox_requires_active_account_correct_key_policy_branch_and_tax_registration(): void
    {
        $key = $this->key();
        $this->withHeader('X-NUERS-API-Key', $key);
        $p = $this->payload();
        $p['branch_code'] = '00001';
        $this->postJson('/api/integrations/transactions', $p)->assertForbidden();
        $p = $this->payload();
        $p['event_type'] = 'payment.recorded';
        $this->postJson('/api/integrations/transactions', $p)->assertUnprocessable();
        DB::table('merchant_api_keys')->update(['ip_whitelist' => '["203.0.113.99"]']);
        $this->postJson('/api/integrations/transactions', $this->payload())->assertForbidden();
        DB::table('merchant_api_keys')->update(['ip_whitelist' => '[]','scopes' => '["transactions:read"]']);
        $this->postJson('/api/integrations/transactions', $this->payload())->assertForbidden();
        DB::table('merchant_api_keys')->update(['scopes' => '["transactions:write"]','status' => 'revoked']);
        $this->postJson('/api/integrations/transactions', $this->payload())->assertForbidden();
        DB::table('merchant_api_keys')->update(['status' => 'active','expires_at' => now()->subMinute()]);
        $this->postJson('/api/integrations/transactions', $this->payload())->assertForbidden();
        DB::table('merchant_api_keys')->update(['expires_at' => now()->addDay()]);
        DB::table('merchants')->update(['status' => 'pending']);
        $this->postJson('/api/integrations/transactions', $this->payload())->assertForbidden();
        DB::table('merchants')->update(['status' => 'active','vat_registered' => false]);
        $this->postJson('/api/integrations/transactions', $this->payload())->assertUnprocessable();
        $this->assertDatabaseCount('integration_sandbox_events', 0);
    }
    public function test_sandbox_record_portal_is_tenant_scoped_and_admin_can_identify_test_environment(): void
    {
        $r = $this->withHeader('X-NUERS-API-Key', $this->key())->postJson('/api/integrations/transactions', $this->payload())->assertCreated();
        $id = $r->json('id');
        $this->flushHeaders();
        $this->withToken($this->owner->api_token)->getJson('/api/merchant/sandbox-records')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.environment', 'sandbox');
        $this->getJson('/api/merchant/sandbox-records/'.$id)->assertOk()->assertJsonPath('response.test_only', true);
        $this->getJson('/api/admin/sandbox-records')->assertForbidden();
        $foreign = $this->user('merchant');
        DB::table('merchants')->insert(['id' => 'foreign','business_name' => 'Synthetic foreign','merchant_account_id' => $foreign->id,'status' => 'active']);
        $this->withToken($foreign->api_token)->getJson('/api/merchant/sandbox-records/'.$id)->assertNotFound();
        $this->getJson('/api/merchant/sandbox-records')->assertOk()->assertJsonPath('total', 0);
        $this->withToken($this->admin->api_token)->getJson('/api/admin/sandbox-records')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.business_name', 'Synthetic Environment QA');
        $this->getJson('/api/admin/sandbox-records?from=2026-10-20&to=2026-10-01')->assertUnprocessable();
    }
    public function test_named_keys_have_immutable_environment_and_environment_filtered_usage(): void
    {
        $this->withToken($this->owner->api_token);
        $s = $this->postJson('/api/merchant/api-keys', ['name' => 'Synthetic Sandbox','environment' => 'sandbox'])->assertCreated()->assertJsonPath('key.env', 'sandbox');
        $this->postJson('/api/merchant/api-keys', ['name' => 'Synthetic Live','environment' => 'live'])->assertCreated()->assertJsonPath('key.env', 'live');
        $this->patchJson('/api/merchant/api-keys/'.$s->json('key.id'), ['environment' => 'live'])->assertConflict();
        $this->getJson('/api/merchant/api-keys?environment=sandbox')->assertOk()->assertJsonPath('summary.total_keys', 1)->assertJsonCount(1, 'keys');
        $this->flushHeaders();
        $this->withHeader('X-NUERS-API-Key', $s->json('key.plain_key'))->postJson('/api/integrations/transactions', $this->payload())->assertCreated();
        $this->flushHeaders();
        $this->withToken($this->owner->api_token)->getJson('/api/merchant/api-keys/logs?environment=live')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/merchant/api-keys/logs?environment=sandbox')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.environment', 'sandbox');
        $this->getJson('/api/merchant/api-keys?environment=sandbox')->assertOk()->assertJsonPath('summary.requests_month', 1);
    }
    public function test_sandbox_audit_failure_rolls_back_acceptance(): void
    {
        $key = $this->key();
        $this->partialMock(BirCompliance::class, fn ($m) => $m->shouldReceive('audit')->andThrow(new \RuntimeException('Synthetic audit unavailable')));
        $this->withHeader('X-NUERS-API-Key', $key)->postJson('/api/integrations/transactions', $this->payload())->assertStatus(500);
        $this->assertDatabaseCount('integration_sandbox_events',0);
    }
    public function test_additive_migration_resumes_without_losing_saved_sandbox_events(): void
    {
        $this->withHeader('X-NUERS-API-Key',$this->key())->postJson('/api/integrations/transactions',$this->payload())->assertCreated();
        \Illuminate\Support\Facades\Schema::table('integration_sandbox_events',fn ($t) => $t->dropIndex('sandbox_merchant_location_date'));
        $migration = require database_path('migrations/2026_10_18_000009_add_api_sandbox_and_pricing_threshold.php');
        $migration->up();
        $migration->up();
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasIndex('integration_sandbox_events','sandbox_merchant_location_date'));
        $this->assertDatabaseCount('integration_sandbox_events',1);
    }

}
