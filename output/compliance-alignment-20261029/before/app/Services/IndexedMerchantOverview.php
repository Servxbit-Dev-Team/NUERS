<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class IndexedMerchantOverview
{
    public function aggregate(string $merchant, string $from, string $to): array
    {
        app(LedgerProjection::class)->catchUp();
        return app(ReportingSnapshotCache::class)->remember('merchant-overview', $merchant, compact('from','to'), fn () => $this->calculate($merchant, $from, $to));
    }

    private function calculate(string $merchant, string $from, string $to): array
    {
        $source = app(LedgerProjection::class)->query($merchant, false);
        if (\Illuminate\Support\Facades\Schema::hasColumn('ledger_documents', 'is_draft')) {
            $source = DB::table('ledger_documents')->where('merchant_id',$merchant)->where('tenant_identity_rank',1)->where('is_draft',false)
                ->select(['merchant_id','business_day','financial_valid','status','document_family','amount','original_invoice_id','document_source','document_id'])->selectRaw('tenant_identity_count as identity_count');
            if (DB::getDriverName()==='mysql') $source->forceIndex('ledger_merchant_overview');
        }
        $q = DB::query()->fromSub($source, 'd')->whereBetween('d.business_day', [$from, $to]);
        $original = DB::getDriverName() === 'sqlite' ? "'invoice:' || d.original_invoice_id" : "CONCAT('invoice:',d.original_invoice_id)";
        $q->leftJoin('ledger_documents as original', fn ($j) => $j->on('original.projection_id', '=', DB::raw($original))->on('original.merchant_id', '=', 'd.merchant_id')->whereIn('d.document_family', ['credit','debit']));
        $latest = '(SELECT o.id FROM eis_document_outbox o WHERE o.merchant_id=d.merchant_id AND o.document_source=d.document_source AND o.document_id=d.document_id ORDER BY o.source_review_revision DESC,o.prepared_at DESC,o.id DESC LIMIT 1)';
        $hasJobs = DB::table('eis_delivery_jobs')->where('merchant_id', $merchant)->exists();
        if ($hasJobs) $q->leftJoin('eis_delivery_jobs as job', fn ($j) => $j->on('job.outbox_id', '=', DB::raw($latest))->on('job.merchant_id', '=', 'd.merchant_id'));
        $jobState = $hasJobs ? 'job.state' : 'NULL';
        $sound = 'd.identity_count=1 AND d.financial_valid=1';
        $sales = "($sound) AND d.status='success' AND d.document_family IN ('invoice','credit','debit')
            AND (d.document_family='invoice' OR (original.document_family='invoice' AND original.status='success' AND original.financial_valid=1 AND original.tenant_identity_count=1))";
        return $q->selectRaw("d.business_day,
            COUNT(*) as documents,
            COALESCE(SUM(CASE WHEN $sales THEN ROUND(d.amount*100)*(CASE WHEN d.document_family='credit' THEN -1 ELSE 1 END) ELSE 0 END),0) as sales,
            SUM(CASE WHEN d.document_family='invoice' AND d.status='success' THEN 1 ELSE 0 END) as invoices,
            SUM(CASE WHEN d.status='cancelled' THEN 1 ELSE 0 END) as cancelled,
            SUM(CASE WHEN d.status<>'cancelled' AND (NOT($sound) OR d.status='failed') THEN 1 ELSE 0 END) as for_review,
            SUM(CASE WHEN ($sound) AND d.status='success' THEN 1 ELSE 0 END) as issued,
            SUM(CASE WHEN ($sound) AND d.status NOT IN ('success','cancelled','failed') THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN $jobState IN ('queued','retry','leased','sending','inquiry') THEN 1 ELSE 0 END) as queued,
            SUM(CASE WHEN $jobState IN ('dead_letter','rejected','uncertain') THEN 1 ELSE 0 END) as needs_attention")
            ->groupBy('d.business_day')->get()->keyBy('business_day')->all();
    }
}
