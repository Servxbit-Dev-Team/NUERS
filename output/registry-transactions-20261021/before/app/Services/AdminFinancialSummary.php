<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class AdminFinancialSummary
{
    /** Read Live records directly; request logs, sandbox events and pending deposits are not money. */
    public function overview(?CarbonImmutable $start = null, ?CarbonImmutable $end = null, bool $includeWallets = false): array
    {
        $transactions = $this->window(DB::table('merchant_transactions'), $start, $end);
        $fees = $this->window(DB::table('merchant_wallet_entries')->where('kind', 'fee'), $start, $end);
        $summary = [
            'currency' => 'PHP',
            'environment' => 'live',
            'period' => $start ? 'today' : 'all_time',
            'transaction_amount_centavos' => (int) round((float) $transactions->sum('amount') * 100),
            // Historical charges stay at their posted rate, including retries that did not create a debit.
            'nuers_charged_centavos' => -(int) (clone $fees)->sum('amount_centavos'),
            'charged_transactions' => (clone $fees)->where('amount_centavos', '<', 0)->count(),
        ];
        if ($includeWallets) {
            $summary['merchant_wallet_centavos'] = (int) DB::table('merchant_wallets')->sum('available_centavos');
            $summary['merchant_deposit_centavos'] = (int) DB::table('merchant_wallet_entries')
                ->where('kind', 'topup')->where('amount_centavos', '>', 0)->sum('amount_centavos');
        }

        return $summary;
    }

    private function window(Builder $query, ?CarbonImmutable $start, ?CarbonImmutable $end): Builder
    {
        $zone = config('app.timezone', 'UTC');
        if ($start) $query->where('created_at', '>=', $start->setTimezone($zone)->format('Y-m-d H:i:s'));
        if ($end) $query->where('created_at', '<=', $end->setTimezone($zone)->format('Y-m-d H:i:s'));
        return $query;
    }
}
