<?php
namespace Tests\Feature;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
class BrandedRateLimitPageTest extends TestCase
{
    public function test_html_429_keeps_status_retry_header_and_branded_accessible_guidance(): void
    {
        Route::get('/auth/google/session', fn()=>abort(429, 'Private limiter message', ['Retry-After'=>45,'X-RateLimit-Remaining'=>0]));
        $r=$this->get('/auth/google/session')->assertTooManyRequests()->assertHeader('Retry-After','45')->assertHeader('X-RateLimit-Remaining','0');
        $r->assertSee('NUERS')->assertSee('nuers-digital-white.png')->assertSee('Try again in 45 seconds')->assertSee('id="title"',false)->assertDontSee('Private limiter message');
        $this->assertStringContainsString('no-store',$r->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('http-equiv="refresh"',$r->getContent());
    }
    public function test_json_errors_stay_json_and_429_without_retry_does_not_invent_wait_time(): void
    {
        Route::get('/auth/google/session', fn()=>abort(429));
        $this->getJson('/auth/google/session')->assertTooManyRequests()->assertHeader('Content-Type','application/json')->assertDontSee('NUERS access protection');
        $this->get('/auth/google/session')->assertTooManyRequests()->assertSee('Please try again shortly')->assertSee('data-seconds="0"',false);
    }
    public function test_real_web_throttle_renders_the_page_without_disabling_the_limiter(): void
    {
        config(['cache.default'=>'array']);
        Route::get('/auth/google/session',fn()=>response('ok'))->middleware('throttle:1,1');
        $this->get('/auth/google/session')->assertOk();
        $this->get('/auth/google/session')->assertTooManyRequests()->assertHeader('Retry-After')->assertSee('Please pause and try again');
        $this->travel(61)->seconds();
        $this->get('/auth/google/session')->assertOk();
    }
}
