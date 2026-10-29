<?php

namespace Tests\Feature;

use App\Services\Infrastructure\Catalog;
use App\Services\Infrastructure\MtlsController;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class InfrastructureGatewayTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = tempnam(sys_get_temp_dir(), 'infra-test-');
        file_put_contents($this->file, str_repeat('synthetic-key-only', 4));
        config(['infrastructure.enabled' => true, 'infrastructure.controller_url' => 'https://controller.example.test', 'infrastructure.controller_host' => 'controller.example.test', 'infrastructure.ca_file' => $this->file, 'infrastructure.client_cert' => $this->file, 'infrastructure.client_key' => $this->file, 'infrastructure.signing_key_file' => $this->file]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        unlink($this->file);
        parent::tearDown();
    }

    public function test_requests_use_exact_origin_signed_digest_nonce_and_no_redirects(): void
    {
        Http::fake(function (Request $r) {
            $this->assertSame('https://controller.example.test/v1/jobs', $r->url());
            $nonce = $r->header('X-NUERS-Nonce')[0];
            $this->assertTrue(Str::isUuid($nonce));
            $canonical = implode("\n", [Catalog::VERSION, 'POST', '/v1/jobs', $r->header('X-NUERS-Timestamp')[0], $nonce, hash('sha256', $r->body())]);
            $this->assertSame(hash_hmac('sha256', $canonical, trim(file_get_contents($this->file))), $r->header('X-NUERS-Signature')[0]);

            return Http::response(['schema_version' => Catalog::VERSION, 'request_nonce' => $nonce, 'ok' => true]);
        });
        $this->assertTrue(app(MtlsController::class)->submit(['job_id' => (string) Str::uuid()])['ok']);
        Http::assertSentCount(1);
    }

    public function test_origin_userinfo_query_and_plaintext_connections_are_refused(): void
    {
        foreach (['http://controller.example.test', 'https://evil.example.test', 'https://user:pass@controller.example.test', 'https://controller.example.test?url=https://other.test', 'https://controller.example.test/path'] as $url) {
            config(['infrastructure.controller_url' => $url]);
            $this->assertFalse(app(MtlsController::class)->ready());
        }
        Http::assertNothingSent();
    }

    public function test_wrong_response_nonce_is_rejected(): void
    {
        Http::fake(['*' => Http::response(['schema_version' => Catalog::VERSION, 'request_nonce' => 'replayed'])]);
        $this->expectException(\RuntimeException::class);
        app(MtlsController::class)->snapshot();
    }

    public function test_oversized_response_is_not_accepted(): void
    {
        Http::fake(['*' => Http::response(str_repeat('a', 1048577))]);
        try {
            app(MtlsController::class)->snapshot();
            $this->fail('Oversized response accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('limit', $e->getMessage());
        }
    }

    public function test_redirect_is_not_accepted(): void
    {
        Http::fake(['*' => Http::response('', 302, ['Location' => 'https://other.example.test'])]);
        $this->expectException(\RuntimeException::class);
        app(MtlsController::class)->snapshot();
    }
}
