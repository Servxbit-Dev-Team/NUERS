<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\{ApiAccess,BirCompliance,IntegrationContract,MerchantWebhooks,MfaSession,WebhookDestination};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Crypt};
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MerchantWebhookController extends Controller
{
    private function actor(Request $r, bool $write = false): array
    {
        $u = app(ApiAccess::class)->require($r, ['merchant','admin','super_admin']);
        $m = app(ApiAccess::class)->merchants($u)->orderBy('id')->first();
        abort_unless($m, 404, 'No linked business account.');
        $owner = (string)$m->merchant_account_id === (string)$u->id;
        if ($write) {
            abort_unless($owner && $m->status === 'active', 403, 'Only the verified Merchant Admin of an active account can manage webhook delivery.');
            if (config('security.enforce_privileged_mfa') && !app(MfaSession::class)->verified($u)) {
                abort(response()->json(['code' => 'MFA_REQUIRED','message' => 'Complete MFA verification for this login.'], 403));
            }
        }
        return [$u,$m,$owner];
    }
    private function locked(Request $r, callable $action)
    {
        return DB::transaction(function () use ($r, $action) {
            [$u,$m] = $this->actor($r, true);
            $m = DB::table('merchants')->where('id', $m->id)->lockForUpdate()->first();
            abort_unless($m && $m->status === 'active' && (string)$m->merchant_account_id === (string)$u->id, 403, 'Business access changed.');
            return $action($u, $m);
        }, 3);
    }
    private function hook(string $id, object $m): object
    {
        $p = DB::table('merchant_webhook_endpoints')->where('id', $id)->where('merchant_id', $m->id)->lockForUpdate()->first();
        abort_unless($p, 404, 'Webhook not found for this business.');
        return $p;
    }
    private function audit(string $event, string $id, string $actor, array $data = []): void
    {
        app(BirCompliance::class)->audit('merchant_webhook.'.$event, $id, $actor, $data);
    }
    private function reply(array $data, int $status = 200)
    {
        return response()->json($data, $status)->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $r)
    {
        [$u,$m,$owner] = $this->actor($r);
        $v = $r->validate(['environment' => 'required|in:live,sandbox']);
        $service = app(MerchantWebhooks::class);
        $q = DB::table('merchant_webhook_deliveries')->where('merchant_id', $m->id)->where('environment', $v['environment']);
        $counts = (clone $q)->select('state', DB::raw('COUNT(*) as n'))->groupBy('state')->pluck('n', 'state');
        $worker = DB::table('merchant_webhook_runtime')->where('id', 'dispatcher')->first();
        $fresh = $worker && $worker->last_seen_at && now()->subMinutes(3)->lte($worker->last_seen_at);
        $endpoints = DB::table('merchant_webhook_endpoints')->where('merchant_id', $m->id)->where('environment', $v['environment'])->orderByDesc('created_at')->limit(100)->get();
        return $this->reply(['environment' => $v['environment'],'can_manage' => $owner && $m->status === 'active','delivery_enabled' => $service->enabled(),
            'worker' => ['state' => !$service->enabled() ? 'paused' : ($fresh ? 'responding' : 'not_observed'),'last_seen_at' => $worker?->last_seen_at],
            'events' => MerchantWebhooks::EVENTS,'endpoints' => $endpoints->map(fn ($p) => $service->endpoint($p, $owner)),
            'summary' => ['active' => $endpoints->where('status', 'active')->whereNotNull('verified_at')->count(),'pending' => (int)($counts['pending'] ?? 0) + (int)($counts['retry'] ?? 0) + (int)($counts['sending'] ?? 0),'delivered' => (int)($counts['delivered'] ?? 0),'dead_letter' => (int)($counts['dead_letter'] ?? 0)],
            'notice' => 'Callbacks are asynchronous and may arrive more than once or out of order. Verify the raw-body signature and deduplicate by event ID. Delivery does not establish BIR acceptance.']);
    }

    public function store(Request $r)
    {
        $this->actor($r, true);
        $v = $r->validate(['request_id' => 'required|uuid','name' => 'required|string|max:120','url' => 'required|string|max:500','environment' => 'required|in:live,sandbox',
            'events' => 'required|array|min:1|max:4','events.*' => ['required','distinct',Rule::in(array_keys(MerchantWebhooks::EVENTS))],'ownership_confirmed' => 'required|accepted']);
        $v['name'] = trim($v['name']);
        abort_if($v['name'] === '', 422, 'Enter an endpoint name.');
        $v['url'] = app(WebhookDestination::class)->normalize($v['url']);
        app(WebhookDestination::class)->resolve($v['url']);
        sort($v['events']);
        $hash = app(IntegrationContract::class)->canonicalHash($v);
        return $this->locked($r, function ($u, $m) use ($v, $hash) {
            $old = DB::table('merchant_webhook_endpoints')->where('request_id', $v['request_id'])->first();
            if ($old) {
                abort_unless($old->merchant_id === $m->id && hash_equals($old->request_hash, $hash), 409, 'Request ID conflicts.');
                return $this->reply(['endpoint' => app(MerchantWebhooks::class)->endpoint($old),'secret' => null,'duplicate' => true,'message' => 'Already saved. If the one-time secret was lost, rotate it.']);
            }
            $q = DB::table('merchant_webhook_endpoints')->where('merchant_id', $m->id)->where('environment', $v['environment'])->where('status', '!=', 'revoked');
            abort_if((clone $q)->count() >= 5, 422, 'At most five non-revoked endpoints are allowed per environment.');
            abort_if((clone $q)->where('url_hash', hash('sha256', $v['url']))->exists(), 409, 'This callback URL is already configured in this environment.');
            $id = (string)Str::uuid();
            $secret = 'whsec_'.Str::random(48);
            DB::table('merchant_webhook_endpoints')->insert(['id' => $id,'merchant_id' => $m->id,'environment' => $v['environment'],'name' => $v['name'],
                'encrypted_url' => Crypt::encryptString($v['url']),'url_hash' => hash('sha256', $v['url']),'host' => parse_url($v['url'], PHP_URL_HOST),
                'events' => json_encode($v['events']),'encrypted_secret' => Crypt::encryptString($secret),'secret_last_four' => substr($secret, -4),
                'request_id' => $v['request_id'],'request_hash' => $hash,'status' => 'pending','created_at' => now(),'updated_at' => now()]);
            $this->audit('created', $id, (string)$u->id, ['merchant_id' => $m->id,'environment' => $v['environment'],'host' => parse_url($v['url'], PHP_URL_HOST),'events' => $v['events'],'ownership_confirmed' => true]);
            return $this->reply(['endpoint' => app(MerchantWebhooks::class)->endpoint($this->hook($id, $m)),'secret' => $secret,'message' => 'Save the signing secret once, install the receiver, verify the endpoint, then enable delivery.'], 201);
        });
    }

    public function update(Request $r, string $id)
    {
        $v = $r->validate(['url' => 'prohibited','environment' => 'prohibited','secret' => 'prohibited','merchant_id' => 'prohibited','revision' => 'required|integer|min:1','name' => 'required|string|max:120','events' => 'required|array|min:1|max:4',
            'events.*' => ['distinct',Rule::in(array_keys(MerchantWebhooks::EVENTS))],'status' => 'required|in:active,paused']);
        $v['name'] = trim($v['name']);
        abort_if($v['name'] === '', 422, 'Enter an endpoint name.');
        return $this->locked($r, function ($u, $m) use ($v, $id) {
            $p = $this->hook($id, $m);
            abort_if($p->status === 'revoked', 409, 'Revoked endpoints are read-only.');
            abort_unless($p->revision === $v['revision'], 409, 'Configuration changed. Refresh first.');
            abort_if($v['status'] === 'active' && !$p->verified_at, 409, 'Verify ownership with a signed challenge before enabling delivery.');
            DB::table('merchant_webhook_endpoints')->where('id', $id)->update(['name' => $v['name'],'events' => json_encode(array_values($v['events'])),'status' => $v['status'],'revision' => $p->revision + 1,'updated_at' => now()]);
            $this->audit('updated', $id, (string)$u->id, ['status' => $v['status'],'events' => $v['events']]);
            return $this->reply(['endpoint' => app(MerchantWebhooks::class)->endpoint($this->hook($id, $m))]);
        });
    }

    public function rotate(Request $r, string $id)
    {
        $v = $r->validate(['revision' => 'required|integer|min:1']);
        return $this->locked($r, function ($u, $m) use ($v, $id) {
            $p = $this->hook($id, $m);
            abort_if($p->status === 'revoked', 409, 'Revoked endpoints cannot be rotated.');
            abort_unless($p->revision === $v['revision'], 409, 'Configuration changed. Refresh first.');
            $secret = 'whsec_'.Str::random(48);
            DB::table('merchant_webhook_endpoints')->where('id', $id)->update(['encrypted_secret' => Crypt::encryptString($secret),'secret_last_four' => substr($secret, -4),
                'secret_version' => $p->secret_version + 1,'status' => 'pending','verified_at' => null,'verification_delivery_id' => null,'revision' => $p->revision + 1,'updated_at' => now()]);
            $this->audit('secret_rotated', $id, (string)$u->id, ['version' => $p->secret_version + 1,'delivery_paused' => true]);
            return $this->reply(['endpoint' => app(MerchantWebhooks::class)->endpoint($this->hook($id, $m)),'secret' => $secret,'message' => 'Store the replacement secret. Old signatures stop validating; verify the receiver again before enabling. In-flight deliveries may still arrive.']);
        });
    }

    public function destroy(Request $r, string $id)
    {
        $v = $r->validate(['revision' => 'required|integer|min:1']);
        return $this->locked($r, function ($u, $m) use ($v, $id) {
            $p = $this->hook($id, $m);
            abort_unless($p->revision === $v['revision'], 409, 'Configuration changed. Refresh first.');
            if ($p->status !== 'revoked') {
                DB::table('merchant_webhook_endpoints')->where('id', $id)->update(['status' => 'revoked','revision' => $p->revision + 1,'updated_at' => now()]);
                $this->audit('revoked', $id, (string)$u->id);
            }
            return $this->reply(['message' => 'Webhook revoked. Existing delivery history is preserved. An already in-flight delivery may still arrive.']);
        });
    }

    public function test(Request $r, string $id)
    {
        $v = $r->validate(['revision' => 'required|integer|min:1','kind' => 'required|in:verify,test']);
        abort_unless(app(MerchantWebhooks::class)->enabled(), 503, 'Merchant webhook dispatch is paused by the platform.');
        return $this->locked($r, function ($u, $m) use ($v, $id) {
            $p = $this->hook($id, $m);
            abort_if($p->status === 'revoked', 409, 'This endpoint is revoked.');
            abort_unless($p->revision === $v['revision'], 409, 'Configuration changed. Refresh first.');
            abort_if($p->last_verification_requested_at && now()->subMinute()->lt($p->last_verification_requested_at), 429, 'Wait one minute between endpoint tests.');
            $service = app(MerchantWebhooks::class);
            $identity = (string)Str::uuid();
            if ($v['kind'] === 'verify') {
                $delivery = $service->queue($p, 'webhook.endpoint_challenge', $id, ['challenge' => Str::random(48),'instruction' => 'Verify the signature, then reply with JSON {"nuers_challenge":"<the challenge value>"}.'], $identity);
                DB::table('merchant_webhook_endpoints')->where('id', $id)->update(['verification_delivery_id' => $delivery,'last_verification_requested_at' => now(),'updated_at' => now()]);
            } else {
                abort_unless($p->verified_at && $p->status === 'active', 409, 'Verify and enable this endpoint before sending a test event.');
                $delivery = $service->queue($p, 'webhook.test', $id, ['test_only' => true,'message' => 'Synthetic callback test. No sale, wallet debit or BIR submission.'], $identity);
                DB::table('merchant_webhook_endpoints')->where('id', $id)->update(['last_verification_requested_at' => now(),'updated_at' => now()]);
            }
            $this->audit('test_queued', $delivery, (string)$u->id, ['endpoint_id' => $id,'kind' => $v['kind'],'environment' => $p->environment]);
            return $this->reply(['delivery_id' => $delivery,'message' => 'Queued for the callback worker. Refresh delivery logs for the verified result.'], 202);
        });
    }

    public function logs(Request $r)
    {
        [$u,$m] = $this->actor($r);
        $v = $r->validate(['environment' => 'required|in:live,sandbox','endpoint' => 'nullable|uuid','state' => 'nullable|in:pending,sending,retry,delivered,dead_letter,cancelled','page' => 'nullable|integer|min:1|max:100000']);
        $q = DB::table('merchant_webhook_deliveries')->where('merchant_id', $m->id)->where('environment', $v['environment']);
        if (!empty($v['endpoint'])) {
            $q->where('endpoint_id', $v['endpoint']);
        }if (!empty($v['state'])) {
            $q->where('state', $v['state']);
        }
        $rows = $q->orderByDesc('created_at')->orderByDesc('id')->paginate(15, ['id','endpoint_id','environment','event_type','subject_id','state','attempts','manual_retries','last_http_status','last_error','available_at','delivered_at','created_at'], 'page', $v['page'] ?? 1);
        return $this->reply($rows->toArray());
    }

    public function detail(Request $r, string $id)
    {
        [$u,$m] = $this->actor($r);
        $v = $r->validate(['environment' => 'required|in:live,sandbox']);
        $d = DB::table('merchant_webhook_deliveries')->where('id', $id)->where('merchant_id', $m->id)->where('environment', $v['environment'])->first();
        abort_unless($d, 404);
        return $this->reply(['delivery' => collect($d)->except(['encrypted_body','lease_token','lease_until'])->all(),
            'body' => json_decode(Crypt::decryptString($d->encrypted_body), true),'attempts' => DB::table('merchant_webhook_attempts')->where('delivery_id', $id)->orderByDesc('number')->limit(60)->get()]);
    }

    public function retry(Request $r, string $id)
    {
        $v = $r->validate(['environment' => 'required|in:live,sandbox']);
        return $this->locked($r, function ($u, $m) use ($id, $v) {
            $row = DB::table('merchant_webhook_deliveries')->where('id', $id)->where('merchant_id', $m->id)->where('environment', $v['environment'])->first();
            abort_unless($row, 404);
            $p = $this->hook($row->endpoint_id, $m);
            $d = DB::table('merchant_webhook_deliveries')->where('id', $id)->lockForUpdate()->first();
            abort_unless($p->status === 'active' && $p->verified_at && $p->environment === $d->environment, 409, 'Verify and enable the same endpoint before retrying.');
            abort_if($d->event_type === 'webhook.endpoint_challenge', 409, 'Queue a new verification challenge instead.');
            abort_unless(in_array($d->state, ['dead_letter','cancelled'], true), 409, 'Only failed or cancelled deliveries can be manually retried.');
            abort_unless($d->event_type === 'webhook.test' || in_array($d->event_type,json_decode($p->events,true),true),409,'This event is no longer subscribed.');
            abort_if($d->manual_retries >= 3,429,'Manual retry limit reached. Reconcile the immutable event with your receiver.');
            DB::table('merchant_webhook_deliveries')->where('id',$id)->update(['state' => 'pending','secret_version' => $p->secret_version,'cycle_attempts' => 0,'manual_retries' => $d->manual_retries + 1,'retry_deadline_at' => now()->addDay(),'available_at' => now(),'lease_token' => null,'lease_until' => null,'updated_at' => now()]);
            $this->audit('manual_retry',$id,(string)$u->id,['endpoint_id' => $p->id,'unchanged_event_id' => $id]);
            return $this->reply(['message' => 'The same immutable event is queued again. The receiver must deduplicate its event ID.'],202);
        });
    }
}
