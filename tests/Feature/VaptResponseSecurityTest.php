<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class VaptResponseSecurityTest extends TestCase
{
    public function test_exception_responses_retain_security_headers_and_hide_server_details(): void
    {
        config(['app.debug' => false]);
        Route::post('/vapt-error-fixture', fn () => throw new \RuntimeException('SECRET_SQL_PASSWORD_fixture'));
        $r = $this->postJson('https://localhost/vapt-error-fixture');
        $r->assertStatus(500)->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY');
        $this->assertStringContainsString("default-src 'self'", $r->headers->get('Content-Security-Policy'));
        $this->assertStringNotContainsString('SECRET_SQL_PASSWORD_fixture', $r->getContent());
        $this->assertStringNotContainsString('trace', $r->getContent());
    }

    public function test_api_not_found_is_not_cacheable_and_has_security_headers(): void
    {
        $r = $this->getJson('https://localhost/api/vapt-not-a-real-route');
        $r->assertNotFound()->assertHeader('X-Frame-Options', 'DENY');
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=31536000', $r->headers->get('Strict-Transport-Security'));
    }

    public function test_branded_rate_limit_page_preserves_retry_and_security_headers(): void
    {
        Route::post('/vapt-throttle-fixture', fn () => abort(429, 'Rate limited', ['Retry-After' => '60']));
        $r = $this->post('https://localhost/vapt-throttle-fixture');
        $r->assertStatus(429)->assertHeader('Retry-After', '60')->assertHeader('X-Frame-Options', 'DENY');
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
    }
}
