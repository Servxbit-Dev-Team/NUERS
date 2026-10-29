<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** A reviewed original-issuer snapshot permits capture only; it never grants NUERS issuance authority. */
class IntegrationSourceProfile
{
    public function forEvent(array $event, object $merchant): ?object
    {
        if (($event['record_mode'] ?? 'nuers_issuance') !== 'source_capture') {
            abort_if(! empty($event['source_profile_id']), 422, 'Source profiles apply only to source_capture.');
            return null;
        }
        $p = DB::table('integration_source_profiles')->where('id', $event['source_profile_id'] ?? '')
            ->where('merchant_id', $merchant->id)->where('branch_code', $event['branch_code'])->where('source_system', $event['source_system'])->first();
        abort_unless($p && $p->status === 'active' && $p->source_system === $event['source_system'] && $p->branch_code === $event['branch_code'], 409, 'An active source connection profile is required for this system and registered location.');
        if ($p->profile_kind === 'account_connection') {
            abort_unless(app(MerchantApiOnboarding::class)->matches($p, $merchant), 409, 'The verified business identity or location changed. Complete account review before accepting new owned-system copies.');
        }
        $at = CarbonImmutable::parse($event['occurred_at']);
        abort_unless($at->betweenIncluded(CarbonImmutable::parse($p->valid_from, config('app.timezone')), CarbonImmutable::parse($p->valid_until, config('app.timezone'))), 409, 'Original event date falls outside the reviewed source profile period.');
        abort_unless(app(SellerIdentity::class)->parse($p->seller_tin, $p->branch_code)['valid'], 409, 'Source seller identity requires review.');
        return $p;
    }
}
