<?php

namespace App\Services\Eis;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/** Only this class may contact the two pinned BIR hosts. No automatic HTTP retries or redirects. */
class WireClient
{
    public function credentials(object $profile): array
    {
        if (! $profile->encrypted_credentials) {
            throw new RuntimeException('BIR credentials have not been configured.');
        }

        return json_decode(Crypt::decryptString($profile->encrypted_credentials), true, 32, JSON_THROW_ON_ERROR);
    }

    public function request(object $profile, string $operation, array $secret, ?array $body = null, ?object $batch = null): array
    {
        if (! config('bir_eis.outbound_enabled') || ! $profile->enabled || ! $profile->contract_reference) {
            throw new RuntimeException('Outbound BIR access is disabled or its contract has not been confirmed.');
        }
        $fresh = DB::table('bir_eis_profiles')->find($profile->id);
        if (! $fresh || ! $fresh->enabled || $fresh->revision !== $profile->revision) {
            throw new RuntimeException('BIR connection changed before the request.');
        }
        $origin = config('bir_eis.origins.'.$profile->environment);
        $prefix = config('bir_eis.prefixes.'.$profile->environment);
        if ($origin !== (['certification' => 'https://eis-cert.bir.gov.ph', 'production' => 'https://eis-api.bir.gov.ph'][$profile->environment] ?? null) || ! in_array($prefix, ['/api', '/v2'], true)) {
            throw new RuntimeException('Unsupported BIR endpoint configuration.');
        }
        $method = $operation === 'inquiry' ? 'GET' : 'POST';
        $path = $prefix.match ($operation) {
            'authentication' => '/authentication','send' => '/invoices','inquiry' => '/invoice_result/'.rawurlencode($batch->submit_id),default => throw new RuntimeException('Unsupported BIR operation.')
        };
        $datetime = now('Asia/Manila')->format('YmdHis');
        $headers = ['accreditationId' => $profile->accreditation_id, 'applicationId' => $profile->application_id, 'datetime' => $datetime, 'authorization' => Protocol::authorization($datetime, $method, $path, $secret['key'])];
        if (isset($secret['authToken'])) {
            $headers['authToken'] = $secret['authToken'];
        }
        $id = (string) Str::uuid();
        if ($operation === 'send' && (! $batch || DB::table('bir_eis_batches')->where('id', $batch->id)->whereNull('submitted_at')->update(['submitted_at' => now()]) !== 1)) {
            throw new RuntimeException('A submitted batch cannot be posted again.');
        }
        DB::table('bir_eis_wire_attempts')->insert(['id' => $id, 'profile_id' => $profile->id, 'batch_id' => $batch?->id, 'operation' => $operation, 'created_at' => now()]);
        try {
            $limit = (int) config('bir_eis.max_response_bytes');
            $http = Http::acceptJson()->asJson()->withHeaders($headers)->connectTimeout(8)->timeout((int) config('bir_eis.timeout_seconds'))
                ->withOptions(['allow_redirects' => false, 'verify' => true, 'stream' => true, 'debug' => false, 'on_headers' => function ($r) use ($limit) {
                    if ((int) $r->getHeaderLine('Content-Length') > $limit) {
                        throw new RuntimeException('BIR response exceeds size limit.');
                    }
                }]);
            $response = $http->send($method, $origin.$path, $body === null ? [] : ['json' => $body]);
            $stream = $response->toPsrResponse()->getBody();
            $raw = '';
            while (! $stream->eof() && strlen($raw) <= $limit) {
                $raw .= $stream->read(min(65536, $limit + 1 - strlen($raw)));
            }
            $stream->close();
            if (strlen($raw) > $limit) {
                throw new RuntimeException('BIR response exceeds size limit.');
            }
            $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($data) || ! in_array($data['status'] ?? null, ['0', '1'], true)) {
                throw new RuntimeException('Invalid BIR response envelope.');
            }
            $error = $data['status'] === '0' ? ($data['errorDetails']['errorCode'] ?? 'UNKNOWN') : null;
            // Authentication ciphertext can disclose sessions if combined with transient keys; do not retain it.
            DB::table('bir_eis_wire_attempts')->where('id', $id)->update(['http_status' => $response->status(), 'error_code' => $error === null ? null : (is_string($error) ? substr($error, 0, 30) : 'UNKNOWN'), 'response_hash' => hash('sha256', $raw), 'encrypted_response' => $operation === 'authentication' ? null : Crypt::encryptString($raw)]);
            if ($response->status() !== 200 || $data['status'] !== '1') {
                return ['ok' => false, 'error_code' => is_string($error) && preg_match('/^E\d{2}$/D', $error) ? $error : 'HTTP_'.$response->status(), 'http_status' => $response->status()];
            }

            return ['ok' => true, 'data' => $data['data'] ?? null, 'http_status' => 200, 'attempt_id' => $id, 'response_hash' => hash('sha256', $raw)];
        } catch (\Throwable $e) {
            DB::table('bir_eis_wire_attempts')->where('id', $id)->update(['error_code' => 'TRANSPORT_UNCERTAIN']);
            // Do not attach the original Guzzle exception: it may contain credentials or customer payloads.
            throw new RuntimeException('BIR connection or response could not be verified. Preserve the Submit ID and inquire before any resend.');
        }
    }

    public function session(object $profile): array
    {
        return Cache::lock('bir-eis-auth:'.$profile->id, 90)->block(3, function () use ($profile) {
            $fresh = DB::table('bir_eis_profiles')->find($profile->id);
            if (! $fresh || ! $fresh->enabled || $fresh->revision !== $profile->revision) {
                throw new RuntimeException('BIR connection changed; reload its configuration.');
            }
            if ($fresh->encrypted_session && $fresh->session_expires_at && CarbonImmutable::parse($fresh->session_expires_at, 'UTC')->gt(now()->addMinutes(2))) {
                return json_decode(Crypt::decryptString($fresh->encrypted_session), true, 16, JSON_THROW_ON_ERROR);
            }
            $c = $this->credentials($fresh);
            $authKey = bin2hex(random_bytes(16));
            $body = ['data' => Protocol::rsa(Protocol::json(['userId' => $c['user_id'], 'password' => $c['password'], 'authKey' => $authKey]), $c['bir_public_key'])];
            $r = $this->request($fresh, 'authentication', ['key' => $c['application_key']], $body);
            if (! $r['ok']) {
                throw new RuntimeException('BIR authentication failed ('.$r['error_code'].').');
            }
            $s = json_decode(Protocol::decrypt((string) $r['data'], $authKey), true, 16, JSON_THROW_ON_ERROR);
            if (($s['accreditationId'] ?? null) !== $profile->accreditation_id || ($s['userId'] ?? null) !== $c['user_id'] || ! is_string($s['authToken'] ?? null) || strlen($s['authToken']) < 16 || strlen($s['authToken']) > 256) {
                throw new RuntimeException('BIR authentication response identity mismatch.');
            }
            Protocol::encrypt('session-check', (string) ($s['sessionKey'] ?? ''));
            $expires = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s', (string) $s['tokenExpiry'], 'Asia/Manila');
            if (! $expires || ! $expires->gt(now()->addMinutes(2)) || $expires->gt(now()->addHours(6)->addMinutes(10))) {
                throw new RuntimeException('BIR token expiry is invalid or server clock needs correction.');
            }
            $session = ['authToken' => $s['authToken'], 'sessionKey' => $s['sessionKey']];
            DB::table('bir_eis_profiles')->where('id', $profile->id)->where('revision', $profile->revision)->update(['encrypted_session' => Crypt::encryptString(Protocol::json($session)), 'session_expires_at' => $expires->utc()->format('Y-m-d H:i:s'), 'authenticated_at' => now()]);

            return $session;
        });
    }

    public function send(object $profile, object $batch, array $documents): array
    {
        if (count($documents) < 1 || count($documents) > 100) {
            throw new RuntimeException('BIR batches require 1–100 invoices.');
        }
        $s = $this->session($profile);
        $c = $this->credentials($profile);
        $jws = array_map(fn ($d) => Protocol::sign($d, $c['key_id'], $c['signing_private_key']), $documents);
        $r = $this->request($profile, 'send', ['key' => $s['sessionKey'], 'authToken' => $s['authToken']], ['submitId' => $batch->submit_id, 'data' => Protocol::encrypt(implode(',', $jws), $s['sessionKey'])], $batch);
        if (! $r['ok']) {
            return ['state' => 'uncertain', 'error_code' => $r['error_code']];
        }
        $data = json_decode(Protocol::decrypt((string) $r['data'], $s['sessionKey']), true, 32, JSON_THROW_ON_ERROR);
        app(ResultVerifier::class)->identity($data, (object) ['accreditation_id' => $profile->accreditation_id, 'user_id' => $c['user_id']], $batch->submit_id);

        return ['state' => 'processing', 'ack_id' => $data['ackId']];
    }

    public function inquire(object $profile, object $batch, array $uids): array
    {
        $s = $this->session($profile);
        $c = $this->credentials($profile);
        $r = $this->request($profile, 'inquiry', ['key' => $s['sessionKey'], 'authToken' => $s['authToken']], null, $batch);
        if (! $r['ok']) {
            if ($r['error_code'] === 'E03') {
                DB::table('bir_eis_profiles')->where('id', $profile->id)->update(['encrypted_session' => null, 'session_expires_at' => null]);
            }

            return ['state' => in_array($r['error_code'], ['E15', 'E16'], true) ? 'reconciliation' : 'uncertain', 'error_code' => $r['error_code']];
        }
        if (! is_array($r['data'])) {
            throw new RuntimeException('BIR inquiry data must be a JSON object.');
        }

        return app(ResultVerifier::class)->verify($r['data'],(object) ['accreditation_id' => $profile->accreditation_id, 'user_id' => $c['user_id']],$batch->submit_id,$uids,$batch->ack_id) + ['ack_id' => $r['data']['ackId'], 'attempt_id' => $r['attempt_id'], 'response_hash' => $r['response_hash']];
    }
}
