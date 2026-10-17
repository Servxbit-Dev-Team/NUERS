<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApiAccess;
use App\Services\BirCompliance;
use App\Services\MerchantSalesReport;
use App\Services\SalesReportExport;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class MerchantSalesReportController extends Controller
{
    private function filters(Request $request): array
    {
        $v = $request->validate(['from' => 'nullable|date_format:Y-m-d|after_or_equal:2000-01-01|before:2101-01-01', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:2000-01-01|before:2101-01-01', 'branch' => 'nullable|string|max:20', 'type' => 'nullable|string|max:60', 'search' => 'nullable|string|max:200',
            'customer_type' => ['nullable', Rule::in(['all', 'business', 'retail', 'walk_in', 'unclassified'])], 'bir' => ['nullable', Rule::in(['all', 'acknowledged', 'queued', 'needs_review', 'blocked', 'not_verified'])], 'view' => ['nullable', Rule::in(['sales', 'review'])], 'page' => 'sometimes|integer|min:1|max:100000', 'per_page' => 'sometimes|integer|in:10,25,50,100', 'format' => ['sometimes', Rule::in(['csv', 'xlsx', 'pdf'])]]);
        $today = CarbonImmutable::now('Asia/Manila');
        $v['from'] = $v['from'] ?? $today->startOfMonth()->toDateString();
        $v['to'] = $v['to'] ?? $today->toDateString();
        abort_if($v['to'] < $v['from'], 422, 'Date To must be on or after Date From.');
        abort_if(CarbonImmutable::parse($v['from'])->diffInDays(CarbonImmutable::parse($v['to'])) > 366, 422, 'Choose a report period of no more than 367 days.');

        return array_intersect_key($v, array_flip(['from', 'to', 'branch', 'type', 'search', 'customer_type', 'bir']));
    }

    public function show(Request $request)
    {
        $merchant = app(ApiAccess::class)->merchant($request);
        $r = app(MerchantSalesReport::class)->generate($merchant, $this->filters($request));
        $rows = $request->query('view') === 'review' ? $r['excluded'] : $r['included'];
        $size = (int) $request->query('per_page', 10);
        $last = max(1, (int) ceil(count($rows) / $size));
        $page = min((int) $request->query('page', 1), $last);
        unset($r['included'], $r['excluded']);

        return response()->json([...$r, 'data' => array_slice($rows, ($page - 1) * $size, $size), 'pagination' => ['page' => $page, 'per_page' => $size, 'total' => count($rows), 'last_page' => $last]])->header('Cache-Control', 'private, no-store');
    }

    public function export(Request $request)
    {
        $merchant = app(ApiAccess::class)->merchant($request);
        $filters = $this->filters($request);
        // A database-backed fallback keeps limits effective even when CACHE_STORE=array is configured.
        $store = Cache::store(config('cache.default') === 'array' ? 'database' : config('cache.default'));
        $budget = 'merchant-sales-export:'.hash('sha256', (string) $merchant->id);
        $limiter = new RateLimiter($store);
        abort_if($limiter->tooManyAttempts($budget, 5), 429, 'Export limit reached. Please retry in a minute.');
        $lock = $store->lock($budget.':lock', 60);
        abort_unless($lock->get(), 429, 'Another sales report export is running for this business. Please wait for it to finish.');
        try {
            $limiter->hit($budget, 60);
            $r = app(MerchantSalesReport::class)->generate($merchant, $filters);
            $format = $request->query('format', 'csv');
            abort_if($format === 'pdf' && count($r['included']) + count($r['excluded']) > 500, 422, 'PDF reports support 500 source documents. Narrow the filters or use Excel / CSV.');
            [$content, $mime] = app(SalesReportExport::class)->render($r, $format);
            app(BirCompliance::class)->audit('merchant.sales_report.exported', (string) $merchant->id, (string) app(ApiAccess::class)->user($request)->id, ['merchant_id' => $merchant->id, 'format' => $format, 'period' => [$r['filters']['from'], $r['filters']['to']], 'records' => $r['count'], 'excluded' => $r['excluded_count'], 'snapshot_hash' => $r['snapshot_hash']]);

            return response($content)->header('Content-Type', $mime)->header('Content-Disposition', 'attachment; filename="NUERS-Sales-Report-'.$r['filters']['from'].'-to-'.$r['filters']['to'].'.'.$format.'"')->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff')->header('X-NUERS-Snapshot', $r['snapshot_hash']);
        } finally {
            $lock->release();
        }
    }
}
