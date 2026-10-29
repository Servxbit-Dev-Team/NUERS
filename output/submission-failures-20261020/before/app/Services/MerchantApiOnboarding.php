<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/** Account verification enables storage of owned-system copies, not legal issuance or BIR reporting. */
class MerchantApiOnboarding
{
    /** Caller holds the owning merchant lock. Never generates or stores plaintext credentials. */
    public function prepare(object $merchant, string $actor, bool $accountReview = false, ?object $branch = null, bool $newKey = false): ?object
    {
        if (! $merchant->merchant_account_id || $merchant->status !== 'active' || ! app(SellerIdentity::class)->parse($merchant->tin, null)['valid']
            || trim((string) $merchant->business_name) === '' || strlen(trim((string) $merchant->address)) < 8) {
            return null;
        }
        if (! $branch) {
            $identity = app(SellerIdentity::class)->parse($merchant->tin, null);
            $q = DB::table('merchant_branches')->where('merchant_id', $merchant->id);
            if ($identity['branch_code']) {
                $existing = (clone $q)->where('bir_branch_code', $identity['branch_code'])->first();
                if ($existing && $existing->status !== 'active') return null;
            } elseif ((clone $q)->where('branch_type', 'main')->where('status', 'active')->whereNotNull('bir_branch_code')->count() !== 1) {
                return null; // A base TIN alone cannot identify a registered establishment.
            }
            $branch = app(MerchantApiContext::class)->resolve($merchant, $actor);
        }
        $snapshot = $this->snapshot($merchant, $branch->bir_branch_code);
        if (! $snapshot) return null;
        // Stable per business/location, including across key rotation and Live/Sandbox credentials.
        $system = 'owned-'.substr(hash('sha256', $merchant->id.'|'.$branch->bir_branch_code), 0, 24);
        $hash = app(IntegrationContract::class)->canonicalHash($snapshot);
        $request = Uuid::uuid5(Uuid::NAMESPACE_URL, 'nuers:account-connection:'.$merchant->id.':'.$branch->bir_branch_code.':'.$hash)->toString();
        $old = DB::table('integration_source_profiles')->where('request_id', $request)->first();
        // A new key request can prepare a replacement from the unchanged verified account.
        // Do not reactivate the revoked profile, and do not do this from a read/backfill job.
        $replaces = null;
        while ($old && $old->status === 'revoked' && $newKey) {
            $replaces = $old->id;
            $request = Uuid::uuid5(Uuid::NAMESPACE_URL, 'nuers:account-connection:replacement:'.$old->id)->toString();
            $old = DB::table('integration_source_profiles')->where('request_id', $request)->first();
        }
        if ($old) return $old;
        $previous = DB::table('integration_source_profiles')->where('merchant_id', $merchant->id)
            ->where('branch_code', $branch->bir_branch_code)->where('profile_kind', 'account_connection')->exists();
        if ($previous && ! $accountReview && ! $replaces) return null; // Changed identity requires a new account review.
        $id = (string) Str::uuid();
        DB::table('integration_source_profiles')->insert([
            ...$snapshot, 'id' => $id, 'source_system' => $system, 'profile_kind' => 'account_connection',
            'authority_reference' => 'Account verification only. Owned-system record storage; no issuance authority or BIR reporting grant.',
            'evidence_reference' => 'Verified NUERS business registry revision '.($merchant->registry_revision ?? 1).'. Snapshot '.$hash,
            'valid_from' => now(), 'valid_until' => '9999-12-31 23:59:59', 'status' => 'active',
            'reviewed_by' => $actor, 'reviewed_at' => now(), 'request_id' => $request, 'request_hash' => $hash,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app(BirCompliance::class)->audit('integration.account_connection.prepared', $id, $actor, [
            'merchant_id' => $merchant->id, 'branch_code' => $branch->bir_branch_code, 'source_system' => $system,
            'basis' => $accountReview ? 'account_approval' : ($newKey ? 'verified_owner_key_creation' : 'existing_active_account'), 'snapshot_hash' => $hash,
            'replaces_revoked_profile' => $replaces,
            'issuance_or_reporting_authority_granted' => false,
        ]);
        return DB::table('integration_source_profiles')->where('id', $id)->first();
    }

    private function snapshot(object $merchant, string $branch): ?array
    {
        $identity = app(SellerIdentity::class)->parse($merchant->tin, $branch);
        if (! $identity['valid']) return null;
        return ['merchant_id' => (string) $merchant->id, 'branch_code' => $branch,
            'seller_name' => (string) $merchant->business_name, 'seller_tin' => (string) $merchant->tin,
            'seller_address' => (string) $merchant->address, 'vat_registered' => (bool) $merchant->vat_registered];
    }

    public function matches(object $profile, object $merchant): bool
    {
        $snapshot = $this->snapshot($merchant, $profile->branch_code);
        return $merchant->status === 'active' && $snapshot
            && hash_equals($profile->request_hash, app(IntegrationContract::class)->canonicalHash($snapshot))
            && DB::table('merchant_branches')->where('merchant_id', $merchant->id)
                ->where('bir_branch_code', $profile->branch_code)->where('status', 'active')->exists();
    }

    public function connection(object $profile): array
    {
        return ['schema_version' => 'NUERS-ingestion-1', 'record_mode' => 'source_capture',
            'source_profile_id' => $profile->id, 'source_system' => $profile->source_system, 'branch_code' => $profile->branch_code];
    }
}
