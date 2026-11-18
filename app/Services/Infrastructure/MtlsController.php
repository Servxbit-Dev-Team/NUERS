<?php

namespace App\Services\Infrastructure;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class MtlsController implements ControllerGateway
{
    public function ready(): bool
    {
        $url = parse_url((string) config('infrastructure.controller_url'));
        if (! config('infrastructure.enabled') || ($url['scheme'] ?? '') !== 'https' || empty($url['host']) || $url['host'] !== config('infrastructure.controller_host') || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) || ! in_array($url['path'] ?? '', ['', '/'], true)) {
            return false;
        }
        foreach (['ca_file', 'client_cert', 'client_key', 'signing_key_file'] as $key) {
            if (! is_readable((string) config('infrastructure.'.$key))) {
                return false;
            }
        }

        return strlen(trim(file_get_contents(config('infrastructure.signing_key_file')))) >= 32;
    }

    private function request(string $method, string $path, array $payload = []): array
    {
        if (! $this->ready()) {
            throw new RuntimeException('Management controller is not configured.');
        }
        $body = $method === 'GET' ? '' : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $nonce = (string) Str::uuid();
        $timestamp = (string) time();
        $canonical = implode("\n", [Catalog::VERSION, $method, $path, $timestamp, $nonce, hash('sha256', $body)]);
        $signature = hash_hmac('sha256', $canonical, trim(file_get_contents(config('infrastructure.signing_key_file'))));
        $response = Http::withOptions(['verify' => config('infrastructure.ca_file'), 'cert' => config('infrastructure.client_cert'), 'ssl_key' => config('infrastructure.client_key'), 'allow_redirects' => false, 'stream' => true, 'read_timeout' => 5])
            ->connectTimeout(3)->timeout(10)->withHeaders(['Accept' => 'application/json', 'Content-Type' => 'application/json', 'X-NUERS-Protocol' => Catalog::VERSION, 'X-NUERS-Timestamp' => $timestamp, 'X-NUERS-Nonce' => $nonce, 'X-NUERS-Signature' => $signature])
            ->send($method, rtrim(config('infrastructure.controller_url'), '/').$path, ['body' => $body]);
        if (! $response->successful()) {
            throw new RuntimeException('Controller request failed; review action history.');
        }
        $stream = $response->toPsrResponse()->getBody();
        $raw = '';
        while (! $stream->eof() && strlen($raw) <= 1048576) {
            $chunk = $stream->read(min(65536, 1048577 - strlen($raw)));
            if ($chunk === '' && ! $stream->eof()) {
                throw new RuntimeException('Controller response stream stalled.');
            }
            $raw .= $chunk;
        }
        if (strlen($raw) > 1048576) {
            throw new RuntimeException('Controller response exceeds contract limit.');
        }
        try {
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException('Controller response is not valid JSON.');
        }
        // Bind the response to this request. The verified mTLS connection authenticates its origin.
        if (! is_array($data) || ($data['request_nonce'] ?? '') !== $nonce || ($data['schema_version'] ?? '') !== Catalog::VERSION) {
            throw new RuntimeException('Controller response binding is invalid.');
        }

        return $data;
    }

    public function snapshot(): array
    {
        return $this->request('GET', '/v1/snapshot');
    }

    public function submit(array $job): array
    {
        return $this->request('POST', '/v1/jobs', $job);
    }

    public function result(string $id): array
    {
        if (! Str::isUuid($id)) {
            throw new RuntimeException('Invalid job identifier.');
        }

        return $this->request('GET', '/v1/jobs/'.$id);
    }
}
