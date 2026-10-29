<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BirCompliance;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RmcComplianceTest extends TestCase
{
    private User $admin;
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'app.timezone' => 'Asia/Manila']);
        DB::purge('sqlite');
        foreach (glob(database_path('migrations/*.php')) as $migrationFile) { (require $migrationFile)->up(); }$this->travelTo(\Carbon\Carbon::parse('2026-10-15 10:24:32', 'Asia/Manila'));
        $this->admin = $this->user('super_admin');
    }
    private function user(string $role): User
    {
        $user = User::factory()->create(['api_token' => fake()->uuid()]);
        DB::table('profiles')->insert(['id' => $user->id, 'email' => $user->email, 'role' => $role]);
        return $user;
    }
    private function payload(array $patch = []): array
    {
        return array_replace(['invoice_number' => 'TEST-001', 'seller_tin' => '123456789', 'issue_date' => '2026-10-15', 'document_type' => 'sales_invoice', 'total_due' => 112, 'is_test' => true], $patch);
    }
    private function queue(array $patch = []): string
    {
        return $this->withToken($this->admin->api_token)->postJson('/api/bir-eis/transmissions', ['payload' => $this->payload($patch)])->assertStatus(202)->json('transmission.id');
    }
    private function application(): string
    {
        return $this->withToken($this->admin->api_token)->postJson('/api/esp-compliance/applications', [])->assertCreated()->json('id');
    }
    private function financialRow(string $id, string $date, string $status = 'completed', string $name = 'Example taxpayer'): void
    {
        if (! DB::table('merchants')->where('id', 'merchant')->exists()) DB::table('merchants')->insert(['id' => 'merchant', 'business_name' => $name, 'tin' => '123-456-789-000']);
        DB::table('transaction_receipts')->insert(['id' => 'r-'.$id, 'transaction_id' => $id, 'merchant_id' => 'merchant', 'receipt_number' => $id, 'merchant_name' => $name, 'merchant_tin' => '123456789', 'status' => 'issued']);
        DB::table('merchant_transactions')->insert(['id' => $id, 'merchant_id' => 'merchant', 'receipt_id' => 'r-'.$id, 'transaction_ref' => $id, 'status' => $status, 'amount' => 112, 'created_at' => $date]);
    }
    public function test_monitor_exports_and_controls_require_administrative_access(): void
    {
        $this->getJson('/api/live-monitoring')->assertUnauthorized();
        $this->getJson('/api/live-monitoring/export')->assertUnauthorized();
        $this->getJson('/api/esp-compliance')->assertUnauthorized();
        $this->getJson('/api/live-monitoring/audit')->assertUnauthorized();
        $client = $this->user('client');
        $this->withToken($client->api_token)->getJson('/api/live-monitoring')->assertForbidden();
        $this->withToken($client->api_token)->postJson('/api/bir-eis/transmissions', ['payload' => $this->payload()])->assertForbidden();
    }
    public function test_real_time_window_uses_manila_midnight_and_excludes_future_and_old_rows(): void
    {
        $this->financialRow('yesterday', '2026-10-14 23:59:59');
        $this->financialRow('midnight', '2026-10-15 00:00:01');
        $this->financialRow('recent', '2026-10-15 10:24:20');
        $this->financialRow('failed', '2026-10-15 10:24:25', 'failed');
        $this->financialRow('pending', '2026-10-15 10:24:26', 'pending');
        $this->financialRow('future', '2026-10-16 00:00:00');
        $r = $this->withToken($this->admin->api_token)->getJson('/api/live-monitoring')->assertOk()
            ->assertJsonPath('today.total', 4)->assertJsonPath('today.successful', 2)->assertJsonPath('today.failed', 1)->assertJsonPath('today.pending', 1)
            ->assertJsonPath('tps_count', 3)->assertJsonPath('queue.production', 0)->assertJsonPath('health.bir_gateway', 'not_connected')->assertJsonPath('production.ready', false)->assertJsonCount(60, 'flow');
        $this->assertSame(1, array_sum(array_column($r->json('flow'), 'successful')));
        $this->assertSame(5, $r->json('pagination.total'));
        $this->assertStringNotContainsString('"reference":"future"', $r->getContent());
        $this->withToken($this->admin->api_token)->getJson('/api/live-monitoring?search=123456789000&status=failed')->assertOk()->assertJsonCount(1, 'transactions')->assertJsonPath('transactions.0.id', 'failed');
    }
    public function test_old_records_are_not_replayed_as_current_flow_or_fake_activity(): void
    {
        $this->financialRow('old', '2026-09-01 10:00:00');
        $r = $this->withToken($this->admin->api_token)->getJson('/api/live-monitoring')->assertOk()->assertJsonPath('today.total', 0)->assertJsonPath('tps', 0)->assertJsonPath('transactions.0.bir_status', 'not_submitted');
        $this->assertSame(0, array_sum(array_column($r->json('flow'), 'successful')));
    }
    public function test_unapproved_production_and_unmarked_certification_cannot_create_records(): void
    {
        $this->withToken($this->admin->api_token)->postJson('/api/bir-eis/transmissions', ['environment' => 'production', 'payload' => $this->payload()])->assertStatus(409);
        $this->withToken($this->admin->api_token)->postJson('/api/bir-eis/transmissions', ['payload' => $this->payload(['is_test' => false])])->assertStatus(422);
        $this->assertDatabaseCount('eis_transmissions', 0);
        $this->withToken($this->admin->api_token)->getJson('/api/bir-eis/readiness')->assertOk()->assertJsonPath('readiness.can_submit_to_bir', false);
    }
    public function test_identity_idempotency_conflict_encryption_and_original_deadline(): void
    {
        $id = $this->queue();
        $this->withToken($this->admin->api_token)->postJson('/api/bir-eis/transmissions', ['payload' => array_reverse($this->payload())])->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('transmission.id', $id);
        $this->withToken($this->admin->api_token)->postJson('/api/bir-eis/transmissions', ['payload' => $this->payload(['total_due' => 224])])->assertStatus(409);
        $this->assertDatabaseCount('eis_transmissions', 1); $this->assertDatabaseCount('eis_invoice_payloads', 1); $this->assertDatabaseCount('eis_retry_queue', 1);
        $row = DB::table('eis_invoice_payloads')->first();
        $this->assertArrayHasKey('encrypted', json_decode($row->payload, true));
        $this->assertSame('TEST-001', json_decode(Crypt::decryptString(json_decode($row->payload, true)['encrypted']), true)['invoice_number']);
        $this->assertSame('2026-10-18 23:59:59', $row->due_at);
        $this->assertTrue(app(BirCompliance::class)->auditIntegrity());
    }
    public function test_certification_acknowledgement_is_explicit_simulated_and_terminal_idempotent(): void
    {
        $this->financialRow('TEST-001', '2026-10-15 10:24:20');
        DB::table('merchants')->where('id', 'merchant')->update(['tin' => '123456789']);
        $id = $this->queue();
        $url = '/api/bir-eis/transmissions/'.$id.'/acknowledge';
        $this->withToken($this->admin->api_token)->postJson($url, [])->assertStatus(422);
        $this->withToken($this->admin->api_token)->postJson($url, ['is_test' => true])->assertOk()->assertJsonPath('transmission.simulated', true);
        $this->withToken($this->admin->api_token)->postJson($url, ['is_test' => true])->assertOk();
        $this->assertDatabaseCount('eis_acknowledgements', 1);
        $this->withToken($this->admin->api_token)->getJson('/api/live-monitoring')->assertOk()->assertJsonPath('transactions.0.test', true);
        $this->withToken($this->admin->api_token)->postJson('/api/bir-eis/transmissions/'.$id.'/retry', [])->assertStatus(409);
        DB::table('eis_transmissions')->where('id', $id)->update(['environment' => 'production']);
        $this->withToken($this->admin->api_token)->postJson($url, ['is_test' => true])->assertStatus(409);
    }
    public function test_retry_is_bounded_and_preserves_original_and_recovery_deadlines(): void
    {
        $id = $this->queue(['issue_date' => '2026-10-12']);
        $url = '/api/bir-eis/transmissions/'.$id.'/retry';
        $this->withToken($this->admin->api_token)->postJson($url, [])->assertOk();
        $this->assertSame(1, DB::table('eis_retry_queue')->count());
        DB::table('eis_transmissions')->where('id', $id)->update(['status' => 'failed', 'response_code' => '504']);
        $incident = $this->withToken($this->admin->api_token)->postJson('/api/esp-compliance/incidents', ['title' => 'Test outage', 'severity' => 'critical', 'detected_at' => '2026-10-14T08:00:00+08:00', 'evidence_reference' => 'test-fixture-evidence'])->assertCreated()->assertJsonPath('report_due_at', '2026-10-15T08:00:00+08:00')->json('id');
        $this->withToken($this->admin->api_token)->postJson($url, ['incident_id' => $incident])->assertStatus(422);
        $this->withToken($this->admin->api_token)->patchJson('/api/esp-compliance/incidents/'.$incident, ['restored_at' => '2026-10-15T08:00:00+08:00'])->assertOk();
        $this->withToken($this->admin->api_token)->postJson($url, ['incident_id' => $incident])->assertOk();
        $row = DB::table('eis_transmissions')->where('id', $id)->first();
        $this->assertSame(2, $row->attempt_number); $this->assertSame('2026-10-15 23:59:59', $row->due_at); $this->assertSame('2026-10-18 23:59:59', $row->recovery_due_at);
        DB::table('eis_transmissions')->where('id', $id)->update(['status' => 'failed', 'attempt_number' => 5]);
        $this->withToken($this->admin->api_token)->postJson($url, [])->assertStatus(409);
        DB::table('eis_transmissions')->where('id', $id)->update(['attempt_number' => 2, 'response_code' => '422']);
        $this->withToken($this->admin->api_token)->postJson($url, [])->assertStatus(409);
    }
    public function test_annex_has_17_controls_and_stage_progression_requires_verified_prior_stages(): void
    {
        $id = $this->application();
        $this->withToken($this->admin->api_token)->getJson('/api/esp-compliance')->assertOk()->assertJsonCount(17, 'annex')->assertJsonCount(7, 'stages')->assertJsonPath('authorized', false);
        $body = ['category' => 'stages', 'code' => '02', 'status' => 'passed', 'evidence_reference' => 'external-test-review'];
        $this->withToken($this->admin->api_token)->postJson('/api/esp-compliance/applications/'.$id.'/reviews', $body)->assertForbidden();
        $reviewer = $this->user('bir');
        $this->withToken($reviewer->api_token)->postJson('/api/esp-compliance/applications/'.$id.'/reviews', $body)->assertStatus(409);
        DB::table('esp_accreditation_controls')->where('accreditation_id', $id)->where('category', 'annex')->update(['status' => 'passed', 'evidence_reference' => 'external-test-docs', 'verified_by' => $reviewer->id, 'verified_at' => now()]);
        $this->withToken($reviewer->api_token)->postJson('/api/esp-compliance/applications/'.$id.'/reviews', $body)->assertStatus(409);
        $this->withToken($reviewer->api_token)->postJson('/api/esp-compliance/applications/'.$id.'/reviews', [...$body, 'code' => '01'])->assertOk();
        $this->withToken($reviewer->api_token)->postJson('/api/esp-compliance/applications/'.$id.'/reviews', $body)->assertOk();
        $this->withToken($this->admin->api_token)->postJson('/api/esp-compliance/applications/'.$id.'/evidence', ['category' => 'annex', 'code' => '08', 'evidence_reference' => 'revised-jv-document'])->assertOk();
        $this->assertSame(0, DB::table('esp_accreditation_controls')->where('category', 'stages')->where('status', 'passed')->count());
        $this->assertTrue(app(BirCompliance::class)->auditIntegrity());
    }
    public function test_pti_does_not_follow_from_cas_ptu_and_transport_is_not_a_credentials_flag(): void
    {
        $integration = DB::table('eis_integrations')->first();
        DB::table('eis_integrations')->where('id', $integration->id)->update(['cas_ptu_number' => 'TEST-CAS-PTU', 'credentials_present' => true, 'public_key_fingerprint' => 'test-key', 'ptt_status' => 'approved', 'permit_to_transmit_number' => 'TEST-PTT']);
        $gate = app(BirCompliance::class)->gate(DB::table('eis_integrations')->first());
        $this->assertFalse($gate['can_transmit']); $this->assertFalse($gate['transport_ready']);
        $this->assertStringContainsString('PTI', implode(' ', $gate['blockers']));
    }
    public function test_append_only_journal_detects_modification_and_tail_deletion(): void
    {
        $policy = app(BirCompliance::class); $policy->audit('test.first', null, 'test'); $policy->audit('test.second', null, 'test');
        $this->assertTrue($policy->auditIntegrity());
        $this->withToken($this->admin->api_token)->getJson('/api/live-monitoring/audit?search=test.first')->assertOk()->assertJsonPath('integrity', true)->assertJsonCount(1, 'events');
        $export = $this->withToken($this->admin->api_token)->get('/api/live-monitoring/audit?download=1')->assertOk()->streamedContent();
        // The export freezes its cutoff before recording the export audit event.
        $this->assertCount(2, json_decode($export, true, flags: JSON_THROW_ON_ERROR)['events']);
        $this->assertSame(3, DB::table('eis_audit_events')->count());
        $row = DB::table('eis_audit_events')->orderBy('id')->first();
        DB::table('eis_audit_events')->where('id', $row->id)->update(['event' => 'tampered']);
        $this->assertFalse($policy->auditIntegrity());
        DB::table('eis_audit_events')->where('id', $row->id)->update(['event' => $row->event]);
        DB::table('eis_audit_events')->where('id', '>', $row->id)->delete();
        $this->assertFalse($policy->auditIntegrity());
    }
    public function test_exports_are_machine_readable_decrypt_authorized_data_and_escape_csv_formulas(): void
    {
        $this->financialRow('TEST-001', '2026-10-15 10:24:20', 'completed', '=FORMULA()'); $this->queue();
        $json = $this->withToken($this->admin->api_token)->get('/api/live-monitoring/export?format=json')->assertOk()->streamedContent();
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('TEST-001', $data['payloads'][0]['payload']['invoice_number']);
        $this->assertCount(1, $data['source_transactions']); $this->assertCount(1, $data['receipts']); $this->assertNotEmpty($data['audit_events']);
        $csv = $this->withToken($this->admin->api_token)->get('/api/live-monitoring/export?format=csv')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=FORMULA()", $csv);
        $empty = $this->withToken($this->admin->api_token)->get('/api/live-monitoring/export?format=csv&search=no-match')->assertOk()->streamedContent();
        $this->assertStringContainsString('receipt_number', $empty);
    }
    public function test_financial_record_deletion_and_registry_match_do_not_forge_verification(): void
    {
        $this->financialRow('TEST-001', '2026-10-15 10:24:20');
        $this->withToken($this->admin->api_token)->deleteJson('/api/tables/merchant_transactions', ['id' => 'TEST-001'])->assertStatus(409);
        $this->getJson('/api/verify-receipt/TEST-001')->assertOk()->assertJsonPath('receipt.signature_valid', null);
        $this->assertDatabaseCount('merchant_transactions', 1);
    }
    public function test_verified_solution_scope_expiry_annual_review_and_taxpayer_permits_are_independent(): void
    {
        $id = $this->application();
        config(['bir_compliance.configuration_reference' => 'test-approved-deployment']);
        DB::table('esp_accreditation_controls')->where('accreditation_id', $id)->update(['status' => 'passed', 'evidence_reference' => 'external-fixture-only', 'verified_by' => $this->admin->id, 'verified_at' => now()]);
        DB::table('esp_accreditations')->where('id', $id)->update(['status' => 'accredited', 'certificate_number' => 'FIXTURE-NOT-REAL', 'issued_at' => '2026-01-01', 'expires_at' => '2031-01-01', 'annual_review_at' => '2026-01-01', 'evidence_reference' => 'external-fixture-only', 'verified_by' => $this->admin->id, 'verified_at' => now(), 'configuration_reference' => 'test-approved-deployment']);
        $policy = app(BirCompliance::class);
        $this->assertTrue($policy->accreditation($id)['authorized']);
        $integrationId = DB::table('eis_integrations')->value('id');
        DB::table('eis_integrations')->where('id', $integrationId)->update(['esp_accreditation_id' => $id, 'pti_status' => 'approved', 'permit_to_issue_number' => 'TEST-PTI', 'taxpayer_authorization_reference' => 'test-authority', 'written_authorization_at' => now()]);
        // Integration flags alone cannot replace maintained branch/environment-specific permit evidence.
        $this->assertFalse($policy->gate(DB::table('eis_integrations')->first(), false)['can_issue']);
        $this->assertFalse($policy->gate(DB::table('eis_integrations')->first())['can_transmit']);
        $merchant = $this->user('merchant');
        DB::table('merchants')->insert(['id' => 'seller', 'business_name' => 'Test seller', 'merchant_account_id' => $merchant->id, 'tin' => '123456789','status'=>'active']);
        // Synthetic funds satisfy the configurable minimum-zero wallet gate.
        DB::transaction(fn () => app(\App\Services\MerchantWallet::class)->credit('seller', 600, (string) \Illuminate\Support\Str::uuid(), 'Synthetic settled test funds', 'test-fixture'));
        DB::table('eis_integrations')->where('id', $integrationId)->update(['merchant_id'=>'seller','environment'=>'production','branch_code'=>'00000']);
        DB::table('taxpayer_authorizations')->insert(['id'=>'fixture-pti','request_id'=>fake()->uuid(),'request_hash'=>str_repeat('a',64),'merchant_id'=>'seller','kind'=>'PTI','reference'=>'TEST-PTI','environment'=>'production','branch_code'=>'00000','scope'=>'Synthetic branch fixture only','issued_at'=>'2026-01-01','valid_until'=>'2027-01-01','status'=>'active','evidence'=>'Synthetic evidence not real','reason'=>'Isolated fixture only','reviewed_by'=>$this->admin->id,'reviewed_at'=>now()]);
        DB::table('invoice_series')->insert(['id'=>'fixture-series','merchant_id'=>'seller','branch_code'=>'00000','document_family'=>'invoice','prefix'=>'TEST-','range_start'=>1,'range_end'=>99,'status'=>'active','authority_reference'=>'Synthetic evidence not real','verified_by'=>$this->admin->id,'valid_from'=>'2026-01-01','valid_until'=>'2027-01-01','created_at'=>now(),'updated_at'=>now()]);
        $invoice = ['request_id'=>fake()->uuid(),'invoice_number' => 'CLIENT-PROPOSED-NUMBER', 'document_type' => 'sales_invoice', 'buyer_name' => 'Test buyer', 'issue_date' => '2026-10-15', 'status' => 'issued', 'line_items' => [['line_number' => 1, 'description' => 'Test item', 'quantity' => 1, 'unit_price' => 10000, 'line_total' => 10000]]];
        $invoiceId = $this->withToken($merchant->api_token)->postJson('/api/business-invoices', $invoice)->assertCreated()->assertJsonPath('invoice.permit_to_issue_number', 'TEST-PTI')->assertJsonPath('invoice.esp_accreditation_number', 'FIXTURE-NOT-REAL')->json('invoice.id');
        $this->postJson('/api/business-invoices',$invoice)->assertOk()->assertJsonPath('invoice.id',$invoiceId);
        $this->assertSame(1,DB::table('business_invoices')->where('merchant_id','seller')->count());
        $this->assertSame(1,DB::table('merchant_customers')->where('merchant_id','seller')->count());
        DB::table('eis_integrations')->where('id', $integrationId)->update(['permit_to_issue_number' => 'CHANGED-PTI']);
        $this->withToken($merchant->api_token)->getJson('/api/business-invoices/'.$invoiceId)->assertOk()->assertJsonPath('invoice.permit_to_issue_number', 'TEST-PTI');
        $this->assertTrue($policy->auditIntegrity());
        DB::table('esp_accreditations')->where('id', $id)->update(['material_change_pending' => true]);
        $this->assertFalse($policy->accreditation($id)['authorized']);
        DB::table('esp_accreditations')->where('id', $id)->update(['material_change_pending' => false, 'annual_review_at' => '2024-01-01']);
        $this->assertFalse($policy->accreditation($id)['authorized']);
        DB::table('esp_accreditations')->where('id', $id)->update(['annual_review_at' => '2026-01-01', 'expires_at' => '2026-11-15']);
        $this->assertTrue($policy->accreditation($id)['renewal_due']);
        $this->assertSame('2026-09-16', $policy->accreditation($id)['renewal_due_at']);
        config(['bir_compliance.solution_version' => 'unapproved-version']);
        $this->assertFalse($policy->accreditation($id)['authorized']);
    }
    public function test_drafts_are_allowed_but_do_not_mirror_an_issued_buyer_expense(): void
    {
        $merchant = $this->user('merchant');
        DB::table('merchants')->insert(['id' => 'seller', 'business_name' => 'Test seller', 'merchant_account_id' => $merchant->id, 'tin' => '123456789']);
        // Synthetic funds satisfy the configurable minimum-zero wallet gate.
        DB::transaction(fn () => app(\App\Services\MerchantWallet::class)->credit('seller', 600, (string) \Illuminate\Support\Str::uuid(), 'Synthetic settled test funds', 'test-fixture'));
        $invoice = ['invoice_number' => 'TEST-DRAFT', 'document_type' => 'sales_invoice', 'buyer_name' => 'Test buyer', 'buyer_tin' => '987654321', 'issue_date' => '2026-10-15', 'status' => 'draft', 'line_items' => [['line_number' => 1, 'description' => 'Test item', 'quantity' => 1, 'unit_price' => 10000, 'line_total' => 10000]]];
        $this->withToken($merchant->api_token)->postJson('/api/business-invoices', $invoice)->assertCreated()->assertJsonPath('invoice.status', 'draft');
        $this->withToken($merchant->api_token)->postJson('/api/business-invoices', [...$invoice, 'invoice_number' => 'TEST-LIVE', 'status' => 'issued'])->assertStatus(409);
        $this->assertDatabaseCount('business_invoices', 1);
        $this->assertDatabaseCount('merchant_transactions', 0);
    }

    public function test_public_signup_and_profile_writes_cannot_forge_bir_review_authority(): void
    {
        $this->postJson('/api/auth/signup', ['email' => 'fake-reviewer@example.test', 'password' => 'test-password', 'meta' => ['role' => 'bir']])->assertStatus(422);
        $this->patchJson('/api/tables/profiles', ['values' => ['role' => 'bir']])->assertUnauthorized();
        $client = $this->user('client');
        $this->withToken($client->api_token)->patchJson('/api/tables/profiles', ['values' => ['role' => 'bir']])->assertForbidden();
        $this->withToken($this->admin->api_token)->patchJson('/api/tables/profiles', ['values' => ['role' => 'bir']])->assertForbidden();
        $this->withToken($client->api_token)->patchJson('/api/tables/profiles', ['values' => ['full_name' => 'Only own profile']])->assertOk()->assertJsonPath('count', 1);
        $this->assertDatabaseHas('profiles', ['id' => $this->admin->id, 'role' => 'super_admin']);
    }

}
