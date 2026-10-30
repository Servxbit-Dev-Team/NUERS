<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\{ApiAccess, BirCompliance, PlatformMail};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Crypt, DB};
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;

class MailConfigurationController extends Controller
{
    public function show(Request $r)
    {
        app(ApiAccess::class)->require($r, ['admin', 'super_admin']);
        $row = DB::table('platform_mail_settings')->where('id', 1)->first();
        $settings = app(PlatformMail::class)->settings();
        $public = $settings ?? ['host' => '', 'port' => 587, 'encryption' => 'starttls', 'username' => '', 'from_address' => '', 'from_name' => 'NUERS', 'enabled' => false];
        $public['from_name'] = 'NUERS';
        $public['has_password'] = !empty($public['password']);
        unset($public['password']);
        return response()->json(['smtp' => $public, 'revision' => $row?->revision ?? 0, 'tested' => $row && $row->tested_hash === $row->connection_hash, 'tested_at' => $row?->tested_at ? CarbonImmutable::parse($row->tested_at, config('app.timezone'))->toIso8601String() : null,
            'environment' => app()->environment(), 'mail_counts' => DB::table('document_email_deliveries')->selectRaw('state, COUNT(*) as total')->groupBy('state')->get(),
            'changes' => DB::table('platform_configuration_changes as c')->leftJoin('users as u', 'u.id', '=', 'c.actor_id')->orderByDesc('c.created_at')->limit(20)->get(['c.id', 'c.module', 'c.action', 'c.revision', 'c.changed_fields', 'c.created_at', 'u.name as actor'])->map(function ($change) {
                $change->created_at = CarbonImmutable::parse($change->created_at, config('app.timezone'))->toIso8601String();
                return $change;
            }),
            'system' => ['timezone' => config('app.timezone'), 'queue' => config('queue.default'), 'eis_transport' => (bool) config('bir_compliance.transport_implemented'), 'maintenance' => app()->isDownForMaintenance(), 'release_reference' => config('security.release_evidence_reference')]])->header('Cache-Control', 'private, no-store');
    }

    public function save(Request $r)
    {
        $actor = app(ApiAccess::class)->require($r, ['admin', 'super_admin']);
        $v = $r->validate(['revision' => 'required|integer|min:0', 'host' => ['required', 'string', 'max:253', 'regex:/\A(?=.{1,253}\z)[a-zA-Z0-9](?:[a-zA-Z0-9.-]*[a-zA-Z0-9])?\z/'],
            'port' => ['required', 'integer', Rule::in([465, 587, 2525])], 'encryption' => ['required', Rule::in(['starttls', 'tls'])], 'username' => 'required|string|max:255|not_regex:/[\r\n]/',
            'password' => 'nullable|string|max:1000|not_regex:/[\r\n]/', 'from_address' => 'required|email:rfc|max:254', 'from_name' => 'required|string|max:100|not_regex:/[\r\n]/', 'enabled' => 'required|boolean']);
        abort_unless(str_contains($v['host'], '.') && !filter_var($v['host'], FILTER_VALIDATE_IP) && !preg_match('/(?:^|\.)(localhost|local|internal|test)$/i', $v['host']), 422, 'Enter a public SMTP hostname.');
        abort_if(($v['port'] === 465) !== ($v['encryption'] === 'tls'), 422, 'Port 465 requires implicit TLS. Use STARTTLS on port 587 or 2525.');
        DB::transaction(function () use ($v, $actor) {
            $row = DB::table('platform_mail_settings')->where('id', 1)->lockForUpdate()->first();
            abort_unless(($row?->revision ?? 0) === $v['revision'], 409, 'Configuration changed. Refresh before saving.');
            $old = app(PlatformMail::class)->settings() ?? [];
            $settings = $v;
            unset($settings['revision']);
            $settings['from_name'] = 'NUERS';
            $settings['host'] = strtolower($settings['host']);
            $settings['password'] = $settings['password'] ?? $old['password'] ?? '';
            abort_if($settings['password'] === '', 422, 'An SMTP password or app password is required.');
            $hash = app(PlatformMail::class)->connectionHash($settings);
            abort_if($settings['enabled'] && (!$row || $row->tested_hash !== $hash), 422, 'Save with delivery disabled, send a successful test, then enable customer copies.');
            $fields = array_keys(array_filter($settings, fn ($value, $key) => ($old[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH));
            $revision = $v['revision'] + 1;
            DB::table('platform_mail_settings')->updateOrInsert(['id' => 1], ['encrypted_settings' => Crypt::encryptString(json_encode($settings, JSON_THROW_ON_ERROR)), 'connection_hash' => $hash, 'revision' => $revision, 'updated_by' => $actor->id, 'created_at' => $row?->created_at ?? now(), 'updated_at' => now()]);
            DB::table('platform_configuration_changes')->insert(['id' => (string) Str::uuid(), 'module' => 'SMTP Configuration', 'action' => $settings['enabled'] ? 'Saved · delivery enabled' : 'Saved · delivery disabled', 'revision' => $revision, 'actor_id' => $actor->id, 'changed_fields' => json_encode($fields), 'created_at' => now()]);
            app(BirCompliance::class)->audit('configuration.smtp.saved', 'smtp', (string) $actor->id, ['revision' => $revision, 'changed_fields' => $fields, 'enabled' => $settings['enabled']]);
        });
        return $this->show($r);
    }

    public function test(Request $r)
    {
        $actor = app(ApiAccess::class)->require($r, ['admin', 'super_admin']);
        $v = $r->validate(['email' => 'required|email:rfc|max:254', 'revision' => 'required|integer|min:1']);
        $row = DB::table('platform_mail_settings')->where('id', 1)->first();
        abort_unless($row && $row->revision === $v['revision'], 409, 'Save and refresh the configuration before testing.');
        $store = config('cache.default');
        $limiter = new RateLimiter(Cache::store($store === 'array' ? 'database' : $store));
        $budget = 'smtp-test:'.$actor->id;
        abort_if($limiter->tooManyAttempts($budget, 5), 429, 'SMTP test budget reached. Try again in a minute.');
        $limiter->hit($budget, 60);
        try {
            app(PlatformMail::class)->send(json_decode(Crypt::decryptString($row->encrypted_settings), true, flags: JSON_THROW_ON_ERROR), $v['email'], 'NUERS SMTP configuration test', 'This is a NUERS SMTP test. If you received this email, outbound delivery reached this mailbox. No BIR transmission or financial record was changed.');
        } catch (\Throwable $e) {
            app(BirCompliance::class)->audit('configuration.smtp.test_failed', 'smtp', (string) $actor->id, ['revision' => $row->revision, 'error_code' => 'smtp_test_failed']);
            return response()->json(['message' => 'SMTP test could not be confirmed. Check host, TLS, credentials and sender permissions. No credentials or provider response are exposed.'], 422);
        }
        DB::table('platform_mail_settings')->where('id', 1)->where('connection_hash', $row->connection_hash)->update(['tested_hash' => $row->connection_hash, 'tested_at' => now()]);
        app(BirCompliance::class)->audit('configuration.smtp.test_accepted', 'smtp', (string) $actor->id, ['revision' => $row->revision]);
        return response()->json(['message' => 'SMTP server accepted the test email. Check the recipient inbox and spam folder.']);
    }
}
