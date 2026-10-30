<?php

namespace App\Http\Controllers\Api;

use App\Services\ApiAccess;
use App\Services\BirCompliance;
use App\Services\MerchantWallet;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminBusinessWalletController
{
    private function query(Request $r, string $merchant)
    {
        $v = $r->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => ['nullable', 'date_format:Y-m-d', ...($r->filled('from') ? ['after_or_equal:from'] : [])], 'kind' => 'nullable|in:fee,topup,withdrawal', 'page' => 'sometimes|integer|min:1|max:100000']);
        $q = DB::table('merchant_wallet_entries')->where('merchant_id', $merchant);
        if (! empty($v['from'])) {
            $q->where('created_at', '>=', $v['from'].' 00:00:00');
        }
        if (! empty($v['to'])) {
            $q->where('created_at', '<', Carbon::parse($v['to'])->addDay());
        }
        if (! empty($v['kind'])) {
            $q->where('kind', $v['kind']);
        }

        return $q;
    }

    private function details($rows): array
    {
        $ids = collect($rows)->pluck('id');
        $adjustments = DB::table('merchant_wallet_adjustments')->whereIn('ledger_entry_id', $ids)->get()->keyBy('ledger_entry_id');
        $topups = DB::table('merchant_wallet_topups')->whereIn('ledger_entry_id', $ids)->get()->keyBy('ledger_entry_id');
        $actors = DB::table('users')->whereIn('id', collect($rows)->pluck('actor_id'))->pluck('name', 'id');

        return collect($rows)->map(function ($row) use ($adjustments, $topups, $actors) {
            $a = $adjustments[$row->id] ?? null;
            $t = $topups[$row->id] ?? null;

            return (array) $row + ['balance_before' => (int) $row->balance_after - (int) $row->amount_centavos, 'actor_name' => $actors[$row->actor_id] ?? 'Recorded actor', 'manual' => (bool) $a, 'reason' => $a ? Crypt::decryptString($a->reason_encrypted) : ($t?->review_encrypted ? Crypt::decryptString($t->review_encrypted) : null), 'settlement_reference' => $a ? Crypt::decryptString($a->reference_encrypted) : ($t ? Crypt::decryptString($t->payment_reference_encrypted) : null), 'payment_method' => $a?->payment_method ?? $t?->payment_method, 'settled_on' => $a?->settled_on ?? $t?->payment_date];
        })->all();
    }

    public function show(Request $r, string $merchant)
    {
        $user = app(ApiAccess::class)->require($r, ['admin', 'super_admin']);

        return DB::transaction(function () use ($r, $merchant, $user) {
            $m = DB::table('merchants')->where('id', $merchant)->sharedLock()->first();
            abort_unless($m, 404);
            $w = app(MerchantWallet::class)->state($merchant);
            $rows = $this->query($r, $merchant)->orderByDesc('sequence')->paginate(20);
            $base = DB::table('merchant_wallet_entries')->where('merchant_id', $merchant);

            return response()->json(['merchant' => ['id' => $m->id, 'business_name' => $m->business_name, 'tin' => $m->tin, 'status' => $m->status], 'wallet' => ['available_centavos' => (int) $w->available_centavos, 'reserved_centavos' => (int) $w->reserved_centavos, 'sequence' => (int) $w->sequence, 'updated_at' => $w->updated_at, 'integrity' => app(MerchantWallet::class)->headValid($w)], 'can_adjust' => $r->attributes->get('api_role') === 'super_admin' && (string) $m->merchant_account_id !== (string) $user->id, 'summary' => ['topups_centavos' => (int) (clone $base)->where('kind', 'topup')->sum('amount_centavos'), 'withdrawals_centavos' => -(int) (clone $base)->where('kind', 'withdrawal')->sum('amount_centavos'), 'fees_centavos' => -(int) (clone $base)->where('kind', 'fee')->sum('amount_centavos')], 'entries' => ['data' => $this->details($rows->items()), 'total' => $rows->total(), 'page' => $rows->currentPage(), 'last_page' => $rows->lastPage()]])->header('Cache-Control', 'private, no-store');
        });
    }

    public function adjust(Request $r, string $merchant)
    {
        $u = app(ApiAccess::class)->require($r, ['super_admin']);
        if (config('security.enforce_privileged_mfa') && ! app(\App\Services\MfaSession::class)->verified($u)) {
            return response()->json(['message' => 'Complete multifactor verification for this login before adjusting a wallet.', 'code' => 'MFA_REQUIRED'], 403);
        }
        $v = $r->validate(['request_id' => 'required|uuid', 'kind' => 'required|in:topup,withdrawal', 'amount_centavos' => 'required|integer|min:100|max:100000000', 'payment_method' => 'required|in:bank_transfer,gcash,maya,other', 'settlement_reference' => 'required|string|min:10|max:160|not_regex:/[\x00-\x1F\x7F]/', 'settled_on' => 'required|date_format:Y-m-d|before_or_equal:today', 'reason' => 'required|string|min:10|max:1000|not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', 'settlement_confirmed' => 'required|accepted', 'expected_sequence' => 'required|integer|min:0', 'expected_balance_centavos' => 'required|integer|min:0|max:1000000000000']);
        $v['settlement_reference'] = trim($v['settlement_reference']);
        $v['reason'] = trim($v['reason']);
        abort_unless(mb_strlen($v['settlement_reference']) >= 10 && mb_strlen($v['reason']) >= 10, 422, 'Enter a meaningful settlement reference and reason.');
        $content = array_intersect_key($v, array_flip(['request_id', 'kind', 'amount_centavos', 'payment_method', 'settlement_reference', 'settled_on', 'reason']));
        $hash = hash('sha256', json_encode([$merchant, $content], JSON_THROW_ON_ERROR));
        try {
            return DB::transaction(function () use ($u, $v, $hash, $merchant) {
                $m = DB::table('merchants')->where('id', $merchant)->lockForUpdate()->first();
                abort_unless($m, 404);
                abort_if((string) $m->merchant_account_id === (string) $u->id, 403, 'A Super Admin cannot adjust their own business wallet.');
                $old = DB::table('merchant_wallet_adjustments')->where('request_id', $v['request_id'])->first();
                if ($old) {
                    abort_unless($old->merchant_id === $merchant && hash_equals($old->request_hash, $hash), 409, 'This request identifier already belongs to different adjustment details.');

                    return response()->json(['duplicate' => true, 'entry' => $this->details([DB::table('merchant_wallet_entries')->where('id', $old->ledger_entry_id)->first()])[0]]);
                }
                $wallet = app(MerchantWallet::class);
                $w = $wallet->locked($merchant);
                abort_unless((int) $w->sequence === (int) $v['expected_sequence'] && (int) $w->available_centavos === (int) $v['expected_balance_centavos'], 409, 'The wallet changed. Refresh and review the updated balance before confirming.');
                $id = (string) Str::uuid();
                $refHash = hash('sha256', strtoupper($v['settlement_reference']));
                $settlement = hash('sha256', $v['payment_method'].'|'.strtoupper($v['settlement_reference']));
                $dedupe = hash('sha256', $v['kind'].'|'.$settlement);
                abort_if(DB::table('merchant_wallet_adjustments')->where('settlement_hash', $dedupe)->exists(), 409, 'This settlement reference has already been recorded.');
                if ($v['kind'] === 'topup') {
                    // Share the existing funding deduplication domain: manual credit cannot credit an approved payment twice.
                    $creditHash = hash('sha256', $v['payment_method'].'|'.$refHash);
                    abort_if(DB::table('merchant_wallet_topups')->where('settlement_hash', $settlement)->orWhere('payment_credit_hash', $creditHash)->orWhere(fn ($q) => $q->where('payment_method', $v['payment_method'])->where('payment_reference_hash', $refHash)->where('status', 'pending'))->exists(), 409, 'This payment is already recorded or awaiting funding review. Review its existing request.');
                    DB::table('merchant_wallet_topups')->insert(['id' => $id, 'merchant_id' => $merchant, 'request_id' => $v['request_id'], 'request_hash' => $hash, 'amount_centavos' => $v['amount_centavos'], 'payment_method' => $v['payment_method'], 'payment_reference_encrypted' => Crypt::encryptString($v['settlement_reference']), 'payment_reference_hash' => $refHash, 'payment_date' => $v['settled_on'], 'status' => 'approved', 'requested_by' => (string) $u->id, 'reviewed_by' => (string) $u->id, 'review_encrypted' => Crypt::encryptString($v['reason']), 'settlement_hash' => $settlement, 'payment_credit_hash' => $creditHash, 'created_at' => now(), 'reviewed_at' => now()]);
                    $entry = $wallet->credit($merchant, (int) $v['amount_centavos'], $id, 'TOP-'.substr($id, 0, 8), (string) $u->id);
                    DB::table('merchant_wallet_topups')->where('id', $id)->update(['ledger_entry_id' => $entry->id]);
                } else {
                    $entry = $wallet->withdraw($merchant, (int) $v['amount_centavos'], $id, (string) $u->id);
                }
                DB::table('merchant_wallet_adjustments')->insert(['id' => $id, 'merchant_id' => $merchant, 'request_id' => $v['request_id'], 'request_hash' => $hash, 'kind' => $v['kind'], 'amount_centavos' => $v['amount_centavos'], 'payment_method' => $v['payment_method'], 'reference_encrypted' => Crypt::encryptString($v['settlement_reference']), 'settlement_hash' => $dedupe, 'settled_on' => $v['settled_on'], 'reason_encrypted' => Crypt::encryptString($v['reason']), 'actor_id' => (string) $u->id, 'ledger_entry_id' => $entry->id, 'created_at' => now()]);
                app(BirCompliance::class)->audit('wallet.adjustment.posted', $merchant, (string) $u->id, ['adjustment_id' => $id, 'ledger_entry_id' => $entry->id, 'kind' => $v['kind'], 'amount_centavos' => (int) $entry->amount_centavos, 'balance_before_centavos' => (int) $w->available_centavos, 'balance_after_centavos' => (int) $entry->balance_after]);

                return response()->json(['duplicate' => false, 'entry' => $this->details([$entry])[0]], 201);
            }, 3);
        } catch (QueryException $e) {
            if (in_array((string) $e->getCode(), ['23000', '23505'], true)) {
                abort(409, 'This adjustment or settlement reference is already recorded. Refresh the wallet history.');
            }
            throw $e;
        }
    }

    public function statement(Request $r, string $merchant)
    {
        app(ApiAccess::class)->require($r, ['admin', 'super_admin']);
        abort_unless(DB::table('merchants')->where('id', $merchant)->exists(), 404);
        $q = $this->query($r, $merchant);
        abort_if((clone $q)->count() > 5000, 422, 'Narrow the date range to at most 5,000 entries.');
        if ($r->has('report_limit')) {
            \App\Services\ReportCsv::limit($r, (clone $q)->count());
        }
        $rows = $this->details($q->orderBy('sequence')->get());

        return response()->streamDownload(function () use ($rows) {
            $f = fopen('php://output', 'w');
            fwrite($f, "\xEF\xBB\xBF");
            fputcsv($f, ['Sequence', 'Entry ID', 'Recorded (PHT)', 'Type', 'Reference', 'Change PHP', 'Balance Before PHP', 'Balance After PHP', 'Actor ID', 'Actor Name', 'Payment Method', 'Settlement Reference', 'Settlement Date', 'Reason', 'Source Type', 'Source ID', 'Entry Hash']);
            foreach ($rows as $x) {
                $cells = [$x['sequence'], $x['id'], $x['created_at'], $x['kind'], $x['reference'], number_format($x['amount_centavos'] / 100, 2, '.', ''), number_format($x['balance_before'] / 100, 2, '.', ''), number_format($x['balance_after'] / 100, 2, '.', ''), $x['actor_id'], $x['actor_name'], $x['payment_method'], $x['settlement_reference'], $x['settled_on'], $x['reason'], $x['source_type'], $x['source_id'], $x['entry_hash']];
                fputcsv($f, array_map(fn ($v) => is_string($v) && preg_match('/^\s*[=+@-]/u', $v) ? "'".$v : $v, $cells));
            } fclose($f);
        }, 'NUERS-business-wallet-statement.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
