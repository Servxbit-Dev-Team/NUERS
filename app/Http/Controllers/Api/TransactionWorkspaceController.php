<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EisReportingPolicy;
use App\Services\IssuedDocumentLedger;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class TransactionWorkspaceController extends Controller
{
    private bool $narrowRead = false;
    protected function actor(Request $r): object
    {
        $u = $r->bearerToken() ? app(\App\Services\ApiAccess::class)->user($r) : null;
        abort_unless($u, 401);
        abort_unless(in_array(DB::table('profiles')->where('id', $u->id)->value('role'), ['admin', 'super_admin', 'bir'], true), 403);

        if ($merchant = $this->merchantScope()) {
            abort_unless(in_array(DB::table('profiles')->where('id', $u->id)->value('role'), ['admin', 'super_admin'], true), 403);
            abort_unless(DB::table('merchants')->where('id', $merchant)->exists(), 404);
            abort_if($r->filled('merchant') && $r->query('merchant') !== $merchant, 422, 'The merchant scope is fixed by the selected business.');
        }
        return $u;
    }

    public function exportDownload(Request $request, string $id)
    {
        return app(\App\Services\TransactionPdfQueue::class)->download($id, $this->actor($request));
    }

    protected function merchantScope(): ?string
    {
        $id = request()->route('registryMerchant');
        return is_string($id) ? $id : null;
    }

    public function merchantShow(Request $r, string $registryMerchant, string $id)
    {
        return $this->show($r, $id);
    }

    protected function ledger()
    {
        return app(\App\Services\TransactionTransmissionQuery::class)->enrich(app(IssuedDocumentLedger::class)->query($this->merchantScope()));
    }

    protected function filterableLedger()
    {
        if ($this->narrowRead) {
            // No transmission hydration is needed unless a BIR filter was requested.
            if (!$this->merchantScope()) {
                return DB::table('ledger_documents as ledger')->where('identity_rank', 1)->where('is_draft', false);
            }
            return DB::query()->fromSub(app(\App\Services\LedgerProjection::class)->query($this->merchantScope(), false), 'ledger');
        }
        return DB::query()->fromSub($this->ledger(), 'ledger');
    }

    protected function filtered(Request $r)
    {
        $v = $r->validate(['sale_category' => ['nullable', Rule::in(['all', ...\App\Services\StatutorySales::CATEGORIES])], 'search' => 'nullable|string|max:200', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'status' => ['nullable', Rule::in(['all', 'success', 'failed', 'pending', 'cancelled'])], 'type' => ['nullable', Rule::in(['all', 'invoices', 'receipts', 'notes', 'cancelled'])], 'bir' => ['nullable', Rule::in(['all', 'not_submitted', 'processing', 'acknowledged', 'rejected', 'test', 'unverified'])], 'vat' => ['nullable', Rule::in(['all', 'vatable', 'exempt', 'zero', 'mixed', 'non_vat_document'])],
            'merchant' => 'nullable|string|max:80', 'document' => 'nullable|string|max:60', 'customer' => 'nullable|string|max:200', 'channel' => 'nullable|string|max:80', 'source' => ['nullable', Rule::in(['all','receipt','invoice','transaction'])], 'discount' => ['nullable', Rule::in(['all','any','manual','statutory','none'])], 'identity' => ['nullable', Rule::in(['all','unique','conflict'])], 'date_basis' => ['nullable', Rule::in(['document','received'])], 'format' => ['nullable', Rule::in(['csv','pdf'])], 'ids' => 'nullable|array|max:100', 'ids.*' => 'required|string|max:100|distinct', 'branch' => 'nullable|string|max:200', 'payment' => 'nullable|string|max:60', 'region' => 'nullable|string|max:100', 'rdo' => 'nullable|string|max:20', 'min' => 'nullable|numeric|min:0', 'max' => 'nullable|numeric|min:0', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100', 'sort' => ['nullable', Rule::in(['newest', 'oldest', 'amount_desc', 'amount_asc'])]]);
        if ($this->merchantScope()) {
            $v['merchant'] = $this->merchantScope();
        }
        $q = $this->filterableLedger();
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
                foreach (['transaction_ref','receipt_number','business','tin','customer_name','customer_tin'] as $col) {
                    $s->orWhereRaw("$col LIKE ? ESCAPE '!'", [$term]);
                }
                if (strlen($digits) >= 3 && preg_match('/^[0-9 .-]+$/', $v['search'])) {
                    $s->orWhere('tin_digits', 'like', '%'.$digits.'%');
                }
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
            $q->where(function ($s) use ($term, $digits, $v) {
                $s->whereRaw("customer_name LIKE ? ESCAPE '!'", [$term])->orWhereRaw("customer_tin LIKE ? ESCAPE '!'", [$term]);
                if (strlen($digits) >= 3 && preg_match('/^[0-9 .-]+$/', $v['customer'])) {
                    $s->orWhereRaw("REPLACE(REPLACE(COALESCE(customer_tin,''),'-',''),' ','') LIKE ?", ['%'.$digits.'%']);
                }
            });
        }
        if (!empty($v['ids'])) {
            $q->whereIn('id', $v['ids']);
        }
        if (($v['identity'] ?? 'all') === 'unique') {
            $q->where('identity_count', 1);
        }
        if (($v['identity'] ?? 'all') === 'conflict') {
            $q->where('identity_count', '>', 1);
        }
        $discount = $v['discount'] ?? 'all';
        if ($discount !== 'all') {
            $q->where(match($discount) {
                'manual' => 'manual_discount_amount', 'statutory' => 'statutory_discount_amount', default => 'discount_amount'
            }, $discount === 'none' ? '=' : '>', 0);
        }
        $vat = $v['vat'] ?? 'all';
        if ($vat === 'mixed') {
            $q->whereRaw('(CASE WHEN vatable_sales>0 THEN 1 ELSE 0 END + CASE WHEN vat_exempt_sales>0 THEN 1 ELSE 0 END + CASE WHEN zero_rated_sales>0 THEN 1 ELSE 0 END + CASE WHEN sspt_sales>0 THEN 1 ELSE 0 END)>1');
        } elseif ($vat === 'non_vat_document') {
            $q->whereIn('document_type', ['non_vat_invoice','non_vat_sales_invoice']);
        } elseif ($vat !== 'all') {
            $q->where(match($vat) {
                'exempt' => 'vat_exempt_sales', 'zero' => 'zero_rated_sales', default => 'vatable_sales'
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

    protected function reportRow(object $row): array
    {
        $r = (array) $row;
        $r['intake_environment'] = 'live';
        foreach (['amount', 'vat_amount', 'vatable_sales', 'vat_exempt_sales', 'zero_rated_sales', 'sspt_sales', 'payment_amount', 'net_amount', 'discount_amount', 'manual_discount_amount', 'statutory_discount_amount', 'vat_relief_amount'] as $k) {
            $r[$k] = (float) $r[$k];
        }
        foreach (['created_at', 'recorded_at', 'submitted_at', 'acknowledged_at', 'due_at', 'next_retry_at', 'acknowledgement_received_at'] as $k) {
            if (! empty($r[$k])) {
                $r[$k] = Carbon::parse($r[$k], config('app.timezone'))->toIso8601String();
            }
        }
        if (($r['bir_status'] ?? null) === 'acknowledged' && !app(\App\Services\SubmissionEvidence::class)->verified($r['transmission_id'])) {
            $r['bir_status'] = 'unverified';
        }
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
            $r['sales_eligible'] = $r['sales_eligible'] && ! empty($row->original_invoice_id) && DB::table('business_invoices')->where('id', $row->original_invoice_id)->where('merchant_id', $row->merchant_id)->whereNotIn('status', ['draft', 'cancelled', 'voided'])->whereNotIn('document_type', ['credit_memo','debit_memo','credit_note','debit_note','payment_receipt','official_receipt','purchase_invoice','delivery_receipt'])->exists();
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
        if (app(\App\Services\LedgerProjection::class)->ready()) {
            app(\App\Services\LedgerProjection::class)->catchUp();
        }
        return DB::transaction(fn () => $this->readIndex($r));
    }

    private function readIndex(Request $r)
    {
        $indexed = app(\App\Services\LedgerProjection::class)->ready();
        if ($indexed) {
            app(\App\Services\LedgerProjection::class)->catchUp();
        }
        $this->narrowRead = $indexed && Schema::hasColumn('ledger_documents', 'is_draft') && in_array($r->input('bir', 'all'), ['', 'all'], true);
        [$q,$v] = $this->filtered($r);
        $aggregateFilters = array_diff_key($v, array_flip(['page', 'per_page', 'sort', 'format']));
        $calculate = function () use ($q, $v) {
            $aggregate = clone $q;
            if ($this->narrowRead && !$this->merchantScope() && DB::getDriverName() === 'mysql'
                && !array_diff(array_keys(array_filter($v, fn ($x) => $x !== null && $x !== '' && $x !== 'all')), ['page','per_page','sort','type','status','identity','format'])) {
                $aggregate->forceIndex('ledger_transaction_summary');
            }
            $counts = "COUNT(*) as total, COALESCE(SUM(CASE WHEN status='success' THEN 1 ELSE 0 END),0) as successful, COALESCE(SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END),0) as failed, COALESCE(SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END),0) as pending, COALESCE(SUM(CASE WHEN status='success' AND identity_count=1 AND document_family IN ('invoice','debit') THEN amount WHEN status='success' AND identity_count=1 AND document_family='credit' THEN -ABS(amount) ELSE 0 END),0) as total_amount";
            $money = 'COALESCE(SUM(amount),0) as recorded_amount, COALESCE(SUM(vat_amount),0) as vat_amount, COALESCE(SUM(manual_discount_amount),0) as manual_discount_amount, COALESCE(SUM(statutory_discount_amount),0) as statutory_discount_amount, COALESCE(SUM(payment_count),0) as payment_events, COALESCE(SUM(payment_amount),0) as payment_amount, COALESCE(SUM(CASE WHEN identity_count>1 THEN 1 ELSE 0 END),0) as identity_conflicts';
            $all = (clone $aggregate)->selectRaw($counts.','.$money.", COALESCE(SUM(CASE WHEN category='invoices' THEN 1 ELSE 0 END),0) as invoices, COALESCE(SUM(CASE WHEN category='receipts' THEN 1 ELSE 0 END),0) as receipts, COALESCE(SUM(CASE WHEN category='notes' THEN 1 ELSE 0 END),0) as notes, COALESCE(SUM(CASE WHEN category='cancelled' THEN 1 ELSE 0 END),0) as cancelled")->first();
            $tabs = (object) array_intersect_key((array)$all, array_flip(['total','invoices','receipts','notes','cancelled','successful','failed','pending','total_amount']));
            $stats = ($v['type'] ?? 'all') === 'all' ? $all : $this->category(clone $aggregate, $v)->selectRaw($counts.','.$money)->first();
            $financial = (object) array_intersect_key((array)$stats, array_flip(['recorded_amount','vat_amount','manual_discount_amount','statutory_discount_amount','payment_events','payment_amount','identity_conflicts']));
            return compact('tabs', 'stats', 'financial');
        };
        $aggregates = $indexed ? app(\App\Services\ReportingSnapshotCache::class)->remember('transaction-workspace-v3', $this->merchantScope() ?? 'authorized-admins', $aggregateFilters, $calculate) : $calculate();
        ['tabs' => $tabs, 'stats' => $stats, 'financial' => $financial] = $aggregates;
        $q = $this->category($q, $v);
        $sort = $v['sort'] ?? 'newest';
        $column = str_starts_with($sort, 'amount') ? 'amount' : (($v['date_basis'] ?? 'document') === 'received' ? 'recorded_at' : 'created_at');
        $direction = in_array($sort, ['oldest', 'amount_asc']) ? 'asc' : 'desc';
        $selection = app(\App\Services\ReportPagination::class)->page($q, (int) $stats->total, (int) ($v['page'] ?? 1), (int) ($v['per_page'] ?? 10), $column, $direction);
        $this->narrowRead = false;
        $rows = $selection['ids']->isEmpty() ? collect() : $this->filterableLedger()->whereIn('id', $selection['ids'])->get()->keyBy('id');
        $items = $selection['ids']->filter(fn ($id) => $rows->has($id))->map(fn ($id) => $this->row($rows->get($id)))->values()->all();

        $optionTransactions = fn () => DB::table('merchant_transactions')->when($this->merchantScope(), fn ($q, $id) => $q->where('merchant_id', $id));
        $optionLoader = fn () => ['documents' => ['sales_invoice','vat_invoice','non_vat_invoice','service_invoice','vat_exempt_invoice','zero_rated_invoice','mixed_sales_invoice','official_receipt','payment_receipt','credit_note','credit_memo','debit_note','debit_memo','delivery_receipt','purchase_invoice'], 'channels' => $optionTransactions()->whereNotNull('channel')->distinct()->orderBy('channel')->limit(100)->pluck('channel'), 'merchants' => DB::table('merchants')->when($this->merchantScope(), fn ($q, $id) => $q->where('id', $id))->orderBy('business_name')->limit(200)->get(['id','business_name','tin']), 'branches' => $optionTransactions()->whereNotNull('branch')->distinct()->orderBy('branch')->limit(200)->pluck('branch'), 'payments' => $optionTransactions()->whereNotNull('payment_method')->distinct()->orderBy('payment_method')->limit(100)->pluck('payment_method'), 'regions' => $optionTransactions()->whereNotNull('region')->distinct()->orderBy('region')->limit(100)->pluck('region'), 'rdos' => DB::table('rdo_offices')->where('status', 'active')->orderBy('rdo_code')->get(['rdo_code', 'rdo_name'])];
        $options = $indexed ? app(\App\Services\ReportingSnapshotCache::class)->remember('transaction-options', $this->merchantScope() ?? 'authorized-admins', ['operations_revision' => app(\App\Services\DashboardRevision::class)->token('transaction-options')], $optionLoader) : $optionLoader();
        return response()->json(['intake_environment' => 'live', 'data' => $items, 'tabs' => $tabs, 'summary' => $stats, 'total' => $selection['total'], 'last_page' => $selection['last_page'], 'page' => $selection['page'], 'payment_summary' => ['total' => (int) $financial->payment_events, 'successful_amount' => (float) $financial->payment_amount], 'population' => 'canonical_documents', 'generated_at' => now()->toIso8601String(), 'gateway' => ['status' => 'not_verified', 'message' => config('bir_compliance.transport_implemented') ? 'No current gateway health probe is recorded.' : 'Production BIR transport is not configured.'], 'pdf_limit' => \App\Services\TransactionReportExport::PDF_LIMIT, 'options' => $options]);
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

        return response()->json(['transaction' => [...$this->row($row), 'submission_history' => $history, 'line_items' => $this->lineItems($row), 'source_records' => app(IssuedDocumentLedger::class)->candidates($row->merchant_id)->where('merchant_id', $row->merchant_id)->where('tin', $row->tin)->whereRaw("COALESCE(branch_code,'')=?", [$row->branch_code ?? ''])->where('receipt_number', $row->receipt_number)->get(['document_source', 'document_id', 'record_status', 'business_date', 'amount'])], 'solution' => ['name' => config('bir_compliance.solution_name', 'NUERS'), 'version' => config('bir_compliance.solution_version'), 'production_transport' => config('bir_compliance.transport_implemented')]]);
    }

    protected function lineItems(object $row): array
    {
        $columns = ['line_number','item_code','description','unit','quantity','unit_price','discount_pct','discount_amount','taxable_amount','vat_rate','vat_amount','line_total','tax_type'];
        if ($row->document_source === 'invoice') {
            return DB::table('business_invoice_items')->where('merchant_id', $row->merchant_id)->where('invoice_id', $row->document_id)->orderBy('line_number')->get($columns)->map(fn ($item) => (array) $item)->all();
        }
        if ($row->document_source !== 'receipt') {
            return [];
        }
        $items = json_decode(DB::table('transaction_receipts')->where('merchant_id', $row->merchant_id)->where('id', $row->document_id)->value('items') ?? '[]', true);
        if (!is_array($items)) {
            return [];
        }
        // Explicit fields keep private identifiers and integration secrets out of the detail view.
        $allowed = array_flip([...$columns, 'name','item_name','qty','price','amount','total','tax_classification','sale_category']);
        return array_values(array_map(fn ($item) => array_intersect_key($item, $allowed), array_filter($items, 'is_array')));
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
