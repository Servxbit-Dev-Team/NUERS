<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApiAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CitizenApprovalController extends Controller
{
    private const CITIZEN_PROFILE_ROLES = ['client', 'consumer', 'taxpayer'];

    public function index(Request $request): JsonResponse
    {
        $this->authorizeReviewer($request);
        abort_unless(Schema::hasTable('client_accounts'), 500, 'client_accounts table is not migrated.');

        $status = strtolower((string) $request->query('status', 'all'));
        $search = trim((string) $request->query('search', ''));

        $query = DB::table('client_accounts')
            ->leftJoin('profiles', 'profiles.id', '=', 'client_accounts.user_id')
            ->select($this->accountSelectColumns());

        if ($status !== 'all') {
            $query->where('client_accounts.status', $status);
        }

        if ($search !== '') {
            $query->where(function ($nested) use ($search): void {
                $like = '%'.$search.'%';
                $nested
                    ->where('client_accounts.full_name', 'like', $like)
                    ->orWhere('client_accounts.email', 'like', $like)
                    ->orWhere('client_accounts.account_number', 'like', $like)
                    ->orWhere('profiles.tin', 'like', $like);
            });
        }

        $accounts = $query
            ->orderByRaw("CASE WHEN client_accounts.status IN ('pending', 'under_review') THEN 0 ELSE 1 END")
            ->orderByDesc('client_accounts.updated_at')
            ->limit(200)
            ->get()
            ->map(fn ($account) => $this->accountPayload($account))
            ->values();

        $rows = $accounts
            ->merge($this->profileCandidates($status, $search))
            ->sortByDesc(fn (array $row) => (string) ($row['updated_at'] ?? ''))
            ->sortBy(fn (array $row) => in_array($row['status'], ['pending', 'under_review'], true) ? 0 : 1)
            ->values()
            ->take(200);

        return response()->json([
            'data' => $rows,
            'summary' => $this->summary(),
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->authorizeReviewer($request);
        abort_unless(Schema::hasTable('client_accounts'), 500, 'client_accounts table is not migrated.');

        $validated = $request->validate([
            'status' => ['required', Rule::in(['active', 'pending', 'under_review', 'rejected'])],
        ]);

        if ($validated['status'] === 'active') {
            $subject = str_starts_with($id, 'profile:') ? (string) Str::after($id, 'profile:') : (string) DB::table('client_accounts')->where('id', $id)->value('user_id');
            $review = Schema::hasTable('taxpayer_identity_reviews') ? DB::table('taxpayer_identity_reviews')->where('user_id', $subject)->where('status', 'approved')->whereNotNull('reviewed_at')->first() : null;
            abort_unless($review, 409, 'Verify identity and taxpayer evidence through an independent identity review before activation.');
            DB::table('client_accounts')->where('user_id', $subject)->update(['tin' => $review->tin]);
        }
        if (str_starts_with($id, 'profile:')) {
            return response()->json([
                'data' => $this->createAccountFromProfile((int) Str::after($id, 'profile:'), $validated['status'], $review->tin ?? null),
                'summary' => $this->summary(),
            ]);
        }

        $account = DB::table('client_accounts')->where('id', $id)->first();
        abort_unless($account, 404, 'Citizen account not found.');

        DB::table('client_accounts')->where('id', $id)->update([
            'status' => $validated['status'],
            'updated_at' => now(),
        ]);

        if ($validated['status'] === 'active' && $account->user_id && Schema::hasTable('profiles')) {
            DB::table('profiles')->where('id', $account->user_id)->update([
                'role' => 'client',
                'updated_at' => now(),
            ]);
        }

        if ($account->status !== $validated['status']) app(\App\Services\PlatformNotifications::class)->user($account->user_id,'citizen.reviewed',(string)Str::uuid(),'Citizen account review update','Your NUERS citizen account status is '.str_replace('_',' ',$validated['status']).'. Sign in to review your account requirements.');

        $updated = DB::table('client_accounts')
            ->leftJoin('profiles', 'profiles.id', '=', 'client_accounts.user_id')
            ->select($this->accountSelectColumns())
            ->where('client_accounts.id', $id)
            ->first();

        return response()->json([
            'data' => $this->accountPayload($updated),
            'summary' => $this->summary(),
        ]);
    }

    private function authorizeReviewer(Request $request): void
    {
        $token = $request->bearerToken();
        abort_unless($token, 401, 'Authenticated reviewer session is required.');

        $user = app(ApiAccess::class)->user($request);
        abort_unless($user, 401, 'Authenticated reviewer session is required.');

        $role = Schema::hasTable('profiles')
            ? DB::table('profiles')->where('id', $user->id)->value('role')
            : null;

        abort_unless(in_array($role, ['admin', 'super_admin', 'bir'], true), 403, 'Citizen approval is restricted to BIR and Super Admin users.');
    }

    private function summary(): array
    {
        $base = DB::table('client_accounts');
        $pendingProfileCandidates = $this->profileCandidateCount();

        return [
            'total' => (clone $base)->count() + $pendingProfileCandidates,
            'pending' => (clone $base)->whereIn('status', ['pending', 'under_review'])->count() + $pendingProfileCandidates,
            'active' => (clone $base)->where('status', 'active')->count(),
            'rejected' => (clone $base)->where('status', 'rejected')->count(),
        ];
    }

    private function accountSelectColumns(): array
    {
        return [
            'client_accounts.id',
            'client_accounts.user_id',
            'client_accounts.account_number',
            'client_accounts.full_name',
            'client_accounts.account_type',
            'client_accounts.email',
            'client_accounts.mobile',
            'client_accounts.status',
            'client_accounts.mfa_enabled',
            'client_accounts.wallet_balance',
            'client_accounts.created_at',
            'client_accounts.updated_at',
            Schema::hasColumn('client_accounts', 'tin') ? DB::raw('COALESCE(client_accounts.tin,profiles.tin) as tin') : DB::raw('NULL as tin'),
            Schema::hasColumn('profiles', 'tin_bound_at') ? 'profiles.tin_bound_at' : DB::raw('NULL as tin_bound_at'),
            Schema::hasColumn('profiles', 'profile_photo_url') ? 'profiles.profile_photo_url' : DB::raw('NULL as profile_photo_url'),
        ];
    }

    private function accountPayload(object $account): array
    {
        return [
            'id' => $account->id,
            'user_id' => $account->user_id,
            'account_number' => $account->account_number,
            'full_name' => $account->full_name,
            'account_type' => $account->account_type,
            'email' => $account->email,
            'mobile' => $account->mobile,
            'status' => $account->status,
            'mfa_enabled' => (bool) $account->mfa_enabled,
            'wallet_balance' => (float) $account->wallet_balance,
            'tin' => $account->tin,
            'tin_bound_at' => $account->tin_bound_at,
            'profile_photo_url' => $account->profile_photo_url,
            'created_at' => $account->created_at,
            'updated_at' => $account->updated_at,
        ];
    }

    private function profileCandidates(string $status, string $search)
    {
        if ($status !== 'all' && $status !== 'pending') {
            return collect();
        }

        if (! Schema::hasTable('profiles')) {
            return collect();
        }

        $query = $this->baseProfileCandidateQuery()
            ->select($this->profileCandidateSelectColumns());

        if ($search !== '') {
            $query->where(function ($nested) use ($search): void {
                $like = '%'.$search.'%';
                $nested
                    ->where('profiles.full_name', 'like', $like)
                    ->orWhere('profiles.email', 'like', $like);

                if (Schema::hasTable('users')) {
                    $nested->orWhere('users.email', 'like', $like)
                        ->orWhere('users.name', 'like', $like);
                }

                if (Schema::hasColumn('profiles', 'tin')) {
                    $nested->orWhere('profiles.tin', 'like', $like);
                }
            });
        }

        return $query
            ->orderByDesc('profiles.updated_at')
            ->limit(200)
            ->get()
            ->map(fn ($profile) => $this->profileCandidatePayload($profile))
            ->values();
    }

    private function createAccountFromProfile(int $profileId, string $status, ?string $verifiedTin = null): array
    {
        abort_unless(Schema::hasTable('profiles'), 500, 'profiles table is not migrated.');

        $profile = DB::table('profiles')
            ->when(Schema::hasTable('users'), fn ($query) => $query->leftJoin('users', 'users.id', '=', 'profiles.id'))
            ->select($this->profileCandidateSelectColumns())
            ->where('profiles.id', $profileId)
            ->first();

        abort_unless($profile, 404, 'Citizen profile not found.');
        abort_unless(in_array($profile->role, self::CITIZEN_PROFILE_ROLES, true), 404, 'Citizen profile not found.');

        $email = $profile->email ?: $profile->user_email;
        $fullName = trim((string) ($profile->full_name ?: $profile->user_name ?: $email ?: 'Citizen Account'));

        $existingAccountQuery = DB::table('client_accounts')->where('user_id', $profileId);

        $existingAccount = $existingAccountQuery->first();

        if ($existingAccount) {
            DB::table('client_accounts')->where('id', $existingAccount->id)->update([
                'status' => $status,
                ...($verifiedTin ? ['tin' => $verifiedTin] : []),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('client_accounts')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $profileId,
                'tin' => $verifiedTin,
                'account_number' => $this->nextAccountNumber(),
                'full_name' => $fullName,
                'account_type' => 'citizen',
                'email' => $email,
                'mobile' => null,
                'wallet_balance' => 0,
                'mfa_enabled' => false,
                'notification_preferences' => json_encode(['email' => true, 'sms' => false, 'push' => true]),
                'status' => $status,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($status === 'active') {
            DB::table('profiles')->where('id', $profileId)->update([
                'role' => 'client',
                'updated_at' => now(),
            ]);
        }

        $account = DB::table('client_accounts')
            ->leftJoin('profiles', 'profiles.id', '=', 'client_accounts.user_id')
            ->select($this->accountSelectColumns())
            ->where('client_accounts.user_id', $profileId)

            ->orderByDesc('client_accounts.updated_at')
            ->first();

        if (!$existingAccount || $existingAccount->status !== $status) app(\App\Services\PlatformNotifications::class)->user($profileId,'citizen.reviewed',(string)Str::uuid(),'Citizen account review update','Your NUERS citizen account status is '.str_replace('_',' ',$status).'. Sign in to review your account requirements.');
        return $this->accountPayload($account);
    }

    private function baseProfileCandidateQuery()
    {
        return DB::table('profiles')
            ->when(Schema::hasTable('users'), fn ($query) => $query->leftJoin('users', 'users.id', '=', 'profiles.id'))
            ->whereIn('profiles.role', self::CITIZEN_PROFILE_ROLES)
            ->whereNotExists(function ($subquery): void {
                $subquery
                    ->select(DB::raw(1))
                    ->from('client_accounts')
                    ->whereColumn('client_accounts.user_id', 'profiles.id')
                    ->orWhereColumn('client_accounts.email', 'profiles.email');
            });
    }

    private function profileCandidateCount(): int
    {
        if (! Schema::hasTable('profiles')) {
            return 0;
        }

        return $this->baseProfileCandidateQuery()->count();
    }

    private function profileCandidateSelectColumns(): array
    {
        return [
            'profiles.id as user_id',
            'profiles.full_name',
            'profiles.email',
            'profiles.role',
            'profiles.created_at',
            'profiles.updated_at',
            Schema::hasTable('users') ? 'users.email as user_email' : DB::raw('NULL as user_email'),
            Schema::hasTable('users') ? 'users.name as user_name' : DB::raw('NULL as user_name'),
            Schema::hasColumn('profiles', 'tin') ? 'profiles.tin' : DB::raw('NULL as tin'),
            Schema::hasColumn('profiles', 'tin_bound_at') ? 'profiles.tin_bound_at' : DB::raw('NULL as tin_bound_at'),
            Schema::hasColumn('profiles', 'profile_photo_url') ? 'profiles.profile_photo_url' : DB::raw('NULL as profile_photo_url'),
        ];
    }

    private function profileCandidatePayload(object $profile): array
    {
        $email = $profile->email ?: $profile->user_email;
        $fullName = trim((string) ($profile->full_name ?: $profile->user_name ?: $email ?: 'Citizen Account'));

        return [
            'id' => 'profile:'.$profile->user_id,
            'user_id' => $profile->user_id,
            'account_number' => 'Pending account',
            'full_name' => $fullName,
            'account_type' => 'citizen',
            'email' => $email,
            'mobile' => null,
            'status' => 'pending',
            'mfa_enabled' => false,
            'wallet_balance' => 0.0,
            'tin' => $profile->tin,
            'tin_bound_at' => $profile->tin_bound_at,
            'profile_photo_url' => $profile->profile_photo_url,
            'created_at' => $profile->created_at,
            'updated_at' => $profile->updated_at,
        ];
    }

    private function nextAccountNumber(): string
    {
        do {
            $accountNumber = 'CL-'.now()->format('Y').'-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
        } while (DB::table('client_accounts')->where('account_number', $accountNumber)->exists());

        return $accountNumber;
    }
}
