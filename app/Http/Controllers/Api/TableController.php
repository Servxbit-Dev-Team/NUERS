<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Models\Merchant;
use App\Models\MerchantTransaction;
use App\Models\Profile;
use App\Models\SystemNotification;
use App\Models\TransactionReceipt;
use App\Models\User;
use App\Services\ApiAccess;
use App\Services\BirCompliance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TableController extends Controller
{
    private const TABLES = [
        'profiles' => Profile::class,
        'client_accounts' => ClientAccount::class,
        'merchants' => Merchant::class,
        'notifications' => SystemNotification::class,
        'merchant_transactions' => MerchantTransaction::class,
        'transaction_receipts' => TransactionReceipt::class,
    ];

    public function handle(Request $request, string $table): JsonResponse
    {
        abort_unless(isset(self::TABLES[$table]), 404, 'Unknown table.');
        $actor = $this->userFromRequest($request);
        abort_unless($actor, 401, 'An authenticated session is required.');
        $actorRole = DB::table('profiles')->where('id', $actor->id)->value('role');
        abort_unless(in_array($actorRole, ['admin', 'super_admin', 'bir', 'merchant', 'client', 'consumer'], true), 403);
        $request->attributes->set('table_actor', $actor);
        $request->attributes->set('table_role', $actorRole);
        if ($request->method() !== 'GET') {
            abort_if($request->method() === 'DELETE', 409, 'Records and linked identities are preserved. Use controlled deactivation instead.');
            abort_if($request->method() === 'POST', 409, 'Records are created only by their verified workflows (registration, provisioning, issuance).');
            abort_unless(is_array($request->input('values', [])), 422, 'values must be an object.');
            if ($table === 'client_accounts') {
                abort_unless(in_array($request->method(), ['PATCH', 'PUT'], true), 409, 'Use the verified registration workflow to create accounts.');
                $allowed = in_array($actorRole, ['admin', 'super_admin'], true) ? ['status', 'full_name', 'mobile', 'notification_preferences'] : ['full_name', 'mobile', 'notification_preferences'];
                abort_if(array_diff(array_keys($request->input('values', [])), $allowed), 403, 'Identity, TIN, wallet balance and security state require dedicated verified workflows.');
            }
            if ($table === 'notifications') {
                abort_unless(in_array($actorRole, ['admin', 'super_admin'], true), 403);
                abort_unless(in_array($request->method(), ['PATCH', 'PUT'], true) && array_diff(array_keys($request->input('values', [])), ['is_read']) === [], 403, 'Only read state may change through this endpoint.');
            }
        }

        if ($table === 'merchants') {
            $actor = $this->userFromRequest($request);
            abort_unless($actor, 401);
            abort_unless($request->method() === 'GET', 409, 'Use the audited business registry workflow. Registry records cannot be deleted or modified through the generic endpoint.');
            $role = DB::table('profiles')->where('id', $actor->id)->value('role');
            abort_unless(in_array($role, ['admin', 'super_admin', 'bir', 'merchant']), 403);
            if ($role === 'merchant') {
                $request->attributes->set('merchant_registry_owner', $actor->id);
            }
        }

        if ($table === 'profiles' && $request->method() !== 'GET') {
            $actor = $this->userFromRequest($request);
            abort_unless($actor, 401, 'An authenticated session is required.');
            $role = DB::table('profiles')->where('id', $actor->id)->value('role');
            $administrator = in_array($role, ['admin', 'super_admin'], true);
            abort_if($role === 'merchant', 403, 'Merchant identity changes require administrator review through Business Profile.');
            if (! $administrator) {
                abort_unless(in_array($request->method(), ['PATCH', 'PUT'], true), 403, 'Only administrators may create or remove profiles.');
                $values = $request->input('values', []);
                foreach (['id' => $actor->id, 'role' => $role, 'email' => $actor->email] as $key => $current) {
                    abort_if(array_key_exists($key, $values) && (string) $values[$key] !== (string) $current, 403, 'Identity and role changes require authorized administration.');
                }
                $request->attributes->set('profile_scope_id', $actor->id);
            }
            $values = $request->input('values', []);
            foreach (Arr::isList($values) ? $values : [$values] as $value) {
                abort_unless(is_array($value), 422, 'values must be an object.');
                abort_if(array_diff(array_keys($value), ['id', 'role', 'email', 'full_name', 'organization']), 403, 'Use the verified identity workflow for TIN and security changes.');
                // BIR review authority requires a separately verified staff provisioning process.
                abort_if(($value['role'] ?? null) === 'bir', 403, 'BIR review authority cannot be self-assigned through the generic profile endpoint.');
                abort_if(($value['role'] ?? null) === 'super_admin' && $role !== 'super_admin', 403, 'Only a super administrator can grant super administrator access.');
            }
        }

        abort_if(in_array($table, ['merchant_transactions', 'transaction_receipts'], true) && $request->method() !== 'GET', 409, 'Issued financial records are preserved. Use the authorized issuance or traceable correction workflow.');

        return match ($request->method()) {
            'GET' => $this->index($request, self::TABLES[$table]),
            'PUT', 'PATCH' => $this->update($request, self::TABLES[$table]),
            default => abort(405),
        };
    }

    private function index(Request $request, string $model): JsonResponse
    {
        /** @var Builder $query */
        $query = $model::query();
        $this->scope($query, $request, $model);
        $this->validateQuery($query, $request);
        $this->applyFilters($query, $request);

        $count = null;
        if ($request->query('count') === 'exact') {
            $count = (clone $query)->count();
        }

        if ($request->boolean('head')) {
            return response()->json(['data' => null, 'count' => $count]);
        }

        $this->applyOrdering($query, $request);

        $query->limit($request->integer('limit', 100));

        $single = $request->boolean('single') || $request->boolean('maybeSingle');
        $data = $single ? $query->first() : $query->get();
        if ($hide = $this->internalColumns($model, $request->attributes->get('table_role'))) {
            $data instanceof \Illuminate\Support\Collection ? $data->each->makeHidden($hide) : $data?->makeHidden($hide);
        }

        return response()->json(['data' => $data, 'count' => $count]);
    }

    private function update(Request $request, string $model): JsonResponse
    {
        /** @var Builder $query */
        $query = $model::query();
        $this->scope($query, $request, $model);
        $this->validateQuery($query, $request);
        $this->applyFilters($query, $request);

        if ($model === Profile::class && $request->attributes->has('profile_scope_id')) {
            $query->where('id', $request->attributes->get('profile_scope_id'));
        }
        $this->guardUpdate($request, $query, $model);
        $request->validate(['values' => 'required|array', 'values.full_name' => 'sometimes|string|max:255', 'values.organization' => 'sometimes|string|max:255', 'values.email' => 'sometimes|email|max:255', 'values.mobile' => 'sometimes|nullable|string|max:80', 'values.is_read' => 'sometimes|boolean', 'values.role' => 'sometimes|in:admin,super_admin,merchant,client,consumer', 'values.status' => 'sometimes|in:pending,under_review,suspended,inactive,rejected']);
        $payload = $this->normalizePayload($request->input('values', []));
        $targetIds = (clone $query)->limit(500)->pluck('id')->all();
        $affected = DB::transaction(function () use ($query, $payload, $request, $model, $targetIds) {
            $roleChangedIds = $model === Profile::class && isset($payload['role']) ? (clone $query)->where('role', '!=', $payload['role'])->pluck('id')->all() : [];
            $affected = (clone $query)->update($payload);
            if ($roleChangedIds) {
                foreach ($roleChangedIds as $changedId) app(\App\Services\PlatformNotifications::class)->user($changedId,'security.role.changed',(string)\Illuminate\Support\Str::uuid(),'Account access changed','An administrator changed your NUERS account permissions. Existing application sessions were revoked. If you were not expecting this change, contact NUERS support.','security');
                User::whereIn('id', $roleChangedIds)->update(app(ApiAccess::class)->revocation());
            }
            app(BirCompliance::class)->audit('account.table.updated', $model, (string) $request->attributes->get('table_actor')->id, ['fields' => array_keys($payload), 'count' => $affected, 'target_ids' => array_slice($targetIds, 0, 50), 'role_to' => $payload['role'] ?? null]);

            return $affected;
        });

        return response()->json([
            'data' => $query->get(),
            'count' => $affected,
        ]);
    }

    /** Update-time rules that depend on which rows the filters actually matched. */
    private function guardUpdate(Request $request, Builder $query, string $model): void
    {
        $role = $request->attributes->get('table_role');
        $administrator = in_array($role, ['admin', 'super_admin'], true);
        if ($administrator && in_array($model, [Profile::class, ClientAccount::class], true)) {
            $filtered = $this->jsonQuery($request, 'eq') || $this->jsonQuery($request, 'in') || $request->filled('or');
            abort_unless($filtered, 422, 'Specify which records to change; unfiltered bulk changes are not accepted.');
        }
        if ($model !== Profile::class) {
            return;
        }
        $values = $request->input('values', []);
        if ($role !== 'super_admin') {
            abort_if((clone $query)->where('role', 'super_admin')->exists(), 403, 'Only a super administrator can change a super administrator account.');
        }
        foreach (['id', 'email'] as $key) {
            if (array_key_exists($key, $values)) {
                abort_if((clone $query)->where($key, '!=', $values[$key])->exists(), 403, 'Identity and sign-in email changes require the verified identity workflow.');
            }
        }
    }

    /** Internal columns a role must not read, filter or sort by through the generic endpoint. */
    private function internalColumns(string $model, ?string $role): array
    {
        if (in_array($role, ['admin', 'super_admin', 'bir'], true)) {
            return [];
        }

        return match (true) {
            $model === Merchant::class => ['notes', 'compliance_score', 'last_audit_date', 'next_audit_date'],
            $model === MerchantTransaction::class => ['external_payload', 'source_api_key_id', 'source_request_hash'],
            default => [],
        };
    }

    private function scope(Builder $query, Request $request, string $model): void
    {
        $actor = $request->attributes->get('table_actor');
        $role = $request->attributes->get('table_role');
        if (in_array($role, ['admin', 'super_admin'], true)) {
            return;
        }
        if ($model === Profile::class) {
            $query->where('id', $actor->id);

            return;
        }
        if ($model === ClientAccount::class) {
            $query->where('user_id', $actor->id);

            return;
        }
        if ($model === Merchant::class) {
            if ($role === 'bir') {
                return;
            }
            abort_unless($role === 'merchant', 403);
            $query->where(fn ($q) => $q->where('merchant_account_id', $actor->id));

            return;
        }
        if (in_array($model, [MerchantTransaction::class, TransactionReceipt::class], true)) {
            if ($role === 'bir') {
                return;
            }
            if ($role === 'merchant') {
                $query->whereIn('merchant_id', DB::table('merchants')->where(fn ($q) => $q->where('merchant_account_id', $actor->id))->select('id'));

                return;
            }
            $tin = app(ApiAccess::class)->taxpayerTin($actor);
            if (! $tin) {
                $query->whereRaw('1=0');

                return;
            }
            $query->where($model === MerchantTransaction::class ? 'customer_tin' : 'buyer_tin', $tin);

            return;
        }
        abort(403, 'Use an explicitly scoped notification or account API.');
    }

    private function validateQuery(Builder $query, Request $request): void
    {
        $request->validate(['limit' => 'nullable|integer|min:1|max:500', 'order' => 'nullable|string|max:80', 'eq' => 'nullable', 'in' => 'nullable', 'or' => 'nullable|string|max:2000']);
        $allowed = array_diff(Schema::getColumnListing($query->getModel()->getTable()), $this->internalColumns($query->getModel()::class, $request->attributes->get('table_role')));
        $columns = [...array_keys($this->jsonQuery($request, 'eq')), ...array_keys($this->jsonQuery($request, 'in'))];
        if ($request->filled('order')) {
            $columns[] = $request->query('order');
        }
        foreach (explode(',', (string) $request->query('or', '')) as $part) {
            if ($part !== '') {
                $columns[] = explode('.', $part, 3)[0];
            }
        }
        abort_if(count($columns) > 50 || array_diff($columns, $allowed), 422, 'Unsupported filter or sort field.');
        foreach ($this->jsonQuery($request, 'in') as $values) {
            abort_unless(is_array($values) && count($values) <= 100, 422, 'Use at most 100 filter values.');
        }
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        foreach ($this->jsonQuery($request, 'eq') as $column => $value) {
            $value === null ? $query->whereNull($column) : $query->where($column, $value);
        }

        foreach ($this->jsonQuery($request, 'in') as $column => $values) {
            $query->whereIn($column, (array) $values);
        }

        $or = $request->query('or');
        if (is_string($or) && $or !== '') {
            $query->where(function (Builder $nested) use ($or): void {
                foreach (explode(',', $or) as $condition) {
                    [$column, $operator, $value] = array_pad(explode('.', $condition, 3), 3, null);
                    if ($column && $operator === 'eq') {
                        $nested->orWhere($column, $value);
                    }
                }
            });
        }
    }

    private function applyOrdering(Builder $query, Request $request): void
    {
        $column = $request->query('order');

        if (is_string($column) && $column !== '') {
            $query->orderBy($column, $request->boolean('ascending') ? 'asc' : 'desc');
        }
    }

    private function jsonQuery(Request $request, string $key): array
    {
        $value = $request->query($key, '{}');

        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function normalizePayload(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if ($value === '' && (str_ends_with($key, '_date') || $key === 'issued_at')) {
                $payload[$key] = null;
            }
        }

        return $payload;
    }

    private function userFromRequest(Request $request): ?User
    {
        $token = $request->bearerToken();

        if (! $token) {
            return null;
        }

        return app(ApiAccess::class)->user($request);
    }
}
