<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Eis\Protocol;
use App\Services\Eis\WireBatches;
use App\Services\Eis\WireClient;
use App\Services\SubmissionEvidence;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class BirWireTransportTest extends TestCase
{
    private User $admin;

    private string $merchant;

    private string $private = '';

    private string $public;

    private string $session = '0123456789ABCDEFGHIJKLMNOPQRSTUV';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array', 'app.timezone' => 'Asia/Manila', 'bir_eis.outbound_enabled' => false]);
        DB::purge('sqlite');
        foreach (glob(database_path('migrations/*.php')) as $file) {
            (require $file)->up();
        }Http::preventStrayRequests();
        $this->admin = $this->actor('super_admin');
        $this->merchant = (string) Str::uuid();
        DB::table('merchants')->insert(['id' => $this->merchant, 'business_name' => 'Synthetic BIR lab', 'tin' => '123456789000', 'address' => 'Synthetic test address', 'status' => 'active']);
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $this->private);
        $this->public = openssl_pkey_get_details($key)['key'];
        $this->withToken($this->admin->api_token);
    }

    private function actor(string $role): User
    {
        $u = User::factory()->create(['api_token' => Str::random(64)]);
        DB::table('profiles')->insert(['id' => $u->id, 'email' => $u->email, 'role' => $role]);

        return $u;
    }

    private function form(): array
    {
        return ['revision' => 0, 'name' => 'Synthetic certification', 'merchant_id' => $this->merchant, 'branch_code' => '00000', 'environment' => 'certification', 'system_type' => 'CAS', 'seller_type' => '0', 'accreditation_id' => '20146IT4', 'application_id' => 'lab-app', 'ptu_number' => 'LAB-ONLY', 'machine_id' => null, 'machine_serial' => null, 'schema_dialect' => 'workbook_20260926', 'authority_reference' => 'Synthetic fixture authority only', 'contract_reference' => 'Synthetic contract review only', 'source_copies_authorized' => false, 'enabled' => true, 'reason' => 'Prepare isolated integration tests'];
    }

    private function profile(): object
    {
        $p = $this->postJson('/api/admin/bir-wire/profiles', $this->form())->assertOk()->json('profile');
        $body = ['revision' => $p['revision'], 'user_id' => 'testuser', 'password' => 'LabOnly-Password', 'application_key' => 'LabOnly-Application-Key-123456789', 'bir_public_key' => $this->public, 'signing_private_key' => $this->private, 'key_id' => 'lab-key', 'key_expires_at' => now()->addMonth()->toIso8601String(), 'reason' => 'Synthetic test credential bundle'];
        $this->postJson('/api/admin/bir-wire/profiles/'.$p['id'].'/credentials', $body)->assertOk();

        return DB::table('bir_eis_profiles')->find($p['id']);
    }

    private function batch(object $p): object
    {
        $id = $this->postJson('/api/admin/bir-wire/profiles/'.$p->id.'/fixture', ['request_id' => (string) Str::uuid()])->assertOk()->json('id');

        return DB::table('bir_eis_batches')->find($id);
    }

    private function fakeBir(string $result = 'SUC001'): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($r) use ($result) {
            $path = parse_url($r->url(), PHP_URL_PATH);
            $date = $r->header('datetime')[0];
            if (str_ends_with($path, '/authentication')) {
                $this->assertSame(Protocol::authorization($date, 'POST', $path, 'LabOnly-Application-Key-123456789'), $r->header('authorization')[0]);
                openssl_private_decrypt(base64_decode($r['data']), $raw, $this->private, OPENSSL_PKCS1_PADDING);
                $body = json_decode($raw, true);
                $this->assertSame('testuser', $body['userId']);
                $this->assertSame('LabOnly-Password', $body['password']);

                return Http::response(['status' => '1', 'data' => Protocol::encrypt(Protocol::json(['accreditationId' => '20146IT4', 'userId' => 'testuser', 'authToken' => str_repeat('T', 32), 'sessionKey' => $this->session, 'tokenExpiry' => now('Asia/Manila')->addHours(6)->format('Y-m-d\TH:i:s')]), $body['authKey'])]);
            }
            $this->assertSame(Protocol::authorization($date, $r->method(), $path, $this->session), $r->header('authorization')[0]);
            if (str_ends_with($path, '/invoices')) {
                $jws = explode(',', Protocol::decrypt($r['data'], $this->session));
                $this->assertCount(1, $jws);
                [$h,$payload,$signature] = explode('.', $jws[0]);
                $this->assertSame(1, openssl_verify($h.'.'.$payload, base64_decode(strtr($signature, '-_', '+/')), $this->public, OPENSSL_ALGO_SHA256));
                $data = $this->identity($r['submitId']);

                return Http::response(['status' => '1', 'data' => Protocol::encrypt(Protocol::json($data), $this->session)]);
            }
            $submit = basename($path);
            $batch = DB::table('bir_eis_batches')->where('submit_id', $submit)->first();
            $docs = DB::table('bir_eis_documents')->where('batch_id', $batch->id)->get();

            return Http::response(['status' => '1', 'data' => $this->identity($submit) + ['processStatusCode' => '01', 'totalCountQuantity' => $docs->count(), 'successCountQuantity' => $result === 'SUC001' ? $docs->count() : 0, 'failCountQuantity' => $result === 'SUC001' ? 0 : $docs->count(), 'processedDocuments' => $docs->map(fn ($d) => ['invoiceUid' => $d->invoice_uid, 'resultStatusCode' => $result])->all()]]);
        });
    }

    private function identity(string $submit): array
    {
        return ['accreditationId' => '20146IT4', 'userId' => 'testuser', 'refSubmitId' => $submit, 'ackId' => 'BIR-20261028120000-AB123', 'responseDtm' => '2026-10-28T12:00:00'];
    }

    public function test_configuration_and_credentials_are_private_and_do_not_send_requests(): void
    {
        $p = $this->profile();
        $this->assertStringNotContainsString('LabOnly', $p->encrypted_credentials);
        $this->assertStringNotContainsString('PRIVATE KEY', $p->encrypted_credentials);
        $r = $this->getJson('/api/admin/bir-wire')->assertOk();
        $this->assertStringNotContainsString('encrypted_credentials', $r->getContent());
        $this->assertStringNotContainsString('LabOnly', $r->getContent());
        foreach (['merchant', 'client', 'bir', 'admin'] as $role) {
            $u = $this->actor($role);
            $this->withToken($u->api_token)->getJson('/api/admin/bir-wire')->assertForbidden();
            $this->postJson('/api/admin/bir-wire/profiles/'.$p->id.'/authenticate')->assertForbidden();
        }
        Http::assertNothingSent();
    }

    public function test_disabled_deployment_cannot_authenticate_or_release_even_with_valid_credentials(): void
    {
        $p = $this->profile();
        $b = $this->batch($p);
        $this->postJson('/api/admin/bir-wire/profiles/'.$p->id.'/authenticate')->assertConflict();
        $this->postJson('/api/admin/bir-wire/batches/'.$b->id.'/release', ['confirm_destination' => 'certification'])->assertConflict();
        Http::assertNothingSent();
    }

    public function test_encrypted_send_receipt_is_not_acceptance_and_final_inquiry_is_isolated_from_production(): void
    {
        $p = $this->profile();
        $b = $this->batch($p);
        config(['bir_eis.outbound_enabled' => true]);
        $this->fakeBir();
        $this->postJson('/api/admin/bir-wire/batches/'.$b->id.'/release', ['confirm_destination' => 'certification'])->assertOk();
        $s = app(WireBatches::class);
        $this->assertSame('processing', $s->runOne()['state']);
        $this->assertNull(DB::table('bir_eis_documents')->where('batch_id', $b->id)->value('result_code'));
        DB::table('bir_eis_batches')->where('id', $b->id)->update(['available_at' => now()->subSecond()]);
        $this->assertSame('accepted', $s->runOne()['state']);
        Http::assertSentCount(3);
        $this->assertSame(0, DB::table('eis_delivery_jobs')->where('state', 'accepted')->count());
        $this->assertSame(0, DB::table('business_invoices')->count());
        $this->assertSame(0, DB::table('merchant_transactions')->count());
        $this->assertSame(1, DB::table('bir_eis_wire_attempts')->where('operation', 'authentication')->whereNull('encrypted_response')->count());
    }

    public function test_a_lost_send_is_inquired_without_a_second_invoice_post(): void
    {
        $p = $this->profile();
        $b = $this->batch($p);
        config(['bir_eis.outbound_enabled' => true]);
        $this->fakeBir();
        app(WireBatches::class)->release($b->id, (string) $this->admin->id);
        app(WireBatches::class)->runOne();
        DB::table('bir_eis_batches')->where('id', $b->id)->update(['state' => 'sending', 'lease_until' => now()->subMinute(), 'available_at' => now()->subSecond()]);
        $this->assertSame('accepted', app(WireBatches::class)->runOne()['state']);
        $this->assertCount(1, Http::recorded(fn ($r) => str_ends_with($r->url(), '/invoices')));
    }

    public function test_duplicate_result_is_rejected_and_unknown_result_never_marks_success(): void
    {
        $p = $this->profile();
        $b = $this->batch($p);
        config(['bir_eis.outbound_enabled' => true]);
        $this->fakeBir('SYN003');
        app(WireBatches::class)->release($b->id, (string) $this->admin->id);
        app(WireBatches::class)->runOne();
        DB::table('bir_eis_batches')->where('id', $b->id)->update(['available_at' => now()->subSecond()]);
        $this->assertSame('rejected', app(WireBatches::class)->runOne()['state']);
    }

    public function test_callback_requires_hmac_and_cannot_publish_final_state_even_with_valid_hmac(): void
    {
        $p = $this->profile();
        $b = $this->batch($p);
        config(['bir_eis.outbound_enabled' => true, 'bir_eis.callback_enabled' => true]);
        $this->fakeBir();
        app(WireBatches::class)->release($b->id, (string) $this->admin->id);
        app(WireBatches::class)->runOne();
        $d = DB::table('bir_eis_documents')->where('batch_id', $b->id)->first();
        $body = $this->identity($b->submit_id) + ['processStatusCode' => '01', 'totalCountQuantity' => 1, 'successCountQuantity' => 1, 'failCountQuantity' => 0, 'processedDocuments' => [['invoiceUid' => $d->invoice_uid, 'resultStatusCode' => 'SUC001']]];
        $url = '/api/bir-eis/receive_invoice_result/'.$p->id;
        $dt = now('Asia/Manila')->format('YmdHis');
        $this->withHeaders(['Authorization' => 'not-valid'])->putJson($url, $body)->assertUnauthorized();
        $headers = ['Authorization' => Protocol::authorization($dt, 'PUT', $url, 'LabOnly-Application-Key-123456789'), 'datetime' => $dt, 'accreditationId' => '20146IT4', 'applicationId' => 'lab-app'];
        $r = $this->withHeaders($headers)->putJson($url, $body)->assertOk();
        $again = $this->putJson($url, $body)->assertOk();
        $this->assertSame($r->json('data.taxpayerAckId'), $again->json('data.taxpayerAckId'));
        $this->assertSame('uncertain', DB::table('bir_eis_batches')->where('id', $b->id)->value('state'));
        $this->assertNull(DB::table('bir_eis_documents')->where('id', $d->id)->value('result_code'));
    }

    public function test_mfa_is_required_for_privileged_configuration(): void
    {
        config(['security.enforce_privileged_mfa' => true]);
        $this->postJson('/api/admin/bir-wire/profiles', $this->form())->assertForbidden()->assertJsonPath('code', 'MFA_REQUIRED');
    }

    public function test_configured_url_cannot_redirect_secrets_to_another_host(): void
    {
        $p = $this->profile();
        config(['bir_eis.outbound_enabled' => true, 'bir_eis.origins.certification' => 'https://example.test']);
        $this->postJson('/api/admin/bir-wire/profiles/'.$p->id.'/authenticate')->assertConflict();
        Http::assertNothingSent();
    }

    private function invoice(string $id = 'inv', ?string $merchant = null): void
    {
        $m = $merchant ?? $this->merchant;
        DB::table('business_invoices')->insert(['id' => $id, 'merchant_id' => $m, 'invoice_number' => 'LAB-'.$id, 'merchant_name' => 'Synthetic BIR lab', 'merchant_tin' => '123456789', 'merchant_address' => 'Synthetic test address', 'seller_branch_code' => '00000', 'document_type' => 'vat_invoice', 'status' => 'sent', 'issue_date' => now('Asia/Manila')->toDateString(), 'total_amount' => 112, 'vat_amount' => 12, 'taxable_amount' => 100, 'buyer_name' => 'Synthetic buyer', 'vat_registered' => true]);
        DB::table('business_invoice_items')->insert(['id' => 'item-'.$id, 'invoice_id' => $id, 'merchant_id' => $m, 'description' => 'Synthetic service', 'quantity' => 1, 'unit_price' => 112, 'line_total' => 112, 'vat_amount' => 12, 'taxable_amount' => 100, 'tax_type' => 'vatable']);
        DB::table('eis_reporting_obligations')->updateOrInsert(['merchant_id' => $m, 'branch_code' => '00000'], ['coverage_status' => 'required', 'effective_from' => now()->subDay()->toDateString(), 'evidence_reference' => 'Synthetic reporting coverage only', 'verified_by' => (string) $this->admin->id, 'verified_at' => now()]);
    }

    private function prepareInvoice(object $p, ?string $request = null, array $documents = ['invoice:inv'])
    {
        return $this->postJson('/api/admin/bir-wire/profiles/'.$p->id.'/prepare', ['request_id' => $request ?? (string) Str::uuid(), 'documents' => $documents, 'metadata' => ['invoice:inv' => ['buyer_branch_code' => '00000', 'buyer_not_registered' => true]]]);
    }

    public function test_production_preparation_is_company_scoped_idempotent_and_authority_gated(): void
    {
        $p = $this->profile();
        DB::table('bir_eis_profiles')->where('id', $p->id)->update(['environment' => 'production']);
        $p = DB::table('bir_eis_profiles')->find($p->id);
        $this->invoice();
        $this->invoice('foreign', 'another-merchant');
        $this->prepareInvoice($p, null, ['invoice:foreign'])->assertUnprocessable();
        $request = (string) Str::uuid();
        $r = $this->prepareInvoice($p, $request)->assertOk();
        $this->prepareInvoice($p, $request)->assertOk()->assertJsonPath('duplicate', true);
        $this->prepareInvoice($p, $request, ['invoice:foreign'])->assertConflict();
        $this->prepareInvoice($p)->assertConflict();
        config(['bir_eis.outbound_enabled' => true]);
        $this->postJson('/api/admin/bir-wire/batches/'.$r->json('id').'/release', ['confirm_destination' => 'production'])->assertConflict();
        $this->postJson('/api/admin/bir-wire/batches/'.$r->json('id').'/cancel', ['reason' => 'Cancel isolated test preparation'])->assertOk();
        $this->travel(61)->seconds();
        $this->prepareInvoice($p)->assertOk();
        Http::assertNothingSent();
    }

    public function test_production_projection_requires_complete_authenticated_invoice_results(): void
    {
        $p = $this->profile();
        DB::table('bir_eis_profiles')->where('id', $p->id)->update(['environment' => 'production']);
        $p = DB::table('bir_eis_profiles')->find($p->id);
        $this->invoice();
        $id = $this->prepareInvoice($p)->assertOk()->json('id');
        // Simulate an already-sent batch to test safe inquiry without fabricating any issuance authority.
        DB::table('bir_eis_batches')->where('id', $id)->update(['state' => 'processing', 'submitted_at' => now(), 'available_at' => now()]);
        config(['bir_eis.outbound_enabled' => true]);
        $this->fakeBir();
        $this->assertSame('accepted', app(WireBatches::class)->runOne()['state']);
        $t = DB::table('eis_transmissions')->where('request_id', $id)->first();
        $this->assertSame($this->merchant, $t->merchant_id);
        $this->assertTrue(app(SubmissionEvidence::class)->verified($t->id));
        $this->getJson('/api/transaction-workspace?bir=acknowledged')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.bir_status', 'acknowledged');
        $this->assertSame(1, DB::table('business_invoices')->count());
        $this->assertSame(0, DB::table('merchant_transactions')->count());
        DB::table('bir_eis_wire_attempts')->where('operation', 'inquiry')->update(['response_hash' => str_repeat('0', 64)]);
        $this->assertFalse(app(SubmissionEvidence::class)->verified($t->id));
        $this->assertCount(0, Http::recorded(fn ($r) => str_ends_with($r->url(), '/invoices')));
    }

    public function test_pause_resume_preserves_revision_and_never_replays_a_submitted_batch(): void
    {
        $p = $this->profile();
        $b = $this->batch($p);
        config(['bir_eis.outbound_enabled' => true]);
        $this->fakeBir();
        app(WireBatches::class)->release($b->id, (string) $this->admin->id);
        app(WireBatches::class)->runOne();
        $this->postJson('/api/admin/bir-wire/profiles/'.$p->id.'/pause', ['reason' => 'Pause isolated test connection'])->assertOk();
        DB::table('bir_eis_batches')->where('id', $b->id)->update(['available_at' => now()->subSecond()]);
        $this->assertSame('blocked', app(WireBatches::class)->runOne()['state']);
        $this->postJson('/api/admin/bir-wire/profiles/'.$p->id.'/resume', ['reason' => 'Resume isolated test connection', 'revision' => $p->revision])->assertOk();
        DB::table('bir_eis_batches')->where('id', $b->id)->update(['available_at' => now()->subSecond()]);
        $this->assertSame('accepted', app(WireBatches::class)->runOne()['state']);
        $this->assertCount(1, Http::recorded(fn ($r) => str_ends_with($r->url(), '/invoices')));
    }

    public function test_certification_profile_cannot_use_the_production_host(): void
    {
        $p = $this->profile();
        config(['bir_eis.outbound_enabled' => true, 'bir_eis.origins.certification' => 'https://eis-api.bir.gov.ph']);
        $this->postJson('/api/admin/bir-wire/profiles/'.$p->id.'/authenticate')->assertConflict();
        Http::assertNothingSent();
    }

    public function test_failed_authentication_leaves_the_batch_unsent_and_cancellable(): void
    {
        $p = $this->profile();
        $b = $this->batch($p);
        config(['bir_eis.outbound_enabled' => true]);
        Http::fake(['*' => Http::response(['status' => '0', 'errorDetails' => ['errorCode' => 'E01']], 200)]);
        app(WireBatches::class)->release($b->id, (string) $this->admin->id);
        $this->assertSame('prepared', app(WireBatches::class)->runOne()['state']);
        $this->assertNull(DB::table('bir_eis_batches')->where('id', $b->id)->value('submitted_at'));
        $this->assertCount(0, Http::recorded(fn ($r) => str_ends_with($r->url(), '/invoices')));
        $this->postJson('/api/admin/bir-wire/batches/'.$b->id.'/cancel', ['reason' => 'Cancel test with invalid credential'])->assertOk();
    }

    public function test_send_timeout_requires_inquiry_and_expired_key_does_not_block_reconciliation(): void
    {
        $p = $this->profile();
        $b = $this->batch($p);
        config(['bir_eis.outbound_enabled' => true]);
        $this->fakeBir();
        app(WireBatches::class)->release($b->id, (string) $this->admin->id);
        app(WireClient::class)->session($p);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::failedConnection()]);
        $this->assertSame('uncertain', app(WireBatches::class)->runOne()['state']);
        $this->assertNotNull(DB::table('bir_eis_batches')->where('id', $b->id)->value('submitted_at'));
        DB::table('bir_eis_profiles')->where('id', $p->id)->update(['key_expires_at' => now()->subDay()]);
        DB::table('bir_eis_batches')->where('id', $b->id)->update(['available_at' => now()->subSecond()]);
        $this->fakeBir();
        $this->assertSame('accepted', app(WireBatches::class)->runOne()['state']);
    }

    public function test_unknown_invoice_result_remains_uncertain_and_is_never_published(): void
    {
        $p = $this->profile();
        $b = $this->batch($p);
        config(['bir_eis.outbound_enabled' => true]);
        $this->fakeBir('S001');
        app(WireBatches::class)->release($b->id, (string) $this->admin->id);
        app(WireBatches::class)->runOne();
        DB::table('bir_eis_batches')->where('id', $b->id)->update(['available_at' => now()->subSecond()]);
        $this->assertSame('uncertain', app(WireBatches::class)->runOne()['state']);
        $this->assertNull(DB::table('bir_eis_documents')->where('batch_id', $b->id)->value('result_code'));
    }

    public function test_linked_authority_can_release_only_reviewed_production_documents(): void
    {
        $p = $this->profile();
        DB::table('bir_eis_profiles')->where('id', $p->id)->update(['environment' => 'production']);
        $body = ['revision' => $p->revision, 'pti_id' => 'lab-pti', 'ptt_id' => 'lab-ptt', 'esp_accreditation_id' => 'lab-esp', 'certificate_number' => 'LAB-CERT-NOT-REAL', 'certificate_expires_at' => now()->addMonth()->toIso8601String(), 'written_authorization_at' => now()->subDay()->toIso8601String(), 'evidence' => 'Synthetic BIR certificate and written authorization - isolated tests only', 'reason' => 'Test evidence linking only'];
        $this->postJson('/api/admin/bir-wire/profiles/'.$p->id.'/authority', $body)->assertUnprocessable();
        config(['bir_compliance.configuration_reference' => 'isolated-lab-deployment']);
        DB::table('esp_accreditations')->insert(['id' => 'lab-esp', 'solution_name' => config('bir_compliance.solution_name'), 'solution_version' => config('bir_compliance.solution_version'), 'configuration_reference' => 'isolated-lab-deployment', 'status' => 'accredited', 'certificate_number' => 'LAB-NOT-REAL', 'issued_at' => now()->subMonth()->toDateString(), 'expires_at' => now()->addYear()->toDateString(), 'annual_review_at' => now()->toDateString(), 'evidence_reference' => 'Synthetic fixture only', 'verified_by' => (string) $this->admin->id, 'verified_at' => now()]);
        foreach (['annex', 'stages'] as $category) {
            foreach (config('bir_compliance.'.$category) as $code => $title) {
                DB::table('esp_accreditation_controls')->insert(['id' => (string) Str::uuid(), 'accreditation_id' => 'lab-esp', 'category' => $category, 'code' => (string) $code, 'status' => 'passed', 'evidence_reference' => 'Synthetic fixture only', 'verified_by' => (string) $this->admin->id, 'verified_at' => now()]);
            }
        }
        foreach (['PTI', 'PTT'] as $kind) {
            DB::table('taxpayer_authorizations')->insert(['id' => 'lab-'.strtolower($kind), 'request_id' => (string) Str::uuid(), 'request_hash' => hash('sha256', $kind), 'merchant_id' => $this->merchant, 'kind' => $kind, 'reference' => 'LAB-'.$kind, 'environment' => 'production', 'branch_code' => '00000', 'scope' => 'Isolated test only', 'issued_at' => now()->subDay()->toDateString(), 'valid_until' => now()->addYear()->toDateString(), 'status' => 'active', 'evidence' => 'Synthetic fixture only', 'reason' => 'Synthetic fixture only', 'reviewed_by' => (string) $this->admin->id, 'reviewed_at' => now()]);
        }
        $this->postJson('/api/admin/bir-wire/profiles/'.$p->id.'/authority', $body)->assertOk();
        $p = DB::table('bir_eis_profiles')->find($p->id);
        $this->invoice();
        $id = $this->prepareInvoice($p)->assertOk()->json('id');
        config(['bir_eis.outbound_enabled' => true]);
        $this->postJson('/api/admin/bir-wire/batches/'.$id.'/release', ['confirm_destination' => 'production'])->assertOk();
        $this->fakeBir();
        $this->assertSame('processing', app(WireBatches::class)->runOne()['state']);
        $this->assertSame(1, DB::table('business_invoices')->count());
        $this->assertSame(0, DB::table('eis_acknowledgements')->count());
        DB::table('taxpayer_authorizations')->where('id', 'lab-ptt')->update(['status' => 'revoked']);
        DB::table('bir_eis_batches')->where('id', $id)->update(['available_at' => now()->subSecond()]);
        $this->assertSame('accepted', app(WireBatches::class)->runOne()['state']);
    }
}
