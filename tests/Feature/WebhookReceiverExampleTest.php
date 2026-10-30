<?php

namespace Tests\Feature;

use PDO;
use Tests\TestCase;

class WebhookReceiverExampleTest extends TestCase
{
    public function test_documented_receiver_checks_signature_identity_and_durable_duplicate(): void
    {
        require_once base_path('docs/examples/nuers-webhook-receiver.php');
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE nuers_inbox(environment TEXT,event_id TEXT,body_hash TEXT,raw_body TEXT,state TEXT,PRIMARY KEY(environment,event_id))');
        $settings = ['secret' => 'synthetic-signing-secret','key_version' => 1,'environment' => 'sandbox','merchant_id' => 'synthetic-business'];
        $event = ['id' => '12345678-1234-4000-8000-123456789abc','type' => 'webhook.test','environment' => 'sandbox','merchant_id' => 'synthetic-business','data' => ['test_only' => true]];
        $body = json_encode($event);
        $at = (string)time();
        $headers = ['x-nuers-event' => $event['id'],'x-nuers-timestamp' => $at,'x-nuers-signature' => 'v1='.hash_hmac('sha256', $at.'.'.$event['id'].'.'.$body, $settings['secret']),'x-nuers-environment' => 'sandbox','x-nuers-key-version' => '1'];
        $this->assertSame(200, nuers_webhook_receive($body, $headers, $settings, $db)[0]);
        $this->assertSame(200, nuers_webhook_receive($body, $headers, $settings, $db)[0]);
        $this->assertSame(1, (int)$db->query('SELECT COUNT(*) FROM nuers_inbox')->fetchColumn());
        $this->assertSame(401, nuers_webhook_receive($body.' ', $headers, $settings, $db)[0]);
        $this->assertSame(401, nuers_webhook_receive($body, [...$headers,'x-nuers-environment' => 'live'], $settings, $db)[0]);
        $this->assertSame(401, nuers_webhook_receive($body, [...$headers,'x-nuers-key-version' => '2'], $settings, $db)[0]);
        $altered = json_encode([...$event,'data' => ['changed' => true]]);
        $h = [...$headers,'x-nuers-signature' => 'v1='.hash_hmac('sha256', $at.'.'.$event['id'].'.'.$altered, $settings['secret'])];
        $this->assertSame(409, nuers_webhook_receive($altered, $h, $settings, $db)[0]);
        $this->assertSame(1, (int)$db->query('SELECT COUNT(*) FROM nuers_inbox')->fetchColumn());
        $challenge = json_encode([...$event,'type' => 'webhook.endpoint_challenge','data' => ['challenge' => str_repeat('a', 48)]]);
        $h = [...$headers,'x-nuers-signature' => 'v1='.hash_hmac('sha256', $at.'.'.$event['id'].'.'.$challenge, $settings['secret'])];
        $this->assertSame([200,['nuers_challenge' => str_repeat('a',48)]],nuers_webhook_receive($challenge,$h,$settings,$db));
    }
}
