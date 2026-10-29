<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class TaxpayerAuthority
{
    public function status(object $permit): string
    {
        if ($permit->status !== 'active') {
            return $permit->status;
        }
        $today = CarbonImmutable::today('Asia/Manila');
        if (CarbonImmutable::parse($permit->issued_at)->greaterThan($today)) {
            return 'not_yet_valid';
        }
        if ($permit->valid_until && CarbonImmutable::parse($permit->valid_until)->lessThan($today)) {
            return 'expired';
        }

        return $permit->valid_until && CarbonImmutable::parse($permit->valid_until)->lessThanOrEqualTo($today->addDays(30)) ? 'expiring_soon' : 'active';
    }
}
