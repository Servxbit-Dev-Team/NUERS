<?php

namespace Tests\Feature;

use App\Contracts\EisProvider;
use App\Http\Controllers\Api\BusinessInvoiceController;
use App\Http\Controllers\Api\MerchantExpenseController;
use App\Models\User;
use App\Services\ApiAccess;
use App\Services\AtomicQuota;
use App\Services\BirCompliance;
use App\Services\EisDelivery;
use App\Services\EisDocumentReadiness;
use App\Services\ExpenseCreditEvidence;
use App\Services\SafeImage;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Synthetic, isolated SQLite fixtures. No external BIR, load or production calls. */
class DurableWorkflowTest extends TestCase
{
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite');
        foreach (glob(database_path('migrations/*.php')) as $f) {
            (require $f)->up();
        }
        $this->owner = $this->actor('merchant');
        DB::table('merchants')->insert(['id' => 'm', 'business_name' => 'Synthetic seller', 'address' => 'Synthetic registered address', 'tin' => '123456789', 'status' => 'active', 'vat_registered' => true, 'merchant_account_id' => $this->owner->id]);
    }

    private function actor(string $role): User
    {
        $u = User::factory()->create(['api_token' => Str::random(64)]);
        DB::table('profiles')->insert(['id' => $u->id, 'email' => $u->email, 'role' => $role]);

        return $u;
    }

    private function key(string $id = 'key', string $merchant = 'm', string $env = 'live'): string
    {
        $raw = Str::random(64);
        DB::table('merchant_api_keys')->insert(['id' => $id, 'merchant_id' => $merchant, 'name' => 'Synthetic key', 'environment' => $env, 'branch_code' => '00000', 'branch_type' => 'main', 'branch_name' => 'Synthetic branch', 'key_hash' => hash('sha256', $raw), 'key_prefix' => 'test', 'key_last_four' => 'test', 'status' => 'active', 'scopes' => '["transactions:write","transactions:read"]', 'rate_limit_per_day' => 1000, 'expires_at' => now()->addDay()]);

        return $raw;
    }

    private function series(): void
    {
        DB::table('invoice_series')->insert(['id' => 'series', 'merchant_id' => 'm', 'branch_code' => '00000', 'document_family' => 'invoice', 'prefix' => 'SYN-', 'range_start' => 1, 'range_end' => 3, 'status' => 'active', 'authority_reference' => 'Synthetic fixture, not real authority', 'verified_by' => 'fixture-reviewer', 'valid_from' => now()->subDay(), 'valid_until' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function payload(): array
    {
        return ['schema_version' => 'NUERS-ingestion-1', 'source_system' => 'fixture-pos', 'source_event_id' => 'event-1', 'event_type' => 'invoice.finalized', 'external_reference' => 'source-invoice-1', 'occurred_at' => now()->subHours(4)->toIso8601String(), 'branch_code' => '00000', 'receipt_number' => 'SYN-00000001', 'document_type' => 'vat_invoice', 'payment_method' => 'cash', 'gross_amount' => '112.00', 'tax_type' => 'vatable', 'vat_inclusive' => true, 'items' => [['description' => 'Synthetic item', 'quantity' => 1, 'unit_price' => '112.00', 'tax_type' => 'vatable']]];
    }

    private function issueGate(): void
    {
        $this->partialMock(BirCompliance::class, fn ($m) => $m->shouldReceive('assertCanIssue')->andReturn(['permit_to_issue_number' => 'SYNTHETIC-ONLY', 'esp_accreditation_number' => 'SYNTHETIC-ONLY']));
    }

    public function test_ingestion_preserves_time_has_stable_duplicate_response_and_tenant_status_scope(): void
    {
        DB::transaction(fn()=>app(\App\Services\MerchantWallet::class)->credit('m',600,(string)Str::uuid(),'Synthetic settled test funds','test-fixture'));
        $key = $this->key();
        $this->series();
        $this->issueGate();
        $p = $this->payload();
        $first = $this->withHeaders(['X-NUERS-API-Key' => $key])->postJson('/api/integrations/transactions', $p)->assertCreated()->assertJsonPath('nuers_status', 'success')->assertJsonMissingPath('bir_acceptance')->json();
        $this->assertSame('SYN-00000001', $first['receipt_number']);
        $this->assertSame(Carbon::parse($p['occurred_at'])->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'), DB::table('transaction_receipts')->value('issued_at'));
        $this->postJson('/api/integrations/transactions', $p)->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('id', $first['id'])->assertJsonPath('accepted_at', $first['accepted_at']);
        $this->postJson('/api/integrations/transactions', array_replace($p, ['source_event_id' => 'different-event', 'external_reference' => 'different-doc']))->assertConflict(); // sequence mismatch rolls everything back
        $this->postJson('/api/integrations/transactions', array_replace($p, ['customer_name' => 'changed']))->assertConflict();
        $this->getJson('/api/integrations/transactions/'.$first['id'])->assertOk()->assertJsonPath('id', $first['id']);
        DB::table('merchants')->insert(['id' => 'other', 'business_name' => 'Foreign', 'tin' => '987654321', 'status' => 'active']);
        $other = $this->key('other-key', 'other');
        $this->withHeaders(['X-NUERS-API-Key' => $other])->getJson('/api/integrations/transactions/'.$first['id'])->assertNotFound();
        $this->assertDatabaseCount('merchant_transactions', 1);
        $this->assertDatabaseCount('integration_inbox', 1);
        $this->assertDatabaseCount('eis_document_outbox', 1);
        $this->assertDatabaseCount('eis_delivery_jobs', 1);
        $this->assertSame(1, DB::table('invoice_series')->value('last_number'));
        $this->assertTrue(app(BirCompliance::class)->auditIntegrity());
    }

    public function test_offline_replay_after_key_rotation_reuses_original_and_separate_branches_cannot_poll(): void
    {
        DB::transaction(fn()=>app(\App\Services\MerchantWallet::class)->credit('m',600,(string)Str::uuid(),'Synthetic settled test funds','test-fixture'));
        $raw = $this->key();
        $this->series();
        $this->issueGate();
        $event = $this->payload();
        $original = $this->withHeaders(['X-NUERS-API-Key' => $raw])->postJson('/api/integrations/transactions', $event)->assertCreated()->json();
        DB::table('merchant_api_keys')->where('id', 'key')->update(['status' => 'revoked']);
        $replacement = $this->key('replacement');
        $this->withHeaders(['X-NUERS-API-Key' => $replacement])->postJson('/api/integrations/transactions', $event)->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('id', $original['id'])->assertJsonPath('receipt_id', $original['receipt_id']);
        $this->postJson('/api/integrations/transactions', array_replace($event, ['occurred_at' => now()->toIso8601String()]))->assertConflict();
        DB::table('merchant_api_keys')->where('id', 'replacement')->update(['branch_code' => '00001']);
        $this->getJson('/api/integrations/transactions/'.$original['id'])->assertNotFound();
        $this->assertDatabaseCount('integration_inbox', 1);
        $this->assertDatabaseCount('transaction_receipts', 1);
        $this->assertSame(1, DB::table('invoice_series')->value('last_number'));
    }

    public function test_ingestion_isolates_sandbox_and_rejects_branch_scope_future_time_bad_schema_and_expiry(): void
    {
        $key = $this->key(env: 'sandbox');
        $p = $this->payload();
        $this->withHeaders(['X-NUERS-API-Key' => $key])->postJson('/api/integrations/transactions', $p)->assertCreated()->assertJsonPath('environment','sandbox')->assertJsonPath('test_only',true);
        $this->assertDatabaseCount('integration_sandbox_events',1);
        DB::table('merchant_api_keys')->update(['environment' => 'live', 'branch_code' => '00001']);
        $this->postJson('/api/integrations/transactions', $p)->assertForbidden();
        DB::table('merchant_api_keys')->update(['branch_code' => '00000']);
        foreach ([['schema_version' => 'BIR-UNKNOWN'], ['occurred_at' => now()->addHour()->toIso8601String()], ['occurred_at' => '2026-10-16 12:00:00'], ['document_type' => 'payment_receipt'], ['items' => []]] as $patch) {
            $this->postJson('/api/integrations/transactions', array_replace($p, $patch))->assertUnprocessable();
        }
        DB::table('merchant_api_keys')->update(['expires_at' => now()->subSecond()]);
        $this->postJson('/api/integrations/transactions', $p)->assertForbidden();
        $this->assertDatabaseCount('merchant_transactions', 0);
        $this->assertDatabaseCount('integration_inbox', 0);
    }

    public function test_ingestion_rolls_back_sequence_ledger_inbox_and_outbox_on_preparation_failure(): void
    {
        DB::transaction(fn()=>app(\App\Services\MerchantWallet::class)->credit('m',600,(string)Str::uuid(),'Synthetic settled test funds','test-fixture'));
        $key = $this->key();
        $this->series();
        $this->issueGate();
        $this->mock(EisDocumentReadiness::class, fn ($m) => $m->shouldReceive('prepare')->once()->andThrow(new \RuntimeException('Isolated crash fixture')));
        $this->withHeaders(['X-NUERS-API-Key' => $key])->postJson('/api/integrations/transactions', $this->payload())->assertStatus(500);
        foreach (['integration_inbox', 'merchant_transactions', 'transaction_receipts', 'eis_document_outbox'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertNull(DB::table('invoice_series')->value('last_number'));
    }

    public function test_shared_atomic_budgets_reject_over_limit_without_partial_bucket_increment(): void
    {
        $quota = new AtomicQuota;
        $quota->admit('synthetic', 3, 1);
        try {
            $quota->admit('synthetic', 3, 1);
            $this->fail('Admission should fail');
        } catch (HttpException $e) {
            $this->assertSame(429, $e->getStatusCode());
        }
        $this->assertSame([1, 1], DB::table('api_quota_buckets')->pluck('used')->all());
        $this->travel(1)->minutes();
        $quota->admit('synthetic', 3, 1);
        $this->assertSame(4, (int) DB::table('api_quota_buckets')->sum('used'));
    }

    private function outbox(): string
    {
        DB::table('eis_document_outbox')->insert(['id' => 'outbox', 'merchant_id' => 'm', 'document_source' => 'receipt', 'document_id' => 'r', 'internal_schema' => 'fixture', 'document_hash' => hash('sha256', 'fixture-payload'), 'encrypted_document' => Crypt::encryptString('fixture-payload'), 'status' => 'blocked_pending_api', 'blockers' => '[]', 'prepared_at' => now()]);

        return (new EisDelivery)->enqueue('outbox');
    }

    private function deliveryGate(): void
    {
        $this->partialMock(BirCompliance::class, fn ($m) => $m->shouldReceive('gate')->andReturn(['can_transmit' => true]));
        $this->mock(EisDocumentReadiness::class, fn ($m) => $m->shouldReceive('inspect')->andReturn(['local_validation_passed' => true, 'document_hash' => hash('sha256', 'fixture-payload')]));
    }

    private function provider(array $send, array $inquiry): EisProvider
    {
        return new class($send, $inquiry) implements EisProvider
        {
            public array $calls = [];

            public function __construct(private array $sendResult, private array $inquiryResult) {}

            public function ready(): bool
            {
                return true;
            }

            public function send(string $key, string $canonical): array
            {
                $this->calls[] = 'send';

                return $this->sendResult;
            }

            public function inquire(string $key): array
            {
                $this->calls[] = 'inquiry';

                return $this->inquiryResult;
            }
        };
    }

    public function test_default_provider_is_blocked_and_uncertain_send_requires_inquiry_before_any_replay(): void
    {
        $id = $this->outbox();
        $this->assertSame('blocked', (new EisDelivery)->runOne()['state']);
        $this->assertDatabaseCount('eis_delivery_attempts', 0);
        $this->deliveryGate();
        $provider = $this->provider(['outcome' => 'uncertain'], ['outcome' => 'accepted', 'authenticated' => true, 'reference' => 'SYNTHETIC', 'raw_response' => ['synthetic' => true]]);
        $worker = new EisDelivery($provider);
        $this->assertSame('uncertain', $worker->runOne()['state']);
        $this->assertSame(['send'], $provider->calls);
        DB::table('eis_delivery_jobs')->where('id', $id)->update(['available_at' => now()->subSecond()]);
        $this->assertSame('accepted', $worker->runOne()['state']);
        $this->assertSame(['send', 'inquiry'], $provider->calls);
        $this->assertDatabaseCount('eis_delivery_attempts', 2);
        $a = DB::table('eis_delivery_attempts')->where('outcome', 'accepted')->first();
        $this->assertSame(hash('sha256', Crypt::decryptString($a->encrypted_response)), $a->response_hash);
        $this->assertDatabaseCount('eis_acknowledgements', 0); // fake-provider evidence never becomes an official legacy ACK
    }

    public function test_expired_worker_lease_uses_inquiry_and_unverified_acceptance_stays_uncertain(): void
    {
        $id = $this->outbox();
        $this->deliveryGate();
        DB::table('eis_delivery_jobs')->where('id', $id)->update(['state' => 'sending', 'lease_token' => fake()->uuid(), 'lease_until' => now()->subMinute()]);
        $provider = $this->provider([], ['outcome' => 'accepted', 'authenticated' => false, 'reference' => 'FORGED', 'raw_response' => 'HTTP 200']);
        $this->assertSame('uncertain', (new EisDelivery($provider))->runOne()['state']);
        $this->assertSame(['inquiry'], $provider->calls);
    }

    public function test_payload_tampering_and_exhausted_retry_budget_are_quarantined_before_send(): void
    {
        $id = $this->outbox();
        $this->deliveryGate();
        DB::table('eis_document_outbox')->update(['document_hash' => str_repeat('0', 64)]);
        $provider = $this->provider([], []);
        $this->assertSame('dead_letter', (new EisDelivery($provider))->runOne()['state']);
        $this->assertSame([], $provider->calls);
        DB::table('eis_document_outbox')->update(['document_hash' => hash('sha256', 'fixture-payload')]);
        DB::table('eis_delivery_jobs')->where('id', $id)->update(['state' => 'retry', 'attempts' => 8]);
        $this->assertSame('dead_letter', (new EisDelivery($provider))->runOne()['state']);
        $this->assertSame([], $provider->calls);
    }

    private function invoice(): void
    {
        DB::table('business_invoices')->insert(['id' => 'invoice', 'merchant_id' => 'm', 'invoice_number' => 'SYN-ORIGINAL', 'merchant_name' => 'Synthetic', 'buyer_name' => 'Buyer', 'status' => 'issued', 'total_amount' => 112]);
    }

    public function test_cancellation_is_durable_idempotent_and_never_mutates_the_original(): void
    {
        $this->invoice();
        $v = ['request_id' => fake()->uuid(), 'kind' => 'cancellation', 'reason' => 'Original evidence requires cancellation review'];
        $this->withToken($this->owner->api_token)->postJson('/api/documents/invoice/invoice/review-requests', $v)->assertStatus(202)->assertJsonPath('status', 'pending');
        $this->postJson('/api/documents/invoice/invoice/review-requests', $v)->assertOk()->assertJsonPath('duplicate', true);
        $this->postJson('/api/documents/invoice/invoice/review-requests', array_replace($v, ['reason' => 'Changed reason is forbidden']))->assertConflict();
        $this->assertDatabaseHas('business_invoices', ['id' => 'invoice', 'status' => 'issued', 'invoice_number' => 'SYN-ORIGINAL', 'total_amount' => 112]);
        $this->assertDatabaseCount('document_review_requests', 1);
    }

    public function test_portability_contains_scoped_new_evidence_and_hold_release_requires_independent_bir_reviewer(): void
    {
        $this->invoice();
        $this->outbox();
        DB::table('merchants')->insert(['id' => 'foreign', 'business_name' => 'SECRET FOREIGN', 'tin' => '987654321']);
        $admin = $this->actor('super_admin');
        $this->withToken($admin->api_token)->postJson('/api/documents/invoice/invoice/holds', ['authority_reference' => 'Synthetic legal hold reference', 'reason' => 'Preserve original document for review'])->assertCreated();
        $hold = DB::table('document_legal_holds')->value('id');
        $this->postJson('/api/documents/holds/'.$hold.'/release', ['release_reference' => 'Synthetic written release'])->assertForbidden();
        $bir = $this->actor('bir');
        $this->withToken($bir->api_token)->postJson('/api/documents/holds/'.$hold.'/release', ['release_reference' => 'Synthetic written release'])->assertOk();
        $out = $this->withToken($this->owner->api_token)->get('/api/businesses/m/portability')->assertOk()->streamedContent();
        $this->assertStringContainsString('canonical_document', $out);
        $this->assertStringContainsString('document_legal_holds', $out);
        $this->assertStringContainsString('eis_delivery_jobs', $out);
        $this->assertStringContainsString('manifest_sha256', $out);
        $this->assertStringNotContainsString('SECRET FOREIGN', $out);
        $this->get('/api/businesses/foreign/portability')->assertNotFound();
        $this->assertDatabaseCount('business_invoices', 1);
    }

    public function test_upload_reencodes_polyglot_with_safe_extension_and_without_executable_trailer(): void
    {
        $file = UploadedFile::fake()->image('attack.php', 12, 12);
        file_put_contents($file->getRealPath(), '<?php echo "injected"; ?>', FILE_APPEND);
        $directory = sys_get_temp_dir().'/nuers-image-test-'.Str::uuid();
        try {
            $name = (new SafeImage)->store($file, $directory);
            $this->assertStringEndsWith('.png', $name);
            $this->assertSame(IMAGETYPE_PNG, getimagesize($directory.'/'.$name)[2]);
            $this->assertStringNotContainsString('<?php', file_get_contents($directory.'/'.$name));
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_unverified_identity_cannot_gain_taxpayer_records_or_activate(): void
    {
        $citizen = $this->actor('client');
        DB::table('client_accounts')->insert(['id' => 'c', 'user_id' => $citizen->id, 'full_name' => 'Test', 'email' => $citizen->email, 'account_number' => 'SYN-C', 'tin' => '123456789', 'status' => 'active']);
        $this->assertNull(app(ApiAccess::class)->taxpayerTin($citizen));
        $admin = $this->actor('super_admin');
        $this->withToken($admin->api_token)->patchJson('/api/citizen-approvals/c', ['status' => 'active'])->assertStatus(409);
    }

    public function test_tax_center_counts_reconciled_document_once_and_ignores_unreviewed_input_credits(): void
    {
        DB::connection()->getPdo()->sqliteCreateFunction('YEAR', fn ($value) => (int) substr((string) $value, 0, 4));
        DB::table('business_invoices')->insert(['id' => 'tax-doc', 'merchant_id' => 'm', 'invoice_number' => 'SYN-TAX-1', 'document_type' => 'vat_invoice', 'merchant_name' => 'Synthetic', 'merchant_tin' => '123456789', 'merchant_address' => 'Synthetic address', 'buyer_name' => 'Buyer', 'status' => 'issued', 'issue_date' => now()->toDateString(), 'total_amount' => 112, 'subtotal_amount' => 112, 'taxable_amount' => 100, 'vat_amount' => 12, 'vat_registered' => true]);
        DB::table('business_invoice_items')->insert(['id' => 'tax-line', 'invoice_id' => 'tax-doc', 'merchant_id' => 'm', 'line_number' => 1, 'description' => 'Synthetic item', 'quantity' => 1, 'unit_price' => 112, 'line_total' => 112, 'taxable_amount' => 100, 'vat_amount' => 12, 'tax_type' => 'vatable', 'vat_rate' => 12]);
        foreach (['p1', 'p2'] as $payment) {
            DB::table('merchant_transactions')->insert(['id' => $payment, 'merchant_id' => 'm', 'transaction_ref' => $payment, 'amount' => 56, 'status' => 'completed', 'created_at' => now()]);
        }
        DB::table('merchant_expenses')->insert(['id' => 'unverified', 'merchant_id' => 'm', 'source_invoice_reference' => 'FAKE-CLAIM', 'supplier_name' => 'Unknown', 'buyer_tin' => '123456789', 'gross_amount' => 112, 'input_vat_amount' => 12, 'claim_status' => 'available', 'issued_at' => now()]);
        $this->withToken($this->owner->api_token)->getJson('/api/merchant/tax-center?year='.now()->year)->assertOk()->assertJsonPath('summary.total_gross_sales', 112)->assertJsonPath('summary.vat_output', 12)->assertJsonPath('summary.input_vat_credit', 0)->assertJsonPath('summary.sales_document_count', 1);
        $this->getJson('/api/tax-intelligence/merchant')->assertOk()->assertJsonPath('reporting_state', 'internal_draft_not_tax_return')->assertJsonPath('kpis.1.value', 'PHP 12.00');
    }

    public function test_document_review_requires_independent_evidence_and_preserves_original_status(): void
    {
        $this->invoice();
        $v = ['request_id' => fake()->uuid(), 'kind' => 'cancellation', 'reason' => 'Synthetic original basis for review'];
        $case = $this->withToken($this->owner->api_token)->postJson('/api/documents/invoice/invoice/review-requests', $v)->assertStatus(202)->json('id');
        $admin = $this->actor('super_admin');
        $decision = ['revision' => 1, 'status' => 'resolved', 'evidence_reference' => 'Synthetic evidence, no actual cancellation', 'resolution_document_id' => 'missing'];
        $this->withToken($admin->api_token)->postJson('/api/documents/review-requests/'.$case.'/decision', $decision)->assertConflict();
        $decision['status'] = 'rejected';
        unset($decision['resolution_document_id']);
        $this->postJson('/api/documents/review-requests/'.$case.'/decision', $decision)->assertOk()->assertJsonPath('original_preserved', true);
        $this->postJson('/api/documents/review-requests/'.$case.'/decision', $decision)->assertConflict();
        $this->assertDatabaseHas('business_invoices', ['id' => 'invoice', 'status' => 'issued', 'total_amount' => 112]);
        $this->assertDatabaseHas('invoice_lifecycle_events', ['document_id' => 'invoice', 'event' => 'review.rejected']);
        $bir = $this->actor('bir');
        $this->withToken($bir->api_token)->postJson('/api/documents/invoice/invoice/publish', ['branch_code' => '00000'])->assertForbidden();
    }

    public function test_draft_publish_reserves_series_and_commits_lifecycle_and_outbox_together(): void
    {
        DB::transaction(fn()=>app(\App\Services\MerchantWallet::class)->credit('m',600,(string)Str::uuid(),'Synthetic settled test funds','test-fixture'));
        $this->series();
        $this->issueGate();
        $id = fake()->uuid();
        $this->withToken($this->owner->api_token)->postJson('/api/invoice-center', ['request_id' => $id, 'merchant_id' => 'm', 'document_type' => 'sales_invoice', 'buyer_name' => 'Synthetic buyer', 'issue_date' => now()->toDateString(), 'vat_inclusive' => true, 'tax_type' => 'vatable', 'items' => [['description' => 'Synthetic line', 'quantity' => 1, 'unit_price' => 112]]])->assertCreated();
        $this->postJson('/api/documents/invoice/'.$id.'/publish', ['branch_code' => '00000'])->assertOk()->assertJsonPath('number', 'SYN-00000001')->assertJsonPath('bir_acceptance', 'not_verified');
        $this->assertDatabaseHas('business_invoices', ['id' => $id, 'status' => 'issued', 'seller_branch_code' => '00000', 'total_amount' => 112, 'vat_amount' => 12]);
        $this->assertDatabaseHas('invoice_lifecycle_events', ['document_id' => $id, 'event' => 'issued']);
        $this->assertDatabaseCount('eis_document_outbox', 1);
        $this->postJson('/api/documents/invoice/'.$id.'/publish', ['branch_code' => '00000'])->assertConflict();
    }

    public function test_key_creation_enforces_branches_scopes_ip_and_rotation_cannot_revive_revoked_keys(): void
    {
        $this->withToken($this->owner->api_token)->postJson('/api/merchant/api-keys', ['name' => 'Synthetic test', 'environment' => 'live', 'branch_code' => 'MAIN'])->assertUnprocessable();
        $data = ['name' => 'Synthetic test', 'environment' => 'live', 'branch_code' => '00000', 'scopes' => ['transactions:write', 'transactions:read']];
        $this->postJson('/api/merchant/api-keys', $data + ['ip_whitelist' => ['not-an-ip']])->assertUnprocessable();
        DB::table('merchant_branches')->insert(['id'=>(string)Str::uuid(),'merchant_id'=>'m','code'=>'SYNTHETIC','bir_branch_code'=>'00000','name'=>'Synthetic branch','address'=>'Synthetic address','city'=>'Synthetic city','region'=>'Synthetic region','rdo_code'=>'001','branch_type'=>'main','status'=>'active','contact_encrypted'=>\Illuminate\Support\Facades\Crypt::encryptString('{}'),'request_id'=>(string)Str::uuid(),'request_hash'=>str_repeat('a',64)]);
        $key = $this->postJson('/api/merchant/api-keys', $data)->assertCreated()->json('key');
        $this->assertSame(hash('sha256', $key['plain_key']), DB::table('merchant_api_keys')->where('id', $key['id'])->value('key_hash'));
        $this->assertNotNull(DB::table('merchant_api_keys')->where('id', $key['id'])->value('expires_at'));
        $this->patchJson('/api/merchant/api-keys/'.$key['id'], ['active' => false])->assertOk()->assertJsonPath('key.active', false);
        $this->deleteJson('/api/merchant/api-keys/'.$key['id'])->assertOk();
        $this->patchJson('/api/merchant/api-keys/'.$key['id'], ['active' => true])->assertConflict();
    }

    public function test_citizen_signup_claim_review_and_activation_do_not_self_bind_a_tin(): void
    {
        $result = $this->postJson('/api/auth/signup', ['email' => 'new-citizen@example.test', 'password' => 'Synthetic-Strong-Password123', 'meta' => ['role' => 'client', 'full_name' => 'Synthetic citizen', 'phone' => '09000000000']])->assertCreated();
        $token = $result->json('session.access_token');
        $userId = $result->json('user.id');
        $account = DB::table('client_accounts')->where('user_id', $userId)->first();
        $this->assertSame('pending', $account->status);
        $this->assertNull($account->tin);
        $this->withToken($token)->getJson('/api/client/profile')->assertOk()->assertJsonPath('data.verified_tin', null);
        $claim = $this->postJson('/api/identity/claims', ['tin' => '123456789', 'evidence_reference' => 'Synthetic original evidence fixture'])->assertStatus(202)->json('id');
        $admin = $this->actor('super_admin');
        $this->withToken($admin->api_token)->patchJson('/api/citizen-approvals/'.$account->id, ['status' => 'active'])->assertStatus(409);
        $this->postJson('/api/identity/claims/'.$claim.'/review', ['status' => 'approved', 'reason' => 'Synthetic independent review only', 'evidence_reference' => 'Synthetic source evidence fixture'])->assertOk();
        $this->patchJson('/api/citizen-approvals/'.$account->id, ['status' => 'active'])->assertOk();
        $this->withToken($token)->getJson('/api/client/profile')->assertOk()->assertJsonPath('data.verified_tin', '123456789');
    }

    public function test_two_supplier_documents_with_the_same_number_never_overwrite_buyer_expenses(): void
    {
        DB::table('merchants')->insert(['id' => 'buyer', 'business_name' => 'Synthetic buyer', 'tin' => '555555555000', 'status' => 'active']);
        $seller = (object) ['id' => 'm', 'business_name' => 'Synthetic seller', 'tin' => '123456789'];
        $other = (object) ['id' => 'other-seller', 'business_name' => 'Second synthetic seller', 'tin' => '987654321'];
        $totals = ['total_amount' => 112, 'taxable_amount' => 100, 'vat_amount' => 12, 'withholding_tax' => 0, 'amount_due' => 112];
        $v = ['invoice_number' => 'SAME-SUPPLIER-NUMBER', 'buyer_tin' => '555555555000', 'buyer_name' => 'Synthetic buyer', 'document_type' => 'vat_invoice', 'issue_date' => now()->toDateString()];
        $method = new \ReflectionMethod(BusinessInvoiceController::class, 'mirrorBuyerExpense');
        $controller = app(BusinessInvoiceController::class);
        $method->invoke($controller, $seller, 'source-1', $v, $totals, now());
        $first = DB::table('merchant_expenses')->where('source_invoice_id', 'source-1')->first();
        $method->invoke($controller, $other, 'source-2', $v, $totals, now());
        $method->invoke($controller, $seller, 'source-1', $v, $totals, now());
        $this->assertDatabaseCount('merchant_expenses', 2);
        $this->assertSame($first->id, DB::table('merchant_expenses')->where('source_invoice_id', 'source-1')->value('id'));
        $this->assertDatabaseHas('merchant_expenses', ['source_invoice_id' => 'source-1', 'supplier_merchant_id' => 'm', 'claim_status' => 'pending_review', 'ai_score' => null]);
        $this->assertDatabaseHas('merchant_expenses', ['source_invoice_id' => 'source-2', 'supplier_merchant_id' => 'other-seller']);
    }

    public function test_credit_evidence_fingerprints_the_original_header_and_lines(): void
    {
        $this->invoice();
        DB::table('business_invoices')->where('id', 'invoice')->update(['buyer_tin' => '555555555', 'vat_amount' => 12]);
        DB::table('business_invoice_items')->insert(['id' => 'line-1', 'invoice_id' => 'invoice', 'merchant_id' => 'm', 'line_number' => 1, 'description' => 'Original evidence', 'quantity' => 1, 'unit_price' => 112, 'line_total' => 112]);
        $source = DB::table('business_invoices')->where('id', 'invoice')->first();
        $policy = app(ExpenseCreditEvidence::class);
        $expense = (object) ['source_invoice_id' => 'invoice', 'claim_status' => 'approved', 'buyer_tin' => '555555555', 'gross_amount' => 112, 'input_vat_amount' => 12, 'withholding_tax_amount' => 0, 'metadata' => json_encode(['claim_review' => ['reviewed_by' => 'synthetic-reviewer', 'reviewed_at' => now()->toIso8601String(), 'evidence_reference' => 'Synthetic evidence only', 'source_hash' => $policy->fingerprint($source)]])];
        $this->assertSame('invoice', $policy->source($expense)->id);
        DB::table('merchant_expenses')->insert((array) $expense + ['id' => 'reviewed-expense', 'merchant_id' => 'm', 'source_invoice_reference' => 'Synthetic source', 'supplier_name' => 'Synthetic supplier']);
        $summary = new \ReflectionMethod(MerchantExpenseController::class, 'summary');
        $controller = app(MerchantExpenseController::class);
        $this->assertEquals(12, $summary->invoke($controller, DB::table('merchant_expenses'))['input_vat']);
        DB::table('business_invoice_items')->where('id', 'line-1')->update(['description' => 'Altered basis']);
        $this->assertNull($policy->source($expense));
        $held = $summary->invoke($controller, DB::table('merchant_expenses'));
        $this->assertEquals(0, $held['input_vat']);
        $this->assertEquals(12, $held['recorded_input_vat']);
    }

    public function test_merchant_overview_is_scoped_read_only_and_never_treats_local_status_as_bir_proof(): void
    {
        $this->invoice();
        DB::table('business_invoices')->where('id', 'invoice')->update(['issue_date' => now()->toDateString(), 'document_type' => 'vat_invoice', 'merchant_tin' => '123456789', 'merchant_address' => 'Synthetic address', 'taxable_amount' => 100, 'vat_amount' => 12, 'vat_registered' => true]);
        DB::table('business_invoice_items')->insert(['id' => 'overview-line', 'invoice_id' => 'invoice', 'merchant_id' => 'm', 'line_number' => 1, 'description' => 'Synthetic original', 'quantity' => 1, 'unit_price' => 112, 'line_total' => 112, 'taxable_amount' => 100, 'vat_amount' => 12, 'tax_type' => 'vatable', 'vat_rate' => 12]);
        DB::table('merchants')->insert(['id' => 'foreign', 'business_name' => 'SECRET OTHER TENANT', 'tin' => '987654321', 'status' => 'active']);
        DB::table('merchant_wallets')->insert([
            ['merchant_id' => 'm', 'available_centavos' => 8200, 'updated_at' => now()],
            ['merchant_id' => 'foreign', 'available_centavos' => 99999999, 'updated_at' => now()],
        ]);
        $walletsBefore = DB::table('merchant_wallets')->orderBy('merchant_id')->get()->toJson();
        DB::table('business_invoices')->insert(['id' => 'foreign-invoice', 'merchant_id' => 'foreign', 'invoice_number' => 'SECRET-FOREIGN', 'merchant_name' => 'SECRET OTHER TENANT', 'buyer_name' => 'SECRET CUSTOMER', 'status' => 'issued', 'issue_date' => now()->toDateString(), 'total_amount' => 999999]);
        $before = DB::table('eis_audit_events')->count();
        $response = $this->withToken($this->owner->api_token)->getJson('/api/merchant/overview?days=7&merchant_id=foreign')->assertOk()
            ->assertJsonPath('account.id', 'm')->assertJsonPath('current.sales', 112)->assertJsonPath('current.invoices', 1)->assertJsonPath('current.transactions', 0)
            ->assertJsonPath('wallet.available_centavos', 8200)->assertJsonPath('wallet.currency', 'PHP')
            ->assertJsonPath('bir.acknowledged', 0)->assertJsonPath('bir.not_verified', 1)->assertJsonPath('recent.0.bir_status', 'not_verified')->assertJsonCount(7, 'sales')->assertJsonCount(1, 'recent');
        $this->assertStringNotContainsString('SECRET', $response->getContent());
        $this->assertSame($before, DB::table('eis_audit_events')->count());
        $this->assertSame($walletsBefore, DB::table('merchant_wallets')->orderBy('merchant_id')->get()->toJson());
        $this->assertDatabaseCount('eis_delivery_jobs', 0);
        $this->getJson('/api/merchant/overview?days=30')->assertOk()->assertJsonPath('days', 30)->assertJsonCount(30, 'sales');
        $this->getJson('/api/merchant/overview?days=10000')->assertUnprocessable();
        $citizen = $this->actor('client');
        $this->withToken($citizen->api_token)->getJson('/api/merchant/overview')->assertForbidden();
    }

    public function test_merchant_overview_calendar_day_uses_philippine_time_and_preserves_unreviewed_sources(): void
    {
        config(['app.timezone' => 'UTC']);
        Carbon::setTestNow(Carbon::parse('2026-10-16 00:30:00', 'Asia/Manila'));
        CarbonImmutable::setTestNow(Carbon::parse('2026-10-16 00:30:00', 'Asia/Manila'));
        try {
            $this->invoice();
            DB::table('business_invoices')->where('id', 'invoice')->update(['issue_date' => '2026-10-15']);
            DB::table('merchant_transactions')->insert(['id' => 'late-local-sale', 'merchant_id' => 'm', 'amount' => 112, 'status' => 'completed', 'created_at' => '2026-10-15 16:15:00']);
            DB::table('merchant_transactions')->insert(['id' => 'prior-day-sale', 'merchant_id' => 'm', 'amount' => 112, 'status' => 'completed', 'created_at' => '2026-10-15 15:59:59']);
            $this->withToken($this->owner->api_token)->getJson('/api/merchant/overview')->assertOk()->assertJsonPath('today', '2026-10-16')
                ->assertJsonPath('wallet.available_centavos', 0)->assertJsonPath('wallet.currency', 'PHP')
                ->assertJsonPath('current.transactions', 1)->assertJsonPath('previous.transactions', 1)->assertJsonPath('current.sales', 0)->assertJsonPath('previous.sales', 0)->assertJsonPath('documents.for_review', 1);
            $this->assertDatabaseCount('merchant_wallets', 0);
            $this->assertDatabaseHas('business_invoices', ['id' => 'invoice', 'total_amount' => 112]);
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
        }
    }

    public function test_notes_navigation_returns_both_adjustment_types_only_for_own_merchant(): void
    {
        $this->invoice();
        foreach (['credit_memo', 'debit_memo'] as $type) {
            DB::table('business_invoices')->insert(['id' => $type, 'merchant_id' => 'm', 'invoice_number' => $type, 'document_type' => $type, 'merchant_name' => 'Synthetic', 'buyer_name' => 'Buyer', 'status' => 'draft', 'total_amount' => 112]);
        }
        $response = $this->withToken($this->owner->api_token)->getJson('/api/business-invoices?type=notes')->assertOk()->assertJsonCount(2, 'invoices');
        $this->assertEqualsCanonicalizing(['credit_memo', 'debit_memo'], array_column($response->json('invoices'), 'document_type'));
    }

    private function recordsInvoice(string $id = 'records-invoice', string $status = 'issued'): void
    {
        DB::table('business_invoices')->insert(['id' => $id, 'merchant_id' => 'm', 'invoice_number' => 'SYN-'.$id, 'document_type' => 'vat_invoice', 'merchant_name' => 'Synthetic seller', 'merchant_tin' => '123456789', 'merchant_address' => 'Synthetic address', 'seller_branch_code' => '00000', 'buyer_name' => 'Synthetic buyer', 'status' => $status, 'issue_date' => '2026-10-16', 'created_at' => '2026-10-16 10:00:00', 'total_amount' => 112, 'taxable_amount' => 100, 'vat_amount' => 12, 'vat_registered' => true, 'prices_include_vat' => true]);
        DB::table('business_invoice_items')->insert(['id' => $id.'-line', 'invoice_id' => $id, 'merchant_id' => 'm', 'line_number' => 1, 'description' => 'Synthetic source item', 'quantity' => 1, 'unit_price' => 112, 'line_total' => 112, 'taxable_amount' => 100, 'vat_amount' => 12, 'vat_rate' => 12, 'tax_type' => 'vatable']);
    }

    public function test_merchant_records_combine_documents_and_drafts_without_counting_payment_receipts_as_sales(): void
    {
        $this->recordsInvoice();
        $this->recordsInvoice('draft-record', 'draft');
        DB::table('transaction_receipts')->insert(['id' => 'payment-receipt', 'merchant_id' => 'm', 'receipt_number' => 'SYN-PAYMENT', 'merchant_name' => 'Synthetic seller', 'merchant_tin' => '123456789', 'document_type' => 'payment_receipt', 'receipt_type' => 'payment_receipt', 'status' => 'issued', 'total_due' => 112, 'buyer_name' => 'Synthetic buyer', 'issued_at' => '2026-10-16 11:00:00']);
        DB::table('merchant_transactions')->insert(['id' => 'portal-payment', 'merchant_id' => 'm', 'receipt_id' => 'payment-receipt', 'amount' => 112, 'status' => 'completed', 'created_at' => '2026-10-16 11:00:00']);
        $before = DB::table('eis_audit_events')->count();
        $this->withToken($this->owner->api_token)->getJson('/api/merchant/records?mode=documents')->assertOk()->assertJsonPath('summary.total', 3)->assertJsonPath('summary.total_amount', 112)->assertJsonPath('summary.invoices', 2)->assertJsonPath('summary.receipts', 1)->assertJsonPath('tabs.drafts', 1)->assertJsonPath('summary.acknowledged', 0);
        $this->getJson('/api/merchant/records?mode=transactions')->assertOk()->assertJsonPath('summary.total', 1)->assertJsonPath('summary.total_amount', 112)->assertJsonPath('data.0.id', 'payment:portal-payment');
        $this->getJson('/api/merchant/records/invoice:records-invoice?mode=documents')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('record.amount', 112);
        $this->assertSame($before, DB::table('eis_audit_events')->count());
        $this->assertDatabaseCount('eis_document_outbox', 0);
    }

    public function test_merchant_records_preserve_one_payment_per_row_and_refuse_foreign_links_and_exports(): void
    {
        DB::table('merchants')->insert(['id' => 'foreign', 'business_name' => 'SECRET FOREIGN', 'tin' => '999999999']);
        DB::table('transaction_receipts')->insert(['id' => 'foreign-receipt', 'merchant_id' => 'foreign', 'receipt_number' => 'SECRET NUMBER', 'merchant_name' => 'SECRET SELLER', 'merchant_tin' => '999999999', 'buyer_name' => 'SECRET BUYER']);
        DB::table('merchant_transactions')->insert(['id' => 'own-payment', 'merchant_id' => 'm', 'receipt_id' => 'foreign-receipt', 'amount' => 50, 'customer_name' => 'Own buyer']);
        foreach (['receipt-one', 'receipt-two'] as $id) {
            DB::table('transaction_receipts')->insert(['id' => $id, 'merchant_id' => 'm', 'transaction_id' => 'own-payment', 'receipt_number' => $id, 'merchant_name' => 'Synthetic seller', 'merchant_tin' => '123456789']);
        }
        DB::table('merchant_transactions')->insert(['id' => 'foreign-payment', 'merchant_id' => 'foreign', 'amount' => 900000]);
        $response = $this->withToken($this->owner->api_token)->getJson('/api/merchant/records?mode=transactions&merchant_id=foreign')->assertOk()->assertJsonPath('summary.total', 1)->assertJsonPath('data.0.document_id', null)->assertJsonPath('data.0.bir_status', 'not_linked');
        $this->assertStringNotContainsString('SECRET', $response->getContent());
        $this->getJson('/api/merchant/records/payment:foreign-payment?mode=transactions')->assertNotFound();
        $this->get('/api/merchant/records/export?mode=transactions&ids[]=payment:own-payment&ids[]=payment:foreign-payment', ['Accept' => 'application/json'])->assertNotFound();
        $citizen = $this->actor('client');
        $this->withToken($citizen->api_token)->getJson('/api/merchant/records')->assertForbidden();
    }

    public function test_merchant_records_filters_use_philippine_dates_and_allowlisted_sort_parameters(): void
    {
        config(['app.timezone' => 'UTC']);
        DB::table('merchant_transactions')->insert(['id' => 'pht-today', 'merchant_id' => 'm', 'amount' => 200, 'status' => 'pending', 'customer_name' => 'Target buyer', 'branch' => 'POS-OWN', 'created_at' => '2026-10-15 16:05:00']);
        DB::table('merchant_transactions')->insert(['id' => 'pht-prior', 'merchant_id' => 'm', 'amount' => 100, 'status' => 'completed', 'created_at' => '2026-10-15 15:59:59']);
        $this->withToken($this->owner->api_token)->getJson('/api/merchant/records?mode=transactions&from=2026-10-16&to=2026-10-16&customer=Target%20buyer&branch=POS-OWN&min=150&max=250&status=pending')->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('data.0.id', 'payment:pht-today');
        $this->getJson('/api/merchant/records?mode=transactions&to=2026-10-15')->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('data.0.id', 'payment:pht-prior');
        $this->getJson('/api/merchant/records?sort=amount;DROP%20TABLE%20users')->assertUnprocessable();
        $this->getJson('/api/merchant/records?from=2026-10-17&to=2026-10-16')->assertUnprocessable();
        $this->getJson('/api/merchant/records?min=200&max=1')->assertUnprocessable();
        $this->getJson('/api/merchant/records?per_page=99999')->assertUnprocessable();
        $this->getJson('/api/merchant/records?min=1e999')->assertUnprocessable();
        $this->assertDatabaseCount('merchant_transactions', 2);
    }

    public function test_merchant_records_exports_all_filtered_pages_and_protects_csv_formula_cells(): void
    {
        for ($i = 0; $i < 26; $i++) {
            DB::table('merchant_transactions')->insert(['id' => 'csv-'.$i, 'merchant_id' => 'm', 'transaction_ref' => 'CSV-'.$i, 'amount' => 10, 'status' => 'completed', 'customer_name' => $i === 0 ? '=SUM(1,2)' : 'Buyer', 'created_at' => '2026-10-16 10:00:00']);
        }
        $this->withToken($this->owner->api_token)->getJson('/api/merchant/records?mode=transactions&per_page=10&page=3')->assertOk()->assertJsonPath('pagination.total', 26)->assertJsonPath('pagination.last_page', 3)->assertJsonCount(6, 'data');
        $csv = $this->get('/api/merchant/records/export?mode=transactions&from=2026-10-16&to=2026-10-16')->assertOk()->streamedContent();
        $this->assertCount(27, explode("\n", trim($csv)));
        $this->assertStringContainsString("'=SUM(1,2)", $csv);
        $this->assertSame(1, DB::table('eis_audit_events')->where('event', 'merchant.records.export')->where('subject_id', 'm')->count());
        $this->assertTrue(app(BirCompliance::class)->auditIntegrity());
        $selected = $this->get('/api/merchant/records/export?mode=transactions&ids[]=payment:csv-0')->assertOk()->streamedContent();
        $this->assertCount(2, explode("\n", trim($selected)));
    }

    public function test_merchant_records_export_budget_preserves_authentication_and_record_reads(): void
    {
        $this->withToken($this->owner->api_token);
        for ($i = 0; $i < 3; $i++) {
            $this->get('/api/merchant/records/export?mode=transactions')->assertOk()->streamedContent();
        }
        $this->app['cache']->store('array')->flush();
        $this->assertGreaterThan(0, DB::table('cache')->count(), 'Export limits must survive a request-local cache reset.');
        $this->getJson('/api/merchant/records/export?mode=transactions')->assertStatus(429)->assertHeader('Retry-After');
        $this->getJson('/api/merchant/records?mode=transactions')->assertOk();
        $this->withoutToken()->getJson('/api/merchant/records/export')->assertUnauthorized();
        $this->assertSame(3, DB::table('eis_audit_events')->where('event', 'merchant.records.export')->count());
    }

    public function test_merchant_records_never_accepts_unverified_or_certification_status_as_bir_acknowledgment(): void
    {
        $this->recordsInvoice();
        DB::table('eis_invoice_payloads')->insert(['id' => 'fake-payload', 'merchant_id' => 'm', 'source_document_type' => 'invoice', 'source_document_id' => 'records-invoice', 'payload' => '{}', 'payload_hash' => 'synthetic-only']);
        DB::table('eis_transmissions')->insert(['id' => 'fake-transmission', 'merchant_id' => 'm', 'payload_id' => 'fake-payload', 'status' => 'accepted', 'environment' => 'production', 'submitted_at' => now(), 'bir_reference_number' => 'CERT-SYNTHETIC-ONLY', 'payload_hash' => 'synthetic-only']);
        config(['bir_compliance.transport_implemented' => true, 'bir_compliance.official_interface_verified' => true, 'bir_compliance.production_response_verification_implemented' => true]);
        $this->withToken($this->owner->api_token)->getJson('/api/merchant/records?mode=documents')->assertOk()->assertJsonPath('summary.acknowledged', 0)->assertJsonPath('data.0.bir_status', 'unverified');
        $this->getJson('/api/merchant/records?mode=documents&bir=acknowledged')->assertOk()->assertJsonPath('pagination.total', 0);
        DB::table('eis_transmissions')->where('id', 'fake-transmission')->update(['environment' => 'certification']);
        $this->getJson('/api/merchant/records?mode=documents&bir=test')->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('data.0.bir_status', 'test');
    }

    public function test_electronic_invoice_view_preserves_source_tax_amounts_and_unknown_metadata(): void
    {
        $this->recordsInvoice();
        DB::table('business_invoices')->where('id', 'records-invoice')->update(['document_type' => 'vat_exempt_invoice', 'subtotal_amount' => 1000, 'total_amount' => 1000, 'taxable_amount' => 0, 'vat_amount' => 0, 'vat_exempt_amount' => 1000, 'amount_due' => 1000]);
        DB::table('business_invoice_items')->where('invoice_id', 'records-invoice')->update(['unit_price' => 1000, 'line_total' => 1000, 'tax_type' => 'vat_exempt', 'vat_amount' => 0, 'taxable_amount' => 0]);
        $before = DB::table('business_invoices')->where('id', 'records-invoice')->first();
        $this->withToken($this->owner->api_token)->getJson('/api/business-invoices/records-invoice')->assertOk()
            ->assertJsonPath('invoice.document_view.amount', 1000)->assertJsonPath('invoice.document_view.vat', 0)
            ->assertJsonPath('invoice.document_view.vat_exempt', 1000)->assertJsonPath('invoice.document_view.items.0.amount', 1000)
            ->assertJsonPath('invoice.document_view.seller_business_style', null)->assertJsonPath('invoice.document_view.payment_method', null)
            ->assertJsonPath('invoice.document_view.verification_url', null)->assertJsonPath('invoice.document_view.date_precision', 'day')
            ->assertJsonPath('invoice.document_view.issued_at', '2026-10-16')->assertJsonPath('invoice.document_view.bir_status', 'not_verified');
        $this->assertEquals($before, DB::table('business_invoices')->where('id', 'records-invoice')->first());
        $this->getJson('/api/merchant/records/invoice:records-invoice?mode=documents')->assertOk()->assertJsonPath('document.vat_exempt', 1000)->assertJsonPath('document.amount', 1000);
    }

    public function test_electronic_invoice_view_distinguishes_document_total_withholding_and_settlements(): void
    {
        $this->recordsInvoice();
        DB::table('business_invoices')->where('id', 'records-invoice')->update(['withholding_tax' => 2, 'amount_paid' => 50, 'amount_due' => 60]);
        $this->withToken($this->owner->api_token)->getJson('/api/business-invoices/records-invoice')->assertOk()
            ->assertJsonPath('invoice.document_view.amount', 112)->assertJsonPath('invoice.document_view.net_payable', 110)
            ->assertJsonPath('invoice.document_view.amount_paid', 50)->assertJsonPath('invoice.document_view.balance_due', 60)->assertJsonPath('invoice.document_view.settlement_validation_passed', true);
        $this->getJson('/api/business-invoices/records-invoice?render=pdf')->assertOk();
        $this->assertSame(1, DB::table('eis_audit_events')->where('event', 'merchant.document.render.authorized')->count());
        $this->assertTrue(app(BirCompliance::class)->auditIntegrity());
    }

    public function test_electronic_document_view_keeps_drafts_and_inconsistent_originals_honest(): void
    {
        $this->recordsInvoice('view-draft', 'draft');
        $this->withToken($this->owner->api_token)->getJson('/api/business-invoices/view-draft')->assertOk()
            ->assertJsonPath('invoice.document_view.record_status', 'draft')->assertJsonPath('invoice.document_view.financial_validation_passed', null)
            ->assertJsonCount(0, 'invoice.document_view.history');
        $this->recordsInvoice();
        DB::table('business_invoices')->where('id', 'records-invoice')->update(['total_amount' => 999]);
        $this->getJson('/api/business-invoices/records-invoice')->assertOk()->assertJsonPath('invoice.document_view.amount', 999)->assertJsonPath('invoice.document_view.financial_validation_passed', false)->assertJsonPath('invoice.document_view.settlement_validation_passed', false);
        $this->assertSame(999.0, (float) DB::table('business_invoices')->where('id', 'records-invoice')->value('total_amount'));
    }

    public function test_electronic_document_view_excludes_foreign_line_items_and_payment_metadata(): void
    {
        $this->recordsInvoice();
        DB::table('merchants')->insert(['id' => 'foreign-view', 'business_name' => 'Foreign seller']);
        DB::table('business_invoice_items')->insert(['id' => 'foreign-view-line', 'invoice_id' => 'records-invoice', 'merchant_id' => 'foreign-view', 'line_number' => 9, 'description' => 'SECRET FOREIGN LINE', 'quantity' => 1, 'unit_price' => 900000, 'line_total' => 900000]);
        $response = $this->withToken($this->owner->api_token)->getJson('/api/business-invoices/records-invoice')->assertOk()->assertJsonCount(1, 'invoice.document_view.items')->assertJsonCount(1, 'invoice.line_items');
        $this->assertStringNotContainsString('SECRET FOREIGN', $response->getContent());
        DB::table('merchant_transactions')->insert(['id' => 'foreign-view-payment', 'merchant_id' => 'foreign-view', 'transaction_ref' => 'SECRET FOREIGN REF', 'payment_method' => 'SECRET FOREIGN METHOD', 'amount' => 900000]);
        DB::table('transaction_receipts')->insert(['id' => 'own-view-receipt', 'merchant_id' => 'm', 'transaction_id' => 'foreign-view-payment', 'receipt_number' => 'OWN-VIEW-RECEIPT', 'merchant_name' => 'Own seller', 'merchant_tin' => '123456789', 'document_type' => 'payment_receipt', 'status' => 'issued', 'total_due' => 12, 'merchant_vat_reg' => 'Non-VAT']);
        $response = $this->getJson('/api/merchant/records/receipt:own-view-receipt?mode=documents')->assertOk()->assertJsonPath('document.payment_method', null)->assertJsonPath('document.reference', null)->assertJsonPath('document.vat_registered', false)->assertJsonPath('document.category', 'receipts')->assertJsonCount(0, 'document.items');
        $this->assertStringNotContainsString('SECRET FOREIGN', $response->getContent());
    }
}
