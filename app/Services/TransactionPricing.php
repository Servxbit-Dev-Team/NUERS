<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** One platform-wide transaction offer; no recurring subscription or storage tiers. */
class TransactionPricing
{
    public const SLUG = 'transaction-pricing';

    public const FEE_CENTAVOS = 300;

    public const MINIMUM_CENTAVOS = 50000;

    public const RETENTION_YEARS = 5;

    public function offer(bool $lock = false): object
    {
        $query = DB::table('website_subscription_plans')->where('slug', self::SLUG)->where('published', true);
        $row = ($lock ? $query->sharedLock() : $query)->first();
        abort_unless($row, 503, 'NUERS transaction pricing is temporarily unavailable.');
        abort_unless(ctype_digit((string) $row->fee_centavos) && (int) $row->fee_centavos <= 1000000, 503, 'Transaction fee policy requires review.');

        // Legacy catalogue columns remain for historical registration references.
        return (object) array_merge((array) $row, [
            'name' => 'NUERS Transaction Pricing', 'billing_model' => 'per_transaction',
            'fee_centavos' => (int) $row->fee_centavos, 'minimum_transaction_centavos' => self::MINIMUM_CENTAVOS,
            'storage_mb' => null, 'retention_years' => self::RETENTION_YEARS,
            'subscription_required' => false, 'recurring_fee_centavos' => 0,
        ]);
    }

    public function feeForCentavos(int $amount): int
    {
        if ($amount < 0) {
            throw new InvalidArgumentException('Transaction amount must be non-negative.');
        }

        return $amount >= self::MINIMUM_CENTAVOS ? (int) $this->offer()->fee_centavos : 0;
    }
}
