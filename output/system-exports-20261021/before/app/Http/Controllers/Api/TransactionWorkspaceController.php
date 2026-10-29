<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BirCompliance;
use App\Services\EisReportingPolicy;
use App\Services\IssuedDocumentLedger;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class TransactionWorkspaceController extends Controller
{
    protected function actor(Request $r): object
    {
        $u = $r->bearerToken() ? app(\App\Services\ApiAccess::class)->user($r) : null;
        abort_unless($u, 401);
        abort_unless(in_array(DB::table('profiles')->where('id', $u->id)->value('role'), ['admin', 'super_admin', 'bir'], true), 403);

        return $u;
    }

    public function exportDownload(Request $request, string $id)
    {
        return app(\App\Services\TransactionPdfQueue::class)->download($id, $this->actor($request));
    }

    protected function ledger()
    {
        $q = DB::query()->fromSub(app(IssuedDocumentLedger::class)->query(), 'd');
        $hasEis = Schema::hasTable('eis_transmissions') && Schema::hasTable('eis_invoice_payloads') && Schema::hasTable('eis_acknowledgements') && DB::table('eis_transmissions')->exists();
        if ($hasEis) {
            $invoiceLink = Schema::hasColumn('eis_invoice_payloads', 'source_document_id') ? " OR (d.document_source='invoice' AND ep.source_document_type='invoice' AND ep.source_document_id=d.document_id)" : '';
            $acceptedSort = config('bir_compliance.transport_implemented') && Schema::hasColumn('eis_acknowledgements', 'verified_at')
                ? "CASE WHEN et.status IN ('acknowledged','accepted','success') AND EXISTS (SELECT 1 FROM eis_acknowledgements va WHERE va.transmission_id=et.id AND va.verified_at IS NOT NULL AND va.verified_response_hash IS NOT NULL) THEN 0 ELSE 1 END"
                : 'CASE WHEN 1=1 THEN 1 ELSE 1 END';
            $latest = "(SELECT et.id FROM eis_transmissions et JOIN eis_invoice_payloads ep ON ep.id=et.payload_id WHERE ((d.document_source='receipt' AND ep.receipt_id=d.document_id)$invoiceLink) AND et.merchant_id=d.merchant_id ORDER BY CASE WHEN et.environment='production' THEN 0 ELSE 1 END, $acceptedSort,et.created_at DESC,et.id DESC LIMIT 1)";
            $q->leftJoin('eis_transmissions as e', fn ($j) => $j->on('e.id', '=', DB::raw($latest)));
            $q->leftJoin('eis_acknowledgements as a', fn ($j) => $j->on('a.id', '=', DB::raw('(SELECT ea.id FROM eis_acknowledgements ea WHERE ea.transmission_id=e.id ORDER BY ea.received_at DESC,ea.id DESC LIMIT 1)')));
            $verified = config('bir_compliance.transport_implemented') && Schema::hasColumn('eis_acknowledgements', 'verified_at') ? 'a.verified_at IS NOT NULL AND a.verified_response_hash IS NOT NULL' : '0=1';
            $q->selectRaw('d.*')->addSelect(['e.id as transmission_id', 'e.status as transmission_status', 'e.environment', 'e.submitted_at', 'e.acknowledged_at', 'e.due_at', 'e.recovery_due_at', 'e.incident_id', 'e.response_code', 'e.response_message', 'e.attempt_number', 'e.max_attempts', 'e.next_retry_at', 'e.payload_hash', 'a.acknowledgement_number', 'a.bir_reference_number', 'a.received_at as acknowledgement_received_at']);
            $q->selectRaw("CASE WHEN e.id IS NULL THEN 'not_submitted' WHEN e.environment<>'production' THEN 'test' WHEN e.status IN ('queued','pending','processing','retry_scheduled','submitted','transmitting') THEN 'processing' WHEN ($verified) AND e.status IN ('acknowledged','accepted','success') AND NULLIF(a.acknowledgement_number,'') IS NOT NULL AND NULLIF(a.bir_reference_number,'') IS NOT NULL AND a.raw_response IS NOT NULL THEN 'acknowledged' WHEN ($verified) AND e.status IN ('rejected','failed') THEN 'rejected' ELSE 'unverified' END as bir_status");
        } else {
            $q->selectRaw("d.*,NULL as transmission_id,NULL as transmission_status,NULL as environment,NULL as submitted_at,NULL as acknowledged_at,NULL as due_at,NULL as recovery_due_at,NULL as incident_id,NULL as response_code,NULL as response_message,NULL as attempt_number,NULL as max_attempts,NULL as next_retry_at,NULL as payload_hash,NULL as acknowledgement_number,NULL as bir_reference_number,NULL as acknowledgement_received_at,'not_submitted' as bir_status");
        }

        return DB::query()->fromSub($q, 'ledger');
    }

    protected function filtered(Request $r)
    {
        $v = $r->validate(['sale_category' => ['nullable', Rule::in(['all', ...\App\Services\StatutorySales::CATEGORIES])], 'search' => 'nullable|string|max:200', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'status' => ['nullable', Rule::in(['all', 'success', 'failed', 'pending', 'cancelled'])], 'type' => ['nullable', Rule::in(['all', 'invoices', 'receipts', 'notes', 'cancelled'])], 'bir' => ['nullable', Rule::in(['all', 'not_submitted', 'processing', 'acknowledged', 'rejected', 'test', 'unverified'])], 'vat' => ['nullable', Rule::in(['all', 'vatable', 'exempt', 'zero', 'mixed', 'non_vat_document'])],
            'merchant' => 'nullable|string|max:80', 'document' => 'nullable|string|max:60', 'customer' => 'nullable|string|max:200', 'channel' => 'nullable|string|max:80', 'source' => ['nullable', Rule::in(['all','receipt','invoice','transaction'])], 'discount' => ['nullable', Rule::in(['all','any','manual','statutory','none'])], 'identity' => ['nullable', Rule::in(['all','unique','conflict'])], 'date_basis' => ['nullable', Rule::in(['document','received'])], 'format' => ['nullable', Rule::in(['csv','pdf'])], 'ids' => 'nullable|array|max:100', 'ids.*' => 'required|string|max:100|distinct', 'branch' => 'nullable|string|max:200', 'payment' => 'nullable|string|max:60', 'region' => 'nullable|string|max:100', 'rdo' => 'nullable|string|max:20', 'min' => 'nullable|numeric|min:0', 'max' => 'nullable|numeric|min:0', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100', 'sort' => ['nullable', Rule::in(['newest', 'oldest', 'amount_desc', 'amount_asc'])]]);
        $q = DB::query()->fromSub($this->ledger(), 'ledger');
        $dateColumn = ($v['date_basis'] ?? 'document') === 'received' ? 'recorded_at' : 'business_date';
        if (! empty($v['from']) && ! empty($v['to']) && $v['to'] < $v['from']) {
            abort(422, 'End date must be on or after the start date.');
        }
        if (isset($v['min'],$v['max']) && $v['max'] < $v['min']) {
            abort(422, 'Maximum amount must be at least the minimum amount.');
        }
        if (!empty($v['search'])) {
            $term = '%'.str_replace(['!','%','_'], ['!!','!%','!_'], $v['search']).'%';
            $digits = preg_replace('/[^0-9]/', '', $v['search']);
            $q->where(function ($s) use ($term, $digits, $v) {
                foreach (['transaction_ref','receipt_number','business','tin','customer_name','customer_tin'] as $col) $s->orWhereRaw("$col LIKE ? ESCAPE '!'", [$term]);
                if (strlen($digits)>=3 && preg_match('/^[0-9 .-]+$/', $v['search'])) $s->orWhere('tin_digits','like','%'.$digits.'%');
            });
        }
        if (! empty($v['from'])) {
            $q->where($dateColumn, '>=', $dateColumn === 'recorded_at' ? \Carbon\CarbonImmutable::parse($v['from'], 'Asia/Manila')->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s') : $v['from']);
        }if (! empty($v['to'])) {
            $q->where($dateColumn, '<', $dateColumn === 'recorded_at' ? \Carbon\CarbonImmutable::parse($v['to'], 'Asia/Manila')->addDay()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s') : \Carbon\CarbonImmutable::parse($v['to'])->addDay()->toDateString());
        }
        foreach (['merchant' => 'merchant_id', 'document' => 'document_type', 'channel' => 'channel', 'source' => 'document_source', 'sale_category' => 'sale_category', 'status' => 'status', 'bir' => 'bir_status', 'branch' => 'branch', 'payment' => 'payment_method', 'region' => 'region', 'rdo' => 'rdo_code'] as $param => $col) {
            if (! empty($v[$param]) && $v[$param] !== 'all') {
                $q->where($col, $v[$param]);
            }
        }
        if (isset($v['min'])) {
            $q->where('amount', '>=', $v['min']);
        }if (isset($v['max'])) {
            $q->where('amount', '<=', $v['max']);
        }

        if (!empty($v['customer'])) {
            $term = '%'.str_replace(['!','%','_'], ['!!','!%','!_'], $v['customer']).'%';
            $digits = preg_replace('/[^0-9]/', '', $v['customer']);
            $q->where(function($s) use($term,$digits,$v) {
                $s->whereRaw("customer_name LIKE ? ESCAPE '!'",[$term])->orWhereRaw("customer_tin LIKE ? ESCAPE '!'",[$term]);
                if(strlen($digits)>=3 && preg_match('/^[0-9 .-]+$/',$v['customer']))$s->orWhereRaw("REPLACE(REPLACE(COALESCE(customer_tin,''),'-',''),' ','') LIKE ?",['%'.$digits.'%']);
            });
        }
        if (!empty($v['ids'])) $q->whereIn('id', $v['ids']);
        if (($v['identity'] ?? 'all') === 'unique') $q->where('identity_count', 1);
        if (($v['identity'] ?? 'all') === 'conflict') $q->where('identity_count', '>', 1);
        $discount = $v['discount'] ?? 'all';
        if ($discount !== 'all') $q->where(match($discount) { 'manual'=>'manual_discount_amount', 'statutory'=>'statutory_discount_amount', default=>'discount_amount' }, $discount === 'none' ? '=' : '>', 0);
        $vat = $v['vat'] ?? 'all';
        if ($vat === 'mixed') {
            $q->whereRaw('(CASE WHEN vatable_sales>0 THEN 1 ELSE 0 END + CASE WHEN vat_exempt_sales>0 THEN 1 ELSE 0 END + CASE WHEN zero_rated_sales>0 THEN 1 ELSE 0 END + CASE WHEN sspt_sales>0 THEN 1 ELSE 0 END)>1');
        } elseif ($vat === 'non_vat_document') {
            $q->whereIn('document_type', ['non_vat_invoice','non_vat_sales_invoice']);
        } elseif ($vat !== 'all') {
            $q->where(match($vat) { 'exempt'=>'vat_exempt_sales', 'zero'=>'zero_rated_sales', default=>'vatable_sales' }, '>', 0);
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

    protected function reportRow(object $row): array
    {
        $r = (array) $row;
        $r['intake_environment']='live';
        foreach (['amount', 'vat_amount', 'vatable_sales', 'vat_exempt_sales', 'zero_rated_sales', 'sspt_sales', 'payment_amount', 'net_amount', 'discount_amount', 'manual_discount_amount', 'statutory_discount_amount', 'vat_relief_amount'] as $k) {
            $r[$k] = (float) $r[$k];
        }
        foreach (['created_at', 'recorded_at', 'submitted_at', 'acknowledged_at', 'due_at', 'next_retry_at', 'acknowledgement_received_at'] as $k) {
            if (! empty($r[$k])) {
                $r[$k] = Carbon::parse($r[$k], config('app.timezone'))->toIso8601String();
            }
        }
        if (($r['bir_status']??null)==='acknowledged' && !app(\App\Services\SubmissionEvidence::class)->verified($r['transmission_id'])) $r['bir_status']='unverified';
        $r['duplicate_reference'] = (int) $row->identity_count > 1;
        $r['source_record_count'] = (int) $row->identity_count;
        return $r;
    }

    protected function row(object $row): array
    {
        $r = $this->reportRow($row);
        $r['is_issued_document'] = $row->document_source !== 'transaction';
        $r['sales_eligible'] = $r['is_issued_document'] && ! $r['duplicate_reference'] && $row->status === 'success' && in_array($row->document_family, ['invoice', 'credit', 'debit'], true);
        if (in_array($row->document_family, ['credit', 'debit'], true)) {
            $r['sales_eligible'] = $r['sales_eligible'] && ! empty($row->original_invoice_id) && DB::table('business_invoices')->where('id', $row->original_invoice_id)->where('merchant_id', $row->merchant_id)->whereNotIn('status', ['draft', 'cancelled', 'voided'])->whereNotIn('document_type',['credit_memo','debit_memo','credit_note','debit_note','payment_receipt','official_receipt','purchase_invoice','delivery_receipt'])->exists();
        }
        $r['sales_sign'] = $row->document_family === 'credit' ? -1 : 1;
        $r = [...$r, ...app(EisReportingPolicy::class)->forDocument($row)];
        $r['recovery_transmission_target'] = $r['recovery_due_at'] ?? null;
        $r['outage_review_required'] = ! empty($r['incident_id']);

        return $r;
    }

    public function index(Request $r)
    {
        $this->actor($r);
        [$q,$v] = $this->filtered($r);
        $tabs = (clone $q)->selectRaw("COUNT(*) as total, COALESCE(SUM(CASE WHEN category='invoices' THEN 1 ELSE 0 END),0) as invoices, COALESCE(SUM(CASE WHEN category='receipts' THEN 1 ELSE 0 END),0) as receipts, COALESCE(SUM(CASE WHEN category='notes' THEN 1 ELSE 0 END),0) as notes, COALESCE(SUM(CASE WHEN category='cancelled' THEN 1 ELSE 0 END),0) as cancelled,COALESCE(SUM(CASE WHEN status='success' THEN 1 ELSE 0 END),0) as successful,COALESCE(SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END),0) as failed,COALESCE(SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END),0) as pending,COALESCE(SUM(CASE WHEN status='success' AND identity_count=1 AND document_family IN ('invoice','debit') THEN amount WHEN status='success' AND identity_count=1 AND document_family='credit' THEN -ABS(amount) ELSE 0 END),0) as total_amount")->first();
        $q = $this->category($q, $v);
        $stats = ($v['type'] ?? 'all') === 'all' ? (object) array_intersect_key((array)$tabs,array_flip(['total','successful','failed','pending','total_amount'])) : (clone $q)->selectRaw("COUNT(*) as total, COALESCE(SUM(CASE WHEN status='success' THEN 1 ELSE 0 END),0) as successful, COALESCE(SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END),0) as failed, COALESCE(SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END),0) as pending, COALESCE(SUM(CASE WHEN status='success' AND identity_count=1 AND document_family IN ('invoice','debit') THEN amount WHEN status='success' AND identity_count=1 AND document_family='credit' THEN -ABS(amount) ELSE 0 END),0) as total_amount")->first();
        $financial = (clone $q)->selectRaw('COALESCE(SUM(amount),0) as recorded_amount, COALESCE(SUM(vat_amount),0) as vat_amount, COALESCE(SUM(manual_discount_amount),0) as manual_discount_amount, COALESCE(SUM(statutory_discount_amount),0) as statutory_discount_amount, COALESCE(SUM(payment_count),0) as payment_events, COALESCE(SUM(payment_amount),0) as payment_amount, COALESCE(SUM(CASE WHEN identity_count>1 THEN 1 ELSE 0 END),0) as identity_conflicts')->first();
        $stats = (object) [...(array)$stats, ...(array)$financial];
        $sort = $v['sort'] ?? 'newest';
        $q->orderBy(str_starts_with($sort, 'amount') ? 'amount' : (($v['date_basis'] ?? 'document') === 'received' ? 'recorded_at' : 'created_at'), in_array($sort, ['oldest', 'amount_asc']) ? 'asc' : 'desc')->orderBy('id', in_array($sort, ['oldest', 'amount_asc']) ? 'asc' : 'desc');
        $page = $q->paginate($v['per_page'] ?? 10, ['*'], 'page', null, (int)$stats->total);

        return response()->json(['intake_environment'=>'live', 'data' => array_map(fn ($x) => $this->row($x), $page->items()), 'tabs' => $tabs, 'summary' => $stats, 'total' => $page->total(), 'last_page' => $page->lastPage(), 'page' => $page->currentPage(), 'payment_summary' => ['total' => (int) $financial->payment_events, 'successful_amount' => (float) $financial->payment_amount], 'population' => 'canonical_documents', 'generated_at' => now()->toIso8601String(), 'gateway' => ['status' => 'not_verified', 'message' => config('bir_compliance.transport_implemented') ? 'No current gateway health probe is recorded.' : 'Production BIR transport is not configured.'], 'pdf_limit' => \App\Services\TransactionReportExport::PDF_LIMIT, 'options' => ['documents' => ['sales_invoice','vat_invoice','non_vat_invoice','service_invoice','vat_exempt_invoice','zero_rated_invoice','mixed_sales_invoice','official_receipt','payment_receipt','credit_note','credit_memo','debit_note','debit_memo','delivery_receipt','purchase_invoice'], 'channels' => DB::table('merchant_transactions')->whereNotNull('channel')->distinct()->orderBy('channel')->limit(100)->pluck('channel'), 'merchants' => DB::table('merchants')->orderBy('business_name')->limit(200)->get(['id','business_name','tin']), 'branches' => DB::table('merchant_transactions')->whereNotNull('branch')->distinct()->orderBy('branch')->limit(200)->pluck('branch'), 'payments' => DB::table('merchant_transactions')->whereNotNull('payment_method')->distinct()->orderBy('payment_method')->limit(100)->pluck('payment_method'), 'regions' => DB::table('merchant_transactions')->whereNotNull('region')->distinct()->orderBy('region')->limit(100)->pluck('region'), 'rdos' => DB::table('rdo_offices')->where('status', 'active')->orderBy('rdo_code')->get(['rdo_code', 'rdo_name'])]]);
    }

    public function show(Request $r, string $id)
    {
        $this->actor($r);
        $row = DB::query()->fromSub($this->ledger(), 'ledger')->where('id', $id)->first();
        abort_unless($row, 404);

        $invoiceLink = Schema::hasColumn('eis_invoice_payloads', 'source_document_id');
        $history = DB::table('eis_transmissions as t')->join('eis_invoice_payloads as p', 'p.id', '=', 't.payload_id')->where('t.merchant_id', $row->merchant_id)->where(function ($q) use ($row, $invoiceLink) {
            if ($row->document_source === 'receipt') {
                $q->where('p.receipt_id', $row->document_id);
            } elseif ($invoiceLink && $row->document_source === 'invoice') {
                $q->where('p.source_document_type', 'invoice')->where('p.source_document_id', $row->document_id);
            } else {
                $q->whereRaw('1=0');
            }
        })->orderByDesc('t.created_at')->get(['t.id', 't.environment', 't.status', 't.created_at', 't.submitted_at', 't.acknowledged_at', 't.response_code', 't.response_message']);

        return response()->json(['transaction' => [...$this->row($row), 'submission_history' => $history, 'source_records' => app(IssuedDocumentLedger::class)->candidates()->where('merchant_id', $row->merchant_id)->where('tin', $row->tin)->whereRaw("COALESCE(branch_code,'')=?", [$row->branch_code ?? ''])->where('receipt_number', $row->receipt_number)->get(['document_source', 'document_id', 'record_status', 'business_date', 'amount'])], 'solution' => ['name' => config('bir_compliance.solution_name', 'NUERS'), 'version' => config('bir_compliance.solution_version'), 'production_transport' => config('bir_compliance.transport_implemented')]]);
    }

    public function export(Request $r)
    {
        $actor = $this->actor($r);
        [$q, $v] = $this->filtered($r);
        $q = $this->category($q, $v);
        $sort = $v['sort'] ?? 'newest';
        $asc = in_array($sort, ['oldest','amount_asc'], true) ? 'asc' : 'desc';
        $q->orderBy(str_starts_with($sort, 'amount') ? 'amount' : (($v['date_basis'] ?? 'document') === 'received' ? 'recorded_at' : 'created_at'), $asc)->orderBy('id', $asc);
        return app(\App\Services\TransactionReportExport::class)->download($q, $v, $actor, 'live', fn ($row) => $this->reportRow($row));
    }
}
