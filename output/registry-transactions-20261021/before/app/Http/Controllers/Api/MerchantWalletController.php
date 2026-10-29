<?php

namespace App\Http\Controllers\Api;

use App\Services\ApiAccess;
use App\Services\BirCompliance;
use App\Services\MerchantWallet;
use App\Services\TransactionPricing;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MerchantWalletController
{
    private function wallet(): MerchantWallet
    {
        return app(MerchantWallet::class);
    }

    private function admin(Request $r)
    {
        return app(ApiAccess::class)->require($r, ['super_admin']);
    }

    private function dates(Request $r): array
    {
        $v = $r->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => ['nullable', 'date_format:Y-m-d', ...($r->filled('from') ? ['after_or_equal:from'] : [])], 'page' => 'sometimes|integer|min:1|max:100000', 'kind' => 'nullable|in:fee,topup,withdrawal']);

        return $v;
    }

    private function topupRow(object $r): array
    {
        return ['id' => $r->id, 'merchant_id' => $r->merchant_id, 'amount_centavos' => (int) $r->amount_centavos, 'payment_method' => $r->payment_method, 'payment_reference' => Crypt::decryptString($r->payment_reference_encrypted), 'payment_date' => $r->payment_date, 'status' => $r->status, 'created_at' => $r->created_at, 'reviewed_at' => $r->reviewed_at, 'review_note' => $r->review_encrypted ? Crypt::decryptString($r->review_encrypted) : null, 'ledger_entry_id' => $r->ledger_entry_id];
    }

    public function index(Request $r)
    {
        $m = app(ApiAccess::class)->merchant($r);

        return DB::transaction(function () use ($r, $m) {
            DB::table('merchants')->where('id', $m->id)->sharedLock()->first();

            return $this->snapshot($r, $m);
        });
    }

    private function snapshot(Request $r, object $m)
    {
        $v = $this->dates($r);
        $w = $this->wallet()->state($m->id);
        $base = DB::table('merchant_wallet_entries')->where('merchant_id', $m->id);
        $query = clone $base;
        if (! empty($v['from'])) {
            $query->where('created_at', '>=', $v['from'].' 00:00:00');
        }if (! empty($v['to'])) {
            $query->where('created_at', '<', Carbon::parse($v['to'])->addDay());
        }if (! empty($v['kind'])) {
            $query->where('kind', $v['kind']);
        }$rows = $query->orderByDesc('sequence')->paginate(10, ['*'], 'page', $v['page'] ?? 1);
        $fees = (clone $base)->where('kind', 'fee');
        $today = (clone $fees)->where('created_at', '>=', today())->sum('amount_centavos');
        $month = (clone $fees)->where('created_at', '>=', now()->startOfMonth())->sum('amount_centavos');
        $count = (clone $fees)->where('created_at', '>=', now()->startOfMonth())->count();
        $start = today()->subDays(29);
        $balance = (int) (clone $base)->where('created_at', '<', $start)->sum('amount_centavos');
        $daily = (clone $base)->where('created_at', '>=', $start)->selectRaw("DATE(created_at) as day, SUM(CASE WHEN kind='topup' THEN amount_centavos ELSE 0 END) as credits,SUM(CASE WHEN kind='fee' THEN -amount_centavos ELSE 0 END) as fees,SUM(CASE WHEN kind='withdrawal' THEN -amount_centavos ELSE 0 END) as withdrawals")->groupByRaw('DATE(created_at)')->get()->keyBy('day');
        $chart = [];
        for ($i = 0; $i < 30; $i++) {
            $day = $start->copy()->addDays($i)->toDateString();
            $x = $daily[$day] ?? null;
            $credits = (int) ($x->credits ?? 0);
            $deducted = (int) ($x->fees ?? 0);
            $withdrawn = (int) ($x->withdrawals ?? 0);
            $balance += $credits - $deducted - $withdrawn;
            $chart[] = ['date' => $day, 'topups_centavos' => $credits, 'fees_centavos' => $deducted, 'withdrawals_centavos' => $withdrawn, 'balance_centavos' => $balance];
        }

        return response()->json(['merchant' => ['id' => $m->id, 'name' => $m->business_name, 'status' => $m->status], 'wallet' => ['available_centavos' => (int) $w->available_centavos, 'reserved_centavos' => (int) $w->reserved_centavos, 'low_balance_threshold' => (int) $w->low_balance_threshold, 'alerts_enabled' => (bool) $w->alerts_enabled, 'intake_mode' => $w->intake_mode, 'revision' => (int) $w->revision, 'updated_at' => $w->updated_at, 'integrity' => $this->wallet()->headValid($w)], 'pricing' => app(TransactionPricing::class)->offer(), 'summary' => ['fees_today_centavos' => -(int) $today, 'fees_month_centavos' => -(int) $month, 'charged_this_month' => $count, 'average_fee_centavos' => $count ? (int) round(-$month / $count) : 0, 'credited_total_centavos' => (int) (clone $base)->where('kind', 'topup')->sum('amount_centavos'), 'pending_topups' => DB::table('merchant_wallet_topups')->where('merchant_id', $m->id)->where('status', 'pending')->count()], 'chart' => $chart, 'entries' => ['data' => $rows->items(), 'total' => $rows->total(), 'page' => $rows->currentPage(), 'last_page' => $rows->lastPage()], 'topups' => DB::table('merchant_wallet_topups')->where('merchant_id', $m->id)->orderByDesc('created_at')->limit(10)->get()->map(fn ($x) => $this->topupRow($x)), 'currency' => 'PHP', 'funding_mode' => 'verified_manual', 'bir_acceptance' => 'not_verified'])->header('Cache-Control', 'private, no-store');
    }

    public function settings(Request $r)
    {
        $m = app(ApiAccess::class)->merchant($r);
        $v = $r->validate(['low_balance_threshold' => 'required|integer|min:0|max:1000000000', 'alerts_enabled' => 'required|boolean', 'intake_mode' => 'required|in:realtime,batch', 'revision' => 'required|integer|min:1']);

        return DB::transaction(function () use ($m, $v, $r) {
            $w = $this->wallet()->locked($m->id);
            abort_unless((int) $w->revision === (int) $v['revision'], 409, 'Wallet settings changed. Refresh before saving.');
            DB::table('merchant_wallets')->where('merchant_id', $m->id)->update([...$v, 'low_alerted' => false, 'revision' => $w->revision + 1, 'updated_at' => now()]);
            app(BirCompliance::class)->audit('wallet.settings.updated', $m->id, (string) $r->attributes->get('api_actor')->id, ['intake_mode' => $v['intake_mode'], 'alerts_enabled' => $v['alerts_enabled']]);

            return response()->json(['saved' => true]);
        });
    }

    public function topup(Request $r)
    {
        $m = app(ApiAccess::class)->merchant($r);
        $v = $r->validate(['request_id' => 'required|uuid', 'amount_centavos' => 'required|integer|min:100|max:100000000', 'payment_method' => 'required|in:bank_transfer,gcash,maya,other', 'payment_reference' => 'required|string|min:6|max:160|not_regex:/[\x00-\x1F\x7F]/', 'payment_date' => 'required|date_format:Y-m-d|before_or_equal:today']);
        $v['payment_reference'] = trim($v['payment_reference']);
        abort_unless(mb_strlen($v['payment_reference']) >= 6, 422, 'Enter a valid payment reference.');
        $hash = hash('sha256', json_encode($v));

        return DB::transaction(function () use ($m, $v, $hash, $r) {
            DB::table('merchants')->where('id', $m->id)->lockForUpdate()->first();
            $old = DB::table('merchant_wallet_topups')->where('merchant_id', $m->id)->where('request_id', $v['request_id'])->first();
            if ($old) {
                abort_unless(hash_equals($old->request_hash, $hash), 409, 'This request identifier belongs to different funding details.');

                return response()->json(['topup' => $this->topupRow($old), 'duplicate' => true]);
            }abort_if(DB::table('merchant_wallet_topups')->where('merchant_id', $m->id)->where('status', 'pending')->count() >= 10, 429, 'Review the pending funding requests before adding another.');
            $id = (string) Str::uuid();
            DB::table('merchant_wallet_topups')->insert(['id' => $id, 'merchant_id' => $m->id, 'request_id' => $v['request_id'], 'request_hash' => $hash, 'amount_centavos' => (int) $v['amount_centavos'], 'payment_method' => $v['payment_method'], 'payment_reference_encrypted' => Crypt::encryptString($v['payment_reference']), 'payment_reference_hash' => hash('sha256', strtoupper($v['payment_reference'])), 'payment_date' => $v['payment_date'], 'status' => 'pending', 'requested_by' => (string) $r->attributes->get('api_actor')->id, 'created_at' => now()]);
            app(BirCompliance::class)->audit('wallet.topup.requested', $m->id, (string) $r->attributes->get('api_actor')->id, ['topup_id' => $id]);

            return response()->json(['topup' => $this->topupRow(DB::table('merchant_wallet_topups')->where('id', $id)->first()), 'credited' => false], 201);
        });
    }

    public function adminIndex(Request $r)
    {
        $this->admin($r);
        $v = $r->validate(['page' => 'sometimes|integer|min:1|max:10000', 'search' => 'nullable|string|max:100']);
        $query = DB::table('merchants as m')->leftJoin('merchant_wallets as w', 'w.merchant_id', '=', 'm.id');
        if (! empty($v['search'])) {
            $query->where(fn ($q) => $q->where('m.business_name', 'like', '%'.$v['search'].'%')->orWhere('m.tin', 'like', '%'.$v['search'].'%'));
        }$rows = $query->orderBy('m.business_name')->paginate(20, ['m.id', 'm.business_name', 'm.tin', 'm.status', 'w.available_centavos', 'w.reserved_centavos', 'w.updated_at'], 'page', $v['page'] ?? 1);
        $requests = DB::table('merchant_wallet_topups as t')->join('merchants as m', 'm.id', '=', 't.merchant_id')->where('t.status', 'pending')->orderBy('t.created_at')->limit(30)->get(['t.*', 'm.business_name']);

        return response()->json(['pricing' => app(TransactionPricing::class)->offer(), 'wallets' => ['data' => $rows->items(), 'total' => $rows->total(), 'page' => $rows->currentPage(), 'last_page' => $rows->lastPage()], 'requests' => $requests->map(fn ($x) => $this->topupRow($x) + ['business_name' => $x->business_name]), 'history' => DB::table('wallet_pricing_history')->orderByDesc('created_at')->limit(10)->get()])->header('Cache-Control', 'private, no-store');
    }

    public function pricing(Request $r)
    {
        $u = $this->admin($r);
        $v = $r->validate(['fee_centavos' => 'required|integer|min:0|max:1000000', 'minimum_transaction_centavos'=>'sometimes|required|integer|min:0|max:1000000000000', 'revision' => 'required|integer|min:1', 'reason' => 'required|string|min:10|max:1000']);

        $v['reason'] = trim($v['reason']);
        abort_unless(mb_strlen($v['reason']) >= 10, 422, 'Enter a meaningful change reference.');

        return DB::transaction(function () use ($u, $v) {
            $p = DB::table('website_subscription_plans')->where('slug', TransactionPricing::SLUG)->where('published', true)->lockForUpdate()->first();
            abort_unless($p, 503);
            abort_unless((int) $p->revision === (int) $v['revision'], 409, 'Pricing changed. Refresh before saving.');
            $v['minimum_transaction_centavos'] ??= (int)$p->minimum_transaction_centavos;
            DB::table('website_subscription_plans')->where('id', $p->id)->update(['minimum_transaction_centavos'=>(int)$v['minimum_transaction_centavos'], 'fee_centavos' => (int) $v['fee_centavos'], 'description' => 'Pay ₱'.number_format($v['fee_centavos'] / 100, 2, '.', ',').((int)$v['minimum_transaction_centavos'] === 0 ? ' per finalized sale, with no minimum amount.' : ' per finalized sale of ₱'.number_format($v['minimum_transaction_centavos']/100,2,'.',',').' and above.').' Unlimited storage with five-year retention.', 'revision' => $p->revision + 1, 'updated_at' => now()]);
            $id = (string) Str::uuid();
            DB::table('wallet_pricing_history')->insert(['id' => $id, 'revision' => $p->revision + 1, 'previous_minimum_transaction_centavos'=>(int)$p->minimum_transaction_centavos, 'minimum_transaction_centavos'=>(int)$v['minimum_transaction_centavos'], 'previous_fee_centavos' => $p->fee_centavos, 'fee_centavos' => $v['fee_centavos'], 'actor_id' => (string) $u->id, 'reason' => $v['reason'], 'created_at' => now()]);
            app(BirCompliance::class)->audit('wallet.pricing.updated', $id, (string) $u->id, ['previous_minimum_transaction_centavos'=>(int)$p->minimum_transaction_centavos, 'minimum_transaction_centavos'=>(int)$v['minimum_transaction_centavos'], 'revision' => $p->revision + 1, 'fee_centavos' => $v['fee_centavos']]);

            return response()->json(['pricing' => app(TransactionPricing::class)->offer()]);
        });
    }

    public function review(Request $r, string $id)
    {
        $u = $this->admin($r);
        $v = $r->validate(['decision' => 'required|in:approved,rejected', 'reason' => 'required|string|min:10|max:1000', 'settlement_reference' => 'required_if:decision,approved|nullable|string|min:10|max:200', 'settlement_confirmed' => 'exclude_unless:decision,approved|required|accepted']);
        $v['reason'] = trim($v['reason']);
        abort_unless(mb_strlen($v['reason']) >= 10, 422, 'Enter a meaningful review reference.');
        if ($v['decision'] === 'approved') {
            $v['settlement_reference'] = trim($v['settlement_reference']);
            abort_unless(mb_strlen($v['settlement_reference']) >= 10, 422, 'Enter a valid settlement trace reference.');
        }
        try {
            return DB::transaction(function () use ($u, $v, $id) {
                $t = DB::table('merchant_wallet_topups')->where('id', $id)->first();
                abort_unless($t, 404);
                $m = DB::table('merchants')->where('id', $t->merchant_id)->lockForUpdate()->first();
                $t = DB::table('merchant_wallet_topups')->where('id', $id)->lockForUpdate()->first();
                abort_if((string) $t->requested_by === (string) $u->id || (string) $m->merchant_account_id === (string) $u->id, 403, 'Funding must be verified independently by another Super Admin.');
                abort_unless($t->status === 'pending', 409, 'This funding request already has a decision. Refresh the queue.');
                $settlement = $v['decision'] === 'approved' ? hash('sha256', $t->payment_method.'|'.strtoupper(trim($v['settlement_reference']))) : null;
                $paymentHash = $settlement ? hash('sha256', $t->payment_method.'|'.$t->payment_reference_hash) : null;
                if ($settlement) {
                    abort_if(DB::table('merchant_wallet_topups')->where('settlement_hash', $settlement)->orWhere('payment_credit_hash', $paymentHash)->exists(), 409, 'This payment or settlement reference is already applied.');
                }DB::table('merchant_wallet_topups')->where('id', $id)->update(['status' => $v['decision'], 'reviewed_by' => (string) $u->id, 'review_encrypted' => Crypt::encryptString($v['reason']), 'settlement_hash' => $settlement, 'payment_credit_hash' => $paymentHash, 'reviewed_at' => now()]);
                $entry = $settlement ? $this->wallet()->credit($m->id, (int) $t->amount_centavos, $t->id, 'TOP-'.substr($t->id, 0, 8), (string) $u->id) : null;
                if ($entry) {
                    DB::table('merchant_wallet_topups')->where('id', $id)->update(['ledger_entry_id' => $entry->id]);
                }app(BirCompliance::class)->audit('wallet.topup.reviewed', $m->id, (string) $u->id, ['topup_id' => $id, 'decision' => $v['decision']]);

                return response()->json(['decision' => $v['decision'], 'credited_centavos' => $entry ? (int) $entry->amount_centavos : 0]);
            }, 3);
        } catch (QueryException$e) {
            if ((string) $e->getCode() === '23000') {
                abort(409, 'Settlement reference conflicts with an existing funding entry.');
            }throw $e;
        }
    }

    public function audit(Request $r, string $merchant)
    {
        $this->admin($r);

        return DB::transaction(function () use ($merchant) {
            abort_unless(DB::table('merchants')->where('id', $merchant)->lockForUpdate()->first(), 404);

            return response()->json($this->wallet()->audit($merchant));
        });
    }

    public function statement(Request $r)
    {
        $m = app(ApiAccess::class)->merchant($r);
        $v = $this->dates($r);
        $q = DB::table('merchant_wallet_entries')->where('merchant_id', $m->id);
        if (! empty($v['from'])) {
            $q->where('created_at', '>=', $v['from'].' 00:00:00');
        }if (! empty($v['to'])) {
            $q->where('created_at', '<', Carbon::parse($v['to'])->addDay());
        }if (! empty($v['kind'])) {
            $q->where('kind', $v['kind']);
        }abort_if((clone $q)->count() > 5000, 422, 'Narrow the date range to at most 5,000 entries.');
        if ($r->has('report_limit')) \App\Services\ReportCsv::limit($r,(clone $q)->count());
        $rows = $q->orderBy('sequence')->get();

        return response()->streamDownload(function () use ($rows) {
            $f = fopen('php://output', 'w');
            fwrite($f, "\xEF\xBB\xBF");
            fputcsv($f, ['Sequence', 'Date (PHT)', 'Reference', 'Type', 'Gross PHP', 'Wallet change PHP', 'Balance after PHP']);
            foreach ($rows as $x) {
                fputcsv($f, [$x->sequence, $x->created_at, preg_match('/^\s*[=+@-]/', $x->reference) ? "'".$x->reference : $x->reference, $x->kind, number_format($x->gross_centavos / 100, 2, '.', ''), number_format($x->amount_centavos / 100, 2, '.', ''), number_format($x->balance_after / 100, 2, '.', '')]);
            }fclose($f);
        }, 'NUERS-wallet-statement.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
