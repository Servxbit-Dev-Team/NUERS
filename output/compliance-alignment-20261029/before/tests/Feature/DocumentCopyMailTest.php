<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\{BirCompliance, PlatformMail};
use Illuminate\Support\Facades\{Crypt, DB};
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class DocumentCopyMailTest extends TestCase
{
    private User $owner;
    private User $admin;
    protected function setUp(): void
    {
        parent::setUp();
        config(['notifications.dispatch_enabled'=>true]);
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'app.timezone' => 'UTC', 'security.enforce_privileged_mfa' => false]); DB::purge('sqlite');
        foreach (glob(database_path('migrations/*.php')) as $file) (require $file)->up();
        $this->owner = $this->actor('merchant'); $this->admin = $this->actor('super_admin');
        DB::table('merchants')->insert(['id' => 'm', 'business_name' => 'QA seller', 'tin' => '123456789', 'merchant_account_id' => $this->owner->id, 'status' => 'active']);
        DB::table('merchants')->insert(['id' => 'foreign', 'business_name' => 'Other seller', 'tin' => '987654321']);
        foreach (['issued', 'draft', 'foreign'] as $id) {
            DB::table('business_invoices')->insert(['id' => $id, 'merchant_id' => $id === 'foreign' ? 'foreign' : 'm', 'invoice_number' => 'QA-'.$id, 'merchant_name' => 'QA seller', 'merchant_tin' => '123456789', 'merchant_address' => 'Original address', 'buyer_name' => 'Test buyer', 'buyer_email' => $id === 'issued' ? 'buyer@example.test' : null,
                'document_type' => 'vat_exempt_invoice', 'status' => $id === 'draft' ? 'draft' : 'issued', 'issue_date' => '2026-10-17', 'total_amount' => 1000, 'subtotal_amount' => 1000, 'vat_amount' => 0, 'vat_exempt_amount' => 1000]);
            DB::table('business_invoice_items')->insert(['id' => 'item-'.$id, 'invoice_id' => $id, 'merchant_id' => $id === 'foreign' ? 'foreign' : 'm', 'description' => 'Recorded exempt service', 'quantity' => 1, 'unit_price' => 1000, 'line_total' => 1000, 'tax_type' => 'vat_exempt']);
        }
    }
    private function actor(string $role): User { $u = User::factory()->create(['api_token' => Str::random(64)]); DB::table('profiles')->insert(['id' => $u->id, 'role' => $role, 'email' => $u->email]); return $u; }
    private function settings(int $revision = 0): array { return ['revision' => $revision, 'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'starttls', 'username' => 'smtp-user', 'password' => 'secret-app-password', 'from_address' => 'receipts@example.com', 'from_name' => 'NUERS QA', 'enabled' => false]; }
    private function configured(): void { $v = $this->settings(); unset($v['revision']); $v['enabled'] = true; $hash = app(PlatformMail::class)->connectionHash($v); DB::table('platform_mail_settings')->insert(['id' => 1, 'encrypted_settings' => Crypt::encryptString(json_encode($v)), 'revision' => 1, 'connection_hash' => $hash, 'tested_hash' => $hash, 'updated_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]); }

    public function test_authentication_tenant_and_admin_boundaries(): void
    {
        $this->getJson('/api/admin/configuration')->assertUnauthorized();
        $this->withToken($this->owner->api_token)->getJson('/api/admin/configuration')->assertForbidden();
        $this->getJson('/api/document-copies/invoice/foreign')->assertNotFound();
        $this->get('/api/document-copies/invoice/foreign/pdf')->assertNotFound();
        $this->postJson('/api/document-copies/invoice/foreign/email', ['email' => 'a@example.test', 'request_id' => Str::uuid()])->assertNotFound();
        $this->getJson('/api/document-copies/invoice/issued')->assertOk()->assertJsonPath('email', 'buyer@example.test')->assertJsonPath('smtp_enabled', false);
        $this->withToken($this->admin->api_token)->get('/api/document-copies/invoice/issued/pdf')->assertNotFound();
    }
    public function test_configuration_secrets_are_encrypted_redacted_and_revision_protected(): void
    {
        $result = $this->withToken($this->admin->api_token)->putJson('/api/admin/configuration/smtp', $this->settings())->assertOk()->assertJsonPath('revision', 1)->assertJsonPath('smtp.has_password', true);
        $this->assertArrayNotHasKey('password', $result->json('smtp'));
        $this->assertStringNotContainsString('secret-app-password', DB::table('platform_mail_settings')->value('encrypted_settings'));
        $this->assertStringNotContainsString('secret-app-password', json_encode(DB::table('eis_audit_events')->get()));
        $this->putJson('/api/admin/configuration/smtp', $this->settings())->assertConflict();
        $this->putJson('/api/admin/configuration/smtp', array_replace($this->settings(1), ['password' => null, 'enabled' => true]))->assertUnprocessable();
        foreach (['127.0.0.1', 'localhost', 'mail.internal', 'smtp.example.com/path'] as $host) $this->putJson('/api/admin/configuration/smtp', array_replace($this->settings(1), ['host' => $host]))->assertUnprocessable();
        $this->putJson('/api/admin/configuration/smtp', array_replace($this->settings(1), ['port' => 80]))->assertUnprocessable();
        $this->assertTrue(app(BirCompliance::class)->auditIntegrity());
    }
    public function test_successful_test_is_required_before_enabling_and_credential_changes_invalidate_it(): void
    {
        $mail = Mockery::mock(PlatformMail::class)->makePartial(); $mail->shouldReceive('send')->once()->withArgs(fn ($settings, $to, $subject, $body) => $to === 'test@example.test' && $settings['password'] === 'secret-app-password'); $this->app->instance(PlatformMail::class, $mail);
        $this->withToken($this->admin->api_token)->putJson('/api/admin/configuration/smtp', $this->settings())->assertOk();
        $this->postJson('/api/admin/configuration/smtp/test', ['email' => 'test@example.test', 'revision' => 1])->assertOk();
        $this->putJson('/api/admin/configuration/smtp', array_replace($this->settings(1), ['password' => null, 'enabled' => true]))->assertOk()->assertJsonPath('tested', true)->assertJsonPath('smtp.enabled', true);
        $this->putJson('/api/admin/configuration/smtp', array_replace($this->settings(2), ['password' => 'new-secret', 'enabled' => true]))->assertUnprocessable();
        $this->putJson('/api/admin/configuration/smtp', array_replace($this->settings(2), ['password' => 'new-secret']))->assertOk()->assertJsonPath('tested', false);
    }
    public function test_failed_test_does_not_expose_provider_secrets_or_enable_mail(): void
    {
        $mail = Mockery::mock(PlatformMail::class)->makePartial(); $mail->shouldReceive('send')->once()->andThrow(new \RuntimeException('secret-app-password confidential server reply')); $this->app->instance(PlatformMail::class, $mail);
        $this->withToken($this->admin->api_token)->putJson('/api/admin/configuration/smtp', $this->settings())->assertOk();
        $response = $this->postJson('/api/admin/configuration/smtp/test', ['email' => 'test@example.test', 'revision' => 1])->assertUnprocessable();
        $this->assertStringNotContainsString('secret-app-password', $response->getContent());
        $this->getJson('/api/admin/configuration')->assertJsonPath('tested', false)->assertJsonPath('smtp.enabled', false);
    }
    public function test_real_server_pdf_contains_original_values_without_financial_mutation(): void
    {
        $before = (array) DB::table('business_invoices')->where('id', 'issued')->first();
        $response = $this->withToken($this->owner->api_token)->get('/api/document-copies/invoice/issued/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertSame($before, (array) DB::table('business_invoices')->where('id', 'issued')->first());
        $this->get('/api/document-copies/invoice/draft/pdf')->assertOk();
    }
    public function test_missing_pdf_runtime_fails_explicitly_without_substituting_another_template(): void
    {
        config(['document_pdf.chrome_binary' => '/no-such-runtime/nuers-chrome']);
        $this->withToken($this->owner->api_token)->getJson('/api/document-copies/invoice/issued/pdf')->assertStatus(503)->assertJsonPath('message', 'Configure NUERS_PDF_CHROME with the installed Chrome or Chromium executable to render the invoice preview PDF.');
        $this->assertSame(0, DB::table('document_email_deliveries')->count());
    }
    public function test_email_attaches_server_pdf_and_idempotency_preserves_originals(): void
    {
        $this->configured(); $before = (array) DB::table('business_invoices')->where('id', 'issued')->first();
        $mail = Mockery::mock(PlatformMail::class)->makePartial(); $mail->shouldReceive('send')->once()->withArgs(fn ($settings, $to, $subject, $body, $pdf, $filename) => $to === 'new-customer@example.test' && str_starts_with($pdf, '%PDF-') && $filename === 'QA-issued.pdf'); $this->app->instance(PlatformMail::class, $mail);
        $body = ['email' => 'new-customer@example.test', 'request_id' => (string) Str::uuid()];
        $this->withToken($this->owner->api_token)->postJson('/api/document-copies/invoice/issued/email', $body)->assertAccepted()->assertJsonPath('state', 'queued');
        $this->postJson('/api/document-copies/invoice/issued/email', array_replace($body,['request_id'=>(string)Str::uuid()]))->assertAccepted();
        $this->assertSame(1, DB::table('document_email_deliveries')->count());
        app(\App\Services\PlatformNotifications::class)->runOne();
        $this->postJson('/api/document-copies/invoice/issued/email', $body)->assertOk();
        $this->postJson('/api/document-copies/invoice/issued/email', array_replace($body, ['email' => 'other@example.test']))->assertConflict();
        $this->assertSame(1, DB::table('document_email_deliveries')->count());
        $delivery = DB::table('document_email_deliveries')->first(); $this->assertSame($body['email'], Crypt::decryptString($delivery->encrypted_recipient)); $this->assertSame(64, strlen($delivery->pdf_hash));
        $this->getJson('/api/document-copies/invoice/issued')->assertOk()->assertJsonPath('email', 'buyer@example.test')->assertJsonPath('history.0.state', 'smtp_accepted');
        $this->assertSame($before, (array) DB::table('business_invoices')->where('id', 'issued')->first());
        $this->assertTrue(app(BirCompliance::class)->auditIntegrity());
    }
    public function test_invalid_addresses_drafts_unconfigured_and_uncertain_sends_fail_honestly(): void
    {
        $this->withToken($this->owner->api_token)->postJson('/api/document-copies/invoice/issued/email', ['email' => 'bad-email', 'request_id' => Str::uuid()])->assertUnprocessable();
        $this->postJson('/api/document-copies/invoice/draft/email', ['email' => 'a@example.test', 'request_id' => Str::uuid()])->assertConflict();
        $this->postJson('/api/document-copies/invoice/issued/email', ['email' => 'a@example.test', 'request_id' => Str::uuid()])->assertStatus(503);
        $this->configured(); $mail = Mockery::mock(PlatformMail::class)->makePartial(); $mail->shouldReceive('send')->once()->andThrow(new \RuntimeException('SMTP timeout secret')); $this->app->instance(PlatformMail::class, $mail);
        $body = ['email' => 'a@example.test', 'request_id' => (string) Str::uuid()];
        $this->postJson('/api/document-copies/invoice/issued/email', $body)->assertAccepted()->assertJsonPath('state', 'queued');
        app(\App\Services\PlatformNotifications::class)->runOne();
        $this->postJson('/api/document-copies/invoice/issued/email', $body)->assertConflict()->assertJsonPath('state', 'uncertain');
        $this->assertSame(1, DB::table('document_email_deliveries')->count());
    }
}
