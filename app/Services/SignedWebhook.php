<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SignedWebhook
{
    public static function signature(string $secret, string $event, string $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$event.'.'.$body, $secret);
    }

    public static function verify(string $secret, string $event, string $timestamp, string $body, string $signature, int $now): bool
    {
        return ctype_digit($timestamp) && abs($now - (int) $timestamp) <= 300 && hash_equals(self::signature($secret, $event, $timestamp, $body), $signature);
    }

    /** Resolver is injectable for isolated tests. Pin one vetted IPv4 address to prevent DNS rebinding. */
    public function address(string $url, ?callable $resolver = null): array
    {
        $u = parse_url($url);
        $host = strtolower($u['host'] ?? '');
        abort_unless(($u['scheme'] ?? '') === 'https' && ! isset($u['user']) && ! isset($u['pass']) && ! isset($u['fragment']) && (! isset($u['port']) || $u['port'] === 443) && str_contains($host, '.') && ! filter_var($host, FILTER_VALIDATE_IP) && ! preg_match('/(?:^|\.)(localhost|local|internal|test)$/', $host), 422, 'Webhook requires a public HTTPS hostname on port 443, without credentials or fragments.');
        $ips = $resolver ? $resolver($host) : (gethostbynamel($host) ?: []);
        abort_unless($ips, 422, 'Webhook hostname does not resolve.');
        foreach ($ips as $ip) {
            abort_unless(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE), 422, 'Webhook resolved to a private or reserved address.');
        }
        foreach ($ips as $ip) {
            $n = ip2long($ip);
            foreach (['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4'] as $range) {
                [$network,$bits] = explode('/', $range);
                $mask = -1 << (32 - (int) $bits);
                abort_if(($n & $mask) === (ip2long($network) & $mask), 422, 'Webhook resolved to a special-use address.');
            }
        }

        return [$host, $ips[0]];
    }

    public function enqueue(string $merchant, string $event, string $subject, array $details = []): void
    {
        $hooks = DB::table('integration_webhooks as w')->join('integration_connections as c', 'c.id', '=', 'w.connection_id')->where('c.merchant_id', $merchant)->where('c.status', 'configured')->where('w.status', 'configured')->get(['w.*']);
        foreach ($hooks as $hook) {
            if (! in_array($event, json_decode($hook->events, true) ?: [], true)) {
                continue;
            }
            $id = (string) Str::uuid();
            $body = json_encode(['id' => $id, 'type' => $event, 'merchant_id' => $merchant, 'subject_id' => $subject, 'occurred_at' => now()->toIso8601String(), 'data' => $details], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            DB::table('webhook_deliveries')->insertOrIgnore(['id' => $id, 'webhook_id' => $hook->id, 'merchant_id' => $merchant, 'event_key' => hash('sha256', $hook->id.'|'.$event.'|'.$subject), 'event_type' => $event, 'subject_id' => $subject, 'encrypted_body' => Crypt::encryptString($body), 'body_hash' => hash('sha256', $body), 'state' => 'pending', 'available_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function runOne(): ?string
    {
        // Delivery requires a separately reviewed egress and destination opt-in. Configuration alone never sends data.
        if (! config('security.webhook_delivery_enabled')) {
            return 'disabled';
        }
        $d = DB::transaction(function () {
            $d = DB::table('webhook_deliveries')->whereIn('state', ['pending', 'retry', 'sending'])->where('available_at', '<=', now())->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<', now()))->orderBy('updated_at')->lockForUpdate()->first();
            if (! $d) {
                return null;
            }$d->lease_token = (string) Str::uuid();
            DB::table('webhook_deliveries')->where('id', $d->id)->update(['lease_token' => $d->lease_token, 'lease_until' => now()->addMinute(), 'state' => 'sending', 'updated_at' => now()]);

            return $d;
        });
        if (! $d) {
            return null;
        }
        $state = 'retry';
        $status = null;
        $error = null;
        try {
            $hook = DB::table('integration_webhooks as w')->join('integration_connections as c', 'c.id', '=', 'w.connection_id')->where('w.id', $d->webhook_id)->where('c.merchant_id', $d->merchant_id)->where('c.status', 'configured')->where('w.status', 'configured')->first(['w.*']);
            abort_unless($hook, 409, 'Webhook was disabled or ownership changed.');
            [$host,$ip] = $this->address($hook->url);
            $body = Crypt::decryptString($d->encrypted_body);
            abort_unless(hash_equals($d->body_hash, hash('sha256', $body)), 409, 'Webhook body integrity failure.');
            $at = (string) now()->timestamp;
            $response = Http::withOptions(['allow_redirects' => false, 'verify' => true, 'curl' => [CURLOPT_PROXY => '', CURLOPT_RESOLVE => [$host.':443:'.$ip], CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS]])->connectTimeout(3)->timeout(10)->withHeaders(['X-NUERS-Event' => $d->id, 'X-NUERS-Timestamp' => $at, 'X-NUERS-Signature' => 'v1='.self::signature(Crypt::decryptString($hook->encrypted_secret), $d->id, $at, $body)])->withBody($body, 'application/json')->post($hook->url);
            $status = $response->status();
            $state = $response->successful() ? 'delivered' : ($status >= 300 && $status < 400 ? 'dead_letter' : 'retry');
        } catch (\Throwable $e) {
            $error = 'Delivery could not be verified; inspect endpoint and egress policy.';
            report($e);
        }
        $attempts = $d->attempts + 1;
        if ($attempts >= 8 && $state !== 'delivered') {
            $state = 'dead_letter';
        }
        DB::table('webhook_deliveries')->where('id', $d->id)->where('lease_token', $d->lease_token)->update(['state' => $state, 'attempts' => $attempts, 'last_http_status' => $status, 'last_error' => $error, 'available_at' => now()->addSeconds(min(3600,2 ** $attempts) + random_int(1,30)), 'lease_token' => null, 'lease_until' => null, 'updated_at' => now()]);

        return $state;
    }
}
