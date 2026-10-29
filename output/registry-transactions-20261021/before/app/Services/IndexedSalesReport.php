<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Database-side reconciliation and pagination over the derivative reporting index. */
class IndexedSalesReport
{
    public const MONEY = ['gross', 'vatable', 'vat', 'zero', 'exempt', 'non_vat', 'net', 'statutory_discount', 'vat_adjustment', 'manual_discount', 'discount'];

    public function available(): bool
    {
        // The future official BIR adapter retains its independently verified evidence path.
        return !config('bir_compliance.production_response_verification_implemented') && app(LedgerProjection::class)->ready();
    }

    public function query(object $merchant, array $filters, bool $refresh = true, bool $includeCustomer = true): Builder
    {
        $q = DB::query()->fromSub(app(LedgerProjection::class)->query((string) $merchant->id, $refresh), 'd')
            ->whereIn('d.document_source', ['invoice', 'receipt'])
            ->where('d.business_day', '>=', $filters['from'])->where('d.business_day', '<=', $filters['to']);
        $original = DB::getDriverName() === 'sqlite' ? "'invoice:' || d.original_invoice_id" : "CONCAT('invoice:',d.original_invoice_id)";
        $q->leftJoin('ledger_documents as original', fn ($j) => $j->on('original.projection_id', '=', DB::raw($original))->on('original.merchant_id', '=', 'd.merchant_id')->whereIn('d.document_family', ['credit','debit']));
        $latest = "(SELECT o.id FROM eis_document_outbox o WHERE o.merchant_id=d.merchant_id AND o.document_source=d.document_source AND o.document_id=d.document_id ORDER BY o.source_review_revision DESC,o.prepared_at DESC,o.id DESC LIMIT 1)";
        $hasJobs = DB::table('eis_delivery_jobs')->where('merchant_id', $merchant->id)->exists();
        if ($hasJobs) $q->leftJoin('eis_delivery_jobs as job', fn ($j) => $j->on('job.outbox_id', '=', DB::raw($latest))->on('job.merchant_id', '=', 'd.merchant_id'));
        // customer_document_once guarantees one mapping per tenant/source/document.
        // Joins let the optimizer merge the derived query and seek the page index.
        $customer = "'unclassified'";
        if ($includeCustomer || ($filters['customer_type'] ?? 'all') !== 'all') {
        $customerTable = DB::getDriverName() === 'mysql'
            ? DB::raw('merchant_customer_documents as cd FORCE INDEX (customer_document_once)')
            : 'merchant_customer_documents as cd';
        $q->leftJoin($customerTable, fn ($j) => $j->on('cd.merchant_id', '=', 'd.merchant_id')->on('cd.document_source', '=', 'd.document_source')->on('cd.document_id', '=', 'd.document_id'))
            ->leftJoin('merchant_customers as c', fn ($j) => $j->on('c.id', '=', 'cd.customer_id')->on('c.merchant_id', '=', 'd.merchant_id'));
        $customer = "COALESCE(c.type,'unclassified')";
        }
        $reason = "CASE
            WHEN d.document_family NOT IN ('invoice','credit','debit') THEN 'Payment / supplementary document: no additional invoice sales'
            WHEN d.status<>'success' THEN 'Not an active issued document'
            WHEN d.identity_count<>1 THEN 'Duplicate document identity needs source review'
            WHEN d.document_family IN ('credit','debit') AND (original.projection_id IS NULL OR original.status<>'success' OR original.document_family<>'invoice' OR original.tenant_identity_count<>1 OR original.financial_valid=0) THEN 'Adjustment requires a reconciled, active original invoice in this business'
            WHEN d.financial_valid=0 THEN 'Original line amounts / tax allocation do not reconcile'
            ELSE NULL END";
        $bir = $hasJobs ? "CASE WHEN job.state IN ('queued','sending','leased','retry') THEN 'queued' WHEN job.state IN ('uncertain','inquiry','rejected','dead_letter') THEN 'needs_review' WHEN job.state='blocked' THEN 'blocked' ELSE 'not_verified' END" : "'not_verified'";
        $q->selectRaw("d.*, $customer as customer_type, $reason as exclusion_reason, $bir as bir_status");
        $base = DB::query()->fromSub($q, 's');
        foreach (['branch' => 'verified_branch', 'type' => 'document_type', 'sale_category' => 'sale_category', 'customer_type' => 'customer_type', 'bir' => 'bir_status'] as $key => $column) {
            if (($filters[$key] ?? 'all') === 'all') continue;
            if ($key === 'branch' && $filters[$key] === 'unrecorded') $base->whereNull($column);
            else $base->where($column, $filters[$key]);
        }
        if ($term = trim($filters['search'] ?? '')) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
            $base->where(fn ($q) => $q->whereRaw("receipt_number LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("customer_name LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("customer_tin LIKE ? ESCAPE '!'", [$pattern]));
        }
        $base->selectRaw("s.*, CASE WHEN exclusion_reason IS NULL THEN 1 ELSE 0 END as included");
        $sign = "CASE WHEN exclusion_reason IS NOT NULL THEN 0 WHEN document_family='credit' THEN -1 ELSE 1 END";
        foreach (['gross' => 'amount', 'vatable' => 'vatable_sales', 'vat' => 'vat_amount', 'zero' => 'zero_rated_sales', 'net' => 'net_amount', 'statutory_discount' => 'statutory_discount_amount', 'vat_adjustment' => 'vat_relief_amount', 'manual_discount' => 'manual_discount_amount', 'discount' => 'discount_amount'] as $key => $column) {
            $base->selectRaw("($sign)*ROUND($column*100) as $key");
        }
        $base->selectRaw("($sign)*non_vat_centavos as non_vat, ($sign)*(ROUND(vat_exempt_sales*100)-non_vat_centavos+ROUND(sspt_sales*100)) as exempt");
        return DB::query()->fromSub($base, 'report');
    }

