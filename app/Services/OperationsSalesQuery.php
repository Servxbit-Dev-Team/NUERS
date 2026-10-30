<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Bounded reads and SQL aggregates over validated, rebuildable source evidence. */
class OperationsSalesQuery
{
    public const ELIGIBLE = 'operations_sales_valid=1 AND identity_count=1';

    /** Resolve selective text filters once; never hydrate more than 5,001 narrow IDs. */
    public function narrow(Builder $q, array $filters): Builder
    {
        if (empty($filters['search']) && empty($filters['customer'])) {
            return $q;
        }
        unset($filters['page'], $filters['per_page'], $filters['sort'], $filters['format']);
        $ids = app(ReportingSnapshotCache::class)->remember(
            'operations-sales-matches-v1',
            'authorized-administrators',
            $filters,
            fn () => (clone $q)->where('status', 'success')->limit(5001)->pluck('projection_id')->all()
        );
        if (count($ids) > 5000) {
            return $q;
        }
        // Keep every original predicate as a second authorization/filter boundary.
        return (clone $q)->whereIn('projection_id', $ids);
    }

    public function included(Builder $q): Builder
    {
        return (clone $q)->where('status', 'success')->whereRaw(self::ELIGIBLE);
    }

    public function signed(string $column): string
    {
        return "CASE WHEN document_family='credit' THEN -ABS($column) ELSE $column END";
    }

    public function sort(Builder $q, array $v): Builder
    {
        $sort = $v['sort'] ?? 'newest';
        $dir = in_array($sort, ['oldest', 'amount_asc'], true) ? 'asc' : 'desc';
        return $q->orderByRaw((str_starts_with($sort, 'amount') ? $this->signed('amount') : 'created_at').' '.$dir)->orderBy('id', $dir);
    }

    public function summary(Builder $q, array $filters, string $group, int $groupPage): array
    {
        unset($filters['page'], $filters['per_page'], $filters['sort'], $filters['format']);
        return app(ReportingSnapshotCache::class)->remember(
            'operations-sales-v2',
            'authorized-administrators',
            [...$filters, 'group' => $group, 'group_page' => $groupPage],
            fn () => $this->calculate($q, $group, $groupPage)
        );
    }

    private function calculate(Builder $q, string $group, int $groupPage): array
    {
        $eligible = self::ELIGIBLE;
        $stats = (clone $q)->where('status', 'success')->selectRaw("COUNT(*) as candidates,
            COALESCE(SUM(CASE WHEN $eligible THEN 1 ELSE 0 END),0) as total,
            COALESCE(SUM(CASE WHEN $eligible THEN 0 ELSE amount END),0) as excluded_amount,
            COALESCE(SUM(CASE WHEN $eligible THEN ".$this->signed('amount')." ELSE 0 END),0) as sales,
            COALESCE(SUM(CASE WHEN $eligible THEN ".$this->signed('vat_amount')." ELSE 0 END),0) as vat,
            COALESCE(SUM(CASE WHEN $eligible THEN ".$this->signed('vatable_sales')." ELSE 0 END),0) as taxable")->first();
        $included = $this->included($q);
        $aggregate = 'COUNT(*) as count,COALESCE(SUM('.$this->signed('amount').'),0) as amount,COALESCE(SUM('.$this->signed('vat_amount').'),0) as vat,COALESCE(SUM('.$this->signed('vatable_sales').'),0) as taxable';
        $groupExpression = match ($group) {
            'monthly' => 'SUBSTR(created_at,1,7)', 'business' => "COALESCE(NULLIF(business,''),'Unspecified')",
            'type' => "COALESCE(NULLIF(document_type,''),'Unspecified')", 'payment' => "COALESCE(NULLIF(payment_method,''),'Unspecified')",
            'branch' => "COALESCE(NULLIF(branch,''),'Unspecified')", default => 'SUBSTR(created_at,1,10)',
        };
        // Group by the projected alias: MariaDB strict mode rejects repeating a
        // COALESCE/NULLIF expression inside a derived aggregate's GROUP BY.
        $groupQuery = (clone $included)->selectRaw("$groupExpression as label,$aggregate")->groupBy('label');
        $groupTotal = DB::query()->fromSub(clone $groupQuery, 'groups')->count();
        $groupLast = max(1, (int) ceil($groupTotal / 50));
        $groupPage = min($groupPage, $groupLast);
        $groups = $groupQuery->orderBy('label', in_array($group, ['daily','monthly']) ? 'desc' : 'asc')->offset(($groupPage - 1) * 50)->limit(50)->get();
        $trend = (clone $included)->selectRaw('SUBSTR(created_at,1,10) as date,SUM('.$this->signed('amount').') as amount')->groupByRaw('SUBSTR(created_at,1,10)')->orderByDesc('date')->limit(7)->get()->reverse()->values();
        $breakdown = (clone $included)->selectRaw('document_type as name,SUM('.$this->signed('amount').') as value')->groupBy('document_type')->orderByDesc('value')->limit(50)->get();
        $top = (clone $included)->selectRaw('business as label,SUM('.$this->signed('amount').') as amount')->groupBy('business')->orderByDesc('amount')->limit(5)->get();
        return ['summary' => ['total' => (int) $stats->total, 'sales' => round($stats->sales, 2), 'vat' => round($stats->vat, 2), 'taxable' => round($stats->taxable, 2),
            'average' => $stats->total ? round($stats->sales / $stats->total, 2) : 0, 'excluded_count' => (int) $stats->candidates - (int) $stats->total, 'excluded_amount' => round($stats->excluded_amount, 2)],
            'groups' => $groups, 'groups_pagination' => ['page' => $groupPage, 'total' => $groupTotal, 'last_page' => $groupLast, 'per_page' => 50],
            'trend' => $trend, 'breakdown' => $breakdown, 'top_businesses' => $top];
    }

    public function page(Builder $q, array $v, int $total): array
    {
        $size = (int) ($v['per_page'] ?? 10);
        $last = max(1, (int) ceil($total / $size));
        $page = min((int) ($v['page'] ?? 1), $last);
        // Sort narrow IDs, then hydrate only the requested page, including deep pagination.
        $offset = ($page - 1) * $size;
        $take = min($size, max(0, $total - $offset));
        $readSort = $v;
        if ($offset > $total / 2) {
            $readSort['sort'] = match($v['sort'] ?? 'newest') {
                'oldest' => 'newest','amount_asc' => 'amount_desc','amount_desc' => 'amount_asc',default => 'oldest'
            };
            $offset = max(0, $total - $offset - $take);
        }
        $ids = $this->sort($this->included($q), $readSort)->select('projection_id')->offset($offset)->limit($take)->pluck('projection_id');
        $rows = $ids->isEmpty() ? collect() : $this->sort((clone $q)->whereIn('projection_id', $ids), $v)->get();
        return ['rows' => $rows, 'page' => $page, 'last_page' => $last];
    }
}
