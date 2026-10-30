<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\{BirCompliance,ComplianceOperations,DocumentPdf,EisDocumentReadiness,InvoiceSequence,MerchantWallet};
use Illuminate\Support\Facades\{Crypt,DB};
use Illuminate\Support\Str;
use Tests\TestCase;

class ComplianceOperationsTest extends TestCase
{
    private User $owner;
    private User $admin;
    private string $merchant;
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite','database.connections.sqlite.database' => ':memory:','cache.default' => 'array','app.timezone' => 'Asia/Manila']);
        DB::purge('sqlite');
        foreach (glob(database_path('migrations/*.php')) as $f) {
            (require $f)->up();
        }
        $this->owner = $this->actor('merchant');
        $this->admin = $this->actor('super_admin');
        $this->merchant = $this->business($this->owner);
        $this->withToken($this->owner->api_token);
    }
    private function actor(string $role): User
    {
        $u = User::factory()->create(['api_token' => Str::random(64)]);
        DB::table('profiles')->insert(['id' => $u->id,'email' => $u->email,'role' => $role]);
        return $u;
    }
    private function business(User $u): string
    {
        $id = (string)Str::uuid();
        DB::table('merchants')->insert(['id' => $id,'merchant_account_id' => $u->id,'business_name' => 'Synthetic '.substr($id, 0, 8),'tin' => '123456789000','status' => 'active','address' => 'Synthetic test address','vat_registered' => true]);
        return $id;
    }
    private function create(string $kind, array $v = [])
    {
        return $this->postJson('/api/compliance-operations', ['kind' => $kind,'request_id' => (string)Str::uuid(),...$v]);
    }
    private function outage(): array
    {
        return $this->create('outage', ['title' => 'Synthetic outage test','started_at' => now()->subHours(3)->toIso8601String(),'evidence_reference' => 'Synthetic incident evidence'])->assertCreated()->json('record');
    }
    private function act(array $r, string $action, array $v = [])
    {
        return $this->postJson('/api/compliance-operations/'.$r['id'].'/actions', ['revision' => $r['revision'],'action' => $action,...$v]);
    }
    private function source(?string $merchant = null, array $v = []): string
    {
        $id = (string)Str::uuid();
        DB::table('business_invoices')->insert(['id' => $id,'merchant_id' => $merchant ?? $this->merchant,'invoice_number' => 'SYN-'.substr($id, 0, 8),'status' => 'issued','document_type' => 'vat_invoice','merchant_name' => 'Synthetic Merchant','merchant_tin' => '123456789000','merchant_address' => 'Test address','buyer_name' => 'Synthetic Buyer','seller_branch_code' => '00000','issue_date' => now()->toDateString(),'total_amount' => 1120,'vat_amount' => 120,'withholding_tax' => 0,...$v]);
        return $id;
    }
    private function cwt(int $amount = 10): array
    {
        return ['withholding_agent' => true,'classification' => 'Buyer confirmed corporate payor','atc' => 'WC160','rate' => $amount / 10,'base' => 1000,'amount' => $amount,'evidence_reference' => 'Synthetic buyer confirmation'];
    }
    private function allowIssue(): void
    {
        $this->partialMock(BirCompliance::class, fn ($m) => $m->shouldReceive('assertCanIssue')->andReturn([]));
        $n = 0;
        $this->mock(InvoiceSequence::class, function ($m) use (&$n) {
            $m->shouldReceive('reserve')->andReturnUsing(function () use (&$n) {
                return ['number' => 'SYNTHETIC-CM-'.++$n,'authority_reference' => 'Synthetic only','series_id' => 'synthetic'];
            });
        });
    }
    public function test_owner_scope_and_exports_are_enforced(): void
    {
        $r = $this->outage();
        $other = $this->actor('merchant');
        $this->business($other);
        $this->withToken($other->api_token);
        $this->getJson('/api/compliance-operations?merchant_id='.$this->merchant)->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/compliance-operations/export?format=csv&id='.$r['id'])->assertNotFound();
        $this->act($r, 'restore', ['restored_at' => now()->toIso8601String(),'evidence_reference' => 'Synthetic restore evidence'])->assertNotFound();
        $this->create('outage', ['merchant_id' => $this->merchant])->assertUnprocessable();
        $this->withToken($this->admin->api_token)->getJson('/api/compliance-operations')->assertOk()->assertJsonPath('total', 1);
        $this->withToken($this->actor('client')->api_token)->getJson('/api/compliance-operations')->assertForbidden();
    }
    public function test_encryption_idempotency_integrity_and_stale_updates(): void
    {
        $v = ['kind' => 'outage','request_id' => (string)Str::uuid(),'title' => 'Synthetic outage test','started_at' => now()->subHour()->toIso8601String(),'evidence_reference' => 'Synthetic evidence reference'];
        $r = $this->postJson('/api/compliance-operations', $v)->assertCreated()->json('record');
        $this->postJson('/api/compliance-operations', $v)->assertCreated()->assertJsonPath('record.id', $r['id']);
        $this->postJson('/api/compliance-operations', [...$v,'title' => 'Changed incident'])->assertConflict();
        $raw = DB::table('merchant_compliance_records')->find($r['id']);
        $this->assertStringNotContainsString('Synthetic', $raw->payload);
        $this->assertStringContainsString('Synthetic', Crypt::decryptString($raw->payload));
        $this->act($r, 'restore', ['restored_at' => now()->toIso8601String(),'evidence_reference' => 'Synthetic verified restore'])->assertOk();
        $this->act($r, 'restore', ['restored_at' => now()->toIso8601String(),'evidence_reference' => 'Synthetic verified restore'])->assertConflict();
        DB::table('merchant_compliance_records')->where('id', $r['id'])->update(['payload_hash' => str_repeat('0', 64)]);
        $this->getJson('/api/compliance-operations')->assertConflict();
    }
    public function test_cwt_memo_preserves_original_and_rejects_stale_competing_draft(): void
    {
        $id = $this->source();
        $before = (array)DB::table('business_invoices')->find($id);
        $r = $this->create('cwt_memo', ['source' => 'invoice','document_id' => $id,'withholding' => $this->cwt(),'reason' => 'Buyer submitted withholding evidence'])->assertCreated()->json('record');
        $competing = $this->create('cwt_memo', ['source' => 'invoice','document_id' => $id,'withholding' => $this->cwt(20),'reason' => 'Different buyer supplied correction'])->assertCreated()->json('record');
        $this->act($r, 'issue')->assertConflict();
        $this->allowIssue();
        $r = $this->act($r, 'issue')->assertOk()->assertJsonPath('record.details.sales_adjustment_centavos', 0)->assertJsonPath('record.details.vat_adjustment_centavos', 0)->assertJsonPath('record.details.corrected_net_payable_centavos', 111000)->json('record');
        $this->act($competing, 'issue')->assertConflict();
        $this->act($r, 'issue')->assertConflict();
        $second = $this->create('cwt_memo', ['source' => 'invoice','document_id' => $id,'withholding' => $this->cwt(20),'reason' => 'Updated buyer supplied correction'])->assertCreated()->json('record');
        $this->act($second, 'issue')->assertOk()->assertJsonPath('record.source_sequence', 2);
        $this->assertSame(2000, app(ComplianceOperations::class)->currentWithholding($this->merchant, app(ComplianceOperations::class)->source($this->merchant, 'invoice', $id)));
        $this->assertEquals($before, (array)DB::table('business_invoices')->find($id));
        $this->assertSame(0, DB::table('merchant_wallet_entries')->count());
    }
    public function test_invalid_withholding_and_foreign_original_are_rejected(): void
    {
        $id = $this->source();
        $v = ['source' => 'invoice','document_id' => $id,'withholding' => [...$this->cwt(),'amount' => 19],'reason' => 'Synthetic reason for adjustment'];
        $this->create('cwt_memo', $v)->assertUnprocessable();
        $other = $this->business($this->actor('merchant'));
        $id = $this->source($other);
        $this->create('cwt_memo', [...$v,'document_id' => $id,'withholding' => $this->cwt()])->assertNotFound();
    }
    public function test_manual_recovery_requires_restoration_exact_identity_and_single_replacement(): void
    {
        $o = $this->outage();
        $r = $this->create('manual_invoice', ['outage_id' => $o['id'],'manual_number' => 'MANUAL-001','branch_code' => '00000','issued_at' => now()->subHours(2)->toIso8601String(),'amount' => 1120,'buyer_name' => 'Synthetic Buyer','authority_reference' => 'Synthetic ATP evidence','evidence_reference' => 'Synthetic retained invoice'])->assertCreated()->json('record');
        $id = $this->source(null, ['reference_number' => 'MANUAL-001']);
        $this->act($r, 'link', ['source' => 'invoice','document_id' => $id])->assertConflict();
        $this->act($o, 'restore', ['restored_at' => now()->subHour()->toIso8601String(),'evidence_reference' => 'Synthetic recovery verification'])->assertOk();
        DB::table('business_invoices')->where('id', $id)->update(['total_amount' => 100]);
        $this->act($r, 'link', ['source' => 'invoice','document_id' => $id])->assertUnprocessable();
        DB::table('business_invoices')->where('id', $id)->update(['total_amount' => 1120]);
        $done = $this->act($r, 'link', ['source' => 'invoice','document_id' => $id])->assertOk()->assertJsonPath('record.status', 'reconciled')->json('record');
        $this->act($done, 'link', ['source' => 'invoice','document_id' => $this->source(null, ['reference_number' => 'MANUAL-001'])])->assertConflict();
    }
    public function test_fee_statements_claim_once_require_issuer_and_do_not_charge_again(): void
    {
        DB::transaction(function () {
            $w = app(MerchantWallet::class);
            $w->credit($this->merchant, 10000, 'synthetic-deposit', 'SYNTHETIC', $this->owner->id);
            $w->charge($this->merchant, 112000, 'vat_invoice', 'invoice', 'synthetic-sale', 'SYNTHETIC', '00000', 'test', $this->owner->id);
        });
        $v = ['from' => now()->toDateString(),'to' => now()->toDateString(),'reason' => 'Synthetic billing reconciliation'];
        $this->create('service_fee', $v)->assertForbidden();
        $this->withToken($this->admin->api_token);
        $v['merchant_id'] = $this->merchant;
        $r = $this->create('service_fee', $v)->assertCreated()->assertJsonPath('record.amount_centavos', 300)->json('record');
        $this->create('service_fee', $v)->assertUnprocessable();
        $this->act($r, 'issue')->assertConflict();
        $this->assertSame(9700, (int)DB::table('merchant_wallets')->where('merchant_id', $this->merchant)->value('available_centavos'));
        $issuer = $this->business($this->actor('merchant'));
        config(['bir_compliance.service_invoice_issuer_id' => $issuer]);
        $this->allowIssue();
        $this->mock(EisDocumentReadiness::class, fn ($m) => $m->shouldReceive('prepare')->once()->andReturn([]));
        $r = $this->act($r, 'issue')->assertOk()->assertJsonPath('record.status', 'issued')->json('record');
        $this->assertSame(1, DB::table('business_invoices')->where('merchant_id', $issuer)->count());
        $this->assertSame(2, DB::table('merchant_wallet_entries')->count());
        $this->withToken($this->owner->api_token)->getJson('/api/compliance-operations?kind=service_fee')->assertOk()->assertJsonPath('total', 1);
    }
    public function test_pdf_is_scoped_escaped_and_branded(): void
    {
        $this->create('outage', ['title' => '<script>unsafe()</script>','started_at' => now()->subHour()->toIso8601String(),'evidence_reference' => 'Synthetic evidence reference'])->assertCreated();
        $this->mock(DocumentPdf::class, function ($m) {
            $m->shouldReceive('renderHtml')->once()->withArgs(function ($html) {
                $this->assertStringContainsString('data:image/png;base64,', $html);
                $this->assertStringNotContainsString('<script>unsafe()', $html);
                $this->assertStringContainsString('&lt;script&gt;', $html);
                return true;
            })->andReturn('%PDF-test');
        });
        $this->get('/api/compliance-operations/export?format=pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }
    public function test_source_accreditation_policy_defaults_to_continuity_and_can_fail_closed(): void
    {
        $this->assertFalse(config('bir_compliance.source_capture_accreditation_enforced'));
        app(BirCompliance::class)->assertSourceCaptureAllowed();
        config(['bir_compliance.source_capture_accreditation_enforced' => true]);
        try {
            app(BirCompliance::class)->assertSourceCaptureAllowed();
            $this->fail('Missing accreditation must block new intake when enabled.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_same_pti_can_cover_evidenced_branches_of_one_approved_system(): void
    {
        $this->withToken($this->admin->api_token);
        $v = ['merchant_id' => $this->merchant,'kind' => 'PTI','reference' => 'SYNTHETIC-SHARED-PTI','environment' => 'production','branch_code' => '00000','system_reference' => 'SYNTHETIC-SYSTEM-1','scope' => 'Synthetic approved system scope','issued_at' => now()->toDateString(),'status' => 'active','evidence' => 'Synthetic BIR permit evidence','reason' => 'Synthetic registration reason'];
        $post = fn ($x) => $this->postJson('/api/taxpayer-authorizations', ['request_id' => (string)Str::uuid(),...$x]);
        $post($v)->assertCreated();
        $post([...$v,'branch_code' => '00001'])->assertUnprocessable();
        $second = [...$v,'branch_code' => '00001','branch_notification_reference' => 'Synthetic branch coverage proof'];
        $post($second)->assertCreated();
        $post($second)->assertUnprocessable();
        $post([...$second,'branch_code' => '00002','system_reference' => 'DIFFERENT-SYSTEM'])->assertUnprocessable();
        $post([...$second,'merchant_id' => $this->business($this->actor('merchant'))])->assertUnprocessable();
        $this->assertSame(2,DB::table('taxpayer_authorizations')->count());
    }
}
