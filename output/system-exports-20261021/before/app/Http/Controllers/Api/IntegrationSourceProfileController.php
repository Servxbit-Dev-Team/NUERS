<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\{ApiAccess, BirCompliance, IntegrationContract, SellerIdentity};
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class IntegrationSourceProfileController extends Controller
{
    public function index(Request $r)
    {
        app(ApiAccess::class)->require($r, ['admin', 'super_admin']);
        return response()->json(['data' => DB::table('integration_source_profiles')->orderByDesc('created_at')->limit(500)->get(),
            'businesses' => DB::table('merchants')->where('status', 'active')->orderBy('business_name')->get(['id','business_name','vat_registered','tin']),
            'branches' => DB::table('merchant_branches')->where('status', 'active')->get(['id','merchant_id','bir_branch_code','name'])])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $r)
    {
        $u = app(ApiAccess::class)->require($r, ['admin', 'super_admin']);
        $v = $r->validate(['request_id' => 'required|uuid', 'merchant_id' => 'required|string|exists:merchants,id', 'branch_code' => 'required|regex:/^[0-9]{5}$/',
            'source_system' => 'required|string|max:120|regex:/^[A-Za-z0-9_.-]+$/', 'vat_registered' => 'required|boolean',
            'seller_name' => 'required|string|max:255', 'seller_tin' => 'required|string|max:20', 'seller_address' => 'required|string|min:8|max:2000',
            'authority_reference' => 'required|string|min:10|max:2000', 'evidence_reference' => 'required|string|min:10|max:3000',
            'valid_from' => ['required','date','before_or_equal:now','regex:/T.*(?:Z|[+-][0-9]{2}:[0-9]{2})$/'],
            'valid_until' => ['required','date','after:valid_from','regex:/T.*(?:Z|[+-][0-9]{2}:[0-9]{2})$/']]);
        return DB::transaction(function () use ($u, $v) {
            $merchant = DB::table('merchants')->where('id', $v['merchant_id'])->lockForUpdate()->first();
            abort_unless($merchant && $merchant->status === 'active', 409, 'Activate the business after completing its review.');
            $hash = app(IntegrationContract::class)->canonicalHash($v);
            $old = DB::table('integration_source_profiles')->where('request_id', $v['request_id'])->first();
            if ($old) {
                abort_unless($old->reviewed_by === (string) $u->id && hash_equals($old->request_hash, $hash), 409, 'Review request ID conflicts.');
                return response()->json(['id' => $old->id, 'duplicate' => true]);
            }
            abort_if((string) $merchant->merchant_account_id === (string) $u->id, 403, 'The business owner cannot approve their own source evidence.');
            $identity = app(SellerIdentity::class)->parse($v['seller_tin'], $v['branch_code']);
            $merchantIdentity = app(SellerIdentity::class)->parse($merchant->tin, null);
            abort_unless($identity['valid'] && $identity['tin'] === $merchantIdentity['tin'], 422, 'Source seller TIN must match this registered taxpayer and exact branch.');
            abort_unless(DB::table('merchant_branches')->where('merchant_id', $merchant->id)->where('bir_branch_code', $v['branch_code'])->where('status', 'active')->exists(), 422, 'Select an active registered branch.');
            $from = CarbonImmutable::parse($v['valid_from'])->setTimezone(config('app.timezone'));
            $until = CarbonImmutable::parse($v['valid_until'])->setTimezone(config('app.timezone'));
            abort_if(DB::table('integration_source_profiles')->where('merchant_id', $merchant->id)->where('branch_code', $v['branch_code'])->where('source_system', $v['source_system'])->where('status', 'active')->where('valid_from', '<=', $until)->where('valid_until', '>=', $from)->exists(), 409, 'An active source profile already covers this system and period. Revoke an incorrect profile before replacing it.');
            $id = (string) Str::uuid();
            DB::table('integration_source_profiles')->insert([...$v, 'id' => $id, 'request_hash' => $hash, 'valid_from' => $from, 'valid_until' => $until, 'status' => 'active', 'reviewed_by' => (string) $u->id, 'reviewed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            app(BirCompliance::class)->audit('integration.source_profile.approved', $id, (string) $u->id, ['merchant_id' => $merchant->id, 'source_system' => $v['source_system'], 'branch_code' => $v['branch_code'], 'review_hash' => $hash]);
            return response()->json(['id' => $id, 'message' => 'Original-source capture profile recorded. This does not authorize new NUERS issuance or BIR transmission.'], 201);
        }, 3);
    }

    public function destroy(Request $r, string $id)
    {
        $u = app(ApiAccess::class)->require($r, ['admin', 'super_admin']);
        $v = $r->validate(['reason' => 'required|string|min:10|max:2000']);
        return DB::transaction(function () use ($u, $v, $id) {
            $profile = DB::table('integration_source_profiles')->where('id', $id)->first();
            abort_unless($profile, 404);
            DB::table('merchants')->where('id', $profile->merchant_id)->lockForUpdate()->first();
            $profile = DB::table('integration_source_profiles')->where('id', $id)->lockForUpdate()->first();
            if ($profile->status !== 'revoked') {
                DB::table('integration_source_profiles')->where('id', $id)->update(['status' => 'revoked', 'revocation_reason' => $v['reason'], 'updated_at' => now()]);
                app(BirCompliance::class)->audit('integration.source_profile.revoked', $id, (string) $u->id, $v);
            }
            return response()->json(['ok' => true]);
        }, 3);
    }
}
