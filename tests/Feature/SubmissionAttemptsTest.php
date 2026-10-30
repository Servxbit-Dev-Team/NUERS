<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MerchantWallet;
use App\Services\SubmissionAttempts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubmissionAttemptsTest extends TestCase
{
    private string $key;
    private string $profile;
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array', 'notifications.dispatch_enabled' => false, 'security.enforce_privileged_mfa' => false]);
        DB::purge('sqlite');
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $owner = User::factory()->create();
        DB::table('merchants')->insert(['id' => 'receipt-test', 'merchant_account_id' => $owner->id, 'business_name' => 'Synthetic Receipt Shop', 'address' => 'Synthetic address', 'tin' => '123456789000', 'vat_registered' => true, 'status' => 'active', 'logo_url' => '/uploads/business-logos/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee.png', 'invoice_logo_url' => '/public/uploads/invoice-logos/bbbbbbbb-cccc-dddd-eeee-ffffffffffff.png']);
        $this->key = Str::random(64);
        DB::table('merchant_api_keys')->insert(['id' => 'key', 'merchant_id' => 'receipt-test', 'environment' => 'live', 'name' => 'Synthetic', 'branch_code' => '00000', 'branch_type' => 'main', 'key_hash' => hash('sha256', $this->key), 'key_prefix' => 'test', 'key_last_four' => 'test', 'status' => 'active', 'scopes' => '["transactions:write","transactions:read"]', 'rate_limit_per_day' => 1000, 'expires_at' => now()->addDay()]);
        $this->profile = (string) Str::uuid();
        DB::table('integration_source_profiles')->insert(['id' => $this->profile, 'merchant_id' => 'receipt-test', 'branch_code' => '00000', 'source_system' => 'fixture-pos', 'vat_registered' => true, 'seller_name' => 'Synthetic Receipt Shop', 'seller_tin' => '123456789000', 'seller_address' => 'Synthetic address', 'authority_reference' => 'Synthetic authority', 'evidence_reference' => 'Synthetic evidence', 'valid_from' => now()->subYear(), 'valid_until' => now()->addYear(), 'status' => 'active', 'reviewed_by' => 'fixture', 'reviewed_at' => now(), 'request_id' => (string) Str::uuid(), 'request_hash' => str_repeat('b', 64), 'created_at' => now(), 'updated_at' => now()]);
        DB::transaction(fn () => app(MerchantWallet::class)->credit('receipt-test', 900, (string) Str::uuid(), 'Synthetic funds', 'test'));
        DB::table('notification_outbox')->delete();
        $this->withHeader('X-NUERS-API-Key', $this->key);
    }

    private function event(string $n = '1'): array
    {
        return ['schema_version' => 'NUERS-ingestion-1', 'record_mode' => 'source_capture', 'source_profile_id' => $this->profile, 'source_system' => 'fixture-pos', 'source_event_id' => 'event-'.$n, 'external_reference' => 'sale-'.$n, 'event_type' => 'invoice.finalized', 'occurred_at' => now()->subMinute()->toIso8601String(), 'branch_code' => '00000', 'receipt_number' => 'TEST-'.$n, 'document_type' => 'vat_invoice', 'payment_method' => 'cash', 'gross_amount' => '112.00', 'tax_type' => 'vatable', 'vat_inclusive' => true, 'customer_email' => 'customer@example.test', 'items' => [['description' => 'Synthetic item', 'quantity' => 1, 'unit_price' => '112.00', 'tax_type' => 'vatable']]];
    }


    private function admin(): User
    {
        $u = User::factory()->create(['api_token' => Str::random(64)]);
        DB::table('profiles')->insert(['id' => $u->id, 'email' => $u->email, 'role' => 'super_admin']);
        $this->flushHeaders();
        $this->withToken($u->api_token);
        return $u;
    }

    public function test_validation_rejection_is_visible_with_reason_and_no_sale_or_wallet_charge(): void
    {
        $this->postJson('/api/integrations/transactions', ['source_event_id' => 'failed-1','external_reference' => 'order-1','merchant_id' => 'forged'])->assertUnprocessable();
        $this->assertDatabaseCount('merchant_api_request_logs', 1);
        $this->assertDatabaseHas('merchant_api_request_logs', ['merchant_id' => 'receipt-test','environment' => 'live','source_event_id' => 'failed-1','status_code' => 422,'error_code' => 'VALIDATION_FAILED','outcome' => 'rejected']);
        $this->assertDatabaseCount('merchant_transactions', 0);
        $this->assertSame(900, (int) DB::table('merchant_wallets')->value('available_centavos'));
        $this->admin();
        $this->getJson('/api/admin/submission-attempts')->assertOk()->assertJsonPath('attempts.total', 1)->assertJsonPath('attempts.data.0.business_name', 'Synthetic Receipt Shop');
        $this->getJson('/api/enterprise-dashboards/admin')->assertOk()->assertJsonPath('metrics.2.value', 1)->assertJsonPath('metrics.0.value', 0);
    }

    public function test_wallet_error_uses_actual_402_not_internal_exception_500(): void
    {
        DB::transaction(fn () => app(MerchantWallet::class)->withdraw('receipt-test', 900, (string)Str::uuid(), 'fixture'));
        $this->postJson('/api/integrations/transactions', $this->event())->assertStatus(402);
        $this->assertDatabaseHas('merchant_api_request_logs', ['status_code' => 402,'error_code' => 'INSUFFICIENT_WALLET_BALANCE','outcome' => 'rejected']);
        $this->assertDatabaseCount('merchant_transactions', 0);
    }

    public function test_throttle_and_expired_key_are_attributed_but_unknown_or_conflicting_keys_are_not(): void
    {
        DB::table('merchant_api_keys')->update(['expires_at' => now()->subMinute()]);
        $this->postJson('/api/integrations/transactions', [])->assertForbidden();
        $this->assertDatabaseHas('merchant_api_request_logs', ['status_code' => 403,'merchant_id' => 'receipt-test']);
        RateLimiter::increment('api-ip:'.hash('sha256', '127.0.0.1'), 60, 300);
        $this->postJson('/api/integrations/transactions', [])->assertTooManyRequests()->assertHeader('Retry-After');
        $this->assertDatabaseHas('merchant_api_request_logs', ['status_code' => 429,'error_code' => 'NETWORK_RATE_LIMITED','merchant_id' => 'receipt-test']);
        $this->withHeader('X-NUERS-API-Key', 'invalid-key')->postJson('/api/integrations/transactions', ['merchant_id' => 'receipt-test'])->assertTooManyRequests();
        $this->withHeader('X-NUERS-API-Key', $this->key)->withToken('conflict')->postJson('/api/integrations/transactions', [])->assertTooManyRequests();
        $this->assertDatabaseCount('merchant_api_request_logs', 2);
    }

    public function test_retry_history_is_distinct_from_sales_and_success_is_not_counted_as_failure(): void
    {
        $event = $this->event();
        $this->postJson('/api/integrations/transactions', array_replace($event, ['gross_amount' => '1.00']))->assertUnprocessable();
        $this->postJson('/api/integrations/transactions', $event)->assertCreated();
        $this->postJson('/api/integrations/transactions', $event)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertDatabaseCount('merchant_api_request_logs', 3);
        $this->assertSame(1, app(SubmissionAttempts::class)->failures()->count());
        $this->assertDatabaseCount('merchant_transactions', 1);
        $this->assertSame(600, (int) DB::table('merchant_wallets')->value('available_centavos'));
    }

    public function test_sandbox_and_live_are_separate_and_environment_is_preserved_after_key_change(): void
    {
        DB::table('merchant_api_keys')->update(['environment' => 'sandbox']);
        $this->postJson('/api/integrations/transactions', [])->assertUnprocessable();
        DB::table('merchant_api_keys')->update(['environment' => 'live']);
        $this->postJson('/api/integrations/transactions', [])->assertUnprocessable();
        $this->assertSame(1, app(SubmissionAttempts::class)->failures('live')->count());
        $this->assertSame(1, app(SubmissionAttempts::class)->failures('sandbox')->count());
        $this->admin();
        $this->getJson('/api/admin/submission-attempts?environment=sandbox&status=422&search=Synthetic')->assertOk()->assertJsonPath('attempts.total', 1);
        $this->getJson('/api/admin/submission-attempts?search=unmatched')->assertOk()->assertJsonPath('attempts.total', 0);
        $this->getJson('/api/admin/submission-attempts?environment=invalid')->assertUnprocessable();
    }

    public function test_historical_logs_have_no_invented_reason_and_unknown_environment_is_excluded(): void
    {
        foreach (['key',null] as $key) {
            DB::table('merchant_api_request_logs')->insert(['id' => (string)Str::uuid(),'merchant_id' => 'receipt-test','merchant_api_key_id' => $key,'endpoint' => '/api/integrations/transactions','method' => 'POST','status_code' => 422,'created_at' => now()]);
        }
        $this->admin();
        $this->getJson('/api/admin/submission-attempts')->assertOk()->assertJsonPath('attempts.total', 1)->assertJsonPath('attempts.data.0.error_message', null);
    }

    public function test_admin_read_api_does_not_expose_credentials_or_allow_merchant_access(): void
    {
        $this->postJson('/api/integrations/transactions', [])->assertUnprocessable();
        $this->flushHeaders();
        $this->getJson('/api/admin/submission-attempts')->assertUnauthorized();
        $u = User::factory()->create(['api_token' => Str::random(64)]);
        DB::table('profiles')->insert(['id' => $u->id,'email' => $u->email,'role' => 'merchant']);
        $this->withToken($u->api_token)->getJson('/api/admin/submission-attempts')->assertForbidden();
        $this->getJson('/api/enterprise-dashboards/admin')->assertForbidden();
        $this->admin();
        $r = $this->getJson('/api/admin/submission-attempts')->assertOk();
        foreach ([$this->key,'key_hash','ip_address','customer_email','external_payload'] as $secret) {
            $this->assertStringNotContainsString($secret, $r->getContent());
        }
    }

    public function test_atomic_batch_failure_is_one_attempt_and_does_not_create_partial_sales(): void
    {
        $this->postJson('/api/integrations/transactions/batch', ['events' => [$this->event(),[]]])->assertUnprocessable();
        $this->assertDatabaseCount('merchant_api_request_logs', 1);
        $this->assertDatabaseCount('merchant_transactions', 0);
        $this->assertDatabaseHas('merchant_api_request_logs', ['endpoint' => '/api/integrations/transactions/batch','status_code' => 422]);
    }

    public function test_unconfirmed_server_error_is_sanitized(): void
    {
        $this->mock(\App\Services\IntegrationContract::class, fn ($m) => $m->shouldReceive('validateEvent')->andThrow(new \RuntimeException('SQL secret@example.test')));
        $r = $this->postJson('/api/integrations/transactions', [])->assertStatus(500)->assertJsonPath('error.outcome', 'unknown');
        $this->assertStringNotContainsString('SQL', $r->getContent());
        $this->assertDatabaseHas('merchant_api_request_logs', ['outcome' => 'unknown','status_code' => 500]);
        $this->assertStringNotContainsString('SQL', DB::table('merchant_api_request_logs')->value('error_message'));
    }
    public function test_telemetry_storage_failure_does_not_change_a_committed_sale_response(): void
    {
        \Illuminate\Support\Facades\Schema::drop('merchant_api_request_logs');
        $this->postJson('/api/integrations/transactions', $this->event())->assertCreated()->assertJsonPath('nuers_status', 'success');
        $this->assertDatabaseCount('merchant_transactions', 1);
        $this->assertSame(600, (int) DB::table('merchant_wallets')->value('available_centavos'));
    }

    public function test_received_timestamp_includes_the_database_timezone_and_formatted_tin_is_searchable(): void
    {
        config(['app.timezone' => 'Asia/Manila']);
        $this->postJson('/api/integrations/transactions', [])->assertUnprocessable();
        DB::table('merchant_api_request_logs')->update(['created_at' => '2026-10-20 09:35:00']);
        $this->admin();
        $this->getJson('/api/admin/submission-attempts?search=123-456-789-000')->assertOk()
            ->assertJsonPath('attempts.total', 1)->assertJsonPath('attempts.data.0.created_at', '2026-10-20T09:35:00+08:00');
    }

    public function test_unknown_key_security_evidence_is_preserved_without_trusting_claimed_merchant(): void
    {
        $this->withHeader('X-NUERS-API-Key', 'unknown-secret')->postJson('/api/integrations/transactions', ['merchant_id' => 'receipt-test'])->assertUnauthorized();
        $this->assertDatabaseHas('merchant_api_request_logs', ['status_code' => 401,'merchant_id' => null,'merchant_api_key_id' => null]);
        $this->assertSame(0,app(SubmissionAttempts::class)->failures()->count());
        $this->assertStringNotContainsString('unknown-secret',json_encode(DB::table('merchant_api_request_logs')->first()));
    }

}
