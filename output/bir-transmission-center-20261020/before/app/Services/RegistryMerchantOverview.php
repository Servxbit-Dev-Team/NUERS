<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read-only, grouped operational totals. Never load transaction/credential payloads. */
class RegistryMerchantOverview
{
    public function available(): bool
    {
        return Schema::hasTable('merchant_wallets') && Schema::hasTable('merchant_wallet_entries');
    }

    private function scoped(string $table, Builder $merchants): Builder
    {
        return DB::table($table)->whereIn('merchant_id', (clone $merchants)->reorder()->select('m.id'));
    }

    private function transactions(Builder $q): Builder
    {
        return $q->selectRaw("COUNT(*) as transactions,
            COALESCE(SUM(ROUND(amount * 100)), 0) as transaction_amount_centavos,
            SUM(CASE WHEN status IN ('completed','valid','verified','validated','success') THEN 1 ELSE 0 END) as successful_transactions,
            SUM(CASE WHEN status IN ('failed','rejected','invalid','cancelled','voided') THEN 1 ELSE 0 END) as failed_transactions,
            MAX(created_at) as last_transaction_at");
    }

    private function entries(Builder $q): Builder
    {
        return $q->selectRaw("COALESCE(SUM(CASE WHEN kind='fee' THEN -amount_centavos ELSE 0 END),0) as charged_centavos,
            SUM(CASE WHEN kind='fee' AND amount_centavos<0 THEN 1 ELSE 0 END) as charged_transactions,
            COALESCE(SUM(CASE WHEN kind='topup' AND amount_centavos>0 THEN amount_centavos ELSE 0 END),0) as deposits_centavos,
            COALESCE(SUM(CASE WHEN kind='withdrawal' THEN -amount_centavos ELSE 0 END),0) as withdrawals_centavos");
    }

    public function summary(Builder $merchants): ?array
    {
        if (!$this->available()) return null;
        // Reuse small aggregates across refreshes; every committed app write changes the key.
        $key = 'registry-overview:v1:'.hash('sha256', json_encode([
            DB::connection()->getDatabaseName(), $merchants->toSql(), $merchants->getBindings(),
            app(DashboardRevision::class)->token('admin-registry-summary'),
        ], JSON_THROW_ON_ERROR));
        return Cache::remember($key, 120, function () use ($merchants) {
            $wallet = $this->scoped('merchant_wallets', $merchants)
                ->selectRaw('COALESCE(SUM(available_centavos),0) as available_centavos, COALESCE(SUM(reserved_centavos),0) as reserved_centavos,
                    SUM(CASE WHEN available_centavos <= low_balance_threshold THEN 1 ELSE 0 END) as low_balance_wallets')->first();
            $transactions = $this->transactions($this->scoped('merchant_transactions', $merchants))->first();
            $entries = $this->entries($this->scoped('merchant_wallet_entries', $merchants))->first();
            $pending = $this->scoped('merchant_wallet_topups', $merchants)->where('status', 'pending')
                ->selectRaw('COUNT(*) as pending_topups, COALESCE(SUM(amount_centavos),0) as pending_topups_centavos')->first();
            $values = (array) $wallet + (array) $transactions + (array) $entries + (array) $pending;
            unset($values['last_transaction_at']);
            return ['currency' => 'PHP', 'environment' => 'live', 'period' => 'all_time', 'scope' => 'filtered_merchants']
                + array_map('intval', $values);
        });
    }

    public function rows(array $ids): array
    {
        if (!$ids || !$this->available()) return [];
        $wallets = DB::table('merchant_wallets')->whereIn('merchant_id', $ids)->get([
            'merchant_id', 'available_centavos', 'reserved_centavos', 'low_balance_threshold', 'updated_at',
        ])->keyBy('merchant_id');
        $transactions = $this->transactions(DB::table('merchant_transactions')->whereIn('merchant_id', $ids))
            ->addSelect('merchant_id')->groupBy('merchant_id')->get()->keyBy('merchant_id');
        $entries = $this->entries(DB::table('merchant_wallet_entries')->whereIn('merchant_id', $ids))
            ->addSelect('merchant_id')->groupBy('merchant_id')->get()->keyBy('merchant_id');
        $pending = DB::table('merchant_wallet_topups')->whereIn('merchant_id', $ids)->where('status', 'pending')
            ->select('merchant_id')->selectRaw('COUNT(*) as pending_topups, SUM(amount_centavos) as pending_topups_centavos')
            ->groupBy('merchant_id')->get()->keyBy('merchant_id');
        $sandbox = Schema::hasTable('integration_sandbox_events')
            ? DB::table('integration_sandbox_events')->whereIn('merchant_id', $ids)->where('environment', 'sandbox')
                ->select('merchant_id')->selectRaw('COUNT(*) as sandbox_events, MAX(created_at) as last_sandbox_at')
                ->groupBy('merchant_id')->get()->keyBy('merchant_id') : collect();
        $keys = Schema::hasTable('merchant_api_keys')
            ? DB::table('merchant_api_keys')->whereIn('merchant_id', $ids)->where('status', 'active')->where('expires_at', '>', now())
                ->select('merchant_id')->selectRaw("SUM(CASE WHEN environment='live' THEN 1 ELSE 0 END) as live_keys,
                    SUM(CASE WHEN environment='sandbox' THEN 1 ELSE 0 END) as sandbox_keys")
                ->groupBy('merchant_id')->get()->keyBy('merchant_id') : collect();
        $out = [];
        foreach ($ids as $id) {
            $w = $wallets[$id] ?? null;
            $values = (array) ($transactions[$id] ?? []) + (array) ($entries[$id] ?? [])
                + (array) ($pending[$id] ?? []) + (array) ($sandbox[$id] ?? []) + (array) ($keys[$id] ?? []);
            $data = ['currency' => 'PHP', 'environment' => 'live', 'wallet_exists' => (bool) $w,
                'available_centavos' => (int) ($w?->available_centavos ?? 0), 'reserved_centavos' => (int) ($w?->reserved_centavos ?? 0),
                'low_balance_threshold_centavos' => (int) ($w?->low_balance_threshold ?? 100000),
                'wallet_status' => !$w ? 'not_funded' : ($w->available_centavos <= 0 ? 'empty' : ($w->available_centavos <= $w->low_balance_threshold ? 'low' : 'funded')),
                'wallet_updated_at' => $this->date($w?->updated_at)];
            foreach (['transactions', 'transaction_amount_centavos', 'successful_transactions', 'failed_transactions',
                'charged_centavos', 'charged_transactions', 'deposits_centavos', 'withdrawals_centavos',
                'pending_topups', 'pending_topups_centavos', 'sandbox_events', 'live_keys', 'sandbox_keys'] as $field) {
                $data[$field] = (int) ($values[$field] ?? 0);
            }
            $data['pending_transactions'] = max(0, $data['transactions'] - $data['successful_transactions'] - $data['failed_transactions']);
            $data['last_transaction_at'] = $this->date($values['last_transaction_at'] ?? null);
            $data['last_sandbox_at'] = $this->date($values['last_sandbox_at'] ?? null);
            $out[$id] = $data;
        }
        return $out;
    }

    private function date(?string $value): ?string
    {
        return $value ? Carbon::parse($value, config('app.timezone'))->toIso8601String() : null;
    }
}
