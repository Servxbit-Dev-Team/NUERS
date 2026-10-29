<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** One authentication and ownership boundary for all human API consumers. */
class ApiAccess
{
    public function user(Request $request): ?User
    {
        $token = $request->bearerToken();
        if (! $token || strlen($token) > 256) {
            return null;
        }
        if (Schema::hasColumn('users', 'api_token_hash')) {
            $user = User::where('api_token_hash', hash('sha256', $token))->first();
            if (! $user || ! $user->api_expires_at || now()->gte($user->api_expires_at)) {
                return null;
            }
            if ($user->api_authenticated_at && now()->gte($user->api_authenticated_at->copy()->addMinutes(config('security.session_absolute_minutes')))) {
                return null;
            }
            if (! $user->api_device_hash && ! app()->environment('testing')) {
                return null;
            }
            if ($user->api_device_hash) {
                $device = $request->header('X-NUERS-Device');
                if (! $device || ! hash_equals($user->api_device_hash, hash('sha256', $device))) {
                    return null;
                }
            }
        } else {
            abort_unless(app()->environment('testing'), 503, 'Apply the reviewed security migrations before serving API requests.');
            $user = User::where('api_token', $token)->first();
        }

        return $user;
    }

    public function require(Request $request, ?array $roles = null): User
    {
        $user = $this->user($request);
        abort_unless($user, 401, 'Your session expired. Sign in again.');
        $role = DB::table('profiles')->where('id', $user->id)->value('role');
        abort_unless(in_array($role, ['admin', 'super_admin', 'bir', 'rdo', 'merchant', 'client', 'consumer'], true), 403, 'This account has no authorized role.');
        if ($roles !== null) {
            abort_unless(in_array($role, $roles, true), 403, 'This operation is not permitted for your role.');
        }
        $request->attributes->set('api_actor', $user);
        $request->attributes->set('api_role', $role);

        return $user;
    }

    public function merchants(object $user)
    {
        // The portal has one implicit business context. Owners must not inherit an
        // unrelated team's context while bypassing delegation checks as an owner.
        if (DB::table('merchants')->where('merchant_account_id', $user->id)->exists()) {
            return DB::table('merchants')->where('merchant_account_id', $user->id);
        }
        return DB::table('merchants')->where(function ($q) use ($user) {
            $q->where('merchant_account_id', $user->id);
            if (app(MerchantTeamAccess::class)->ready()) $q->orWhereIn('id', DB::table('merchant_team_members')->where('user_id', (string) $user->id)->where('status', 'active')->select('merchant_id'));
        });
    }

    public function merchant(Request $request): object
    {
        $user = $this->require($request, ['merchant', 'admin', 'super_admin']);
        $merchant = $this->merchants($user)->orderBy('id')->first();
        abort_unless($merchant, 404, 'No verified business membership is linked to this account.');
        if (! $request->isMethod('GET')) {
            abort_if(in_array($merchant->status ?? 'pending', ['suspended', 'inactive'], true), 403, 'Business access is suspended. Preserved records remain available for authorized retrieval.');
        }

        return $merchant;
    }

    public function revocation(): array
    {
        return ['api_token' => null] + (Schema::hasColumn('users', 'api_token_hash') ? ['api_token_hash' => null, 'api_expires_at' => null, 'api_mfa_verified_at' => null] : []);
    }

    public function taxpayerTin(object $user): ?string
    {
        // Display/profile TINs and email/name labels are never ownership proof.
        $account = DB::table('client_accounts')->where('user_id', $user->id)->where('status', 'active')->first();
        if (! $account?->tin) {
            return null;
        }
        if (Schema::hasTable('taxpayer_identity_reviews')) {
            $claim = DB::table('taxpayer_identity_reviews')->where('user_id', (string) $user->id)->where('tin', preg_replace('/\D/', '', $account->tin))->where('status', 'approved')->whereNotNull('reviewed_by')->whereNotNull('reviewed_at')->first();

            return $claim ? $account->tin : null;
        }

        return app()->environment('testing') ? $account->tin : null;
    }
}
