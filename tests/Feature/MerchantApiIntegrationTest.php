<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MerchantApiIntegrationTest extends TestCase
{
    private User $owner;

    private string $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array', 'notifications.dispatch_enabled' => false, 'security.enforce_privileged_mfa' => true]);
        DB::purge('sqlite');
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->owner = User::factory()->create(['api_token' => Str::random(64)]);
        DB::table('profiles')->insert(['id' => $this->owner->id, 'email' => $this->owner->email, 'role' => 'merchant']);
        $this->merchant = (string) Str::uuid();
        DB::table('merchants')->insert(['id' => $this->merchant, 'business_name' => 'Synthetic API Seller', 'tin' => '123456789000', 'address' => 'Synthetic registered address', 'vat_registered' => false, 'merchant_account_id' => $this->owner->id, 'status' => 'active']);
        DB::table('merchant_branches')->insert(['id' => (string)Str::uuid(),'merchant_id' => $this->merchant,'code' => 'SYNTHETIC','bir_branch_code' => '00000','name' => 'Synthetic branch','address' => 'Synthetic address','city' => 'Synthetic city','region' => 'Synthetic region','rdo_code' => '001','branch_type' => 'main','status' => 'active','contact_encrypted' => Crypt::encryptString('{}'),'request_id' => (string)Str::uuid(),'request_hash' => str_repeat('a', 64)]);
        $this->withToken($this->owner->api_token);
    }

    private function freshMfa(): void
    {
        DB::table('users')->where('id', $this->owner->id)->update(['mfa_secret' => Crypt::encryptString('SYNTHETICKEY'), 'api_mfa_verified_at' => now()]);
    }

    private function keyPayload(): array
    {
        return ['name' => 'Synthetic ERP', 'environment' => 'live', 'branch_code' => '00000', 'branch_name' => 'Synthetic Branch', 'scopes' => ['transactions:write', 'transactions:read'], 'ip_whitelist' => ['203.0.113.3'], 'rate_limit_per_day' => 1000];
    }

    public function test_name_only_key_assigns_the_registered_business_without_branch_or_terminal_setup(): void
    {
        $this->freshMfa();
        DB::table('merchant_branches')->where('merchant_id', $this->merchant)->delete();
        DB::table('merchants')->where('id', $this->merchant)->update(['tin' => '123-456-789-000', 'address' => 'Registered business address', 'city' => 'Quezon City', 'region' => 'NCR', 'rdo_code' => '039']);
        $r = $this->postJson('/api/merchant/api-keys', ['name' => '  My ERP  '])->assertCreated()
            ->assertJsonPath('key.name', 'My ERP')->assertJsonPath('key.env', 'live')->assertJsonPath('key.branch_code', '00000');
        $this->assertSame(['transactions:write', 'transactions:read'], $r->json('key.permissions'));
        $this->assertSame(50000, $r->json('key.rate_limit'));
        $branch = DB::table('merchant_branches')->first();
        $this->assertSame('draft', $branch->review_status);
        $this->assertNull($branch->reviewed_at);
        $this->assertSame('Registered business address', $branch->address);
        $this->assertSame(0, DB::table('merchant_terminals')->count());
        $this->assertSame(0, DB::table('merchant_transactions')->count());
        $this->postJson('/api/merchant/api-keys', ['name' => 'Another ERP'])->assertCreated();
        $this->assertSame(1, DB::table('merchant_branches')->count());
        $this->assertSame(1, DB::table('merchant_branch_events')->where('action', 'registered_location_linked')->count());
    }

    public function test_name_only_key_uses_the_embedded_five_digit_branch_without_guessing_head_office(): void
    {
        $this->freshMfa();
        DB::table('merchants')->where('id', $this->merchant)->update(['tin' => '123-456-789-00123']);
        $this->postJson('/api/merchant/api-keys', ['name' => 'ERP'])->assertCreated()->assertJsonPath('key.branch_code', '00123');
        $this->assertSame('branch', DB::table('merchant_branches')->where('bir_branch_code', '00123')->value('branch_type'));
    }

    public function test_name_only_key_preserves_existing_review_and_rejects_inactive_location(): void
    {
        $this->freshMfa();
        DB::table('merchants')->where('id', $this->merchant)->update(['tin' => '123456789000']);
        DB::table('merchant_branches')->update(['review_status' => 'needs_changes', 'review_reference' => 'Existing review evidence']);
        $before = json_encode(DB::table('merchant_branches')->first());
        $this->postJson('/api/merchant/api-keys', ['name' => 'ERP'])->assertCreated();
        $this->assertSame($before, json_encode(DB::table('merchant_branches')->first()));
        DB::table('merchant_branches')->update(['status' => 'inactive']);
        $this->postJson('/api/merchant/api-keys', ['name' => 'ERP 2'])->assertUnprocessable();
        $this->assertSame('inactive', DB::table('merchant_branches')->value('status'));
        $this->assertSame(1, DB::table('merchant_api_keys')->count());
    }

    public function test_name_only_key_requires_registration_identity_and_never_uses_a_foreign_main(): void
    {
        $this->freshMfa();
        DB::table('merchant_branches')->where('merchant_id', $this->merchant)->delete();
        DB::table('merchants')->where('id', $this->merchant)->update(['tin' => null]);
        $this->postJson('/api/merchant/api-keys', ['name' => 'ERP'])->assertUnprocessable();
        DB::table('merchants')->where('id', $this->merchant)->update(['tin' => '123456789']);
        $this->postJson('/api/merchant/api-keys', ['name' => 'ERP'])->assertUnprocessable();
        $this->postJson('/api/merchant/api-keys', ['name' => 'ERP', 'branch_code' => '99999'])->assertUnprocessable();
        $this->assertSame(0, DB::table('merchant_api_keys')->count());
        $this->assertSame(0, DB::table('merchant_branches')->count());
    }

    public function test_name_only_key_can_use_one_existing_main_for_base_only_tin(): void
    {
        $this->freshMfa();
        DB::table('merchants')->where('id', $this->merchant)->update(['tin' => '123456789']);
        $this->postJson('/api/merchant/api-keys', ['name' => 'ERP'])->assertCreated()->assertJsonPath('key.branch_code', '00000');
    }

    public function test_name_only_key_keeps_activation_mfa_and_name_validation_guards(): void
    {
        DB::table('merchants')->where('id', $this->merchant)->update(['tin' => '123456789000']);
        $this->postJson('/api/merchant/api-keys', ['name' => 'ERP'])->assertForbidden()->assertJsonPath('code', 'MFA_REQUIRED');
        $this->freshMfa();
        $this->postJson('/api/merchant/api-keys', ['name' => '   '])->assertUnprocessable();
        DB::table('merchants')->where('id', $this->merchant)->update(['status' => 'pending']);
        $this->postJson('/api/merchant/api-keys', ['name' => 'ERP'])->assertForbidden();
        $this->assertSame(0, DB::table('merchant_api_keys')->count());
    }

    public function test_key_generation_requires_owner_login_mfa_and_never_reveals_a_secret_again(): void
    {
        $this->postJson('/api/merchant/api-keys', $this->keyPayload())->assertForbidden()->assertJsonPath('code', 'MFA_REQUIRED');
        $this->freshMfa();
        DB::table('users')->where('id', $this->owner->id)->update(['api_authenticated_at' => now()->subHour(), 'api_mfa_verified_at' => now()->subMinutes(40)]);
        $r = $this->postJson('/api/merchant/api-keys', $this->keyPayload())->assertCreated();
        $secret = $r->json('key.plain_key');
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        $this->assertSame(hash('sha256', $secret), DB::table('merchant_api_keys')->value('key_hash'));
        $read = $this->getJson('/api/merchant/api-keys')->assertOk()->assertJsonPath('summary.active_keys', 1)->assertJsonPath('access.owner', true);
        $this->assertStringNotContainsString($secret, $read->getContent());
        $this->assertStringNotContainsString('key_hash', $read->getContent());
        $this->assertSame(0, DB::table('merchant_transactions')->count());
    }

    public function test_revoked_key_cannot_be_resurrected_through_inactive_status(): void
    {
        $this->freshMfa();
        $key = $this->postJson('/api/merchant/api-keys', $this->keyPayload())->assertCreated()->json('key.id');
        $this->deleteJson('/api/merchant/api-keys/'.$key)->assertOk();
        $this->patchJson('/api/merchant/api-keys/'.$key, ['active' => false])->assertConflict();
        $this->patchJson('/api/merchant/api-keys/'.$key, ['active' => true])->assertConflict();
        $this->assertSame('revoked', DB::table('merchant_api_keys')->value('status'));
    }

    public function test_branch_identity_is_immutable_ip_rules_are_validated_and_expired_keys_are_not_active(): void
    {
        $this->freshMfa();
        $key = $this->postJson('/api/merchant/api-keys', $this->keyPayload())->assertCreated()->json('key.id');
        $this->patchJson('/api/merchant/api-keys/'.$key, ['branch_code' => '00001'])->assertConflict();
        $this->patchJson('/api/merchant/api-keys/'.$key, ['ip_whitelist' => ['0.0.0.0/0']])->assertUnprocessable();
        $this->patchJson('/api/merchant/api-keys/'.$key, ['ip_whitelist' => ['2001:db8::1']])->assertOk();
        DB::table('merchant_api_keys')->where('id', $key)->update(['expires_at' => now()->subMinute()]);
        $this->getJson('/api/merchant/api-keys')->assertOk()->assertJsonPath('summary.active_keys', 0)->assertJsonPath('keys.0.status', 'expired')->assertJsonPath('keys.0.active', false);
    }

    public function test_metadata_and_logs_are_private_scoped_and_do_not_seed_wallet_or_financial_records(): void
    {
        $foreign = (string) Str::uuid();
        $key = null;
        DB::table('merchants')->insert(['id' => $foreign, 'business_name' => 'Synthetic Foreign']);
        foreach ([$this->merchant, $foreign] as $m) {
            DB::table('merchant_api_request_logs')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $m, 'merchant_api_key_id' => $key, 'method' => 'POST', 'endpoint' => '/api/integrations/transactions', 'request_reference' => $m === $foreign ? 'FOREIGN' : 'OWN', 'status_code' => 201, 'latency_ms' => 12, 'created_at' => now()]);
        }
        $r = $this->getJson('/api/merchant/api-keys')->assertOk()->assertJsonPath('summary.requests_today', 1)->assertJsonPath('summary.avg_latency_ms', 12)->assertJsonPath('summary.active_webhooks', 0)->assertJsonCount(8, 'integration.document_types');
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        $this->getJson('/api/merchant/api-keys/logs')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.request_reference', 'OWN')->assertJsonMissing(['request_reference' => 'FOREIGN']);
        $this->getJson('/api/merchant/api-keys/logs?from=2026-10-18&to=2026-10-01')->assertUnprocessable();
        $this->getJson('/api/merchant/api-keys/logs?key=not-a-uuid')->assertUnprocessable();
        $this->assertSame(0, DB::table('merchant_wallets')->count());
        $this->assertSame(0, DB::table('merchant_transactions')->count());
    }

    public function test_exact_ip_allowlist_uses_trusted_edge_and_ignores_direct_spoofed_forwarding(): void
    {
        $this->freshMfa();
        $raw = $this->postJson('/api/merchant/api-keys', $this->keyPayload())->assertCreated()->json('key.plain_key');
        $this->withToken('')->withServerVariables(['REMOTE_ADDR' => '172.71.0.1'])->withHeaders(['X-Forwarded-For' => '203.0.113.3', 'X-NUERS-API-Key' => $raw])->getJson('/api/integrations/transactions/wallet')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])->getJson('/api/integrations/transactions/wallet')->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '172.71.0.1'])->withHeader('X-Forwarded-For', '203.0.113.9')->getJson('/api/integrations/transactions/wallet')->assertForbidden();
    }

    public function test_synthetic_ingestion_preview_validates_each_document_family_and_reconciles_items(): void
    {
        $this->withToken('');
        foreach (['vat_invoice' => 'vatable', 'non_vat_invoice' => 'non_vat', 'vat_exempt_invoice' => 'vat_exempt', 'zero_rated_invoice' => 'zero_rated', 'payment_receipt' => 'non_vat', 'sales_invoice' => 'vatable', 'official_receipt' => 'non_vat', 'mixed_sales_invoice' => 'vatable'] as $doc => $tax) {
            $payment = in_array($doc, ['payment_receipt', 'official_receipt']);
            $payload = ['schema_version' => 'NUERS-ingestion-1', 'source_system' => 'sample-pos', 'source_event_id' => 'SAMPLE-event', 'external_reference' => 'SAMPLE-source', 'event_type' => $payment ? 'payment.recorded' : 'invoice.finalized', 'occurred_at' => now()->toIso8601String(), 'branch_code' => '00000', 'receipt_number' => 'SAMPLE-00000001', 'document_type' => $doc, 'payment_method' => 'cash', 'tax_type' => $tax, 'gross_amount' => $tax === 'vatable' ? '112.00' : '100.00', 'vat_inclusive' => true, 'items' => [['description' => 'SAMPLE item', 'quantity' => 1, 'unit_price' => $tax === 'vatable' ? '112.00' : '100.00', 'tax_type' => $tax]]];
            if ($doc === 'mixed_sales_invoice') {
                $payload['gross_amount'] = '212.00';
                $payload['items'][] = ['description' => 'SAMPLE exempt item', 'quantity' => 1, 'unit_price' => '100.00', 'tax_type' => 'vat_exempt'];
            }
            $request = ['is_test' => true, 'action' => 'ingestion', 'seller_vat_registered' => $tax !== 'non_vat', 'payload' => $payload];
            $this->postJson('/api/developer/sandbox', $request)->assertOk()->assertJsonPath('persisted', false)->assertJsonPath('bir_contacted', false)->assertJsonPath('status', 'valid');
            if ($doc === 'vat_invoice') {
                $request['payload']['gross_amount'] = '1.00';
                $this->postJson('/api/developer/sandbox', $request)->assertUnprocessable();
            }
        }
        $this->assertSame(0, DB::table('transaction_receipts')->count());
        $this->assertSame(0, DB::table('merchant_wallet_entries')->count());
    }

    private function ownedEvent(array $connection, string $reference = 'OWNED-1', string $gross = '100.00'): array
    {
        return $connection + ['source_event_id' => $reference,'external_reference' => $reference,'event_type' => 'invoice.finalized',
            'occurred_at' => now()->toIso8601String(),'receipt_number' => $reference,'document_type' => 'non_vat_invoice',
            'payment_method' => 'cash','gross_amount' => $gross,'tax_type' => 'non_vat','vat_inclusive' => true,
            'items' => [['description' => 'Synthetic owned system item','quantity' => 1,'unit_price' => $gross,'tax_type' => 'non_vat']]];
    }

    public function test_verified_account_gets_automatic_connection_with_key_and_rotations_keep_mapping(): void
    {
        $this->freshMfa();
        $a = $this->postJson('/api/merchant/api-keys', ['name' => 'Owned ERP'])->assertCreated();
        $connection = $a->json('connection');
        $this->assertSame('source_capture', $connection['record_mode']);
        $this->assertDatabaseHas('integration_source_profiles', ['id' => $connection['source_profile_id'],'profile_kind' => 'account_connection','merchant_id' => $this->merchant,'status' => 'active']);
        $this->assertDatabaseCount('merchant_terminals', 0);
        $b = $this->postJson('/api/merchant/api-keys', ['name' => 'ERP rotation'])->assertCreated();
        $this->assertSame($connection, $b->json('connection'));
        $this->assertNotSame($a->json('key.plain_key'), $b->json('key.plain_key'));
        $this->assertDatabaseCount('integration_source_profiles', 1);
        $this->getJson('/api/merchant/api-keys')->assertOk()->assertJsonPath('source_profiles.0.profile_kind', 'account_connection');
        $this->assertDatabaseCount('merchant_transactions', 0);
    }

    public function test_approved_account_prepares_connection_before_the_owner_generates_any_secret(): void
    {
        $admin = User::factory()->create(['api_token' => Str::random(64),'mfa_secret' => Crypt::encryptString('SYNTHETICKEY'),'api_mfa_verified_at' => now()]);
        DB::table('profiles')->insert(['id' => $admin->id,'email' => $admin->email,'role' => 'super_admin']);
        DB::table('merchants')->where('id', $this->merchant)->update(['status' => 'pending','registry_revision' => 1]);
        $this->freshMfa();
        $this->getJson('/api/merchant/api-keys')->assertOk()->assertJsonPath('merchant.status', 'pending')->assertJsonPath('access.owner', true);
        $sessionBefore = DB::table('users')->where('id', $this->owner->id)->first(['api_token','api_mfa_verified_at']);
        $this->partialMock(\App\Services\MerchantVerificationDocuments::class, fn ($mock) => $mock->shouldReceive('assertActivation')->once()->with($this->merchant));
        $this->withToken($admin->api_token)->postJson('/api/business-registry/'.$this->merchant.'/review', [
            'revision' => 1,'status' => 'active','stage' => 'onboarded','reason' => 'All account evidence independently verified.',
            'evidence' => 'Synthetic business account review reference',
            'checks' => array_fill_keys(['registration_certificate','identity','business_information','representative_authority','contact_verification'], true),
        ])->assertOk()->assertJsonPath('business.status', 'active');
        $this->assertDatabaseHas('integration_source_profiles', ['merchant_id' => $this->merchant,'profile_kind' => 'account_connection','reviewed_by' => (string)$admin->id]);
        $this->assertDatabaseCount('merchant_api_keys', 0);
        $this->withToken($this->owner->api_token)->getJson('/api/merchant/api-keys')->assertOk()->assertJsonPath('merchant.status', 'active')->assertJsonPath('access.owner', true);
        $this->assertEquals($sessionBefore, DB::table('users')->where('id', $this->owner->id)->first(['api_token','api_mfa_verified_at']));
        $this->withToken($this->owner->api_token)->postJson('/api/merchant/api-keys', ['name' => 'Approved ERP'])->assertCreated();
        $this->assertDatabaseCount('integration_source_profiles', 1);
    }

    public function test_generated_connection_can_capture_and_retry_without_a_second_sale(): void
    {
        DB::transaction(fn () => app(\App\Services\MerchantWallet::class)->credit($this->merchant, 300, (string)Str::uuid(), 'Synthetic settled test funds', 'test-fixture'));
        $this->freshMfa();
        $r = $this->postJson('/api/merchant/api-keys', ['name' => 'ERP'])->assertCreated();
        $p = $this->ownedEvent($r->json('connection'));
        $this->withToken('')->withHeader('X-NUERS-API-Key', $r->json('key.plain_key'));
        $a = $this->postJson('/api/integrations/transactions', $p)->assertCreated()->assertJsonPath('record_mode', 'source_capture')->assertJsonMissingPath('bir_acceptance')->assertJsonPath('nuers_status', 'success');
        $this->postJson('/api/integrations/transactions', $p)->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('id', $a->json('id'));
        $this->assertDatabaseCount('merchant_transactions', 1);
        $this->assertDatabaseCount('transaction_receipts', 1);
        $this->assertDatabaseCount('invoice_series', 0);
        $p['customer_name'] = 'Changed original';
        $this->postJson('/api/integrations/transactions', $p)->assertConflict();
    }

    public function test_automatic_connection_does_not_bypass_revocation_identity_period_or_activation(): void
    {
        $this->freshMfa();
        $r = $this->postJson('/api/merchant/api-keys', ['name' => 'ERP'])->assertCreated();
        $p = $this->ownedEvent($r->json('connection'));
        $this->withToken('')->withHeader('X-NUERS-API-Key', $r->json('key.plain_key'));
        $earlier = $p;
        $earlier['occurred_at'] = now()->subDay()->toIso8601String();
        $this->postJson('/api/integrations/transactions', $earlier)->assertConflict();
        DB::table('merchants')->where('id', $this->merchant)->update(['address' => 'Changed registered address']);
        $this->postJson('/api/integrations/transactions', $p)->assertConflict();
        $this->withHeader('X-NUERS-API-Key', '')->withToken($this->owner->api_token)->postJson('/api/merchant/api-keys', ['name' => 'Cannot bypass changed identity'])->assertUnprocessable();
        DB::table('merchants')->where('id', $this->merchant)->update(['address' => 'Synthetic registered address']);
        DB::table('integration_source_profiles')->where('id', $p['source_profile_id'])->update(['status' => 'revoked']);
        $replacement = $this->postJson('/api/merchant/api-keys', ['name' => 'Replacement owned ERP'])->assertCreated();
        $this->assertNotSame($p['source_profile_id'], $replacement->json('connection.source_profile_id'));
        $this->assertSame($p['source_system'], $replacement->json('connection.source_system'));
        $this->assertDatabaseHas('integration_source_profiles', ['id' => $p['source_profile_id'],'status' => 'revoked']);
        $this->assertDatabaseHas('integration_source_profiles', ['id' => $replacement->json('connection.source_profile_id'),'status' => 'active']);
        $again = $this->postJson('/api/merchant/api-keys', ['name' => 'Replacement rotation'])->assertCreated();
        $this->assertSame($replacement->json('connection'), $again->json('connection'));
        $this->withToken('')->withHeader('X-NUERS-API-Key', $r->json('key.plain_key'))->postJson('/api/integrations/transactions', $p)->assertConflict();
        DB::table('merchants')->where('id', $this->merchant)->update(['status' => 'suspended']);
        $this->postJson('/api/integrations/transactions', $p)->assertForbidden();
        $this->assertDatabaseCount('integration_source_profiles', 2);
        $this->assertDatabaseCount('merchant_transactions', 0);
    }

    public function test_auto_connection_retains_wallet_admission_and_sandbox_isolation(): void
    {
        $this->freshMfa();
        $live = $this->postJson('/api/merchant/api-keys', ['name' => 'ERP','environment' => 'live'])->assertCreated();
        $sandbox = $this->postJson('/api/merchant/api-keys', ['name' => 'ERP test','environment' => 'sandbox'])->assertCreated();
        $this->assertSame($live->json('connection'), $sandbox->json('connection'));
        $p = $this->ownedEvent($live->json('connection'), 'OWNED-500', '500.00');
        $this->withToken('')->withHeader('X-NUERS-API-Key', $live->json('key.plain_key'))->postJson('/api/integrations/transactions', $p)->assertStatus(402);
        $this->assertDatabaseCount('merchant_transactions', 0);
        $this->withHeader('X-NUERS-API-Key', $sandbox->json('key.plain_key'))->postJson('/api/integrations/transactions', $p)->assertCreated()->assertJsonPath('environment', 'sandbox');
        $this->assertDatabaseCount('merchant_transactions', 0);
        $this->assertDatabaseCount('integration_sandbox_events', 1);
        $this->assertDatabaseCount('merchant_wallet_entries', 0);
    }

    public function test_owned_capture_accepts_mixed_tax_lines_and_never_changes_seller_registration(): void
    {
        DB::transaction(fn () => app(\App\Services\MerchantWallet::class)->credit($this->merchant, 300, (string)Str::uuid(), 'Synthetic settled test funds', 'test-fixture'));
        $this->freshMfa();
        DB::table('merchants')->where('id', $this->merchant)->update(['vat_registered' => true]);
        $key = $this->postJson('/api/merchant/api-keys', ['name' => 'Mixed VAT ERP'])->assertCreated();
        $p = $this->ownedEvent($key->json('connection'), 'MIXED-1', '312.00');
        $p['tax_type'] = 'vatable';
        $p['document_type'] = 'mixed_sales_invoice';
        $p['items'] = [['description' => 'Taxable product','quantity' => 1,'unit_price' => '112.00','tax_type' => 'vatable'],['description' => 'Exempt product','quantity' => 1,'unit_price' => '100.00','tax_type' => 'vat_exempt','tax_basis_reference' => 'Synthetic exemption evidence'],['description' => 'Eligible zero rated product','quantity' => 1,'unit_price' => '100.00','tax_type' => 'zero_rated','tax_basis_reference' => 'Synthetic zero rating evidence']];
        $this->withToken('')->withHeader('X-NUERS-API-Key', $key->json('key.plain_key'));
        $r = $this->postJson('/api/integrations/transactions', $p)->assertCreated();
        $this->assertEquals(12, $r->json('calculation.vat_amount'));
        $this->assertEquals(100, $r->json('calculation.vatable_sales'));
        $this->assertEquals(100, $r->json('calculation.vat_exempt_sales'));
        $this->assertEquals(100, $r->json('calculation.zero_rated_sales'));
        $bad = $p;
        $bad['source_event_id'] = 'BAD-2';
        $bad['external_reference'] = 'BAD-2';
        $bad['receipt_number'] = 'BAD-2';
        $bad['items'][1]['tax_type'] = 'non_vat';
        $this->postJson('/api/integrations/transactions', $bad)->assertUnprocessable();
        $this->assertDatabaseCount('merchant_transactions', 1);
        $this->assertEquals(1, DB::table('merchants')->where('id', $this->merchant)->value('vat_registered'));
    }

    public function test_existing_verified_account_setup_is_idempotent_and_never_mints_credentials(): void
    {
        $this->artisan('integrations:prepare-verified-connections')->assertSuccessful();
        $this->artisan('integrations:prepare-verified-connections')->assertSuccessful();
        $this->assertDatabaseCount('integration_source_profiles',1);
        $this->assertDatabaseCount('merchant_api_keys',0);
        $this->assertDatabaseCount('merchant_transactions',0);
        DB::table('integration_source_profiles')->update(['status' => 'revoked']);
        $this->artisan('integrations:prepare-verified-connections')->assertSuccessful();
        $this->assertDatabaseCount('integration_source_profiles',1);
        $this->assertDatabaseHas('integration_source_profiles',['status' => 'revoked']);
    }
}
