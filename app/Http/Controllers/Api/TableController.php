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
            abort_unless(in_array($request->method(), ['PATCH', 'PUT'], true), 409, 'Use the verified account provisioning workflow.');
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
                abort_if(array_diff(array_keys($value), ['id', 'role', 'email', 'full_name', 'organization']), 403, 'Use the verified identity workflow for TIN and security changes.');
                // BIR review authority requires a separately verified staff provisioning process.
                abort_if(($value['role'] ?? null) === 'bir', 403, 'BIR review authority cannot be self-assigned through the generic profile endpoint.');
            }
        }

        abort_if(in_array($table, ['merchant_transactions', 'transaction_receipts'], true) && $request->method() !== 'GET', 409, 'Issued financial records are preserved. Use the authorized issuance or traceable correction workflow.');

        return match ($request->method()) {
            'GET' => $this->index($request, self::TABLES[$table]),
            'POST' => $this->store($request, self::TABLES[$table]),
            'PUT', 'PATCH' => $this->update($request, self::TABLES[$table]),
            'DELETE' => $this->destroy($request, self::TABLES[$table]),
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

        if ($model === Profile::class && $request->filled('search')) {
            $term = '%'.trim($request->query('search')).'%';
            $query->where(function ($q) use ($term) {
                foreach (['full_name', 'email', 'organization', 'tin'] as $column) {
                    $q->orWhere($column, 'like', $term);
                }
            });
        }

        $count = null;
        if ($request->query('count') === 'exact') {
            $count = (clone $query)->count();
        }

        if ($request->boolean('head')) {
            return response()->json(['data' => null, 'count' => $count]);
        }

        $this->applyOrdering($query, $request);

        $query->orderBy($query->getModel()->getKeyName())->offset($request->integer('offset', 0))->limit($request->integer('limit', 100));

        $single = $request->boolean('single') || $request->boolean('maybeSingle');
        $data = $single ? $query->first() : $query->get();

        return response()->json(['data' => $data, 'count' => $count]);
    }

    private function store(Request $request, string $model): JsonResponse
    {
        $values = $request->input('values', []);
        $isList = Arr::isList($values);
        $rows = $isList ? $values : [$values];

        $created = collect($rows)
            ->map(fn (array $row) => $model::create($this->normalizePayload($row)))
            ->values();

        return response()->json([
            'data' => $isList ? $created : $created->first(),
            'count' => $created->count(),
        ], 201);
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
        $request->validate(['values' => 'required|array', 'values.full_name' => 'sometimes|string|max:255', 'values.organization' => 'sometimes|string|max:255', 'values.email' => 'sometimes|email|max:255', 'values.mobile' => 'sometimes|nullable|string|max:80', 'values.is_read' => 'sometimes|boolean', 'values.role' => 'sometimes|in:admin,super_admin,merchant,client,consumer', 'values.status' => 'sometimes|in:pending,under_review,suspended,inactive,rejected']);
        $payload = $this->normalizePayload($request->input('values', []));
        $affected = DB::transaction(function () use ($query, $payload, $request, $model) {
            if ($model === Profile::class) {
                $actor = $request->attributes->get('table_actor');
                $actorRole = $request->attributes->get('table_role');
                $targets = (clone $query)->lockForUpdate()->get();
                abort_unless($targets->count() === 1, 422, 'Select one existing profile for each audited update.');
                $target = $targets->first();
                $privileged = ['admin', 'super_admin', 'bir', 'rdo'];
                abort_if($actorRole === 'admin'
                    && (in_array($target->role, $privileged, true) || in_array($payload['role'] ?? '', $privileged, true)), 403, 'Only a Super Administrator can manage privileged profiles.');
                abort_if(isset($payload['role']) && $payload['role'] !== $target->role && (string) $target->id === (string) $actor->id, 403, 'You cannot change your own platform role.');
                foreach (['id', 'email'] as $identity) {
                    abort_if(isset($payload[$identity]) && (string) $payload[$identity] !== (string) $target->$identity, 403, 'Login identity changes require the verified account workflow.');
                }
                foreach (['full_name', 'organization'] as $identity) {
                    abort_if($target->role === 'merchant' && array_key_exists($identity, $payload) && (string) $payload[$identity] !== (string) $target->$identity, 403, 'Change merchant identity in Registered Businesses so the business profile and owner directory remain aligned.');
                }
            }
            $roleChangedIds = $model === Profile::class && isset($payload['role']) ? (clone $query)->where('role', '!=', $payload['role'])->pluck('id')->all() : [];
            $affected = (clone $query)->update($payload);
            if ($model === Profile::class && array_key_exists('full_name', $payload)) {
                User::where('id', $target->id)->update(['name' => $payload['full_name']]);
            }
            if ($roleChangedIds) {
                foreach ($roleChangedIds as $changedId) {
                    app(\App\Services\PlatformNotifications::class)->user($changedId, 'security.role.changed', (string)\Illuminate\Support\Str::uuid(), 'Account access changed', 'An administrator changed your NUERS account permissions. Existing application sessions were revoked. If you were not expecting this change, contact NUERS support.', 'security');
                }
                User::whereIn('id', $roleChangedIds)->update(app(ApiAccess::class)->revocation());
            }
            app(BirCompliance::class)->audit('account.table.updated', $model, (string) $request->attributes->get('table_actor')->id, ['fields' => array_keys($payload), 'count' => $affected]);

            return $affected;
        });

        return response()->json([
            'data' => $query->get(),
            'count' => $affected,
        ]);
    }

    private function destroy(Request $request, string $model): JsonResponse
    {
        /** @var Builder $query */
        $query = $model::query();
        $this->scope($query, $request, $model);
        $this->validateQuery($query, $request);
        $this->applyFilters($query, $request);

        if ($model === Profile::class) {
            return $this->destroyProfiles($request, $query);
        }

        if ($model === ClientAccount::class) {
            return $this->destroyClientAccounts($request, $query);
        }

        $affected = $query->delete();

        return response()->json([
            'data' => null,
            'count' => $affected,
        ]);
    }

    private function destroyProfiles(Request $request, Builder $query): JsonResponse
    {
        $profiles = (clone $query)->get(['id']);
        $ids = $profiles->pluck('id')->map(fn ($id) => (int) $id)->filter()->values();

        if ($ids->isEmpty()) {
            return response()->json(['data' => null, 'count' => 0]);
        }

        if ($currentUser = $this->userFromRequest($request)) {
            abort_if($ids->contains((int) $currentUser->id), 422, 'You cannot delete your own active admin account.');
        }

        DB::transaction(function () use ($ids, $query): void {
            $this->notifyRemoval($ids->all());
            $query->delete();
            User::whereIn('id', $ids)->delete();
        });

        return response()->json([
            'data' => null,
            'count' => $ids->count(),
        ]);
    }

    private function destroyClientAccounts(Request $request, Builder $query): JsonResponse
    {
        $accounts = (clone $query)->get(['id', 'user_id']);
        $ids = $accounts->pluck('id')->filter()->values();
        $userIds = $accounts->pluck('user_id')->map(fn ($id) => $id ? (int) $id : null)->filter()->values();

        if ($ids->isEmpty()) {
            return response()->json(['data' => null, 'count' => 0]);
        }

        if ($currentUser = $this->userFromRequest($request)) {
            abort_if($userIds->contains((int) $currentUser->id), 422, 'You cannot delete your own active account.');
        }

        DB::transaction(function () use ($query, $userIds): void {
            $this->notifyRemoval($userIds->all());
            $query->delete();

            if ($userIds->isNotEmpty()) {
                Profile::whereIn('id', $userIds)->delete();
                User::whereIn('id', $userIds)->delete();
            }
        });

        return response()->json([
            'data' => null,
            'count' => $ids->count(),
        ]);
    }

    private function notifyRemoval(array $ids): void
    {
        foreach (User::whereIn('id', $ids)->get() as $u) {
            app(\App\Services\PlatformNotifications::class)->queue($u->email, 'security.account.removed', (string)\Illuminate\Support\Str::uuid(), 'NUERS account removed', 'An administrator removed your NUERS account. If this was unexpected, contact NUERS support.', 'security');
        }
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
        $request->validate(['offset' => 'nullable|integer|min:0|max:1000000', 'search' => 'nullable|string|max:200', 'limit' => 'nullable|integer|min:1|max:500', 'order' => 'nullable|string|max:80', 'eq' => 'nullable', 'in' => 'nullable', 'or' => 'nullable|string|max:2000']);
        $allowed = Schema::getColumnListing($query->getModel()->getTable());
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
