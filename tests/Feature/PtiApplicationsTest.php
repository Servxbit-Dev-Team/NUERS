<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\{BirCompliance,DocumentPdf,PtiApplications};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Crypt,DB,Storage};
use Illuminate\Support\Str;
use Tests\TestCase;

class PtiApplicationsTest extends TestCase
{
    private User $admin;
    private User $owner;
    private string $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite','database.connections.sqlite.database' => ':memory:','cache.default' => 'array','app.timezone' => 'Asia/Manila']);
        DB::purge('sqlite');
        foreach (glob(database_path('migrations/*.php')) as $f) {
            (require $f)->up();
        }
        Storage::fake('local');
        $this->admin = $this->actor('super_admin');
        $this->owner = $this->actor('merchant');
        $this->merchant = (string)Str::uuid();
        DB::table('merchants')->insert(['id' => $this->merchant,'merchant_account_id' => $this->owner->id,'business_name' => 'Synthetic PTI Business','tin' => '123456789000','owner_name' => 'Synthetic Owner','email' => $this->owner->email,'status' => 'active','address' => 'Synthetic test address','rdo_code' => '027']);
        $this->withToken($this->owner->api_token);
    }
    private function actor(string $role): User
    {
        $u = User::factory()->create(['api_token' => Str::random(64)]);
        DB::table('profiles')->insert(['id' => $u->id,'email' => $u->email,'role' => $role]);
        return $u;
    }
    private function form(array $more = []): array
    {
        return array_replace(['request_id' => (string)Str::uuid(),'application_type' => 'new','previous_reference' => '','system_name' => 'NUERS','system_version' => '1.0.0','system_provider' => 'NUERS','system_type' => 'nuers','scope' => 'Synthetic main office invoicing only','branches' => ['00000'],'representative' => 'Synthetic Owner','contact_email' => 'pti@example.test','contact_phone' => '+639000000000','office' => 'RDO 027','notes' => 'Synthetic test only'], $more);
    }
    private function draft(): array
    {
        return $this->postJson('/api/pti-applications', $this->form())->assertCreated()->json('record');
    }
    private function upload(array $r, string $kind): array
    {
        return $this->post('/api/pti-applications/'.$r['id'].'/documents', ['revision' => $r['revision'],'kind' => $kind,'file' => UploadedFile::fake()->createWithContent('synthetic.pdf', "%PDF-1.4\nSynthetic evidence only\n".str_repeat(" ", 1100)."%%EOF")], ['Accept' => 'application/json'])->assertCreated()->json('record');
    }
    private function prepared(): array
    {
        $r = $this->draft();
        foreach (PtiApplications::REVIEW_DOCUMENTS as $kind) {
            $r = $this->upload($r, $kind);
        }
        return $this->move($r, 'in_review')->assertOk()->json('record');
    }
    private function move(array $r, string $status, array $more = [])
    {
        return $this->postJson('/api/pti-applications/'.$r['id'].'/status', array_replace(['revision' => $r['revision'],'status' => $status,'note' => 'Synthetic evidence reviewed for isolated test','confirmed' => true], $more));
    }
    public function test_owner_scope_is_enforced_on_every_read_and_write(): void
    {
        $r = $this->upload($this->draft(), 'registration');
        $doc = DB::table('pti_application_documents')->first();
        $this->withoutToken()->getJson('/api/pti-applications')->assertUnauthorized();
        $stranger = $this->actor('merchant');
        $other = (string)Str::uuid();
        DB::table('merchants')->insert(['id' => $other,'merchant_account_id' => $stranger->id,'business_name' => 'Other tenant','tin' => '987654321000','status' => 'active']);
        $this->withToken($stranger->api_token)->getJson('/api/pti-applications')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/pti-applications/businesses')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $other);
        foreach (['','/packet','/documents/'.$doc->id] as $suffix) {
            $this->getJson('/api/pti-applications/'.$r['id'].$suffix)->assertNotFound();
        }
        $this->putJson('/api/pti-applications/'.$r['id'], $this->form(['revision' => $r['revision']]))->assertNotFound();
        $this->move($r, 'withdrawn')->assertNotFound();
        $this->post('/api/pti-applications/'.$r['id'].'/documents', ['revision' => $r['revision'],'kind' => 'registration','file' => UploadedFile::fake()->createWithContent('x.pdf', '%PDF-1.4 synthetic'.str_repeat(' ', 1100))], ['Accept' => 'application/json'])->assertNotFound();
        $this->postJson('/api/pti-applications', $this->form(['merchant_id' => $this->merchant]))->assertUnprocessable();
        foreach (['client','bir','merchant'] as $role) {
            $u = $this->actor($role);
            $this->withToken($u->api_token)->getJson('/api/pti-applications')->assertForbidden();
        }
        $this->withToken($this->admin->api_token)->getJson('/api/pti-applications')->assertOk()->assertJsonPath('total', 1);
    }
    public function test_encryption_idempotency_and_stale_revision_protection(): void
    {
        $f = $this->form(['identity' => ['tin' => 'FAKE']]);
        $r = $this->postJson('/api/pti-applications', $f)->assertCreated()->json('record');
        $this->assertSame('123456789000', $r['payload']['identity']['tin']);
        $this->postJson('/api/pti-applications', $f)->assertOk()->assertJsonPath('duplicate', true);
        $this->postJson('/api/pti-applications', array_replace($f, ['scope' => 'Changed scope for replay']))->assertConflict();
        $raw = DB::table('pti_applications')->find($r['id']);
        $this->assertStringNotContainsString('Synthetic', $raw->payload_encrypted);
        $this->assertSame('NUERS', json_decode(Crypt::decryptString($raw->payload_encrypted), true)['form']['system_name']);
        $this->putJson('/api/pti-applications/'.$r['id'], $this->form(['revision' => 1]))->assertOk()->assertJsonPath('record.revision', 2);
        $this->putJson('/api/pti-applications/'.$r['id'], $this->form(['revision' => 1]))->assertConflict();
        $this->assertTrue(app(BirCompliance::class)->auditIntegrity());
    }
    public function test_review_requires_preparation_evidence_and_does_not_issue_a_permit(): void
    {
        $r = $this->draft();
        $this->move($r, 'in_review')->assertUnprocessable();
        foreach (PtiApplications::REVIEW_DOCUMENTS as $k) {
            $r = $this->upload($r, $k);
        }
        $r = $this->move($r, 'in_review')->assertOk()->json('record');
        $this->move($r, 'ready_to_file')->assertForbidden();
        $this->putJson('/api/pti-applications/'.$r['id'], $this->form(['revision' => $r['revision']]))->assertConflict();
        $this->assertSame(0, DB::table('taxpayer_authorizations')->count());
        $this->getJson('/api/pti-applications')->assertJsonPath('catalog.direct_submission_available', false);
    }
    public function test_attachments_are_private_integrity_checked_and_cannot_claim_bir_outcomes(): void
    {
        $r = $this->upload($this->draft(), 'registration');
        $d = DB::table('pti_application_documents')->first();
        $this->assertStringNotContainsString('%PDF', Storage::disk('local')->get($d->private_path));
        $this->get('/api/pti-applications/'.$r['id'].'/documents/'.$d->id)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->post('/api/pti-applications/'.$r['id'].'/documents', ['revision' => $r['revision'],'kind' => 'bir_permit','file' => UploadedFile::fake()->createWithContent('permit.pdf', '%PDF-1.4 synthetic'.str_repeat(' ', 1100))], ['Accept' => 'application/json'])->assertForbidden();
        $this->post('/api/pti-applications/'.$r['id'].'/documents', ['revision' => $r['revision'],'kind' => 'registration','file' => UploadedFile::fake()->createWithContent('script.html', '<script>alert(1)</script>')], ['Accept' => 'application/json'])->assertUnprocessable();
        Storage::disk('local')->put($d->private_path, 'tampered');
        $this->getJson('/api/pti-applications/'.$r['id'].'/documents/'.$d->id)->assertConflict();
    }
    public function test_registered_identity_change_must_be_reviewed_again(): void
    {
        $r = $this->prepared();
        DB::table('merchants')->where('id', $this->merchant)->update(['business_name' => 'Changed registered name']);
        $this->withToken($this->admin->api_token);
        $this->move($r, 'ready_to_file')->assertConflict();
        $r = $this->move($r, 'needs_changes')->assertOk()->json('record');
        $r = $this->putJson('/api/pti-applications/'.$r['id'], $this->form(['revision' => $r['revision']]))->assertOk()->assertJsonPath('record.payload.identity.business_name', 'Changed registered name')->json('record');
        $this->move($r, 'in_review')->assertOk();
    }
    public function test_filing_and_permit_outcomes_require_evidence_and_create_exactly_one_authority(): void
    {
        $r = $this->prepared();
        $this->withToken($this->admin->api_token);
        $r = $this->move($r, 'ready_to_file')->assertOk()->json('record');
        $this->move($r, 'filed')->assertUnprocessable();
        $r = $this->upload($r, 'bir_receipt');
        $proof = DB::table('pti_application_documents')->where('kind', 'bir_receipt')->value('id');
        $r = $this->move($r, 'filed', ['evidence_id' => $proof,'filing_reference' => 'SYNTHETIC-FILING-ONLY','filed_on' => now()->subDay()->toDateString(),'receiving_office' => 'RDO 027'])->assertOk()->json('record');
        $this->move($r, 'issued', ['evidence_id' => $proof])->assertUnprocessable();
        $r = $this->upload($r, 'bir_permit');
        $proof = DB::table('pti_application_documents')->where('kind', 'bir_permit')->value('id');
        $fields = ['evidence_id' => $proof,'permit_reference' => 'SYNTHETIC-PTI-ONLY','issued_at' => now()->toDateString(),'branch_code' => '00000'];
        $this->move($r, 'issued', array_replace($fields, ['branch_code' => '99999']))->assertUnprocessable();
        $issued = $this->move($r, 'issued', $fields)->assertOk()->json('record');
        $this->assertNotNull($issued['authorization_id']);
        $this->assertSame(1, DB::table('taxpayer_authorizations')->count());
        $permit = DB::table('taxpayer_authorizations')->first();
        $this->assertSame($this->merchant, $permit->merchant_id);
        $this->assertSame('production', $permit->environment);
        $this->assertSame('00000', $permit->branch_code);
        $this->move($r, 'issued', $fields)->assertConflict();
        $this->move($issued, 'issued', $fields)->assertConflict();
        $this->assertSame(1, DB::table('taxpayer_authorizations')->count());
        $this->assertFalse(config('bir_compliance.transport_implemented'));
        $this->assertTrue(app(BirCompliance::class)->auditIntegrity());
    }
    public function test_pdf_uses_scoped_identity_and_escapes_user_content(): void
    {
        $r = $this->postJson('/api/pti-applications', $this->form(['notes' => '<script>unsafe()</script>']))->assertCreated()->json('record');
        $this->mock(DocumentPdf::class, function ($mock) {
            $mock->shouldReceive('renderHtml')->once()->withArgs(function ($html) {
                $this->assertStringContainsString('Synthetic PTI Business', $html);
                $this->assertStringNotContainsString('<script>unsafe()', $html);
                $this->assertStringContainsString('&lt;script&gt;', $html);
                $this->assertStringContainsString('data:image/', $html);
                return true;
            })->andReturn('%PDF-test-only');
        });
        $this->get('/api/pti-applications/'.$r['id'].'/packet')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }
    public function test_zip_contains_only_verified_application_attachments(): void
    {
        $r = $this->upload($this->draft(), 'registration');
        $this->mock(DocumentPdf::class, fn ($mock) => $mock->shouldReceive('renderHtml')->once()->andReturn('%PDF-synthetic-only'));
        $response = $this->get('/api/pti-applications/'.$r['id'].'/packet?format=zip')->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $zip = new \ZipArchive();
        try {
            $this->assertTrue($zip->open($path));
            $this->assertSame(3, $zip->numFiles);
            $this->assertSame('%PDF-synthetic-only',$zip->getFromName('NUERS-Application-Summary.pdf'));
            $this->assertStringContainsString('No data has been transmitted to BIR',$zip->getFromName('README.txt'));
            $d = DB::table('pti_application_documents')->first();
            $bytes = $zip->getFromName('attachments/registration-'.$d->id.'.pdf');
            $this->assertSame($d->sha256,hash('sha256',$bytes));
        } finally {
            $zip->close();
            unlink($path);
        }
    }
}