    public function summary(object $merchant, array $filters, Builder $query): array
    {
        return app(ReportingSnapshotCache::class)->remember('merchant-sales', (string) $merchant->id,
            [...$filters, 'business_name' => $merchant->business_name, 'business_tin' => $merchant->tin],
            fn () => $this->calculateSummary($merchant, $filters, $query));
    }

    private function calculateSummary(object $merchant, array $filters, Builder $query): array
    {
        $aggregates = ['COUNT(*) as total', 'COALESCE(SUM(included),0) as included_count'];
        foreach (self::MONEY as $key) $aggregates[] = "COALESCE(SUM($key),0) as $key";
        foreach (['acknowledged', 'queued', 'needs_review', 'blocked', 'not_verified'] as $state) $aggregates[] = "COALESCE(SUM(CASE WHEN included=1 AND bir_status='$state' THEN 1 ELSE 0 END),0) as bir_$state";
        $stats = (clone $query)->selectRaw(implode(',', $aggregates))->first();
        $totals = []; $bir = [];
        foreach (self::MONEY as $key) $totals[$key] = (int) $stats->$key;
        foreach (['acknowledged', 'queued', 'needs_review', 'blocked', 'not_verified'] as $state) $bir[$state] = (int) $stats->{'bir_'.$state};
        $options = DB::table('ledger_documents')->where('merchant_id', $merchant->id)->where('tenant_identity_rank', 1)->whereIn('document_source', ['invoice', 'receipt']);
        $revision = (int) DB::table('ledger_projection_state')->where('id', 1)->value('revision');
        return [
            'merchant' => ['name' => $merchant->business_name, 'tin' => $merchant->tin], 'filters' => $filters,
            'count' => (int) $stats->included_count, 'excluded_count' => (int) $stats->total - (int) $stats->included_count,
            'totals' => $totals, 'bir' => $bir,
            'options' => ['branches' => (clone $options)->select('verified_branch')->distinct()->orderBy('verified_branch')->limit(201)->pluck('verified_branch')->map(fn ($b) => $b ?? '')->all(), 'types' => (clone $options)->select('document_type')->distinct()->orderBy('document_type')->limit(101)->pluck('document_type')->all()],
            'reporting_state' => 'internal_sales_report', 'currency' => 'PHP', 'amount_unit' => 'centavo', 'generated_at' => now()->toIso8601String(),
            'snapshot_hash' => hash('sha256', json_encode([$merchant->id, $filters, $revision, $totals, $stats->total], JSON_THROW_ON_ERROR)), 'report_revision' => $revision,
            'notice' => 'Internal sales report. Reconciled source invoices and linked adjustments are counted once; payment receipts, drafts, duplicate identities and invalid amounts do not add sales. Source amounts and tax classifications are retained. BIR acknowledgment requires independently verified production evidence.',
        ];
    }

    public function page(object $merchant, array $filters, int $page, int $size, string $view): array
    {
        app(LedgerProjection::class)->catchUp();
        return DB::transaction(function () use ($merchant, $filters, $page, $size, $view) {
            $query = $this->query($merchant, $filters, false, false);
            $result = $this->summary($merchant, $filters, $query);
            $count = $view === 'review' ? $result['excluded_count'] : $result['count'];
            $last = max(1, (int) ceil($count / $size)); $page = max(1, min($page, $last));
            $ids = (clone $query)->select('projection_id')->where('included', $view === 'review' ? 0 : 1)->orderByDesc('business_day')->orderByDesc('projection_id')->offset(($page - 1) * $size)->limit($size)->pluck('projection_id');
            // Hydrate only the selected page; customer joins never sort a million wide rows.
            $rows = $ids->isEmpty() ? collect() : $this->query($merchant, $filters, false)->whereIn('projection_id', $ids)->orderByDesc('business_day')->orderByDesc('projection_id')->get();
            return [...$result, 'data' => $rows->map(fn ($r) => $this->row($r))->all(), 'pagination' => ['page' => $page, 'per_page' => $size, 'total' => $count, 'last_page' => $last]];
        });
    }

    public function boundedExport(object $merchant, array $filters, int $limit): array
    {
        $query = $this->query($merchant, $filters);
        $r = $this->summary($merchant, $filters, $query);
        abort_if($r['count'] + $r['excluded_count'] > $limit, 422, 'This download is too large for this format. Use a background CSV export or narrow the report dates.');
        $rows = $query->orderByDesc('business_day')->orderBy('projection_id')->get()->map(fn ($r) => $this->row($r));
        return [...$r, 'included' => $rows->where('included', true)->values()->all(), 'excluded' => $rows->where('included', false)->values()->all()];
    }

    public function row(object $r): array
    {
        $out = ['id' => $r->id, 'source' => $r->document_source, 'document_id' => $r->document_id, 'number' => $r->receipt_number, 'date' => $r->business_day,
            'date_precision' => $r->document_source === 'invoice' ? 'day' : 'instant', 'customer' => $r->customer_name ?: 'Not recorded', 'tin' => $r->customer_tin,
            'customer_type' => $r->customer_type, 'branch' => $r->verified_branch, 'type' => $r->document_type, 'family' => $r->document_family, 'original_invoice_id' => $r->original_invoice_id,
            'bir_status' => $r->bir_status, 'included' => (bool) $r->included, 'exclusion_reason' => $r->exclusion_reason, 'source_amount' => (int) round($r->amount * 100), 'sale_category' => $r->sale_category];
        foreach (self::MONEY as $key) $out[$key] = (int) $r->$key;
        return $out;
    }
}
