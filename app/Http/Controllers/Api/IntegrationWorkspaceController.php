<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BirCompliance;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class IntegrationWorkspaceController extends Controller
{
    private function actor(Request $r, bool $write = false)
    {
        $u = app(BirCompliance::class)->administrativeUser($r);
        if ($write) {
            abort_unless(in_array(DB::table('profiles')->where('id', $u->id)->value('role'), ['admin', 'super_admin']), 403);
        }

        return $u;
    }

    private function lock()
    {
        DB::table('business_registry_lock')->where('id', 1)->lockForUpdate()->first();
    }

    private function present($x)
    {
        $p = (array) $x;
        $p['credential_configured'] = ! empty($p['encrypted_secret']);
        unset($p['encrypted_secret']);

        return $p;
    }

    private function logQuery(Request $r)
    {
        $q = DB::table('eis_api_request_logs as l')->leftJoin('eis_integrations as i', 'i.id', '=', 'l.integration_id')->select('l.id', 'l.integration_id', 'l.method', 'l.endpoint', 'l.status_code', 'l.latency_ms', 'l.error_code', 'l.error_message', 'l.created_at', 'i.environment', 'i.merchant_name', 'i.taxpayer_tin');
        if ($r->filled('environment') && $r->environment !== 'all') {
            $q->where('i.environment', $r->environment);
        }if ($r->filled('from')) {
            $q->whereDate('l.created_at', '>=', $r->from);
        }if ($r->filled('to')) {
            $q->whereDate('l.created_at', '<=', $r->to);
        }if ($r->filled('search')) {
            $q->where(function ($q) use ($r) {
                $s = '%'.$r->search.'%';
                $q->where('l.endpoint', 'like', $s)->orWhere('i.merchant_name', 'like', $s)->orWhere('i.taxpayer_tin', 'like', $s)->orWhere('l.error_code', 'like', $s);
            });
        }if ($r->status === 'success') {
            $q->whereBetween('l.status_code', [200, 299]);
        }if ($r->status === 'failed') {
            $q->where('l.status_code', '>=', 400);
        }if ($r->filled('method') && $r->method !== 'all') {
            $q->where('l.method', $r->method);
        }

        return $q;
    }

    private function redact($log)
    {
        $log->error_message = $log->error_message ? preg_replace('/(bearer\s+)[\w.\-]+|((?:secret|token|password|api[_-]?key)\s*[=:]\s*)[^\s,;]+/i', '$1$2[REDACTED]', $log->error_message) : null;
        $log->endpoint = explode('?', $log->endpoint)[0];

        return $log;
    }

    public function index(Request $r)
    {
        $u = $this->actor($r);
        $r->validate(['page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100', 'search' => 'nullable|string|max:255', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from', 'environment' => 'nullable|in:all,production,certification,sandbox', 'method' => 'nullable|in:all,GET,POST,PUT,PATCH,DELETE', 'status' => 'nullable|in:all,success,failed']);
        $q = DB::table('integration_connections');
        if ($r->filled('environment') && $r->environment !== 'all') {
            $q->where('environment', $r->environment);
        }$connections = $q->orderBy('name')->get()->map(fn ($x) => $this->present($x));
        $ids = $connections->pluck('id');
        $logs = $this->logQuery($r)->orderByDesc('l.created_at')->paginate((int) ($r->per_page ?? 10));
        $logs->setCollection($logs->getCollection()->map(fn ($x) => $this->redact($x)));
        $today = CarbonImmutable::now('Asia/Manila')->startOfDay()->utc();
        $daily = DB::table('eis_api_request_logs')->where('created_at', '>=', $today);
        $count = (clone $daily)->count();
        $success = (clone $daily)->whereBetween('status_code', [200, 299])->count();
        $failed = (clone $daily)->where('status_code', '>=', 400)->count();

        return response()->json(['connections' => $connections, 'endpoints' => DB::table('integration_endpoints')->whereIn('connection_id', $ids)->orderBy('name')->get(), 'webhooks' => DB::table('integration_webhooks')->whereIn('connection_id', $ids)->get()->map(function ($x) {
            $p = (array) $x;
            unset($p['encrypted_secret']);
            $p['events'] = json_decode($p['events'], true);

            return $p;
        }), 'logs' => $logs, 'summary' => ['requests' => $count, 'success_rate' => $count ? round(100 * $success / $count, 2) : null, 'failed' => $failed, 'latency_ms' => (clone $daily)->avg('latency_ms'), 'connected' => false], 'businesses' => DB::table('merchants')->orderBy('business_name')->get(['id', 'business_name', 'tin']), 'can_write' => in_array(DB::table('profiles')->where('id', $u->id)->value('role'), ['admin', 'super_admin']), 'generated_at' => now()->toIso8601String(), 'notice' => 'BIR production transport is not implemented. Stored configuration, diagnostics and certification logs do not establish a live connection. Webhooks are configuration records; delivery is not enabled.']);
    }

    private function https(string $url): void
    {
        $p = parse_url($url);
        abort_unless($p && ($p['scheme'] ?? '') === 'https' && ! isset($p['user']) && ! isset($p['pass']) && ! isset($p['query']) && ! isset($p['fragment']) && ! filter_var($p['host'] ?? '', FILTER_VALIDATE_IP) && ! in_array(strtolower($p['host'] ?? ''), ['localhost', 'localhost.localdomain']) && ! str_ends_with(strtolower($p['host'] ?? ''), '.local'), 422, 'Use a public HTTPS hostname without credentials, query parameters or fragments.');
    }

    public function save(Request $r, ?string $id = null)
    {
        $u = $this->actor($r, true);
        $v = $r->validate(['request_id' => 'required|uuid', 'revision' => 'required|integer|min:0', 'name' => 'required|string|max:120', 'provider' => 'required|in:bir_eis,third_party', 'merchant_id' => 'nullable|exists:merchants,id', 'environment' => 'required|in:certification,production', 'base_url' => 'required|url:https|max:500', 'client_id' => 'nullable|string|max:255', 'api_version' => 'required|string|max:50', 'timeout_seconds' => 'required|integer|min:5|max:120', 'max_retries' => 'required|integer|min:0|max:5', 'status' => 'required|in:draft,configured,disabled', 'evidence' => 'required|string|min:10|max:3000', 'reason' => 'required|string|min:10|max:2000']);
        $this->https($v['base_url']);

        return DB::transaction(function () use ($u, $v, $id) {
            $this->lock();
            $old = $id ? DB::table('integration_connections')->find($id) : null;
            if ($id) {
                abort_unless($old, 404);
                abort_unless($old->revision === $v['revision'], 409, 'Configuration changed. Refresh before saving.');
                abort_unless($old->environment === $v['environment'] && $old->provider === $v['provider'] && $old->merchant_id === ($v['merchant_id'] ?? null), 422, 'Provider, environment and taxpayer cannot be reassigned. Create a new connection.');
            } else {
                abort_if(DB::table('integration_connections')->where('request_id', $v['request_id'])->exists(), 409, 'Request already completed. Refresh the connection list.');
            }$cid = $id ?? (string) Str::uuid();
            $data = collect($v)->except(['request_id', 'revision'])->all() + ['revision' => ($old?->revision ?? 0) + 1, 'updated_at' => now()];
            $endpointChanged = $old && $old->base_url !== $v['base_url'];
            if ($old && ($endpointChanged || $old->client_id !== ($v['client_id'] ?? null))) {
                $data += ['encrypted_secret' => null, 'secret_hint' => null, 'secret_updated_at' => null];
            }if ($old) {
                DB::table('integration_connections')->where('id', $id)->update($data);
                if ($endpointChanged) {
                    DB::table('integration_endpoints')->where('connection_id', $id)->update(['enabled' => false, 'updated_at' => now()]);
                }
            } else {
                DB::table('integration_connections')->insert($data + ['id' => $cid, 'request_id' => $v['request_id'], 'created_at' => now()]);
            }$before = $old ? $this->present($old) : null;
            app(BirCompliance::class)->audit('integration.configuration_saved', $cid, $u->id, ['before' => $before, 'after' => $data]);

            return response()->json(['connection' => $this->present(DB::table('integration_connections')->find($cid))], $old ? 200 : 201);
        });
    }

    public function credential(Request $r, string $id)
    {
        $u = $this->actor($r, true);
        $v = $r->validate(['revision' => 'required|integer|min:1', 'secret' => 'nullable|string|min:16|max:4096', 'revoke' => 'required|boolean', 'reason' => 'required|string|min:10|max:2000']);
        abort_unless($v['revoke'] || ! empty($v['secret']), 422, 'Supply a provider-issued secret, or choose revoke.');

        return DB::transaction(function () use ($id, $u, $v) {
            $this->lock();
            $old = DB::table('integration_connections')->find($id);
            abort_unless($old, 404);
            abort_unless($old->revision === $v['revision'], 409, 'Configuration changed. Refresh first.');
            abort_unless($old->client_id, 422, 'Set the provider client ID first.');
            $secret = $v['revoke'] ? null : $v['secret'];
            DB::table('integration_connections')->where('id', $id)->update(['encrypted_secret' => $secret ? Crypt::encryptString($secret) : null, 'secret_hint' => $secret ? 'configured' : null, 'secret_updated_at' => now(), 'revision' => $old->revision + 1, 'updated_at' => now()]);
            app(BirCompliance::class)->audit($secret ? 'integration.credential_replaced' : 'integration.credential_revoked', $id, $u->id, ['reason' => $v['reason'], 'environment' => $old->environment]);

            return response()->json(['message' => $secret ? 'Credential encrypted and stored.' : 'Stored credential revoked.', 'revision' => $old->revision + 1]);
        });
    }

    public function certificate(Request $r, string $id)
    {
        $u = $this->actor($r, true);
        $r->validate(['revision' => 'required|integer|min:1', 'file' => 'required|file|max:128', 'reason' => 'required|string|min:10|max:2000']);
        $pem = file_get_contents($r->file('file')->getRealPath());
        abort_if(str_contains($pem, 'PRIVATE KEY'), 422, 'Upload a public X.509 certificate only.');
        $cert = @openssl_x509_parse($pem);
        abort_unless($cert && isset($cert['validTo_time_t']), 422, 'A valid PEM X.509 certificate is required.');
        $fingerprint = openssl_x509_fingerprint($pem, 'sha256');
        abort_unless($fingerprint, 422, 'Unable to calculate certificate fingerprint.');

        return DB::transaction(function () use ($r, $id, $u, $cert, $fingerprint) {
            $this->lock();
            $old = DB::table('integration_connections')->find($id);
            abort_unless($old, 404);
            abort_unless($old->revision === $r->integer('revision'), 409, 'Configuration changed.');
            DB::table('integration_connections')->where('id', $id)->update(['certificate_fingerprint' => $fingerprint, 'certificate_expires_at' => gmdate('Y-m-d H:i:s', $cert['validTo_time_t']), 'revision' => $old->revision + 1, 'updated_at' => now()]);
            app(BirCompliance::class)->audit('integration.certificate_recorded', $id, $u->id, ['fingerprint' => $fingerprint, 'reason' => $r->reason]);

            return response()->json(['message' => 'Public certificate metadata recorded. Issuer trust and private-key custody require separate verification.']);
        });
    }

    public function endpoint(Request $r, ?string $id = null)
    {
        $u = $this->actor($r, true);
        $v = $r->validate(['connection_id' => 'required|exists:integration_connections,id', 'revision' => 'required|integer|min:0', 'name' => 'required|string|max:120', 'method' => 'required|in:GET,POST,PUT,PATCH,DELETE', 'path' => ['required', 'string', 'max:255', 'regex:~^/[A-Za-z0-9_/{}/.\-]*$~'], 'description' => 'required|string|max:2000', 'enabled' => 'required|boolean', 'reason' => 'required|string|min:10|max:2000']);
        abort_if(str_contains($v['path'], '..') || str_starts_with($v['path'], '//'), 422, 'Use a relative endpoint path.');

        return DB::transaction(function () use ($id, $v, $u) {
            $this->lock();
            $old = $id ? DB::table('integration_endpoints')->find($id) : null;
            if ($id) {
                abort_unless($old, 404);
                abort_unless($old->revision === $v['revision'], 409, 'Endpoint changed.');
                abort_unless($old->connection_id === $v['connection_id'], 422);
            }abort_if(DB::table('integration_endpoints')->where('connection_id', $v['connection_id'])->where('method', $v['method'])->where('path', $v['path'])->when($id, fn ($q) => $q->where('id', '!=', $id))->exists(), 422, 'Endpoint already exists.');
            $eid = $id ?? (string) Str::uuid();
            $data = collect($v)->except(['reason', 'revision'])->all() + ['revision' => ($old?->revision ?? 0) + 1, 'updated_at' => now()];
            if ($old) {
                DB::table('integration_endpoints')->where('id', $id)->update($data);
            } else {
                DB::table('integration_endpoints')->insert($data + ['id' => $eid, 'created_at' => now()]);
            }app(BirCompliance::class)->audit('integration.endpoint_saved', $eid, $u->id, ['connection_id' => $v['connection_id'], 'reason' => $v['reason'], 'after' => $data]);

            return response()->json(['record' => DB::table('integration_endpoints')->find($eid)]);
        });
    }

    public function webhook(Request $r, ?string $id = null)
    {
        $u = $this->actor($r, true);
        $v = $r->validate(['connection_id' => 'required|exists:integration_connections,id', 'revision' => 'required|integer|min:0', 'url' => 'required|url:https|max:500', 'events' => 'required|array|min:1|max:4', 'events.*' => 'required|in:invoice.issued,transmission.acknowledged,transmission.failed,permit.expiring', 'status' => 'required|in:disabled,configured', 'secret' => 'nullable|string|min:32|max:4096', 'evidence' => 'required|string|min:10|max:3000', 'reason' => 'required|string|min:10|max:2000']);
        $this->https($v['url']);
        $urlParts = parse_url($v['url']);
        abort_if(isset($urlParts['user']) || isset($urlParts['pass']) || isset($urlParts['fragment']) || (isset($urlParts['port']) && $urlParts['port'] !== 443), 422, 'Webhook URLs cannot contain credentials, fragments or non-443 ports.');

        return DB::transaction(function () use ($v, $u, $id) {
            $this->lock();
            $old = $id ? DB::table('integration_webhooks')->find($id) : null;
            if ($id) {
                abort_unless($old, 404);
                abort_unless($old->revision === $v['revision'], 409, 'Webhook changed.');
                abort_unless($old->connection_id === $v['connection_id'], 422);
            }abort_unless($old || ! empty($v['secret']), 422, 'Provide a signing secret of at least 32 characters.');
            $wid = $id ?? (string) Str::uuid();
            $data = ['connection_id' => $v['connection_id'], 'url' => $v['url'], 'events' => json_encode(array_values(array_unique($v['events']))), 'status' => $v['status'], 'evidence' => $v['evidence'], 'revision' => ($old?->revision ?? 0) + 1, 'updated_at' => now()];
            if (! empty($v['secret'])) {
                $data['encrypted_secret'] = Crypt::encryptString($v['secret']);
            }if ($old) {
                DB::table('integration_webhooks')->where('id', $id)->update($data);
            } else {
                DB::table('integration_webhooks')->insert($data + ['id' => $wid, 'created_at' => now()]);
            }unset($data['encrypted_secret']);
            app(BirCompliance::class)->audit('integration.webhook_saved', $wid, $u->id, ['reason' => $v['reason'], 'after' => $data]);

            return response()->json(['message' => 'Webhook configuration saved. Delivery is not enabled.']);
        });
    }

    public function diagnose(Request $r, string $id)
    {
        $u = $this->actor($r);
        $p = DB::table('integration_connections')->find($id);
        abort_unless($p, 404);
        $integration = app(BirCompliance::class)->integration($p->merchant_id ?? 'unbound');
        $gate = app(BirCompliance::class)->gate($integration);
        $checks = [['name' => 'HTTPS configuration', 'passed' => str_starts_with($p->base_url, 'https://')], ['name' => 'Provider-issued credential stored', 'passed' => (bool) $p->encrypted_secret], ['name' => 'Public certificate metadata current', 'passed' => $p->certificate_expires_at && CarbonImmutable::parse($p->certificate_expires_at)->isFuture()], ['name' => 'Connection configuration enabled', 'passed' => $p->status === 'configured'], ['name' => 'BIR production transport implemented', 'passed' => false]];
        app(BirCompliance::class)->audit('integration.diagnostics', $id, $u->id, ['checks' => $checks, 'external_request' => false]);

        return response()->json(['connected' => false, 'external_request' => false, 'checks' => $checks, 'blockers' => $gate['blockers'], 'message' => 'Local readiness diagnostics completed. No network request or BIR token exchange was made.']);
    }

    public function export(Request $r)
    {
        $u = $this->actor($r);
        $r->validate(['search' => 'nullable|string|max:255', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from']);
        if ($r->has('report_limit')) {
            \App\Services\ReportCsv::limit($r, $this->logQuery($r)->count());
        }
        $rows = $this->logQuery($r)->orderByDesc('l.created_at')->get()->map(fn ($x) => $this->redact($x));
        app(BirCompliance::class)->audit('integration.logs_exported', null, $u->id, ['count' => $rows->count()]);

        return response()->streamDownload(function () use ($rows) {
            $f = fopen('php://output', 'w');
            fwrite($f, "\xEF\xBB\xBF");
            $header = ['created_at', 'endpoint', 'method', 'status_code', 'latency_ms', 'environment', 'merchant_name', 'error_code', 'error_message'];
            fputcsv($f, $header);
            foreach ($rows as $x) {
                fputcsv($f, array_map(function ($k) use ($x) {
                    $v = $x->$k ?? '';

                    return preg_match('/^\s*[=+@\-]/u', (string) $v) ? "'".$v : $v;
                }, $header));
            }fclose($f);
        }, 'NUERS-api-request-logs.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
