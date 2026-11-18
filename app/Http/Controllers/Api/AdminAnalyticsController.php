<?php

namespace App\Http\Controllers\Api;

use App\Services\{AdminAnalytics, BirCompliance, LedgerProjection, ReportCsv, ReportingSnapshotCache, TransactionTransmissionQuery};
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminAnalyticsController extends TransactionWorkspaceController
{
    protected function filterableLedger()
    {
        $q = DB::table('ledger_documents')->where('identity_rank', 1)->where('is_draft', false);
        if (request()->input('bir', 'all') !== 'all') {
            return DB::query()->fromSub(app(TransactionTransmissionQuery::class)->enrich($q), 'ledger');
        }
        return $q;
    }

    private function prepare(Request $request): array
    {
        $this->actor($request);
        $request->validate([
            'from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from',
            'group' => ['nullable', Rule::in(array_keys(AdminAnalytics::GROUPS))],
            'seller_vat' => 'nullable|in:all,vat,non_vat', 'industry' => 'nullable|string|max:255',
            'branch_code' => 'nullable|string|max:20',
        ]);
        abort_if(CarbonImmutable::parse($request->input('from'))->diffInDays(CarbonImmutable::parse($request->input('to'))) > 365, 422, 'Choose a reporting period of up to 366 days. Export separate years for longer history.');
        $projection = app(LedgerProjection::class);
        abort_unless($projection->ready(), 503, 'The reporting index is not ready. Please retry shortly.');
        $projection->catchUp();
        [$q, $filters] = $this->filtered($request);
        $q = $this->category($q, $filters);
        abort_if((clone $q)->whereNull('operations_sales_valid')->exists(), 503, 'Sales validation is still being indexed. Retry after indexing completes.');
        return [$q, $filters, $request->input('group', 'daily')];
    }

    public function index(Request $request)
    {
        [$q, $filters, $group] = $this->prepare($request);
        $service = app(AdminAnalytics::class);
        return DB::transaction(function () use ($q, $filters, $group, $service, $request) {
            $summary = app(ReportingSnapshotCache::class)->remember('admin-analytics-v2', 'authorized-administrators', array_diff_key($filters, array_flip(['page', 'per_page', 'sort', 'format'])), fn () => $service->summary($q, $filters));
            $schedule = app(ReportingSnapshotCache::class)->remember('admin-analytics-groups-v2', 'authorized-administrators', [...$filters, 'group' => $group], function () use ($q, $group, $service, $request) {
                $groups = $service->groups($q, $group);
                $total = DB::query()->fromSub(clone $groups, 'groups')->count();
                $size = $request->integer('per_page', 20);
                $last = max(1, (int) ceil($total / $size));
                $page = min($request->integer('page', 1), $last);
                $rows = $groups->orderBy('label')->orderBy('merchant_id')->orderBy('detail')->offset(($page - 1) * $size)->limit($size)->get()->map($service->row(...))->all();
                return ['data' => $rows, 'pagination' => ['page' => $page, 'per_page' => $size, 'total' => $total, 'last_page' => $last]];
            });
            return response()->json([
                ...$summary, ...$schedule,
                'group' => $group, 'filters' => $filters, 'options' => $service->options($filters['merchant'] ?? null),
                'refreshed_at' => now()->toIso8601String(), 'currency' => 'PHP', 'environment' => 'live',
            ])->header('Cache-Control', 'private, no-store');
        });
    }

    public function export(Request $request)
    {
        [$q, $filters, $group] = $this->prepare($request);
        $service = app(AdminAnalytics::class);
        $rows = $service->groups($q, $group);
        $count = DB::query()->fromSub(clone $rows, 'groups')->count();
        ReportCsv::limit($request, $count);
        app(BirCompliance::class)->audit('analytics.report.exported', $group, (string) $this->actor($request)->id, ['filters' => $filters, 'groups' => $count]);
        $headers = ['Group', 'Seller ID', 'Seller Registered Name', 'Seller TIN', 'Details / Buyer TIN', 'Document Records', 'Included Sales Documents', 'Excluded Documents', 'Net Document Amount PHP', 'VATable Sales PHP', 'Output VAT PHP', 'VAT Exempt Sales PHP', 'Zero Rated Sales PHP', 'Non VAT Sales PHP', 'SSPT Sales PHP', 'Manual Discount PHP', 'Statutory Discount PHP', 'VAT Relief PHP'];
        $keys = ['label', 'merchant_id', 'seller_name', 'seller_tin', 'detail', 'records', 'included', 'excluded', ...array_keys(AdminAnalytics::MONEY)];
        return ReportCsv::download($headers, (function () use ($rows, $service, $keys) {
            foreach ($rows->orderBy('label')->orderBy('merchant_id')->orderBy('detail')->lazy(500) as $row) {
                $data = $service->row($row);
                yield array_map(fn ($key) => $data[$key], $keys);
            }
        })(), 'NUERS-analytics-'.$group.'-'.now()->format('Ymd-His').'.csv');
    }
}
