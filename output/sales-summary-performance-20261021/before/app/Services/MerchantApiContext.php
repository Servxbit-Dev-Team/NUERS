<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MerchantApiContext
{
    /** Called inside key creation while the owning merchant row is locked. */
    public function resolve(object $merchant, string $actor, ?string $explicitCode = null): object
    {
        $branches = DB::table('merchant_branches')->where('merchant_id', $merchant->id);
        if ($explicitCode !== null) {
            // Retain compatibility for existing branch-scoped connectors; never accept a new arbitrary code.
            $branch = (clone $branches)->where('bir_branch_code', $explicitCode)->first();
            abort_unless($branch && $branch->status === 'active', 422, 'This registered business location is unavailable. Ask an administrator to review its identity and operational status.');

            return $branch;
        }

        $identity = app(SellerIdentity::class)->parse($merchant->tin, null);
        abort_unless($identity['valid'], 422, 'Your business TIN needs correction in Business Profile before an API key can be assigned.');
        $code = $identity['branch_code'];
        if (! $code) {
            // A nine-digit base TIN alone does not encode a branch. Do not guess 00000.
            $main = (clone $branches)->where('branch_type', 'main')->whereNotNull('bir_branch_code')->get();
            abort_unless($main->count() === 1, 422, 'Your registration does not identify one business location. Ask an administrator to confirm the BIR branch code in your business registration.');
            $branch = $main->first();
        } else {
            $branch = (clone $branches)->where('bir_branch_code', $code)->first();
        }
        if ($branch) {
            abort_unless($branch->status === 'active', 422, 'Your registered business location is inactive. Ask an administrator to review it before generating a key.');

            return $branch;
        }

        // This is a registration-derived routing context, not a branch evidence approval or BIR permit.
        $id = (string) Str::uuid();
        DB::table('merchant_branches')->insert([
            'id' => $id, 'merchant_id' => $merchant->id, 'code' => 'REG-'.$code,
            'bir_branch_code' => $code, 'name' => Str::limit($merchant->business_name, 160, ''),
            'address' => $merchant->address ?? '', 'city' => $merchant->city ?? '',
            'region' => $merchant->region ?? '', 'rdo_code' => $merchant->rdo_code ?? '',
            'branch_type' => $code === '00000' ? 'main' : 'branch', 'status' => 'active',
            'review_status' => 'draft', 'contact_encrypted' => Crypt::encryptString('{}'),
            'request_id' => (string) Str::uuid(),
            'request_hash' => hash('sha256', json_encode([$merchant->id, $identity['tin'], $code])),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app(MerchantBranches::class)->event((string) $merchant->id, $id, null, 'registered_location_linked', $actor, [
            'source' => 'business_registration', 'branch_code' => $code,
            'reason' => 'Assigned the existing registered establishment when creating a named API key. Independent evidence and BIR authority checks remain separate.',
        ]);

        return DB::table('merchant_branches')->where('id', $id)->first();
    }
}
