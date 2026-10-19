<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApiAccess;
use App\Services\BirCompliance;
use App\Services\IntegrationContract;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MerchantApiKeyController extends Controller
{
    private const DEFAULT_SCOPES = [
        'transactions:write',
        'transactions:read',
    ];

    public function index(Request $request): JsonResponse
    {
        $merchant = $this->currentMerchant($request);
        $environment=$request->validate(['environment'=>'nullable|in:live,sandbox'])['environment']??null;

        abort_unless(Schema::hasTable('merchant_api_keys'), 500, 'merchant_api_keys table is not migrated.');

        $keys = DB::table('merchant_api_keys')
            ->where('merchant_id', $merchant->id)
            ->when($environment,fn($q)=>$q->where('environment',$environment))
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($key) => $this->keyPayload($key))
            ->values();

        return response()->json([
            'environment'=>$environment??'all', 'summary' => $this->summary($merchant->id,$environment),
            'keys' => $keys,
            'webhooks' => $this->webhooks($merchant->id),
            'hourly_requests' => $this->hourlyRequests($merchant->id,$environment),
            'latency_data' => $this->latencyData($merchant->id,$environment),
            'merchant' => ['id' => $merchant->id, 'name' => $merchant->business_name, 'status' => $merchant->status, 'vat_registered' => (bool) $merchant->vat_registered],
            'source_profiles' => DB::table('integration_source_profiles')->where('merchant_id', $merchant->id)->orderByDesc('created_at')->limit(500)->get()->map(function ($p) use ($merchant) {
                $ready = $p->status === 'active' && ($p->profile_kind !== 'account_connection' || app(\App\Services\MerchantApiOnboarding::class)->matches($p, $merchant));
                return ['id'=>$p->id,'branch_code'=>$p->branch_code,'source_system'=>$p->source_system,'vat_registered'=>(bool)$p->vat_registered,
                    'seller_name'=>$p->seller_name,'valid_from'=>$p->valid_from,'valid_until'=>$p->valid_until,'profile_kind'=>$p->profile_kind,
                    'status'=>$ready ? 'active' : ($p->status === 'active' ? 'review_required' : $p->status)];
            }),
            'registered_branches' => DB::table('merchant_branches')->where('merchant_id', $merchant->id)->where('status','active')->get(['bir_branch_code','name','branch_type']),
            'access' => ['owner' => (string) $merchant->merchant_account_id === (string) $this->userFromRequest($request)->id, 'credential_mfa_required' => (bool) config('security.enforce_privileged_mfa')],
            'integration' => [
                'transaction_endpoint' => url('/api/integrations/transactions'),
                'auth_header' => 'X-NUERS-API-Key',
                'accepted_tax_types' => ['vatable', 'vat_exempt', 'zero_rated', 'non_vat'],
                'schema_version' => 'NUERS-ingestion-1',
                'document_types' => app(IntegrationContract::class)->documentCatalog(),
                'webhook_delivery_enabled' => (bool) config('security.merchant_webhook_delivery_enabled'),
                'key_validity_days' => 90,
            ],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function logs(Request $request): JsonResponse
    {
        $m = $this->currentMerchant($request);
        $v = $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])], 'page' => 'sometimes|integer|min:1|max:100000', 'key' => 'nullable|uuid', 'result' => 'nullable|in:success,error','environment'=>'nullable|in:live,sandbox']);
        $envKeys=DB::table('merchant_api_keys')->where('merchant_id',$m->id)->pluck('environment','id');
        $q = $this->environmentLogs($m->id,$v['environment']??null);
        if (! empty($v['key'])) {
            $q->where('merchant_api_key_id', $v['key']);
        }
        if (! empty($v['from'])) {
            $q->where('created_at', '>=', $v['from'].' 00:00:00');
        }
        if (! empty($v['to'])) {
            $q->where('created_at', '<', Carbon::parse($v['to'])->addDay());
        }
        if (! empty($v['result'])) {
            $q->where('status_code', $v['result'] === 'success' ? '<' : '>=', 400);
        }
        $rows = $q->orderByDesc('created_at')->orderByDesc('id')->paginate(20, ['id', 'merchant_api_key_id', 'endpoint', 'method', 'status_code', 'latency_ms', 'request_reference', 'source_system', 'created_at'], 'page', $v['page'] ?? 1);
        $rows->getCollection()->transform(function ($row) use($envKeys) {
            $row->environment=$envKeys[$row->merchant_api_key_id]??'unknown';
            $row->created_at = Carbon::parse($row->created_at)->toIso8601String();

            return $row;
        });

        return response()->json($rows)->header('Cache-Control', 'private, no-store');
    }

    public function connections(Request $request): JsonResponse
    {
        $merchant = $this->currentMerchant($request);

        abort_unless(Schema::hasTable('merchant_api_keys'), 500, 'merchant_api_keys table is not migrated.');

        $keys = DB::table('merchant_api_keys')
            ->where('merchant_id', $merchant->id)
            ->orderByRaw('last_used_at IS NULL')
            ->orderByDesc('last_used_at')
            ->orderByDesc('created_at')
            ->get();

        $devices = $keys
            ->map(fn ($key) => $this->connectionPayload($merchant->id, $key))
            ->values();

        $online = $devices->where('status', 'online')->count();
        $idle = $devices->where('status', 'idle')->count();
        $offline = $devices->where('status', 'offline')->count();
        $errorsToday = $devices->sum('errors_today');
        $requestsToday = $devices->sum('requests_today');
        $avgSuccessRate = $devices->count() > 0 ? round($devices->avg('success_rate'), 1) : 0;

        return response()->json([
            'summary' => [
                'total_connections' => $devices->count(),
                'active_connections' => $devices->where('active', true)->count(),
                'online' => $online,
                'idle' => $idle,
                'offline' => $offline,
                'requests_today' => $requestsToday,
                'transactions_today' => $requestsToday,
                'errors_today' => $errorsToday,
                'avg_success_rate' => $avgSuccessRate,
                'avg_latency_ms' => $this->avgLatency($merchant->id),
            ],
            'connections' => $devices,
            'activity' => $devices->map(fn ($device) => [
                'name' => Str::limit($device['name'], 18, ''),
                'requests' => $device['requests_today'],
                'errors' => $device['errors_today'],
            ])->values(),
            'hourly_requests' => $this->hourlyRequests($merchant->id),
            'integration' => [
                'transaction_endpoint' => url('/api/integrations/transactions'),
                'auth_header' => 'X-NUERS-API-Key',
                'accepted_tax_types' => ['vatable', 'vat_exempt', 'zero_rated', 'non_vat'],
            ],
        ]);
    }

    public function branches(Request $request): JsonResponse
    {
        $merchant = $this->currentMerchant($request);

        abort_unless(Schema::hasTable('merchant_api_keys'), 500, 'merchant_api_keys table is not migrated.');

        $keys = DB::table('merchant_api_keys')
            ->where('merchant_id', $merchant->id)
            ->orderBy('branch_type')
            ->orderBy('branch_name')
            ->get();

        $branches = $keys
            ->groupBy(fn ($key) => $this->branchCode($key))
            ->map(fn ($group, $branchCode) => $this->branchPayload($merchant, $branchCode, $group))
            ->sortBy(fn ($branch) => $branch['branch_type'] === 'main' ? 0 : 1)
            ->values();

        $active = $branches->where('status', 'active')->count();
        $totalRevenue = $branches->sum('monthly_revenue');
        $totalTxnsToday = $branches->sum('txns_today');
        $avgCompliance = $branches->count() > 0 ? round($branches->avg('compliance')) : 0;

        return response()->json([
            'summary' => [
                'total_branches' => $branches->count(),
                'active_branches' => $active,
                'monthly_revenue' => $totalRevenue,
                'transactions_today' => $totalTxnsToday,
                'avg_compliance' => $avgCompliance,
                'api_connections' => $keys->count(),
            ],
            'branches' => $branches,
            'chart' => $branches
                ->where('status', 'active')
                ->map(fn ($branch) => [
                    'name' => Str::limit($branch['name'], 18, ''),
                    'monthly_revenue' => $branch['monthly_revenue'],
                    'txns_month' => $branch['txns_month'],
                ])
                ->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $merchant = $this->currentMerchant($request);
        $user = $this->userFromRequest($request);

        abort_unless(Schema::hasTable('merchant_api_keys'), 500, 'merchant_api_keys table is not migrated.');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'environment' => ['sometimes', Rule::in(['live', 'sandbox'])],
            'scopes' => ['sometimes', 'required', 'array', 'min:1', 'max:2'],
            'scopes.*' => ['distinct', 'string', Rule::in(self::DEFAULT_SCOPES)],
            'ip_whitelist' => ['nullable', 'array', 'max:30'],
            'ip_whitelist.*' => ['ip'],
            'rate_limit_per_day' => ['nullable', 'integer', 'min:100', 'max:1000000'],
            'branch_type' => ['nullable', Rule::in(['main', 'branch'])],
            'branch_code' => ['sometimes', 'required', 'regex:/^[0-9]{5}$/'],
            'branch_name' => ['nullable', 'string', 'max:255'],
            'branch_location' => ['nullable', 'string', 'max:255'],
        ]);

        $validated['name'] = trim($validated['name']);
        abort_if($validated['name'] === '', 422, 'Enter an API key name.');
        $validated['environment'] ??= 'live';
        $plainKey = $this->generateKey($validated['environment']);
        $id = (string) Str::uuid();
        $now = now();

        return DB::transaction(function () use ($request, $merchant, $user, $validated, $plainKey, $id, $now) {
        $registered = $this->lockMerchant($request, $merchant->id);
        $branch = app(\App\Services\MerchantApiContext::class)->resolve($registered, (string) $user->id, $validated['branch_code'] ?? null);
        $profile = app(\App\Services\MerchantApiOnboarding::class)->prepare($registered, (string) $user->id, false, $branch, true);
        abort_unless($profile, 422, 'Your verified business identity needs review before connection setup. Confirm the registered name, TIN, address and VAT registration in Business Profile.');
        abort_unless($profile->status === 'active', 409, 'This source connection is unavailable. Confirm your account is active and your registered details are current.');
        DB::table('merchant_api_keys')->insert([
            'id' => $id,
            'merchant_id' => $merchant->id,
            'created_by_user_id' => $user?->id,
            'name' => $validated['name'],
            'environment' => $validated['environment'],
            'branch_type' => $branch->branch_type,
            'branch_code' => $branch->bir_branch_code,
            'branch_name' => $branch->name,
            'branch_location' => $branch->address,
            'key_prefix' => Str::substr($plainKey, 0, 18),
            'key_hash' => hash('sha256', $plainKey),
            'expires_at' => now()->addDays(90),
            'key_last_four' => Str::substr($plainKey, -4),
            'status' => 'active',
            'scopes' => json_encode($validated['scopes'] ?? ['transactions:write', 'transactions:read']),
            'ip_whitelist' => json_encode($validated['ip_whitelist'] ?? []),
            'rate_limit_per_day' => $validated['rate_limit_per_day'] ?? ($validated['environment'] === 'live' ? 50000 : 5000),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        app(BirCompliance::class)->audit('api_key.created', $id, (string) $user->id, ['merchant_id' => $merchant->id, 'environment' => $validated['environment'], 'branch_code' => $branch->bir_branch_code]);
        $record = DB::table('merchant_api_keys')->where('id', $id)->first();

        return response()->json([
            'key' => [
                ...$this->keyPayload($record),
                'plain_key' => $plainKey,
            ],
            'connection' => app(\App\Services\MerchantApiOnboarding::class)->connection($profile),
            'message' => 'Store this key now. NUERS will only show it once.',
        ], 201)->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        return DB::transaction(function () use ($request, $id) {
            $merchant = $this->currentMerchant($request);
            $this->lockMerchant($request, $merchant->id);

            $key = DB::table('merchant_api_keys')
                ->where('merchant_id', $merchant->id)
                ->where('id', $id)
                ->lockForUpdate()->first();

            abort_unless($key, 404, 'API key was not found for this Business Account.');

            $validated = $request->validate([
                'active' => ['nullable', 'boolean'],
                'name' => ['nullable', 'string', 'max:255'],
                'scopes' => ['sometimes', 'required', 'array', 'min:1', 'max:2'],
                'scopes.*' => ['distinct', 'string', Rule::in(self::DEFAULT_SCOPES)],
                'ip_whitelist' => ['nullable', 'array', 'max:30'],
                'ip_whitelist.*' => ['ip'],
                'rate_limit_per_day' => ['nullable', 'integer', 'min:100', 'max:1000000'],
                'branch_type' => ['nullable', Rule::in(['main', 'branch'])],
                'branch_code' => ['sometimes', 'required', 'regex:/^[0-9]{5}$/'],
                'branch_name' => ['nullable', 'string', 'max:255'],
                'branch_location' => ['nullable', 'string', 'max:255'],
            ]);

            abort_if($request->filled('environment') && $request->input('environment') !== $key->environment, 409, 'Key environment is immutable. Revoke and replace this key.');
            abort_if($key->status === 'revoked', 409, 'Revoked keys are read-only. Create a replacement.');
            abort_if(isset($validated['branch_code']) && $validated['branch_code'] !== $key->branch_code, 409, 'Key branch is immutable. Revoke and replace this key.');
            $updates = ['updated_at' => now()];

            if (array_key_exists('active', $validated)) {
                $updates['status'] = $validated['active'] ? 'active' : 'inactive';
                $updates['revoked_at'] = $validated['active'] ? null : now();
            }

            foreach (['name', 'rate_limit_per_day', 'branch_type', 'branch_name', 'branch_location'] as $field) {
                if (array_key_exists($field, $validated)) {
                    $updates[$field] = $validated[$field];
                }
            }

            if (array_key_exists('branch_code', $validated)) {
                $updates['branch_code'] = $this->normalizeBranchCode($validated['branch_code'] ?: ($validated['branch_name'] ?? $key->branch_name ?? $key->name));
            }

            if (array_key_exists('scopes', $validated)) {
                $updates['scopes'] = json_encode($validated['scopes']);
            }

            if (array_key_exists('ip_whitelist', $validated)) {
                $updates['ip_whitelist'] = json_encode($validated['ip_whitelist']);
            }

            DB::table('merchant_api_keys')->where('id', $id)->update($updates);
            app(BirCompliance::class)->audit('api_key.updated', $id, (string) $this->userFromRequest($request)->id, ['merchant_id' => $merchant->id, 'fields' => array_keys($updates)]);

            return response()->json([
                'key' => $this->keyPayload(DB::table('merchant_api_keys')->where('id', $id)->first()),
            ]);
        }, 3);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $merchant = $this->currentMerchant($request);

        return DB::transaction(function () use ($request, $merchant, $id) {
            $this->lockMerchant($request, $merchant->id);
            $key = DB::table('merchant_api_keys')->where('merchant_id', $merchant->id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($key, 404, 'API key was not found for this Business Account.');
            if ($key->status !== 'revoked') {
                DB::table('merchant_api_keys')->where('id', $id)->update(['status' => 'revoked', 'revoked_at' => now(), 'updated_at' => now()]);
                app(BirCompliance::class)->audit('api_key.revoked', $id, (string) $this->userFromRequest($request)->id, ['merchant_id' => $merchant->id]);
            }
            return response()->json(['ok' => true])->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    private function lockMerchant(Request $request, string $id): object
    {
        $merchant = DB::table('merchants')->where('id', $id)->lockForUpdate()->first();
        abort_unless($merchant && $merchant->status === 'active' && (string) $merchant->merchant_account_id === (string) $this->userFromRequest($request)->id, 403, 'Business ownership or activation changed. Refresh before managing credentials.');
        return $merchant;
    }

    private function currentMerchant(Request $request): object
    {
        $user = $this->userFromRequest($request);

        abort_unless($user, 401, 'Authenticated Business Account session is required.');

        $merchant = app(ApiAccess::class)->merchants($user)->first();

        abort_unless($merchant, 404, 'No Business Account registry record is linked to this login.');

        if (! $request->isMethod('GET')) {
            abort_unless((string) $merchant->merchant_account_id === (string) $user->id, 403, 'Only the registered business owner may manage integration credentials.');
            abort_unless($merchant->status === 'active', 403, 'Only active businesses can manage credentials.');
            if (config('security.enforce_privileged_mfa') && ! app(\App\Services\MfaSession::class)->verified($user)) {
                abort(response()->json(['message' => 'Complete MFA verification for this login to manage API credentials.', 'code' => 'MFA_REQUIRED', 'enrollment_required' => ! $user->mfa_secret], 403));
            }
        }

        return $merchant;
    }

    private function userFromRequest(Request $request): ?object
    {
        $token = $request->bearerToken();

        if (! $token) {
            return null;
        }

        return app(ApiAccess::class)->require($request, ['merchant', 'admin', 'super_admin']);
    }

    private function keyPayload(object $key): array
    {
        $today = $this->requestCount($key->merchant_id, $key->id, now()->startOfDay());
        $month = $this->requestCount($key->merchant_id, $key->id, now()->startOfMonth());

        return [
            'id' => (string) $key->id,
            'name' => $key->name,
            'key_preview' => "{$key->key_prefix}••••••••••••{$key->key_last_four}",
            'env' => $key->environment,
            'created' => $key->created_at ? Carbon::parse($key->created_at)->toIso8601String() : '',
            'last_used' => $key->last_used_at ? $this->humanTime($key->last_used_at) : 'Never',
            'last_used_at' => $key->last_used_at ? Carbon::parse($key->last_used_at)->toIso8601String() : null,
            'requests_today' => $today,
            'requests_month' => $month,
            'rate_limit' => (int) $key->rate_limit_per_day,
            'expires_at' => ! empty($key->expires_at) ? Carbon::parse($key->expires_at)->toIso8601String() : null, 'active' => $key->status === 'active' && ! empty($key->expires_at) && now()->lt($key->expires_at),
            'status' => $key->status === 'active' && (! $key->expires_at || now()->gte($key->expires_at)) ? 'expired' : $key->status,
            'permissions' => $this->jsonArray($key->scopes),
            'ip_whitelist' => $this->jsonArray($key->ip_whitelist),
            'branch_type' => $key->branch_type ?? 'main',
            'branch_code' => $this->branchCode($key),
            'branch_name' => $this->branchName($key),
            'branch_location' => $key->branch_location ?? null,
        ];
    }

    private function environmentLogs(string $merchantId,?string $environment=null) { return DB::table('merchant_api_request_logs')->where('merchant_id',$merchantId)->when($environment,fn($q)=>$q->whereIn('merchant_api_key_id',DB::table('merchant_api_keys')->select('id')->where('merchant_id',$merchantId)->where('environment',$environment))); }

    private function summary(string $merchantId,?string $environment=null): array
    {
        $keys = DB::table('merchant_api_keys')->where('merchant_id', $merchantId)->when($environment,fn($q)=>$q->where('environment',$environment));

        if (! Schema::hasTable('merchant_api_request_logs')) {
            return [
                'active_keys' => (clone $keys)->where('status', 'active')->where('expires_at', '>', now())->count(),
                'total_keys' => (clone $keys)->count(),
                'requests_today' => 0,
                'requests_month' => 0,
                'active_webhooks' => $this->activeWebhookCount($merchantId,$environment),
                'avg_latency_ms' => null,
            ];
        }

        $todayLogs = $this->environmentLogs($merchantId,$environment)->where('created_at', '>=', now()->startOfDay());
        $monthLogs = $this->environmentLogs($merchantId,$environment)->where('created_at', '>=', now()->startOfMonth());
        $avgLatency = (clone $todayLogs)->avg('latency_ms');

        return [
            'active_keys' => (clone $keys)->where('status', 'active')->where('expires_at', '>', now())->count(),
            'total_keys' => (clone $keys)->count(),
            'requests_today' => (clone $todayLogs)->count(),
            'requests_month' => (clone $monthLogs)->count(),
            'active_webhooks' => $this->activeWebhookCount($merchantId,$environment),
            'avg_latency_ms' => $avgLatency === null ? null : round((float) $avgLatency, 1),
        ];
    }

    private function activeWebhookCount(string $merchantId,?string $environment): int
    {
        if(!Schema::hasTable('merchant_webhook_endpoints'))return 0;
        return DB::table('merchant_webhook_endpoints')->where('merchant_id',$merchantId)->when($environment,fn($q)=>$q->where('environment',$environment))->where('status','active')->whereNotNull('verified_at')->count();
    }

    private function webhooks(string $merchantId): array
    {
        if (! Schema::hasTable('merchant_webhooks')) {
            return [];
        }

        return DB::table('merchant_webhooks')
            ->where('merchant_id', $merchantId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($webhook) => [
                'id' => (string) $webhook->id,
                'url' => $webhook->url,
                'events' => $this->jsonArray($webhook->events),
                'status' => $webhook->status,
                'deliveries' => (int) $webhook->deliveries,
                'success_rate' => $webhook->deliveries > 0 ? round(($webhook->success_count / $webhook->deliveries) * 100, 1) : 0,
                'last_delivery' => $webhook->last_delivery_at ? $this->humanTime($webhook->last_delivery_at) : 'Never',
            ])
            ->values()
            ->all();
    }

    private function hourlyRequests(string $merchantId,?string $environment=null): array
    {
        if (! Schema::hasTable('merchant_api_request_logs')) {
            return [];
        }

        return collect(range(11, 0))
            ->map(function ($offset) use ($merchantId,$environment) {
                $hour = now()->subHours($offset)->startOfHour();
                $next = $hour->copy()->addHour();
                $base = $this->environmentLogs($merchantId,$environment)
                    ->whereBetween('created_at', [$hour, $next]);

                return [
                    'hour' => $hour->format('H:00'),
                    'requests' => (clone $base)->count(),
                    'errors' => (clone $base)->where('status_code', '>=', 400)->count(),
                ];
            })
            ->values()
            ->all();
    }

    private function latencyData(string $merchantId,?string $environment=null): array
    {
        if (! Schema::hasTable('merchant_api_request_logs')) {
            return [];
        }

        return collect(range(11, 0))
            ->map(function ($offset) use ($merchantId,$environment) {
                $hour = now()->subHours($offset)->startOfHour();
                $next = $hour->copy()->addHour();
                $latency = $this->environmentLogs($merchantId,$environment)
                    ->whereBetween('created_at', [$hour, $next])
                    ->avg('latency_ms') ?: 0;

                return [
                    'hour' => $hour->format('H:00'),
                    'latency' => round((float) $latency, 1),
                ];
            })
            ->values()
            ->all();
    }

    private function requestCount(string $merchantId, string $keyId, mixed $from): int
    {
        if (! Schema::hasTable('merchant_api_request_logs')) {
            return 0;
        }

        return DB::table('merchant_api_request_logs')
            ->where('merchant_id', $merchantId)
            ->where('merchant_api_key_id', $keyId)
            ->where('created_at', '>=', $from)
            ->count();
    }

    private function avgLatency(string $merchantId): float
    {
        if (! Schema::hasTable('merchant_api_request_logs')) {
            return 0;
        }

        return round((float) (DB::table('merchant_api_request_logs')
            ->where('merchant_id', $merchantId)
            ->where('created_at', '>=', now()->startOfDay())
            ->avg('latency_ms') ?: 0), 1);
    }

    private function connectionPayload(string $merchantId, object $key): array
    {
        $today = now()->startOfDay();
        $month = now()->startOfMonth();
        $logs = Schema::hasTable('merchant_api_request_logs')
            ? DB::table('merchant_api_request_logs')->where('merchant_id', $merchantId)->where('merchant_api_key_id', $key->id)
            : null;
        $lastLog = $logs ? (clone $logs)->orderByDesc('created_at')->first() : null;
        $requestsToday = $logs ? (clone $logs)->where('created_at', '>=', $today)->count() : 0;
        $requestsMonth = $logs ? (clone $logs)->where('created_at', '>=', $month)->count() : 0;
        $errorsToday = $logs ? (clone $logs)->where('created_at', '>=', $today)->where('status_code', '>=', 400)->count() : 0;
        $last30 = $logs ? (clone $logs)->where('created_at', '>=', now()->subDays(30)) : null;
        $last30Count = $last30 ? (clone $last30)->count() : 0;
        $success30 = $last30 ? (clone $last30)->where('status_code', '<', 400)->count() : 0;
        $successRate = $last30Count > 0 ? round(($success30 / $last30Count) * 100, 1) : null;
        $lastSeenAt = $key->last_used_at ?: $lastLog?->created_at;
        $active = $key->status === 'active';

        return [
            'id' => (string) $key->id,
            'name' => $key->name,
            'type' => $this->connectionType($key->name, $lastLog?->source_system),
            'key_preview' => "{$key->key_prefix}••••••••••••{$key->key_last_four}",
            'environment' => $key->environment,
            'status' => $this->connectionStatus($active, $lastSeenAt),
            'active' => $active,
            'last_seen' => $lastSeenAt ? $this->humanTime($lastSeenAt) : 'Never connected',
            'last_seen_at' => $lastSeenAt,
            'last_source_system' => $lastLog?->source_system ?: 'Awaiting first API call',
            'branch_type' => $key->branch_type ?? 'main',
            'branch_code' => $this->branchCode($key),
            'branch_name' => $this->branchName($key),
            'branch_location' => $key->branch_location ?? null,
            'requests_today' => $requestsToday,
            'requests_month' => $requestsMonth,
            'errors_today' => $errorsToday,
            'success_rate' => $successRate,
            'uptime_pct' => $successRate,
            'rate_limit' => (int) $key->rate_limit_per_day,
            'permissions' => $this->jsonArray($key->scopes),
            'ip_whitelist' => $this->jsonArray($key->ip_whitelist),
            'created' => $key->created_at ? Carbon::parse($key->created_at)->toIso8601String() : '',
        ];
    }

    private function connectionStatus(bool $active, mixed $lastSeenAt): string
    {
        if (! $active) {
            return 'offline';
        }

        if (! $lastSeenAt) {
            return 'idle';
        }

        $lastSeen = Carbon::parse($lastSeenAt);

        if ($lastSeen->greaterThanOrEqualTo(now()->subMinutes(15))) {
            return 'online';
        }

        if ($lastSeen->greaterThanOrEqualTo(now()->subDay())) {
            return 'idle';
        }

        return 'offline';
    }

    private function connectionType(string $name, ?string $sourceSystem): string
    {
        $value = Str::lower($name.' '.($sourceSystem ?: ''));

        return match (true) {
            str_contains($value, 'accounting'), str_contains($value, 'quickbooks'), str_contains($value, 'xero') => 'Accounting',
            str_contains($value, 'billing') => 'Billing',
            str_contains($value, 'erp'), str_contains($value, 'sap'), str_contains($value, 'netsuite') => 'ERP',
            str_contains($value, 'shopify'), str_contains($value, 'woocommerce'), str_contains($value, 'commerce'), str_contains($value, 'storefront') => 'E-Commerce',
            str_contains($value, 'mobile'), str_contains($value, 'app') => 'Mobile App',
            str_contains($value, 'kiosk') => 'Kiosk',
            str_contains($value, 'pos'), str_contains($value, 'cashier'), str_contains($value, 'terminal') => 'POS',
            default => 'External API',
        };
    }

    private function branchPayload(object $merchant, string $branchCode, mixed $keys): array
    {
        $first = $keys->first();
        $branchName = $this->branchName($first);
        $branchType = $first->branch_type ?? 'main';
        $keyIds = $keys->pluck('id')->values()->all();
        $online = $keys->filter(fn ($key) => $this->connectionStatus($key->status === 'active', $key->last_used_at) === 'online')->count();
        $idle = $keys->filter(fn ($key) => $this->connectionStatus($key->status === 'active', $key->last_used_at) === 'idle')->count();
        $offline = $keys->filter(fn ($key) => $this->connectionStatus($key->status === 'active', $key->last_used_at) === 'offline')->count();
        $transactionQuery = $this->branchTransactionQuery($merchant->id, $branchCode, $branchName);
        $monthQuery = (clone $transactionQuery)->where('created_at', '>=', now()->startOfMonth());
        $todayQuery = (clone $transactionQuery)->where('created_at', '>=', now()->startOfDay());
        $last30Logs = Schema::hasTable('merchant_api_request_logs')
            ? DB::table('merchant_api_request_logs')->whereIn('merchant_api_key_id', $keyIds)->where('created_at', '>=', now()->subDays(30))
            : null;
        $last30Count = $last30Logs ? (clone $last30Logs)->count() : 0;
        $successCount = $last30Logs ? (clone $last30Logs)->where('status_code', '<', 400)->count() : 0;
        $compliance = $last30Count > 0 ? round(($successCount / $last30Count) * 100) : null;
        $latestSeen = $keys->pluck('last_used_at')->filter()->sortDesc()->first();

        return [
            'id' => $branchCode,
            'name' => $branchName,
            'branch_code' => $branchCode,
            'branch_type' => $branchType,
            'address' => ($first->branch_location ?? null) ?: ($branchType === 'main' ? $this->merchantAddress($merchant) : 'Branch location managed by API key'),
            'city' => $merchant->city ?: '',
            'region' => $merchant->region ?: '',
            'status' => $keys->where('status', 'active')->count() > 0 ? 'active' : 'inactive',
            'monthly_revenue' => (float) (clone $monthQuery)->sum('amount'),
            'txns_today' => (int) (clone $todayQuery)->count(),
            'txns_month' => (int) (clone $monthQuery)->count(),
            'compliance' => $compliance,
            'growth' => 0,
            'api_connections' => $keys->count(),
            'online_connections' => $online,
            'idle_connections' => $idle,
            'offline_connections' => $offline,
            'last_seen' => $latestSeen ? $this->humanTime($latestSeen) : 'Never connected',
            'keys' => $keys->map(fn ($key) => [
                'id' => (string) $key->id,
                'name' => $key->name,
                'environment' => $key->environment,
                'status' => $key->status,
                'key_preview' => "{$key->key_prefix}••••••••••••{$key->key_last_four}",
                'last_seen' => $key->last_used_at ? $this->humanTime($key->last_used_at) : 'Never connected',
                'requests_today' => $this->requestCount($merchant->id, $key->id, now()->startOfDay()),
            ])->values(),
        ];
    }

    private function branchTransactionQuery(string $merchantId, string $branchCode, string $branchName)
    {
        $query = DB::table('merchant_transactions')->where('merchant_id', $merchantId);

        return $query->where(function ($filter) use ($branchCode, $branchName) {
            if (Schema::hasColumn('merchant_transactions', 'branch_code')) {
                $filter->where('branch_code', $branchCode);
            }

            $filter
                ->orWhere('branch', $branchName)
                ->orWhere('branch', $branchCode);
        });
    }

    private function branchCode(object $key): string
    {
        $branchType = $key->branch_type ?? 'main';
        $fallback = $branchType === 'main' ? 'MAIN' : ($key->branch_name ?? $key->name);

        return $this->normalizeBranchCode($key->branch_code ?? $fallback);
    }

    private function branchName(object $key): string
    {
        if (! empty($key->branch_name)) {
            return $key->branch_name;
        }

        return ($key->branch_type ?? 'main') === 'main' ? 'Main Office' : $key->name;
    }

    private function normalizeBranchCode(?string $value): string
    {
        $normalized = preg_replace('/[^A-Z0-9]+/', '-', Str::upper(trim((string) $value)));
        $normalized = trim((string) $normalized, '-');

        return $normalized !== '' ? Str::limit($normalized, 40, '') : 'MAIN';
    }

    private function merchantAddress(object $merchant): string
    {
        return trim((string) ($merchant->business_registration_address ?? $merchant->address ?? '')) ?: implode(', ', array_filter([
            $merchant->barangay ?? null,
            $merchant->city ?? null,
            $merchant->country ?? null,
        ]));
    }

    private function jsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    private function generateKey(string $environment): string
    {
        return 'nuers_'.$environment.'_sk_'.Str::random(40);
    }

    private function humanTime(mixed $timestamp): string
    {
        return Carbon::parse($timestamp)->diffForHumans();
    }
}
