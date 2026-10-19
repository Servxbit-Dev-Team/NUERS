<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApiAccess;
use App\Services\BirCompliance;
use App\Services\EisDocumentReadiness;
use App\Services\ElectronicDocumentView;
use App\Services\IssuedDocumentLedger;
use App\Services\SubmissionEvidence;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Merchant-only, read-only document and payment projections; never an issuance or BIR transport path. */
class MerchantRecordsController extends Controller
{
    private function prefix(string $prefix, string $column): string
    {
        return DB::getDriverName() === 'sqlite' ? "'$prefix' || $column" : "CONCAT('$prefix',$column)";
    }

    private function base(object $merchant, string $mode): Builder
    {
        if ($mode === 'documents') {
            $canonical = app(IssuedDocumentLedger::class)->query((string) $merchant->id)->whereIn('document_source', ['invoice', 'receipt']);
            $q = DB::query()->fromSub($canonical, 'd')->selectRaw("d.id,d.document_id as record_id,d.document_id,d.document_source,d.receipt_number as number,d.business_date as source_date,d.recorded_at,d.customer_name as customer,d.customer_tin,d.document_type,d.document_family as family,
                CASE WHEN d.status='success' THEN 'issued' ELSE d.status END as status,d.record_status as raw_status,d.amount,d.vat_amount as vat,COALESCE(d.branch_code,d.branch) as branch,d.channel,d.payment_method,d.tin as seller_tin,d.identity_count,d.original_invoice_id,
                CASE WHEN d.document_source='invoice' THEN 'day' ELSE 'instant' END as date_precision,d.sale_category,d.statutory_discount_amount,d.vat_relief_amount,d.discount_amount");
            $draft = DB::table('business_invoices as b')->where('b.merchant_id', $merchant->id)->where('b.status', 'draft')->selectRaw($this->prefix('invoice:', 'b.id')." as id,b.id as record_id,b.id as document_id,'invoice' as document_source,b.invoice_number as number,b.issue_date as source_date,b.created_at as recorded_at,b.buyer_name as customer,b.buyer_tin as customer_tin,b.document_type,
                CASE WHEN b.document_type IN ('credit_note','credit_memo') THEN 'credit' WHEN b.document_type IN ('debit_note','debit_memo') THEN 'debit' WHEN b.document_type IN ('payment_receipt','official_receipt') THEN 'receipt' WHEN b.document_type IN ('delivery_receipt','purchase_invoice') THEN 'supplementary' ELSE 'invoice' END as family,
                'draft' as status,b.status as raw_status,b.total_amount as amount,b.vat_amount as vat,b.seller_branch_code as branch,NULL as channel,NULL as payment_method,b.merchant_tin as seller_tin,1 as identity_count,b.original_invoice_id,'day' as date_precision,b.sale_category,b.statutory_discount_amount,b.vat_relief_amount,b.discount_amount");
            $q->unionAll($draft);
        } else {
            // Exactly one tenant-owned receipt can join a payment; repeated receipt links cannot multiply its rows.
            $q = DB::table('merchant_transactions as t')->leftJoin('transaction_receipts as r', function ($j) {
                $j->on('r.id', '=', DB::raw('COALESCE(t.receipt_id,(SELECT rr.id FROM transaction_receipts rr WHERE rr.transaction_id=t.id AND rr.merchant_id=t.merchant_id ORDER BY rr.id LIMIT 1))'))->on('r.merchant_id', '=', 't.merchant_id');
            })->where('t.merchant_id', $merchant->id)->selectRaw($this->prefix('payment:', 't.id')." as id,t.id as record_id,r.id as document_id,'payment' as document_source,t.transaction_ref as number,t.created_at as source_date,t.created_at as recorded_at,COALESCE(t.customer_name,r.buyer_name) as customer,COALESCE(t.customer_tin,r.buyer_tin) as customer_tin,t.transaction_type as document_type,'payment' as family,
                CASE WHEN t.status IN ('completed','paid','settled','verified','success','valid') THEN 'completed' WHEN t.status IN ('cancelled','canceled','voided','void','refunded','refund') THEN 'cancelled' WHEN t.status IN ('failed','rejected','invalid') THEN 'failed' ELSE 'pending' END as status,t.status as raw_status,t.amount,t.vat_amount as vat,t.branch,t.channel,t.payment_method,r.merchant_tin as seller_tin,1 as identity_count,NULL as original_invoice_id,'instant' as date_precision,COALESCE(r.sale_category,'regular') as sale_category,COALESCE(r.statutory_discount_amount,0) as statutory_discount_amount,COALESCE(r.vat_relief_amount,0) as vat_relief_amount,COALESCE(r.discount_amount,0) as discount_amount");
        }
        $q = DB::query()->fromSub($q, 'records');
        $q->leftJoin('eis_document_outbox as o', function ($j) use ($merchant) {
            $j->on('o.id', '=', DB::raw("(SELECT ox.id FROM eis_document_outbox ox WHERE ox.merchant_id=? AND ox.document_id=records.document_id AND ox.document_source=CASE WHEN records.document_source='payment' THEN 'receipt' ELSE records.document_source END ORDER BY ox.source_review_revision DESC,ox.prepared_at DESC,ox.id DESC LIMIT 1)"));
            $j->addBinding($merchant->id, 'join');
        })->leftJoin('eis_delivery_jobs as job', function ($j) use ($merchant) {
            $j->on('job.outbox_id', '=', 'o.id')->where('job.merchant_id', $merchant->id);
        });
        $q->leftJoin('eis_transmissions as legacy', function ($j) use ($merchant) {
            $j->on('legacy.id', '=', DB::raw("(SELECT et.id FROM eis_transmissions et JOIN eis_invoice_payloads ep ON ep.id=et.payload_id AND ep.merchant_id=et.merchant_id WHERE et.merchant_id=? AND ((records.document_source='invoice' AND ep.source_document_type='invoice' AND ep.source_document_id=records.document_id) OR (records.document_source IN ('receipt','payment') AND (ep.receipt_id=records.document_id OR (ep.source_document_type='receipt' AND ep.source_document_id=records.document_id)))) ORDER BY CASE WHEN et.environment='production' THEN 0 ELSE 1 END,et.created_at DESC,et.id DESC LIMIT 1)"));
            $j->addBinding($merchant->id, 'join');
        });
        $accepted = [];
        if (config('bir_compliance.transport_implemented') && config('bir_compliance.official_interface_verified') && config('bir_compliance.production_response_verification_implemented')) {
            $proofs = DB::table('eis_transmissions as t')->join('eis_invoice_payloads as p', 'p.id', '=', 't.payload_id')->where('t.merchant_id', $merchant->id)->where('p.merchant_id', $merchant->id)->where('t.environment', 'production')->whereIn('t.status', ['accepted', 'acknowledged', 'success'])->get(['t.id', 'p.source_document_type', 'p.source_document_id', 'p.receipt_id']);
            foreach ($proofs as $proof) {
                if (app(SubmissionEvidence::class)->verified($proof->id)) {
                    $source = $proof->source_document_type ?: ($proof->receipt_id ? 'receipt' : null);
                    $id = $proof->source_document_id ?: $proof->receipt_id;
                    if ($source && $id) {
                        $accepted[$source.':'.$id] = true;
                    }
                }
            }
        }
        $key = DB::getDriverName() === 'sqlite' ? "(CASE WHEN records.document_source='payment' THEN 'receipt' ELSE records.document_source END)||':'||records.document_id" : "CONCAT(CASE WHEN records.document_source='payment' THEN 'receipt' ELSE records.document_source END,':',records.document_id)";
        $verified = $accepted ? $key.' IN ('.implode(',', array_fill(0, count($accepted), '?')).')' : '0=1';
        $q->selectRaw("records.*,job.state as delivery_state,job.updated_at as delivery_updated_at,CASE WHEN records.status='draft' THEN 'not_submitted' WHEN records.document_source='payment' AND records.document_id IS NULL THEN 'not_linked' WHEN ($verified) THEN 'acknowledged' WHEN job.state IN ('queued','sending','retry') THEN 'queued' WHEN job.state IN ('uncertain','inquiry') THEN 'uncertain' WHEN job.state IN ('rejected','dead_letter') THEN 'needs_review' WHEN job.state='blocked' THEN 'blocked' WHEN legacy.id IS NOT NULL AND legacy.environment<>'production' THEN 'test' WHEN legacy.id IS NOT NULL THEN 'unverified' ELSE 'not_submitted' END as bir_status", array_keys($accepted));

        return app(\App\Services\MerchantTeamAccess::class)->scopeRecords(DB::query()->fromSub($q, 'r'));
    }

    private function filters(Request $r): array
    {
        $v = $r->validate(['sale_category' => ['nullable', Rule::in(\App\Services\StatutorySales::CATEGORIES)], 'mode' => ['sometimes', Rule::in(['documents', 'transactions'])], 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'search' => 'nullable|string|max:200',
            'category' => ['nullable', Rule::in(['all', 'invoices', 'receipts', 'credit', 'debit', 'drafts', 'notes'])], 'type' => 'nullable|string|max:60', 'customer' => 'nullable|string|max:255', 'branch' => 'nullable|string|max:200',
            'status' => ['nullable', Rule::in(['all', 'issued', 'draft', 'pending', 'failed', 'cancelled', 'completed', 'issues'])], 'bir' => ['nullable', Rule::in(['all', 'not_submitted', 'not_linked', 'blocked', 'queued', 'uncertain', 'acknowledged', 'needs_review', 'unverified', 'test'])],
            'min' => 'nullable|numeric|between:-999999999999.99,999999999999.99', 'max' => 'nullable|numeric|between:-999999999999.99,999999999999.99', 'channel' => 'nullable|string|max:100', 'payment' => 'nullable|string|max:60', 'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|in:10,25,50,100',
            'sort' => ['nullable', Rule::in(['newest', 'oldest', 'amount_desc', 'amount_asc'])], 'ids' => 'sometimes|array|max:100', 'ids.*' => 'string|max:120']);
        abort_if(! empty($v['from']) && ! empty($v['to']) && $v['to'] < $v['from'], 422, 'To date must be on or after From date.');
        abort_if(isset($v['min'], $v['max']) && $v['max'] < $v['min'], 422, 'Maximum amount must be at least the minimum amount.');

        return $v;
    }

    private function apply(Builder $q, array $v, bool $category = true): Builder
    {
        foreach (['from', 'to'] as $side) {
            if (empty($v[$side])) {
                continue;
            }
            $date = CarbonImmutable::parse($v[$side], 'Asia/Manila')->startOfDay();
            if ($side === 'to') {
                $date = $date->addDay();
            }
            $op = $side === 'from' ? '>=' : '<';
            $q->where(function ($s) use ($date, $op) {
                $s->where(fn ($a) => $a->where('date_precision', 'day')->where('source_date', $op, $date->toDateString()))
                    ->orWhere(fn ($a) => $a->where('date_precision', 'instant')->where('source_date', $op, $date->setTimezone(config('app.timezone', 'UTC'))->format('Y-m-d H:i:s')));
            });
        }
        if (! empty($v['search'])) {
            $q->where(fn ($s) => $s->where('number', 'like', '%'.$v['search'].'%')->orWhere('customer', 'like', '%'.$v['search'].'%')->orWhere('customer_tin', 'like', '%'.$v['search'].'%'));
        }
        foreach (['sale_category' => 'sale_category', 'type' => 'document_type', 'customer' => 'customer', 'branch' => 'branch', 'status' => 'status', 'bir' => 'bir_status', 'channel' => 'channel', 'payment' => 'payment_method'] as $key => $column) {
            if ($key === 'status' && ($v[$key] ?? '') === 'issues') {
                $q->whereIn('status', ['failed', 'cancelled']);
            } elseif (isset($v[$key]) && $v[$key] !== '' && $v[$key] !== 'all') {
                $q->where($column, $v[$key]);
            }
        }
        if (isset($v['min'])) {
            $q->where('amount', '>=', $v['min']);
        }
        if (isset($v['max'])) {
            $q->where('amount', '<=', $v['max']);
        }
        if ($category && ! empty($v['category']) && $v['category'] !== 'all') {
            match ($v['category']) {
                'invoices' => $q->where('family', 'invoice'), 'receipts' => $q->whereIn('family', ['receipt', 'supplementary']),
                'notes' => $q->whereIn('family', ['credit', 'debit']), 'drafts' => $q->where('status', 'draft'), default => $q->where('family', $v['category']),
            };
        }

        return $q;
    }

    private function row(object $row): array
    {
        $r = (array) $row;
        $r['manual_discount_amount'] = max(0, round((float)$row->discount_amount - (float)$row->statutory_discount_amount, 2));
        $r['amount'] = (float) $row->amount;
        $r['vat'] = (float) $row->vat;
        $r['date'] = $row->date_precision === 'day' ? substr((string) $row->source_date, 0, 10) : ($row->source_date ? CarbonImmutable::parse($row->source_date, config('app.timezone'))->setTimezone('Asia/Manila')->toIso8601String() : null);
        $r['recorded_at'] = $row->recorded_at ? CarbonImmutable::parse($row->recorded_at, config('app.timezone'))->toIso8601String() : null;
        $r['duplicate_identity'] = (int) $row->identity_count > 1;

        return $r;
    }

    private function ordered(Builder $q, array $v): Builder
    {
        $sort = $v['sort'] ?? 'newest';

        return $q->orderBy(str_starts_with($sort, 'amount') ? 'amount' : 'source_date', in_array($sort, ['oldest', 'amount_asc']) ? 'asc' : 'desc')->orderByDesc('recorded_at')->orderBy('id');
    }

    public function index(Request $r)
    {
        $merchant = app(ApiAccess::class)->merchant($r);
        $v = $this->filters($r);
        $mode = $v['mode'] ?? 'documents';
        $base = $this->base($merchant, $mode);
        $filtered = $this->apply(clone $base, $v, false);
        $q = $this->apply(clone $base, $v);
        $s = (clone $q)->selectRaw("COUNT(*) as total,COALESCE(SUM(CASE WHEN status IN ('issued','completed') THEN 1 ELSE 0 END),0) as successful,COALESCE(SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END),0) as pending,COALESCE(SUM(CASE WHEN status IN ('failed','cancelled') THEN 1 ELSE 0 END),0) as failed,COALESCE(SUM(CASE WHEN bir_status='acknowledged' THEN 1 ELSE 0 END),0) as acknowledged,COALESCE(SUM(CASE WHEN bir_status='queued' THEN 1 ELSE 0 END),0) as queued,COALESCE(SUM(CASE WHEN bir_status IN ('blocked','uncertain','needs_review') THEN 1 ELSE 0 END),0) as attention,COALESCE(SUM(CASE WHEN family='invoice' THEN 1 ELSE 0 END),0) as invoices,COALESCE(SUM(CASE WHEN family IN ('receipt','supplementary') THEN 1 ELSE 0 END),0) as receipts")->first();
        $sales = 0;
        if ($mode === 'transactions') {
            foreach ((clone $q)->where('status', 'completed')->select('amount')->cursor() as $payment) {
                $sales += (int) round($payment->amount * 100);
            }
        } else {
            foreach ((clone $q)->where('status', 'issued')->where('identity_count', 1)->whereIn('family', ['invoice', 'credit', 'debit'])->cursor() as $doc) {
                if (in_array($doc->family, ['credit', 'debit'], true) && (! $doc->original_invoice_id || ! DB::table('business_invoices')->where('id', $doc->original_invoice_id)->where('merchant_id', $merchant->id)->whereNotIn('status', ['draft', 'cancelled', 'voided'])->whereNotIn('document_type', ['credit_memo', 'debit_memo', 'credit_note', 'debit_note', 'official_receipt', 'payment_receipt', 'purchase_invoice', 'delivery_receipt'])->exists())) {
                    continue;
                }
                if (app(EisDocumentReadiness::class)->inspect($doc->document_source, $doc->document_id)['financial_validation_passed']) {
                    $sales += (int) round($doc->amount * 100) * ($doc->family === 'credit' ? -1 : 1);
                }
            }
        }
        $s->total_amount = $sales / 100;
        $tabs = (array) (clone $filtered)->selectRaw("COUNT(*) as 'all',COALESCE(SUM(CASE WHEN family='invoice' THEN 1 ELSE 0 END),0) as invoices,COALESCE(SUM(CASE WHEN family IN ('receipt','supplementary') THEN 1 ELSE 0 END),0) as receipts,COALESCE(SUM(CASE WHEN family='credit' THEN 1 ELSE 0 END),0) as credit,COALESCE(SUM(CASE WHEN family='debit' THEN 1 ELSE 0 END),0) as debit,COALESCE(SUM(CASE WHEN status='draft' THEN 1 ELSE 0 END),0) as drafts")->first();
        $page = $this->ordered(clone $q, $v)->paginate($v['per_page'] ?? 10);
        $options = [];
        foreach (['customers' => 'customer', 'branches' => 'branch', 'types' => 'document_type', 'channels' => 'channel', 'payments' => 'payment_method'] as $label => $column) {
            $options[$label] = (clone $base)->whereNotNull($column)->where($column, '!=', '')->select($column)->distinct()->orderBy($column)->pluck($column);
        }

        return response()->json(['mode' => $mode, 'merchant' => ['id' => $merchant->id, 'name' => $merchant->business_name, 'status' => $merchant->status], 'data' => array_map(fn ($row) => $this->row($row), $page->items()), 'summary' => $s, 'tabs' => $tabs, 'options' => $options, 'pagination' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
            'recent' => $this->ordered(clone $q, ['sort' => 'newest'])->limit(5)->get()->map(fn ($row) => $this->row($row)), 'generated_at' => now()->toIso8601String(), 'notice' => $mode === 'documents' ? 'Source-reconciled invoice sales draft; payment/delivery receipts and drafts are excluded from sales. BIR acknowledgment requires verified production evidence.' : 'Payment/ledger activity only, including portal and API records. Completed amounts are not additional invoice sales. Unlinked entries carry no BIR acknowledgment proof.'])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $r, string $record)
    {
        $merchant = app(ApiAccess::class)->merchant($r);
        $v = $this->filters($r);
        $row = $this->base($merchant, $v['mode'] ?? 'documents')->where('id', $record)->first();
        abort_unless($row, 404);
        $items = [];
        $history = [];
        if ($row->document_source === 'invoice') {
            $items = DB::table('business_invoice_items')->where('invoice_id', $row->record_id)->where('merchant_id', $merchant->id)->orderBy('line_number')->get(['description', 'quantity', 'unit_price', 'line_total', 'tax_type']);
        }
        if ($row->document_id) {
            $history = DB::table('invoice_lifecycle_events')->where('merchant_id', $merchant->id)->where('document_id', $row->document_id)->where('document_source', $row->document_source === 'payment' ? 'receipt' : $row->document_source)->orderByDesc('occurred_at')->limit(30)->get(['event', 'occurred_at', 'reason']);
        }

        $document = null;
        if ($row->document_id) {
            $source = $row->document_source === 'payment' ? 'receipt' : $row->document_source;
            $original = DB::table($source === 'invoice' ? 'business_invoices' : 'transaction_receipts')->where('id', $row->document_id)->where('merchant_id', $merchant->id)->first();
            if ($original) {
                $document = app(ElectronicDocumentView::class)->make($source, $original);
            }
        }

        return response()->json(['record' => $this->row($row), 'document' => $document, 'items' => $items, 'history' => $history, 'notice' => 'Read-only original-source details. Corrections and cancellation requests use the existing independently reviewed workflow.'])->header('Cache-Control', 'private, no-store');
    }

    public function export(Request $r)
    {
        $merchant = app(ApiAccess::class)->merchant($r);
        $v = $this->filters($r);
        // The local array cache is request-local; keep this budget durable across HTTP requests.
        $store = config('cache.default');
        $limiter = new RateLimiter(Cache::store($store === 'array' ? 'database' : $store));
        $budget = 'merchant-records-export:'.hash('sha256', (string) $merchant->id);
        if ($limiter->tooManyAttempts($budget, 3)) {
            return response()->json(['message' => 'Export budget reached. Please retry in a minute.'], 429)->header('Retry-After', $limiter->availableIn($budget));
        }
        $limiter->hit($budget, 60);
        $mode = $v['mode'] ?? 'documents';
        $base = $this->base($merchant, $mode);
        $q = $this->apply(clone $base, $v);
        if (! empty($v['ids'])) {
            $ids = array_values(array_unique($v['ids']));
            abort_unless((clone $q)->whereIn('id', $ids)->count() === count($ids), 404, 'One or more selected records do not match this authorized export.');
            $q->whereIn('id', $ids);
        }
        app(BirCompliance::class)->audit('merchant.records.export', (string) $merchant->id, (string) $r->attributes->get('api_actor')->id, ['merchant_id' => $merchant->id, 'actor_role' => $r->attributes->get('api_role'), 'correlation_id' => (string) Str::uuid(), 'outcome' => 'authorized_for_stream', 'format' => 'NUERS-merchant-records-csv-1', 'mode' => $mode, 'filters' => $v]);

        return response()->streamDownload(function () use ($q, $v) {
            $f = fopen('php://output', 'w');
            fwrite($f, "\xEF\xBB\xBF");
            fputcsv($f, ['Record ID', 'Source', 'Document / Transaction No.', 'Original Date', 'Date Precision', 'Customer', 'Customer TIN', 'Type', 'Branch', 'Amount PHP', 'VAT PHP', 'Local Status', 'BIR Evidence Status', 'Delivery State', 'Identity Source Count', 'Sale Category', 'Statutory Discount PHP', 'VAT Adjustment PHP', 'Manual Discount PHP', 'Total Discount PHP'], escape: '');
            $safe = fn ($x) => preg_match('/^\s*[=+@\-]/u', (string) $x) ? "'".$x : (string) $x;
            foreach ($this->ordered($q, $v)->cursor() as $row) {
                $x = $this->row($row);
                fputcsv($f, [$safe($x['id']), $x['document_source'], $safe($x['number']), $x['date'], $x['date_precision'], $safe($x['customer']), $safe($x['customer_tin']), $safe($x['document_type']), $safe($x['branch']), number_format($x['amount'], 2, '.', ''), number_format($x['vat'], 2, '.', ''), $x['status'], $x['bir_status'], $x['delivery_state'], $x['identity_count'], $x['sale_category'], number_format($x['statutory_discount_amount'], 2, '.', ''), number_format($x['vat_relief_amount'], 2, '.', ''), number_format($x['manual_discount_amount'], 2, '.', ''), number_format($x['discount_amount'], 2, '.', '')], escape: '');
            }fclose($f);
        }, 'NUERS-'.$mode.'-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
