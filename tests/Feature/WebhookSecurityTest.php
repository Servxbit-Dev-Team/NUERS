<?php

namespace Tests\Feature;

use App\Services\SignedWebhook;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WebhookSecurityTest extends TestCase
{
    public function test_signatures_bind_body_id_and_timestamp_and_reject_expired_or_modified_envelopes(): void
    {
        $secret = str_repeat('synthetic', 8);
        $time = (string) time();
        $body = '{"synthetic":true}';
        $sig = SignedWebhook::signature($secret, 'event-1', $time, $body);
        $this->assertTrue(SignedWebhook::verify($secret, 'event-1', $time, $body, $sig, (int) $time));
        foreach ([['event-2', $time, $body], ['event-1', $time, $body.' '], ['event-1', (string) ((int) $time - 301), $body]] as [$id,$at,$raw]) {
            $this->assertFalse(SignedWebhook::verify($secret, $id, $at, $raw, $sig, (int) $time));
        }
        $this->assertSame('disabled', (new SignedWebhook())->runOne());
    }

    public function test_egress_rejects_credentials_redirect_destinations_ports_local_and_special_addresses(): void
    {
        $service = new SignedWebhook();
        foreach (['http://public.example.com', 'https://user@public.example.com', 'https://public.example.com:8443', 'https://localhost', 'https://127.0.0.1', 'https://public.example.com/#fragment'] as $url) {
            try {
                $service->address($url, fn () => ['8.8.8.8']);
                $this->fail('Unsafe target accepted');
            } catch (HttpException $e) {
                $this->assertSame(422, $e->getStatusCode());
            }
        }
        foreach (['127.0.0.1', '10.0.0.1', '169.254.169.254', '100.64.0.1', '198.18.0.1', '224.0.0.1', '::1'] as $ip) {
            try {
                $service->address('https://public.example.com', fn () => [$ip]);
                $this->fail('Unsafe DNS accepted');
            } catch (HttpException $e) {
                $this->assertSame(422, $e->getStatusCode());
            }
        }
        $this->assertSame(['public.example.com', '8.8.8.8'], $service->address('https://public.example.com/path', fn () => ['8.8.8.8']));
    }
}
