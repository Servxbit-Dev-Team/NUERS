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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

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
        if ($request->attributes->has('merchant_registry_owner')) {
            $query->where('merchant_account_id', $request->attributes->get('merchant_registry_owner'));
        }
        $this->applyFilters($query, $request);

        $count = null;
        if ($request->query('count') === 'exact') {
            $count = (clone $query)->count();
        }

        if ($request->boolean('head')) {
            return response()->json(['data' => null, 'count' => $count]);
        }

        $this->applyOrdering($query, $request);

        if ($limit = $request->integer('limit')) {
            $query->limit($limit);
        }

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
        $this->applyFilters($query, $request);

        if ($model === Profile::class && $request->attributes->has('profile_scope_id')) {
            $query->where('id', $request->attributes->get('profile_scope_id'));
        }
        $payload = $this->normalizePayload($request->input('values', []));
        $affected = (clone $query)->update($payload);

        return response()->json([
            'data' => $query->get(),
            'count' => $affected,
        ]);
    }

    private function destroy(Request $request, string $model): JsonResponse
    {
        /** @var Builder $query */
        $query = $model::query();
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

        return User::where('api_token', $token)->first();
    }
}
