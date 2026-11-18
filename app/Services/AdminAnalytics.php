<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** SQL-only aggregates over canonical documents; financial totals use validated sales evidence. */
class AdminAnalytics
{
    public const GROUPS = ['daily' => 'Daily sales', 'monthly' => 'Monthly sales', 'business' => 'By merchant', 'branch' => 'By branch', 'buyer' => 'Monthly buyer schedule', 'category' => 'Sale categories', 'document' => 'Document types', 'payment' => 'Payment methods', 'region' => 'Regions', 'rdo' => 'RDOs'];
    public const MONEY = ['sales' => 'amount', 'vatable' => 'vatable_sales', 'vat' => 'vat_amount', 'exempt' => '(vat_exempt_sales - non_vat_centavos / 100.0)', 'zero' => 'zero_rated_sales', 'non_vat' => '(non_vat_centavos / 100.0)', 'sspt' => 'sspt_sales', 'manual_discount' => 'manual_discount_amount', 'statutory_discount' => 'statutory_discount_amount', 'vat_relief' => 'vat_relief_amount'];
    private const INCLUDED = "status='success' AND operations_sales_valid=1 AND identity_count=1";

    private function aggregate(): string
    {
        $eligible = self::INCLUDED;
        $sql = "COUNT(*) as records, COALESCE(SUM(CASE WHEN $eligible THEN 1 ELSE 0 END),0) as included";
        foreach (self::MONEY as $name => $column) {
            $sql .= ",COALESCE(SUM(CASE WHEN $eligible THEN CASE WHEN document_family='credit' THEN -ABS($column) ELSE $column END ELSE 0 END),0) as $name";
        }
        return $sql;
    }

    public function row(object $row): array
    {
        $r = (array) $row;
        foreach (self::MONEY as $name => $column) {
            $r[$name] = round((float) $r[$name], 2);
        }
        $r['records'] = (int) $r['records'];
        $r['included'] = (int) $r['included'];
        $r['excluded'] = $r['records'] - $r['included'];
        return $r;
    }

    public function groups(Builder $q, string $group): Builder
    {
        $label = match ($group) {
            'monthly', 'buyer' => 'SUBSTR(business_day,1,7)', 'business' => 'business', 'branch' => 'branch',
            'category' => 'sale_category', 'document' => 'document_type', 'payment' => 'payment_method',
            'region' => 'region', 'rdo' => 'rdo_code', default => 'business_day',
        };
        $seller = in_array($group, ['business', 'branch', 'buyer'], true);
        $columns = ["COALESCE(NULLIF($label,''),'Not recorded') as label",
            ($seller ? 'merchant_id' : "''").' as merchant_id', ($seller ? 'business' : "''").' as seller_name',
            ($seller ? 'tin' : "''").' as seller_tin'];
        if ($group === 'buyer') {
            // Keep merchants, buyer identities and each month separate; never merge across sellers.
            $columns[0] = "COALESCE(NULLIF(customer_name,''),'Not recorded') as label";
            $concat = DB::getDriverName() === 'sqlite' ? "COALESCE(customer_tin,'') || ' · ' || SUBSTR(business_day,1,7)" : "CONCAT(COALESCE(customer_tin,''),' · ',SUBSTR(business_day,1,7))";
            $columns[] = "$concat as detail";
        } else {
            $columns[] = ($group === 'branch' ? "COALESCE(branch_code,'Not recorded')" : "''").' as detail';
        }
        // Do not group all-merchant schedules by the underlying merchant_id column.
        $dimensions = $seller ? ['label', 'merchant_id', 'seller_name', 'seller_tin', 'detail'] : ['label'];
        return (clone $q)->selectRaw(implode(',', $columns).','.$this->aggregate())->groupBy($dimensions);
    }

    public function summary(Builder $q, array $filters): array
    {
        $extra = ",COUNT(DISTINCT merchant_id) as merchants, MIN(business_day) as first_business_day, MAX(business_day) as last_business_day";
        foreach (['success', 'failed', 'pending', 'cancelled'] as $status) {
            $extra .= ",COALESCE(SUM(CASE WHEN status='$status' THEN 1 ELSE 0 END),0) as $status";
        }
        $extra .= ",COALESCE(SUM(CASE WHEN identity_count>1 THEN 1 ELSE 0 END),0) as conflicts, COALESCE(SUM(CASE WHEN customer_tin IS NULL OR customer_tin='' THEN 1 ELSE 0 END),0) as missing_buyer_tin";
        $totals = $this->row((clone $q)->selectRaw($this->aggregate().$extra)->first());
        foreach (['merchants', 'success', 'failed', 'pending', 'cancelled', 'conflicts', 'missing_buyer_tin'] as $key) {
            $totals[$key] = (int) $totals[$key];
        }
        $days = \Carbon\CarbonImmutable::parse($totals['first_business_day'] ?: $filters['from'])->diffInDays(\Carbon\CarbonImmutable::parse($totals['last_business_day'] ?: $filters['to']));
        $trendGroup = $days > 92 ? 'monthly' : 'daily';
        $collect = fn ($group, $limit, $order = 'label') => $this->groups($q, $group)->orderBy($order, $order === 'label' ? 'asc' : 'desc')->limit($limit)->get()->map($this->row(...))->all();
        $trend = $this->groups($q, $trendGroup)->orderBy('label', 'desc')->limit(367)->get()->map($this->row(...))->all();
        $truncated = count($trend) > 366;
        $trend = array_reverse(array_slice($trend, 0, 366));
        return ['totals' => $totals, 'trend' => $trend, 'trend_interval' => $trendGroup, 'trend_truncated' => $truncated,
            'categories' => $collect('category', 20), 'top_merchants' => $collect('business', 6, 'sales'),
            'document_types' => $collect('document', 50), 'generated_at' => now()->toIso8601String(),
            'revision' => (int) DB::table('ledger_projection_state')->where('id', 1)->value('revision'),
            'notice' => 'Internal NUERS sales analytics. Only validated invoices and linked adjustments contribute to sales; credits reduce totals. Drafts are omitted. Payment copies, failed/cancelled documents, duplicate identities and invalid evidence do not add sales. Dates use Philippine business dates; received-date filters affect selection only. BIR filing and acceptance remain separate.'];
    }

    public function options(?string $merchant): array
    {
        return app(ReportingSnapshotCache::class)->remember('analytics-options-v1', 'authorized-administrators', ['merchant' => $merchant], function () use ($merchant) {
            $q = DB::table('ledger_documents')->where('identity_rank', 1);
            if ($merchant && $merchant !== 'all') {
                $q->where('merchant_id', $merchant);
            }
            $options = [];
            foreach (['branches' => 'branch', 'branch_codes' => 'branch_code', 'documents' => 'document_type', 'payments' => 'payment_method', 'regions' => 'region', 'rdos' => 'rdo_code', 'channels' => 'channel'] as $key => $column) {
                $options[$key] = (clone $q)->whereNotNull($column)->where($column, '<>', '')->distinct()->orderBy($column)->limit(200)->pluck($column)->all();
            }
            $options['merchants'] = DB::table('merchants')->orderBy('business_name')->limit(200)->get(['id', 'business_name', 'tin']);
            $options['industries'] = DB::table('merchants')->whereNotNull('sector')->distinct()->orderBy('sector')->limit(200)->pluck('sector')->all();
            return $options;
        });
    }
}
