<?php

namespace App\Services;

use App\Exceptions\InsufficientWalletBalance;
use App\Support\SchemaMap;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MerchantWallet
{
    public const ZERO = '0000000000000000000000000000000000000000000000000000000000000000';

    public const MAX_BALANCE = 1000000000000;

    public function available(): bool
    {
        return SchemaMap::hasTable('merchant_wallets');
    }

    public function state(string $merchant): object
    {
        abort_unless($this->available(), 503, 'Wallet storage is not available.');

        return DB::table('merchant_wallets')->where('merchant_id', $merchant)->first() ?? (object) ['merchant_id' => $merchant, 'available_centavos' => 0, 'reserved_centavos' => 0, 'sequence' => 0, 'ledger_head' => self::ZERO, 'low_balance_threshold' => 100000, 'alerts_enabled' => true, 'low_alerted' => false, 'intake_mode' => 'realtime', 'revision' => 1, 'updated_at' => null];
    }

    public function locked(string $merchant): object
    {
        abort_unless(DB::transactionLevel() > 0, 500, 'Wallet changes require an atomic transaction.');
        abort_unless($this->available(), 503, 'Wallet storage is not available.');
        abort_unless(DB::table('merchants')->where('id', $merchant)->lockForUpdate()->first(), 404);
        if (! DB::table('merchant_wallets')->where('merchant_id', $merchant)->exists()) {
            DB::table('merchant_wallets')->insert(['merchant_id' => $merchant, 'updated_at' => now()]);
        }
        $w = DB::table('merchant_wallets')->where('merchant_id', $merchant)->lockForUpdate()->first();
        abort_unless($this->headValid($w), 409, 'Wallet integrity requires administrator review. No transaction was accepted.');

        return $w;
    }

    public function fee(int $gross, string $documentType): int
    {
        if (in_array($documentType, ['payment_receipt', 'official_receipt', 'delivery_receipt', 'purchase_invoice', 'credit_memo', 'debit_memo', 'credit_note', 'debit_note'], true)) {
            return 0;
        }

        return app(TransactionPricing::class)->feeForCentavos($gross);
    }

    public function check(string $merchant, int $gross, string $type): array
    {
        abort_unless($gross >= 0, 422, 'Gross amount must be non-negative.');
        if (in_array($type, ['payment_receipt', 'official_receipt', 'delivery_receipt', 'purchase_invoice', 'credit_memo', 'debit_memo', 'credit_note', 'debit_note'], true)) {
            return ['fee_centavos' => 0, 'pricing_revision' => null];
        }
        $p = app(TransactionPricing::class)->offer(DB::transactionLevel() > 0);
        if ($gross < (int)$p->minimum_transaction_centavos) return ['fee_centavos'=>0,'pricing_revision'=>(int)$p->revision];
        $fee = (int) $p->fee_centavos;
        $w = $this->locked($merchant);
        if ((int) $w->available_centavos < $fee) {
            throw new InsufficientWalletBalance((int) $w->available_centavos, $fee);
        }

        return ['fee_centavos' => $fee, 'pricing_revision' => (int) $p->revision];
    }

    public function charge(string $merchant, int $gross, string $type, string $source, string $id, string $reference, ?string $branch, string $channel, string $actor): array
    {
        abort_unless(DB::transactionLevel() > 0, 500, 'Wallet charges require an atomic transaction.');
        abort_unless(DB::table('merchants')->where('id', $merchant)->lockForUpdate()->first(), 404);
        $event = hash('sha256', $source.'|'.$id);
        $old = $this->available() ? DB::table('merchant_wallet_entries')->where('merchant_id', $merchant)->where('event_key', $event)->first() : null;
        if ($old) {
            abort_unless($old->kind === 'fee' && (int) $old->gross_centavos === $gross, 409, 'Wallet source identity conflicts with the original fee.');

            return ['fee_centavos' => -(int) $old->amount_centavos, 'ledger_entry_id' => $old->id, 'pricing_revision' => $old->pricing_revision];
        }
        $quote = $this->check($merchant, $gross, $type);
        if (! $quote['fee_centavos']) {
            return $quote + ['ledger_entry_id' => null];
        }
        $w = $this->locked($merchant);
        $entry = $this->append($w, -$quote['fee_centavos'], 'fee', $source, $id, $reference, $branch, $channel, $gross, $quote['pricing_revision'], $actor, $event);
        if ($w->alerts_enabled && ! $w->low_alerted && $entry->balance_after <= $w->low_balance_threshold) {
            DB::table('merchant_wallets')->where('merchant_id', $merchant)->update(['low_alerted' => true]);
            app(BirCompliance::class)->audit('wallet.balance.low', $merchant, $actor, ['merchant_id' => $merchant]);
        }

        return $quote + ['ledger_entry_id' => $entry->id, 'balance_after_centavos' => (int) $entry->balance_after];
    }

    public function credit(string $merchant, int $amount, string $id, string $reference, string $actor): object
    {
        $w = $this->locked($merchant);
        abort_unless($amount > 0, 422, 'Top-up must be positive.');
        $event = hash('sha256', 'topup|'.$id);
        $old = DB::table('merchant_wallet_entries')->where('merchant_id', $merchant)->where('event_key', $event)->first();
        if ($old) {
            abort_unless((int) $old->amount_centavos === $amount, 409, 'Funding request conflicts with its original amount.');

            return $old;
        }

        abort_unless($w->available_centavos <= self::MAX_BALANCE - $amount, 422, 'Top-up exceeds the supported wallet balance limit.');

        return $this->append($w, $amount, 'topup', 'topup', $id, $reference, null, 'verified_manual', 0, null, $actor, $event);
    }

    private function append(object $w, int $amount, string $kind, string $source, string $id, string $reference, ?string $branch, string $channel, int $gross, ?int $revision, string $actor, string $event): object
    {
        $balance = (int) $w->available_centavos + $amount;
        abort_unless($balance >= 0 && $balance <= self::MAX_BALANCE, 409, 'Wallet balance invariant failed.');
        $row = ['id' => (string) Str::uuid(), 'merchant_id' => $w->merchant_id, 'sequence' => (int) $w->sequence + 1, 'kind' => $kind, 'amount_centavos' => $amount, 'balance_after' => $balance, 'source_type' => $source, 'source_id' => $id, 'reference' => mb_substr($reference, 0, 160), 'branch_code' => $branch, 'channel' => $channel, 'gross_centavos' => $gross, 'pricing_revision' => $revision, 'event_key' => $event, 'actor_id' => $actor, 'previous_hash' => $w->ledger_head, 'created_at' => now()->format('Y-m-d H:i:s')];
        $row['entry_hash'] = $this->hash($row);
        DB::table('merchant_wallet_entries')->insert($row);
        DB::table('merchant_wallets')->where('merchant_id', $w->merchant_id)->update(['available_centavos' => $balance, 'sequence' => $row['sequence'], 'ledger_head' => $row['entry_hash'], 'low_alerted' => $balance > $w->low_balance_threshold ? false : $w->low_alerted, 'updated_at' => now()]);

        return (object) $row;
    }

    public function withdraw(string $merchant, int $amount, string $id, string $actor): object
    {
        $w = $this->locked($merchant);
        abort_unless($amount > 0, 422, 'Withdrawal must be positive.');
        $event = hash('sha256', 'withdrawal|'.$id);
        $old = DB::table('merchant_wallet_entries')->where('merchant_id', $merchant)->where('event_key', $event)->first();
        if ($old) {
            abort_unless((int) $old->amount_centavos === -$amount, 409, 'Withdrawal conflicts with its original amount.');

            return $old;
        }
        abort_unless((int) $w->available_centavos >= $amount, 409, 'Withdrawal exceeds the current available balance. Refresh the wallet.');

        return $this->append($w, -$amount, 'withdrawal', 'manual_withdrawal', $id, 'WDR-'.substr($id, 0, 8), null, 'verified_manual', 0, null, $actor, $event);
    }

    public function hash(array $row): string
    {
        unset($row['entry_hash']);
        foreach (['sequence', 'amount_centavos', 'balance_after', 'gross_centavos'] as $key) {
            $row[$key] = (int) $row[$key];
        }$row['pricing_revision'] = $row['pricing_revision'] === null ? null : (int) $row['pricing_revision'];
        ksort($row);

        return hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function headValid(object $w): bool
    {
        if ((int) $w->available_centavos < 0 || (int) $w->reserved_centavos !== 0) {
            return false;
        }
        $last = DB::table('merchant_wallet_entries')->where('merchant_id', $w->merchant_id)->orderByDesc('sequence')->first();
        if (! $last) {
            return (int) $w->sequence === 0 && (int) $w->available_centavos === 0 && $w->ledger_head === self::ZERO;
        }

        return (int) $last->sequence === (int) $w->sequence && (int) $last->balance_after === (int) $w->available_centavos && hash_equals($last->entry_hash, $w->ledger_head) && hash_equals($last->entry_hash, $this->hash((array) $last));
    }

    public function audit(string $merchant): array
    {
        $w = $this->state($merchant);
        $head = self::ZERO;
        $sequence = 0;
        $balance = 0;
        $valid = true;
        foreach (DB::table('merchant_wallet_entries')->where('merchant_id', $merchant)->orderBy('sequence')->cursor() as $row) {
            $sequence++;
            $balance += (int) $row->amount_centavos;
            if ((int) $row->sequence !== $sequence || $row->previous_hash !== $head || ! hash_equals($row->entry_hash, $this->hash((array) $row)) || (int) $row->balance_after !== $balance || $balance < 0) {
                $valid = false;
            }$head = $row->entry_hash;
        }

        return ['valid' => $valid && $head === $w->ledger_head && $sequence === (int) $w->sequence && $balance === (int) $w->available_centavos && $this->headValid($w), 'entries' => $sequence, 'balance_centavos' => $balance];
    }
}
