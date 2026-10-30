<?php

namespace App\Http\Controllers\Api;

use App\Services\BirCompliance;
use App\Services\EisDocumentReadiness;
use App\Services\SellerIdentity;
use App\Services\LedgerProjection;
use App\Services\OperationsSalesQuery;
use App\Services\ReportingSnapshotCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class OperationsReportController extends TransactionWorkspaceController
{
    private bool $indexedReconciliationRead = false;
    private bool $reconciliationAggregateRead = false;
    private bool $indexedSalesRead = false;
    private bool $salesAggregateRead = false;
    private array $salesAggregateFilters = [];

    protected function ledger()
    {
        if (!$this->indexedSalesRead) {
            return parent::ledger();
        }
        // All sales aggregates require normalized status=success; drafts normalize
        // to pending. Avoid the wide record_status column in covering index reads.
        $q = app(LedgerProjection::class)->query(null, true, true);
        return app(\App\Services\TransactionTransmissionQuery::class)->enrich($q);
    }

    protected function filterableLedger()
    {
        if ($this->indexedReconciliationRead && in_array(request()->input('bir', 'all'), ['', 'all'], true)) {
            $q = DB::table('ledger_documents as ledger')->where('identity_rank', 1)->where('is_draft', false);
            if ($this->reconciliationAggregateRead) {
                $columns = ['id','reconciliation_prefix','reconciliation_suffix','identity_count','document_family','amount','vat_amount','vatable_sales','created_at','business'];
                $groupColumn = ['type' => 'document_type','payment' => 'payment_method','branch' => 'branch'][request()->input('group')] ?? null;
                if ($groupColumn) {
                    $columns[] = $groupColumn;
                }
                $q->select($columns);
                if (DB::getDriverName() === 'mysql' && !request()->filled('search') && !request()->filled('customer') && !$groupColumn) {
                    $q->forceIndex('ledger_reconciliation_summary');
                }
            }
            return $q;
        }
        if (!$this->salesAggregateRead || !in_array($this->salesAggregateFilters['bir'] ?? 'all', ['', 'all'], true)) {
            return parent::filterableLedger();
        }
        // Aggregate directly on the validated projection. Nested SELECT * views
        // prevent covering-index reads on MySQL; no source payload is needed here.
        $q = DB::table('ledger_documents as ledger')->where('identity_rank', 1);
        $f = $this->salesAggregateFilters;
        if (DB::getDriverName() === 'mysql' && empty($f['search']) && empty($f['customer']) && empty($f['ids'])) {
            $q->forceIndex('ledger_sales_summary');
        }
        return $q;
    }

    protected function records(Request $r): array
    {
        [$q,$v] = $this->filtered($r);
        $q = $this->category($q, $v);
        if ($r->has('report_limit')) {
            \App\Services\ReportCsv::limit($r, (clone $q)->count());
        }
        abort_if((clone $q)->limit(1001)->get(['id'])->count() > 1000, 503, 'This report is awaiting its reporting index. Please retry shortly or narrow the dates.');
        $rows = (clone $q)->orderByDesc('created_at')->orderBy('id')->get()->map(fn ($x) => $this->row($x));
        $payloads = DB::table('eis_transmissions as t')->join('eis_invoice_payloads as p', 'p.id', '=', 't.payload_id')->whereIn('t.id', $rows->pluck('transmission_id')->filter())->get(['t.id', 'p.payload', 'p.payload_hash'])->keyBy('id');

        return $rows->map(function ($x) use ($payloads) {
            $x['source_financial_checks_passed'] = false;
            if ($x['document_source'] !== 'transaction') {
                $x['source_financial_checks_passed'] = app(EisDocumentReadiness::class)->inspect($x['document_source'], $x['document_id'])['financial_validation_passed'];
            }
            $x['payload_comparison'] = 'No readable linked payload';
            $p = $payloads->get($x['transmission_id']);
            if ($p) {
                try {
                    $body = json_decode($p->payload, true, 512, JSON_THROW_ON_ERROR);
                    $raw = isset($body['encrypted']) ? Crypt::decryptString($body['encrypted']) : $p->payload;
                    $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                    $x['payload_hash_verified'] = hash_equals((string) $p->payload_hash, hash('sha256', $raw));
                    $total = $body['total_due'] ?? $body['gross_amount'] ?? $body['total_amount'] ?? data_get($body, 'totals.total_due');
                    $tin = $body['seller_tin'] ?? data_get($body, 'seller.tin') ?? $body['merchant_tin'] ?? null;
                    $x['payload_amount'] = $total === null ? null : (float) $total;
                    $x['payload_tin'] = $tin;
                    $x['payload_document_type'] = $body['document_type'] ?? null;
                    $x['payload_comparison'] = ($total !== null && $tin && $x['payload_hash_verified']) ? 'Comparable' : 'Incomplete payload evidence';
                } catch (\Throwable $e) {
                    $x['payload_hash_verified'] = false;
                    $x['payload_comparison'] = 'Payload could not be verified';
                }
            }

            return $x;
        })->all();
    }

    protected function exception(array $x): string
    {
        if ($x['amount'] < 0) {
            return 'Invalid Amount';
        }
        if (in_array($x['transmission_status'], ['rejected', 'failed'])) {
            return 'Transmission Rejected';
        }
        if ($x['status'] === 'failed') {
            return 'Failed Transaction';
        }
        if ($x['status'] === 'cancelled') {
            return 'Cancelled';
        }
        if (! $x['is_issued_document']) {
            return 'Missing Receipt';
        }
        if (! $x['tin']) {
            return 'Missing TIN';
        }
        if (! app(SellerIdentity::class)->parse($x['tin'], $x['branch_code'])['valid']) {
            return 'Invalid TIN';
        }
        if (! $x['source_financial_checks_passed']) {
            return 'Source Financial Mismatch';
        }
        if (array_key_exists('payload_hash_verified', $x) && ! $x['payload_hash_verified']) {
            return 'Payload Integrity';
        }
        if (in_array($x['document_family'], ['credit', 'debit'], true) && ! $x['sales_eligible']) {
            return 'Adjustment Reference Unverified';
        }
        if ($x['duplicate_reference']) {
            return 'Duplicate Receipt Reference';
        }
        if (isset($x['payload_amount']) && abs($x['payload_amount'] - $x['amount']) > 0.005) {
            return 'Amount Mismatch';
        }
        if (! empty($x['payload_tin']) && preg_replace('/\D/', '', $x['payload_tin']) !== preg_replace('/\D/', '', $x['tin'])) {
            return 'TIN Mismatch';
        }
        if (! empty($x['payload_document_type']) && $x['payload_document_type'] !== $x['document_type']) {
            return 'Document Type Mismatch';
        }
        if ($x['bir_status'] === 'acknowledged' && $x['payload_comparison'] === 'Comparable') {
            return 'Matched';
        }

        return 'For Review';
    }

    public function index(Request $r, bool $export = false)
    {
        $this->indexedReconciliationRead = false;
        $this->indexedSalesRead = false;
        $this->reconciliationAggregateRead = false;
        $this->salesAggregateRead = false;
        $this->salesAggregateFilters = [];
        $this->actor($r);
        $r->validate(['mode' => ['required', Rule::in(['sales', 'reconciliation'])], 'group' => ['nullable', Rule::in(['daily', 'monthly', 'business', 'type', 'payment', 'branch'])], 'review' => ['nullable', Rule::in(['all', 'matched', 'exceptions', 'review', 'resolved'])], 'exception' => 'nullable|string|max:80', 'review_status' => ['nullable', Rule::in(['all', 'open', 'in_progress', 'resolved', 'ignored'])]]);
        $mode = $r->input('mode');
        if ($mode === 'sales' && app(LedgerProjection::class)->ready() && Schema::hasColumn('ledger_documents', 'operations_sales_valid')) {
            return $this->indexedSales($r, $export);
        }
        if ($mode === 'reconciliation' && app(LedgerProjection::class)->ready() && Schema::hasColumn('ledger_documents', 'reconciliation_prefix')) {
            return $this->indexedReconciliation($r, $export);
        }
        $rows = $this->records($r);
        if ($mode === 'sales') {
            $rows = array_values(array_filter($rows, fn ($x) => $x['status'] === 'success'));
        }
        $reviews = $mode === 'reconciliation' ? DB::table('reconciliation_reviews')->get()->keyBy('transaction_id') : collect();
        foreach ($rows as &$x) {
            $review = $reviews->get($x['id']);
            $x['exception'] = $this->exception($x);
            $x['sales_eligible'] = $x['sales_eligible'] && $x['business_date'] && in_array($x['exception'], ['For Review', 'Matched'], true);
            $x['document_amount'] = $x['amount'];
            if ($mode === 'sales' && $x['document_family'] === 'credit') {
                foreach (['amount', 'vat_amount', 'vatable_sales', 'vat_exempt_sales', 'zero_rated_sales'] as $key) {
                    $x[$key] = -abs((float) $x[$key]);
                }
            }
            $x['review_status'] = $review?->status ?? ($x['exception'] === 'Matched' ? 'matched' : 'open');
            $x['review_revision'] = $review?->revision ?? 0;
            $x['review_reason'] = $review?->reason;
            $x['owner_id'] = $review?->owner_id;
            $x['priority'] = $review?->priority;
            $x['due_at'] = $review?->due_at;
            $x['closure_evidence'] = $review?->closure_evidence;
            $x['reviewed_at'] = $review?->updated_at;
        }
        unset($x);
        $excluded = $mode === 'sales' ? collect($rows)->where('sales_eligible', false) : collect();
        if ($mode === 'sales') {
            $rows = array_values(array_filter($rows, fn ($x) => $x['sales_eligible']));
        }
        $all = collect($rows);
        $summary = ['total' => $all->count(), 'sales' => round($all->sum('amount'), 2), 'vat' => round($all->sum('vat_amount'), 2), 'taxable' => round($all->sum('vatable_sales'), 2), 'average' => round($all->avg('amount') ?? 0, 2), 'matched' => $all->where('exception', 'Matched')->count(), 'exceptions' => $all->whereNotIn('exception', ['Matched', 'For Review', 'Cancelled'])->count(), 'review' => $all->where('exception', 'For Review')->count(), 'resolved' => $all->where('review_status', 'resolved')->count(), 'reconciled_today' => $all->filter(fn ($x) => $x['review_status'] === 'resolved' && str_starts_with($x['reviewed_at'] ?? '', now()->toDateString()))->count()];
        $group = $r->input('group', 'daily');
        $key = match ($group) {
            'business' => 'business','type' => 'document_type','payment' => 'payment_method','branch' => 'branch',default => 'created_at'
        };
        $groups = $all->groupBy(fn ($x) => in_array($group, ['daily', 'monthly']) ? substr($x['created_at'], 0, $group === 'daily' ? 10 : 7) : ($x[$key] ?: 'Unspecified'))->map(fn ($g, $k) => ['label' => (string) $k, 'count' => $g->count(), 'amount' => round($g->sum('amount'), 2), 'vat' => round($g->sum('vat_amount'), 2), 'taxable' => round($g->sum('vatable_sales'), 2)])->values()->all();
        $trend = $all->groupBy(fn ($x) => substr($x['created_at'], 0, 10))->sortKeys()->map(fn ($g, $k) => ['date' => $k, 'amount' => $g->sum('amount'), 'matched' => $g->where('exception', 'Matched')->count(), 'exceptions' => $g->whereNotIn('exception', ['Matched', 'For Review', 'Cancelled'])->count(), 'review' => $g->where('exception', 'For Review')->count()])->values()->take(-7)->values();
        $breakdown = $all->groupBy($mode === 'sales' ? 'document_type' : 'exception')->map(fn ($g, $k) => ['name' => $k, 'value' => $mode === 'sales' ? round($g->sum('amount'), 2) : $g->count()])->values();
        $statuses = $all->groupBy('review_status')->map(fn ($g, $k) => ['name' => $k, 'value' => $g->count()])->values();
        $recent = $all->where('review_status', 'resolved')->sortByDesc('reviewed_at')->take(5)->values();
        $rows = $all->filter(function ($x) use ($r, $mode) {
            if ($mode !== 'reconciliation') {
                return true;
            }$review = $r->input('review', 'all');
            if ($review === 'matched' && $x['exception'] !== 'Matched') {
                return false;
            }if ($review === 'exceptions' && in_array($x['exception'], ['Matched', 'For Review', 'Cancelled'])) {
                return false;
            }if ($review === 'review' && $x['exception'] !== 'For Review') {
                return false;
            }if ($review === 'resolved' && $x['review_status'] !== 'resolved') {
                return false;
            }if ($r->filled('exception') && $r->input('exception') !== 'all' && $x['exception'] !== $r->input('exception')) {
                return false;
            }if ($r->filled('review_status') && $r->input('review_status') !== 'all' && $x['review_status'] !== $r->input('review_status')) {
                return false;
            }

            return true;
        })->values();
        if ($r->input('sort') === 'oldest') {
            $rows = $rows->sortBy('created_at')->values();
        }
        if ($r->input('sort') === 'amount_desc') {
            $rows = $rows->sortByDesc('amount')->values();
        }
        if ($r->input('sort') === 'amount_asc') {
            $rows = $rows->sortBy('amount')->values();
        }
        if ($export) {
            $u = $this->actor($r);
            app(BirCompliance::class)->audit('operations.report.exported', $mode, (string) $u->id, ['filters' => $r->query(), 'records' => $rows->count()]);

            return response()->streamDownload(function () use ($rows, $mode) {
                $f = fopen('php://output', 'w');
                fwrite($f, "\xEF\xBB\xBF");
                $keys = ['transaction_ref', 'receipt_number', 'business', 'tin', 'created_at', 'document_type', 'amount', 'vat_amount', 'vatable_sales', 'vat_exempt_sales', 'zero_rated_sales', 'payment_method', 'branch', 'region', 'rdo_code', 'status', 'bir_status', 'sale_category', 'manual_discount_amount', 'statutory_discount_amount', 'vat_relief_amount', 'discount_amount'];
                if ($mode === 'reconciliation') {
                    $keys = array_merge($keys, ['exception', 'review_status', 'review_reason', 'reviewed_at']);
                }fputcsv($f, $keys);
                foreach ($rows as $row) {
                    fputcsv($f, array_map(function ($key) use ($row) {
                        $v = $row[$key] ?? '';
                        if (is_string($v) && preg_match('/^\s*[=+@\-]/', $v)) {
                            $v = "'".$v;
                        }

                        return $v;
                    }, $keys));
                }fclose($f);
            }, 'NUERS-'.$mode.'-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
        }
        $size = (int) $r->input('per_page', 10);
        $page = (int) $r->input('page', 1);

        return response()->json(['data' => $rows->slice(($page - 1) * $size, $size)->values(), 'total' => $rows->count(), 'last_page' => max(1, (int) ceil($rows->count() / $size)), 'summary' => [...$summary, 'excluded_count' => $excluded->count(), 'excluded_amount' => round($excluded->sum('document_amount'), 2)], 'reporting_state' => 'internal_draft_not_bir_submission', 'groups' => $groups, 'trend' => $trend, 'breakdown' => $breakdown, 'statuses' => $statuses, 'recent' => $recent, 'top_businesses' => $all->groupBy('business')->map(fn ($g, $k) => ['label' => $k, 'amount' => round($g->sum('amount'), 2)])->sortByDesc('amount')->take(5)->values(), 'options' => ['reviewers' => DB::table('profiles')->whereIn('role', ['admin', 'super_admin'])->get(['id', 'full_name']), 'payments' => DB::table('merchant_transactions')->distinct()->pluck('payment_method'), 'regions' => DB::table('merchant_transactions')->distinct()->pluck('region'), 'rdos' => DB::table('rdo_offices')->where('status', 'active')->get(['rdo_code', 'rdo_name']), 'branches' => DB::table('merchant_transactions')->whereNotNull('branch')->distinct()->pluck('branch')], 'generated_at' => now()->toIso8601String(), 'notice' => 'Internal draft only; not an official BIR SLS file. Duplicate/conflicting and supplementary records are excluded from sales totals. NUERS ledger review. BIR matches require a production acknowledgment with recorded evidence. Review resolution does not alter the invoice, payment, or BIR acceptance.']);
    }

    public function export(Request $r)
    {
        return $this->index($r, true);
    }

    protected function indexedSales(Request $r, bool $export)
    {
        $r->validate(['group_page' => 'nullable|integer|min:1']);
        $this->indexedSalesRead = true;
        [$q, $v] = $this->filtered($r); // Includes authorization-independent validation and index catch-up.
        abort_if(DB::table('ledger_documents')->whereNull('operations_sales_valid')->exists(), 503, 'Sales evidence is being indexed. Please retry shortly.');
        $q = $this->category($q, $v);
        $service = app(OperationsSalesQuery::class);
        $q = $service->narrow($q, $v);
        $this->salesAggregateFilters = $r->query();
        $this->salesAggregateRead = true;
        [$aggregateQ] = $this->filtered($r);
        $this->salesAggregateRead = false;
        $aggregateQ = $service->narrow($this->category($aggregateQ, $v), $v);
        $group = $r->input('group', 'daily');
        if ($export) {
            $included = $service->included($q);
            $count = (clone $included)->count();
            \App\Services\ReportCsv::limit($r, $count);
            app(BirCompliance::class)->audit('operations.report.exported', 'sales', (string) $this->actor($r)->id, ['filters' => $r->query(), 'records' => $count]);
            return response()->streamDownload(function () use ($service, $included, $v) {
                $f = fopen('php://output', 'w');
                fwrite($f, "\xEF\xBB\xBF");
                $keys = ['transaction_ref','receipt_number','business','tin','created_at','document_type','amount','vat_amount','vatable_sales','vat_exempt_sales','zero_rated_sales','payment_method','branch','region','rdo_code','status','bir_status','sale_category','manual_discount_amount','statutory_discount_amount','vat_relief_amount','discount_amount'];
                fputcsv($f, $keys, ',', '"', '');
                foreach ($service->sort(clone $included, $v)->lazy(500) as $row) {
                    $data = $this->salesRow($row);
                    fputcsv($f, array_map(function ($key) use ($data) {
                        $value = $data[$key] ?? '';
                        return is_string($value) && preg_match('/^\s*[=+@\-]/', $value) ? "'".$value : $value;
                    }, $keys), ',', '"', '');
                }
                fclose($f);
            }, 'NUERS-sales-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
        }
        return DB::transaction(function () use ($q, $aggregateQ, $v, $r, $service, $group) {
            $result = $service->summary($aggregateQ, $v, $group, $r->integer('group_page', 1));
            $page = $service->page($q, $v, $result['summary']['total']);
            $options = app(ReportingSnapshotCache::class)->remember('operations-sales-options-v2', 'authorized-administrators', [], fn () => [
                'payments' => DB::table('merchant_transactions')->whereNotNull('payment_method')->distinct()->orderBy('payment_method')->limit(100)->pluck('payment_method'),
                'regions' => DB::table('merchant_transactions')->whereNotNull('region')->distinct()->orderBy('region')->limit(100)->pluck('region'),
                'branches' => DB::table('merchant_transactions')->whereNotNull('branch')->distinct()->orderBy('branch')->limit(200)->pluck('branch'),
                'rdos' => DB::table('rdo_offices')->where('status', 'active')->get(['rdo_code','rdo_name']),
            ]);
            return response()->json([...$result, 'data' => $page['rows']->map(fn ($row) => $this->salesRow($row)),
                'total' => $result['summary']['total'], 'page' => $page['page'], 'last_page' => $page['last_page'], 'options' => $options,
                'query_mode' => 'indexed_sales', 'reporting_state' => 'internal_draft_not_bir_submission',
                'generated_at' => now()->toIso8601String(), 'notice' => 'Internal sales draft. Validated source invoices and linked adjustments are counted once. Conflicts, invalid source evidence and supplementary documents are excluded. BIR acceptance is separate.']);
        });
    }

    protected function salesRow(object $row): array
    {
        $r = $this->reportRow($row);
        $r['document_amount'] = $r['amount'];
        $r['source_financial_checks_passed'] = (bool) $row->financial_valid;
        $r['sales_eligible'] = true;
        if ($row->document_family === 'credit') {
            foreach (['amount','vat_amount','vatable_sales','vat_exempt_sales','zero_rated_sales'] as $key) {
                $r[$key] = -abs($r[$key]);
            }
        }
        return $r;
    }

    private function indexedReconciliation(Request $r, bool $export)
    {
        app(LedgerProjection::class)->catchUp();
        abort_if(DB::table('ledger_documents')->whereNull('reconciliation_prefix')->exists(), 503, 'Reconciliation evidence is being indexed. Please retry shortly.');
        $this->indexedReconciliationRead = true;
        [$base, $v] = $this->filtered($r);
        $service = app(\App\Services\ReconciliationQuery::class);
        $all = $service->decorate($this->category($base, $v));
        $filters = [...$v, ...$r->only(['review', 'exception', 'review_status', 'group'])];
        $query = $service->filtered($all, $filters);
        $this->reconciliationAggregateRead = true;
        [$aggregateBase] = $this->filtered($r);
        $aggregateAll = $service->decorate($this->category($aggregateBase, $v));
        $this->reconciliationAggregateRead = false;
        $sort = $v['sort'] ?? 'newest';
        $column = str_starts_with($sort, 'amount') ? 'amount' : 'created_at';
        $direction = in_array($sort, ['oldest', 'amount_asc'], true) ? 'asc' : 'desc';
        if ($export) {
            $count = (clone $query)->count();
            \App\Services\ReportCsv::limit($r, $count);
            app(BirCompliance::class)->audit('operations.report.exported', 'reconciliation', (string) $this->actor($r)->id, ['filters' => $r->query(), 'records' => $count]);
            $rows = (function () use ($query, $column, $direction) {
                foreach ((clone $query)->orderBy($column, $direction)->orderBy('id', $direction)->lazy(250)->chunk(250) as $batch) {
                    foreach ($this->reconciliationRows($batch) as $row) {
                        yield array_map(fn ($key) => $row[$key] ?? '', $this->reconciliationColumns());
                    }
                }
            })();
            return \App\Services\ReportCsv::download($this->reconciliationColumns(), $rows, 'NUERS-reconciliation-'.now()->format('Y-m-d-His').'.csv');
        }
        return DB::transaction(function () use ($all, $aggregateAll, $query, $filters, $v, $column, $direction, $service) {
            $result = $service->summary($aggregateAll, $filters);
            $page = app(\App\Services\ReportPagination::class)->page($query, $result['total'], (int) ($v['page'] ?? 1), (int) ($v['per_page'] ?? 10), $column, $direction);
            $cases = $page['ids']->isEmpty() ? collect() : (clone $query)->whereIn('id', $page['ids'])->get()->keyBy('id');
            $rows = $this->reconciliationRows($page['ids']->filter(fn ($id) => $cases->has($id))->map(fn ($id) => $cases->get($id)));
            $recent = [];
            if ($result['summary']['resolved'] > 0) {
                // Seek reviewed IDs before touching the document ledger.
                $recentQuery = (clone $all)->whereIn('id', DB::table('reconciliation_reviews')->where('status', 'resolved')->select('transaction_id'));
                $recent = $this->reconciliationRows($recentQuery->orderByDesc('reviewed_at')->orderByDesc('id')->limit(5)->get());
            }
            return response()->json([...$result, 'data' => $rows, 'page' => $page['page'], 'last_page' => $page['last_page'], 'recent' => $recent,
                'query_mode' => 'indexed_reconciliation', 'reporting_state' => 'internal_draft_not_bir_submission', 'generated_at' => now()->toIso8601String(),
                'options' => $this->operationsOptions(), 'notice' => 'NUERS ledger review. BIR matches require a verified production acknowledgment. Review resolution does not alter the invoice, payment, or BIR acceptance. Group summaries show up to 50 groups.']);
        });
    }

    private function reconciliationRows($cases): array
    {
        if ($cases->isEmpty()) {
            return [];
        }
        $records = DB::query()->fromSub($this->ledger(), 'records')->whereIn('id', $cases->pluck('id'))->get()->keyBy('id');
        $payloads = DB::table('eis_transmissions as t')->join('eis_invoice_payloads as p', 'p.id', '=', 't.payload_id')
            ->whereIn('t.id', $records->pluck('transmission_id')->filter())->get(['t.id', 'p.payload', 'p.payload_hash'])->keyBy('id');
        return $cases->filter(fn ($case) => $records->has($case->id))->map(function ($case) use ($records, $payloads) {
            $record = $records->get($case->id);
            $row = $this->row($record);
            $row += app(\App\Services\ReconciliationEvidence::class)->payload($payloads->get($record->transmission_id));
            foreach (['exception', 'review_status', 'review_revision', 'review_reason', 'owner_id', 'priority', 'closure_evidence', 'reviewed_at'] as $key) {
                $row[$key] = $case->$key;
            }
            $row['due_at'] = $case->review_due_at;
            $row['source_financial_checks_passed'] = (bool) $case->financial_valid;
            $row['document_amount'] = $row['amount'];
            $row['sales_eligible'] = $row['sales_eligible'] && $row['business_date'] && in_array($row['exception'], ['For Review', 'Matched'], true);
            return $row;
        })->values()->all();
    }

    private function operationsOptions(): array
    {
        return app(ReportingSnapshotCache::class)->remember('operations-options-v1', 'authorized-admins', [], fn () => [
            'reviewers' => DB::table('profiles')->whereIn('role', ['admin', 'super_admin'])->orderBy('full_name')->limit(200)->get(['id', 'full_name']),
            'payments' => DB::table('merchant_transactions')->distinct()->orderBy('payment_method')->limit(100)->pluck('payment_method'),
            'regions' => DB::table('merchant_transactions')->distinct()->orderBy('region')->limit(100)->pluck('region'),
            'rdos' => DB::table('rdo_offices')->where('status', 'active')->orderBy('rdo_code')->get(['rdo_code', 'rdo_name']),
            'branches' => DB::table('merchant_transactions')->whereNotNull('branch')->distinct()->orderBy('branch')->limit(200)->pluck('branch'),
        ]);
    }

    private function reconciliationColumns(): array
    {
        return ['transaction_ref','receipt_number','business','tin','created_at','document_type','amount','vat_amount','vatable_sales','vat_exempt_sales','zero_rated_sales','payment_method','branch','region','rdo_code','status','bir_status','sale_category','manual_discount_amount','statutory_discount_amount','vat_relief_amount','discount_amount','exception','review_status','review_reason','reviewed_at'];
    }

    public function review(Request $r, string $id)
    {
        $u = $this->actor($r);
        abort_unless(in_array(DB::table('profiles')->where('id', $u->id)->value('role'), ['admin', 'super_admin']), 403);
        $v = $r->validate(['status' => ['required', Rule::in(['open', 'in_progress', 'resolved', 'ignored'])], 'reason' => 'required|string|min:10|max:2000', 'revision' => 'required|integer|min:0', 'owner_id' => 'nullable|exists:users,id', 'priority' => 'nullable|in:low,normal,high,critical', 'due_at' => 'nullable|date', 'closure_evidence' => 'nullable|string|min:10|max:2000']);
        if (in_array($v['status'], ['resolved', 'ignored'], true)) {
            abort_unless(! empty($v['closure_evidence']) && ! empty($v['owner_id']), 422, 'Closing or ignoring a case requires assigned ownership and original closure evidence.');
        }
        abort_unless(DB::query()->fromSub($this->ledger(), 'review_documents')->where('id', $id)->exists(), 404);
        $result = DB::transaction(function () use ($id, $v, $u) {
            DB::table('eis_audit_head')->where('id', 1)->lockForUpdate()->first();
            $old = DB::table('reconciliation_reviews')->where('transaction_id', $id)->first();
            abort_unless(($old?->revision ?? 0) === $v['revision'], 409, 'Review changed. Refresh before saving.');
            if (in_array($v['status'], ['resolved', 'ignored'], true)) {
                abort_unless($old && $old->owner_id, 409, 'Assign and investigate the case before requesting independent closure.');
                abort_if((string) $old->reviewed_by === (string) $u->id || (string) $old->owner_id === (string) $u->id, 403, 'An independent reviewer must record case closure.');
                abort_unless((string) $old->owner_id === (string) $v['owner_id'], 409, 'The case owner cannot be changed during closure.');
            }

            $data = array_intersect_key($v, array_flip(['owner_id', 'priority', 'due_at', 'closure_evidence'])) + ['status' => $v['status'], 'reason' => $v['reason'], 'reviewed_by' => $u->id, 'revision' => $v['revision'] + 1, 'updated_at' => now()];
            if ($old) {
                DB::table('reconciliation_reviews')->where('id', $old->id)->update($data);
            } else {
                DB::table('reconciliation_reviews')->insert($data + ['transaction_id' => $id, 'created_at' => now()]);
            }app(BirCompliance::class)->audit('reconciliation.review.updated', $id, (string) $u->id, ['actor' => $u->id, 'before' => $old, 'after' => $data]);

            return $data;
        });

        return response()->json($result);
    }
}
