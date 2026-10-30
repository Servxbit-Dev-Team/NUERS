<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ReconciliationQuery
{
    public const EXCEPTIONS = "exception NOT IN ('Matched','For Review','Cancelled')";

    public function decorate(Builder $documents): Builder
    {
        $q = DB::query()->fromSub($documents, 'd')->select('d.*')->selectRaw("CASE
            WHEN reconciliation_prefix<>'' THEN reconciliation_prefix
            WHEN identity_count>1 AND document_family IN ('credit','debit') THEN 'Adjustment Reference Unverified'
            WHEN identity_count>1 THEN 'Duplicate Receipt Reference'
            ELSE reconciliation_suffix END as exception");
        if (!DB::table('reconciliation_reviews')->exists()) {
            $q = DB::query()->fromSub($q, 'e')->select('e.*')->selectRaw("CASE WHEN exception='Matched' THEN 'matched' ELSE 'open' END as review_status,
                0 as review_revision,NULL as review_reason,NULL as owner_id,NULL as priority,NULL as review_due_at,NULL as closure_evidence,NULL as reviewed_at");
            return DB::query()->fromSub($q, 'cases');
        }
        $q = DB::query()->fromSub($q, 'e')->leftJoin('reconciliation_reviews as r', 'r.transaction_id', '=', 'e.id')
            ->select('e.*')->selectRaw("COALESCE(r.status,CASE WHEN exception='Matched' THEN 'matched' ELSE 'open' END) as review_status,
                COALESCE(r.revision,0) as review_revision,r.reason as review_reason,r.owner_id,r.priority,r.due_at as review_due_at,r.closure_evidence,r.updated_at as reviewed_at");
        return DB::query()->fromSub($q, 'cases');
    }

    public function filtered(Builder $query, array $filters): Builder
    {
        $q = clone $query;
        match ($filters['review'] ?? 'all') {
            'matched' => $q->where('exception', 'Matched'),
            'exceptions' => $q->whereRaw(self::EXCEPTIONS),
            'review' => $q->where('exception', 'For Review'),
            'resolved' => $q->where('review_status', 'resolved'),
            default => null,
        };
        foreach (['exception', 'review_status'] as $key) {
            if (!empty($filters[$key]) && $filters[$key] !== 'all') {
                $q->where($key, $filters[$key]);
            }
        }
        return $q;
    }

    public function summary(Builder $all, array $filters): array
    {
        unset($filters['page'], $filters['per_page'], $filters['sort'], $filters['format']);
        $reviewVersion = DB::table('reconciliation_reviews')->selectRaw('COUNT(*) as count,COALESCE(SUM(revision),0) as revision,MAX(updated_at) as updated_at')->first();
        return app(ReportingSnapshotCache::class)->remember(
            'reconciliation-v1',
            'authorized-admins',
            [...$filters, 'reviews' => (array) $reviewVersion, 'today' => now()->toDateString()],
            function () use ($all, $filters) {
                $exception = self::EXCEPTIONS;
                $stats = (clone $all)->selectRaw(
                    "COUNT(*) as total,COALESCE(SUM(amount),0) as sales,
                    COALESCE(SUM(vat_amount),0) as vat,COALESCE(SUM(vatable_sales),0) as taxable,COALESCE(AVG(amount),0) as average,
                    COALESCE(SUM(CASE WHEN exception='Matched' THEN 1 ELSE 0 END),0) as matched,
                    COALESCE(SUM(CASE WHEN $exception THEN 1 ELSE 0 END),0) as exceptions,
                    COALESCE(SUM(CASE WHEN exception='For Review' THEN 1 ELSE 0 END),0) as review,
                    COALESCE(SUM(CASE WHEN review_status='resolved' THEN 1 ELSE 0 END),0) as resolved,
                    COALESCE(SUM(CASE WHEN review_status='resolved' AND reviewed_at>=? AND reviewed_at<? THEN 1 ELSE 0 END),0) as reconciled_today",
                    [now()->startOfDay(), now()->addDay()->startOfDay()]
                )->first();
                $summary = (array) $stats;
                foreach ($summary as $key => $value) {
                    $summary[$key] = in_array($key, ['sales', 'vat', 'taxable', 'average']) ? round($value, 2) : (int) $value;
                }
                $summary += ['excluded_count' => 0, 'excluded_amount' => 0];
                $trend = (clone $all)->selectRaw("SUBSTR(created_at,1,10) as date,SUM(amount) as amount,
                    SUM(CASE WHEN exception='Matched' THEN 1 ELSE 0 END) as matched,
                    SUM(CASE WHEN $exception THEN 1 ELSE 0 END) as exceptions,
                    SUM(CASE WHEN exception='For Review' THEN 1 ELSE 0 END) as review")->groupBy('date')->orderByDesc('date')->limit(7)->get()->reverse()->values();
                $breakdown = (clone $all)->selectRaw('exception as name,COUNT(*) as value')->groupBy('exception')->get();
                $statuses = (clone $all)->selectRaw('review_status as name,COUNT(*) as value')->groupBy('review_status')->get();
                $group = $filters['group'] ?? 'daily';
                $expression = match ($group) {
                    'monthly' => 'SUBSTR(created_at,1,7)', 'business' => "COALESCE(NULLIF(business,''),'Unspecified')",
                    'type' => "COALESCE(NULLIF(document_type,''),'Unspecified')", 'payment' => "COALESCE(NULLIF(payment_method,''),'Unspecified')",
                    'branch' => "COALESCE(NULLIF(branch,''),'Unspecified')", default => 'SUBSTR(created_at,1,10)',
                };
                $groups = (clone $all)->selectRaw("$expression as label,COUNT(*) as count,SUM(amount) as amount,SUM(vat_amount) as vat,SUM(vatable_sales) as taxable")
                    ->groupBy('label')->orderByDesc('amount')->limit(50)->get();
                $top = (clone $all)->selectRaw('business as label,SUM(amount) as amount')->groupBy('business')->orderByDesc('amount')->limit(5)->get();
                return ['summary' => $summary, 'total' => empty(array_filter(array_intersect_key($filters, array_flip(['review','exception','review_status'])), fn ($v) => $v && $v !== 'all')) ? $summary['total'] : $this->filtered($all, $filters)->count(), 'trend' => $trend, 'groups' => $groups,
                    'breakdown' => $breakdown, 'statuses' => $statuses, 'top_businesses' => $top];
            }
        );
    }
}
