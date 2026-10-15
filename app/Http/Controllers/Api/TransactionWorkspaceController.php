<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BirCompliance;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class TransactionWorkspaceController extends Controller
{
    protected function actor(Request $r): object
    {
        $u = $r->bearerToken() ? DB::table('users')->where('api_token', $r->bearerToken())->first() : null;
        abort_unless($u, 401);
        abort_unless(in_array(DB::table('profiles')->where('id', $u->id)->value('role'), ['admin', 'super_admin', 'bir'], true), 403);

        return $u;
    }

    protected function ledger()
    {
        $q = DB::table('merchant_transactions as t')->leftJoin('merchants as m', 't.merchant_id', '=', 'm.id')->leftJoin('transaction_receipts as r', 't.receipt_id', '=', 'r.id');
        $hasEis = Schema::hasTable('eis_transmissions') && Schema::hasTable('eis_invoice_payloads') && Schema::hasTable('eis_acknowledgements');
        if ($hasEis) {
            $latest = '(SELECT et.id FROM eis_transmissions et JOIN eis_invoice_payloads ep ON ep.id=et.payload_id WHERE ep.receipt_id=t.receipt_id AND et.merchant_id=t.merchant_id ORDER BY et.created_at DESC, et.id DESC LIMIT 1)';
            $q->leftJoin('eis_transmissions as e', fn ($j) => $j->on('e.id', '=', DB::raw($latest)));
            $ack = '(SELECT ea.id FROM eis_acknowledgements ea WHERE ea.transmission_id=e.id ORDER BY ea.received_at DESC, ea.id DESC LIMIT 1)';
            $q->leftJoin('eis_acknowledgements as a', fn ($j) => $j->on('a.id', '=', DB::raw($ack)));
        }
        $success = "'completed','paid','issued','settled','verified'";
        $cancelled = "'cancelled','canceled','voided','void','refunded','refund'";
        $q->select(['t.id', 't.merchant_id', 't.transaction_ref', 't.amount', 't.vat_amount', 't.vatable_sales', 't.net_amount', 't.payment_method', 't.region', 't.branch', 't.channel', 't.customer_name', 't.customer_tin', 't.status as record_status', 't.created_at', 't.receipt_id', 'r.receipt_number', 'r.vat_exempt_sales', 'r.zero_rated_sales']);
        $q->selectRaw("COALESCE(NULLIF(m.business_name,''),NULLIF(r.merchant_name,''),'Unregistered business') as business, COALESCE(NULLIF(m.tin,''),r.merchant_tin,'') as tin, COALESCE(t.rdo_code,r.rdo_code,m.rdo_code) as rdo_code, COALESCE(r.document_type,r.receipt_type,t.transaction_type,'sale') as document_type");
        $q->selectRaw("CASE WHEN t.status IN ($success) THEN 'success' WHEN t.status IN ($cancelled) THEN 'cancelled' WHEN t.status IN ('failed','rejected') THEN 'failed' ELSE 'pending' END as status");
        if ($hasEis) {
            $verified = config('bir_compliance.transport_implemented') ? 1 : 0;
            $q->addSelect(['e.id as transmission_id', 'e.status as transmission_status', 'e.environment', 'e.submitted_at', 'e.acknowledged_at', 'e.due_at', 'e.response_code', 'e.response_message', 'e.attempt_number', 'e.max_attempts', 'e.next_retry_at', 'e.payload_hash', 'a.acknowledgement_number', 'a.bir_reference_number', 'a.received_at as acknowledgement_received_at']);
            $q->selectRaw("CASE WHEN e.id IS NULL THEN 'not_submitted' WHEN e.environment <> 'production' THEN 'test' WHEN e.status IN ('queued','pending','processing','retry_scheduled','submitted','transmitting') THEN 'processing' WHEN $verified=1 AND e.status IN ('acknowledged','accepted','success') AND a.id IS NOT NULL AND NULLIF(a.acknowledgement_number,'') IS NOT NULL AND NULLIF(a.bir_reference_number,'') IS NOT NULL AND a.raw_response IS NOT NULL THEN 'acknowledged' WHEN $verified=1 AND e.status IN ('rejected','failed') THEN 'rejected' ELSE 'unverified' END as bir_status");
        } else {
            $q->selectRaw("NULL as transmission_id, NULL as transmission_status, NULL as environment, NULL as submitted_at, NULL as acknowledged_at, NULL as due_at, NULL as response_code, NULL as response_message, NULL as attempt_number, NULL as max_attempts, NULL as next_retry_at, NULL as payload_hash, NULL as acknowledgement_number, NULL as bir_reference_number, NULL as acknowledgement_received_at, 'not_submitted' as bir_status");
        }

        return DB::query()->fromSub($q, 'x')->selectRaw("x.*, CASE WHEN status='cancelled' THEN 'cancelled' WHEN document_type IN ('credit_note','debit_note','credit_memo','debit_memo') THEN 'notes' WHEN document_type IN ('official_receipt','payment_receipt') THEN 'receipts' ELSE 'invoices' END as category");
    }

    protected function filtered(Request $r)
    {
        $v = $r->validate(['search' => 'nullable|string|max:200', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'status' => ['nullable', Rule::in(['all', 'success', 'failed', 'pending', 'cancelled'])], 'type' => ['nullable', Rule::in(['all', 'invoices', 'receipts', 'notes', 'cancelled'])], 'bir' => ['nullable', Rule::in(['all', 'not_submitted', 'processing', 'acknowledged', 'rejected', 'test', 'unverified'])], 'vat' => ['nullable', Rule::in(['all', 'vatable', 'exempt', 'zero'])],
            'branch' => 'nullable|string|max:200', 'payment' => 'nullable|string|max:60', 'region' => 'nullable|string|max:100', 'rdo' => 'nullable|string|max:20', 'min' => 'nullable|numeric|min:0', 'max' => 'nullable|numeric|min:0', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100', 'sort' => ['nullable', Rule::in(['newest', 'oldest', 'amount_desc', 'amount_asc'])]]);
        $q = DB::query()->fromSub($this->ledger(), 'ledger');
        if (! empty($v['from']) && ! empty($v['to']) && $v['to'] < $v['from']) {
            abort(422, 'End date must be on or after the start date.');
        }
        if (isset($v['min'],$v['max']) && $v['max'] < $v['min']) {
            abort(422, 'Maximum amount must be at least the minimum amount.');
        }
        if (! empty($v['search'])) {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v['search']).'%';
            $q->where(fn ($s) => $s->where('transaction_ref', 'like', $term)->orWhere('receipt_number', 'like', $term)->orWhere('business', 'like', $term)->orWhere('tin', 'like', $term)->orWhere('customer_name', 'like', $term)->orWhere('customer_tin', 'like', $term));
        }
        if (! empty($v['from'])) {
            $q->where('created_at', '>=', Carbon::parse($v['from'], config('app.timezone'))->startOfDay());
        }if (! empty($v['to'])) {
            $q->where('created_at', '<=', Carbon::parse($v['to'], config('app.timezone'))->endOfDay());
        }
        foreach (['status' => 'status', 'bir' => 'bir_status', 'branch' => 'branch', 'payment' => 'payment_method', 'region' => 'region', 'rdo' => 'rdo_code'] as $param => $col) {
            if (! empty($v[$param]) && $v[$param] !== 'all') {
                $q->where($col, $v[$param]);
            }
        }
        if (isset($v['min'])) {
            $q->where('amount', '>=', $v['min']);
        }if (isset($v['max'])) {
            $q->where('amount', '<=', $v['max']);
        }

        if (! empty($v['vat']) && $v['vat'] !== 'all') {
            $q->where(match ($v['vat']) {
                'exempt' => 'vat_exempt_sales','zero' => 'zero_rated_sales',default => 'vatable_sales'
            }, '>', 0);
        }

        return [$q, $v];
    }

    protected function category($q, array $v)
    {
        if (($v['type'] ?? 'all') !== 'all') {
            $q->where('category', $v['type']);
        }

        return $q;
    }

    protected function row(object $row): array
    {
        $r = (array) $row;
        foreach (['amount', 'vat_amount', 'vatable_sales', 'net_amount'] as $k) {
            $r[$k] = (float) $r[$k];
        }
        foreach (['created_at', 'submitted_at', 'acknowledged_at', 'due_at', 'next_retry_at', 'acknowledgement_received_at'] as $k) {
            if (! empty($r[$k])) {
                $r[$k] = Carbon::parse($r[$k], config('app.timezone'))->toIso8601String();
            }
        }
        $r['transmission_target'] = $r['due_at'] ?? app(BirCompliance::class)->deadline($row->created_at)->toIso8601String();
        $r['overdue'] = $r['bir_status'] !== 'acknowledged' && $r['status'] !== 'cancelled' && Carbon::parse($r['transmission_target'])->isPast();

        return $r;
    }

    public function index(Request $r)
    {
        $this->actor($r);
        [$q,$v] = $this->filtered($r);
        $tabs = (clone $q)->selectRaw("COUNT(*) as total, COALESCE(SUM(CASE WHEN category='invoices' THEN 1 ELSE 0 END),0) as invoices, COALESCE(SUM(CASE WHEN category='receipts' THEN 1 ELSE 0 END),0) as receipts, COALESCE(SUM(CASE WHEN category='notes' THEN 1 ELSE 0 END),0) as notes, COALESCE(SUM(CASE WHEN category='cancelled' THEN 1 ELSE 0 END),0) as cancelled")->first();
        $q = $this->category($q, $v);
        $stats = (clone $q)->selectRaw("COUNT(*) as total, COALESCE(SUM(CASE WHEN status='success' THEN 1 ELSE 0 END),0) as successful, COALESCE(SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END),0) as failed, COALESCE(SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END),0) as pending, COALESCE(SUM(CASE WHEN status='success' THEN amount ELSE 0 END),0) as total_amount")->first();
        $sort = $v['sort'] ?? 'newest';
        $q->orderBy(str_starts_with($sort, 'amount') ? 'amount' : 'created_at', in_array($sort, ['oldest', 'amount_asc']) ? 'asc' : 'desc')->orderBy('id');
        $page = $q->paginate($v['per_page'] ?? 10);

        return response()->json(['data' => array_map(fn ($x) => $this->row($x), $page->items()), 'tabs' => $tabs, 'summary' => $stats, 'total' => $page->total(), 'last_page' => $page->lastPage(), 'page' => $page->currentPage(), 'generated_at' => now()->toIso8601String(), 'gateway' => ['status' => 'not_verified', 'message' => config('bir_compliance.transport_implemented') ? 'No current gateway health probe is recorded.' : 'Production BIR transport is not configured.'], 'options' => ['branches' => DB::table('merchant_transactions')->whereNotNull('branch')->distinct()->orderBy('branch')->pluck('branch'), 'payments' => DB::table('merchant_transactions')->whereNotNull('payment_method')->distinct()->orderBy('payment_method')->pluck('payment_method'), 'regions' => DB::table('merchant_transactions')->whereNotNull('region')->distinct()->orderBy('region')->pluck('region'), 'rdos' => DB::table('rdo_offices')->where('status', 'active')->orderBy('rdo_code')->get(['rdo_code', 'rdo_name'])]]);
    }

    public function show(Request $r, string $id)
    {
        $this->actor($r);
        $row = DB::query()->fromSub($this->ledger(), 'ledger')->where('id', $id)->first();
        abort_unless($row, 404);

        return response()->json(['transaction' => $this->row($row), 'solution' => ['name' => config('bir_compliance.solution_name', 'NUERS'), 'version' => config('bir_compliance.solution_version'), 'production_transport' => config('bir_compliance.transport_implemented')]]);
    }

    public function export(Request $r)
    {
        $actor = $this->actor($r);
        [$q,$v] = $this->filtered($r);
        $q = $this->category($q, $v);
        app(BirCompliance::class)->audit('transactions.export.requested', 'transaction-workspace', (string) $actor->id, ['filters' => $v]);

        return response()->streamDownload(function () use ($q) {
            $f = fopen('php://output', 'w');
            fwrite($f, "\xEF\xBB\xBF");
            fputcsv($f, ['Transaction ID', 'Transaction No.', 'Receipt No.', 'Business', 'TIN', 'Date / Time', 'Document Type', 'Amount PHP', 'VAT PHP', 'Transaction Status', 'BIR Status', 'Transmission ID', 'Environment', 'Acknowledgment No.', 'BIR Reference']);
            foreach ($q->lazyById(500, 'id') as $row) {
                $x = $this->row($row);
                $text = fn ($s) => preg_match('/^\s*[=+@\-]/', (string) $s) ? "'".$s : (string) $s;
                fputcsv($f, [$text($x['id']), $text($x['transaction_ref']), $text($x['receipt_number']), $text($x['business']), $text($x['tin']), $x['created_at'], $text($x['document_type']), number_format($x['amount'], 2, '.', ''), number_format($x['vat_amount'], 2, '.', ''), $x['status'], $x['bir_status'], $text($x['transmission_id']), $text($x['environment']), $text($x['acknowledgement_number']), $text($x['bir_reference_number'])]);
            }fclose($f);
        }, 'NUERS-transactions-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
