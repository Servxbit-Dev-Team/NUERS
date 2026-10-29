<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TransactionController extends Controller
{
    private const SUCCESS_STATUSES = ['completed', 'paid', 'issued', 'settled', 'verified'];
    private const PENDING_STATUSES = ['pending', 'queued', 'processing', 'under_review'];
    private const FAILURE_STATUSES = ['failed', 'voided', 'refunded', 'cancelled', 'canceled', 'rejected'];

    public function overview(Request $request): JsonResponse
    {
        ini_set('serialize_precision', '-1');

        if (! Schema::hasTable('merchant_transactions')) {
            return response()->json($this->emptyPayload($request));
        }

        $page = max(1, $request->integer('page', 1));
        $perPage = min(100, max(5, $request->integer('per_page', 15)));
        $filteredQuery = $this->applyFilters($this->baseTransactionQuery(), $request);
        $totalFiltered = (clone $filteredQuery)->count();

        $transactions = (clone $filteredQuery)
            ->select($this->transactionSelect())
            ->orderByDesc('t.created_at')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->map(fn ($row) => $this->transactionPayload($row))
            ->values();

        return response()->json([
            'meta' => [
                'generated_at' => now()->toISOString(),
                'source_tables' => [
                    'merchant_transactions',
                    'transaction_receipts',
                    'merchants',
                    'system_health_checks',
                ],
                'note' => 'All transaction counts, tax totals, records, filters, and latest feed rows are derived from the NUERS MySQL database.',
            ],
            'metrics' => $this->metrics($request),
            'throughput_series' => $this->throughputSeries($request),
            'live_feed' => $this->liveFeed($request, $totalFiltered),
            'transactions' => $transactions,
            'receipts' => $this->receiptsForTransactions($transactions),
            'tax_summary' => $this->taxSummary($request),
            'filters' => [
                'payment_methods' => $this->options('payment_method', ['cash', 'card', 'gcash', 'maya', 'bank_transfer', 'check']),
                'regions' => $this->options('region', ['NCR']),
                'statuses' => $this->options('status', ['completed', 'pending', 'voided', 'refunded']),
                'rdos' => $this->rdoOptions($request),
            ],
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $totalFiltered,
                'total_pages' => (int) max(1, ceil($totalFiltered / $perPage)),
            ],
        ]);
    }

    private function emptyPayload(Request $request): array
    {
        return [
            'meta' => [
                'generated_at' => now()->toISOString(),
                'source_tables' => [],
                'note' => 'merchant_transactions table is not available.',
            ],
            'metrics' => [
                'throughput_tps' => 0,
                'latest_transactions_60s' => 0,
                'avg_latency_ms' => null,
                'latency_source' => 'No system health telemetry table is available.',
                'queue_depth' => 0,
                'success_rate' => 0,
                'total_transactions' => 0,
                'pending_transactions' => 0,
                'successful_transactions' => 0,
                'failed_transactions' => 0,
                'receipts_issued' => 0,
                'throughput_window' => 'Last 30 minutes',
            ],
            'throughput_series' => [],
            'live_feed' => [],
            'transactions' => [],
            'receipts' => [],
            'tax_summary' => $this->blankTaxSummary(),
            'filters' => [
                'payment_methods' => ['all'],
                'regions' => ['all'],
                'statuses' => ['all'],
                'rdos' => [['code' => 'all', 'name' => 'All RDO Branches', 'label' => 'All RDO Branches']],
            ],
            'pagination' => [
                'page' => max(1, $request->integer('page', 1)),
                'per_page' => min(100, max(5, $request->integer('per_page', 15))),
                'total' => 0,
                'total_pages' => 1,
            ],
        ];
    }

    private function baseTransactionQuery(): Builder
    {
        return DB::table('merchant_transactions as t')
            ->leftJoin('merchants as m', 't.merchant_id', '=', 'm.id')
            ->leftJoin('transaction_receipts as r', 't.receipt_id', '=', 'r.id');
    }

    private function applyFilters(Builder $query, Request $request): Builder
    {
        $status = (string) $request->query('status', 'all');
        $region = (string) $request->query('region', 'all');
        $payment = (string) $request->query('payment', 'all');
        $rdo = (string) $request->query('rdo', 'all');
        $dateFrom = $this->dateBoundary($request->query('date_from'), 'start');
        $dateTo = $this->dateBoundary($request->query('date_to'), 'end');
        $search = trim((string) $request->query('search', ''));

        if ($status !== '' && $status !== 'all') {
            $query->where('t.status', $status);
        }

        if ($region !== '' && $region !== 'all') {
            $query->where('t.region', $region);
        }

        if ($payment !== '' && $payment !== 'all') {
            $query->where('t.payment_method', $payment);
        }

        if ($rdo !== '' && $rdo !== 'all') {
            $query->where(function (Builder $inner) use ($rdo) {
                $inner
                    ->where('t.rdo_code', $rdo)
                    ->orWhere('m.rdo_code', $rdo)
                    ->orWhere('r.rdo_code', $rdo);
            });
        }

        if ($dateFrom) {
            $query->where('t.created_at', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->where('t.created_at', '<=', $dateTo);
        }

        if ($search !== '') {
            $like = "%{$search}%";
            $query->where(function (Builder $inner) use ($like) {
                $inner
                    ->where('t.transaction_ref', 'like', $like)
                    ->orWhere('t.customer_name', 'like', $like)
                    ->orWhere('t.customer_tin', 'like', $like)
                    ->orWhere('t.branch', 'like', $like)
                    ->orWhere('t.rdo_code', 'like', $like)
                    ->orWhere('t.rdo_name', 'like', $like)
                    ->orWhere('m.business_name', 'like', $like)
                    ->orWhere('m.tin', 'like', $like)
                    ->orWhere('m.rdo_code', 'like', $like)
                    ->orWhere('m.rdo_name', 'like', $like)
                    ->orWhere('r.rdo_code', 'like', $like)
                    ->orWhere('r.rdo_name', 'like', $like)
                    ->orWhere('r.receipt_number', 'like', $like);
            });
        }

        return $query;
    }

    private function dateBoundary(mixed $value, string $edge): ?Carbon
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            $date = Carbon::parse($value, config('app.timezone'));

            return $edge === 'end' ? $date->endOfDay() : $date->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function transactionSelect(): array
    {
        return [
            't.id',
            't.merchant_id',
            't.merchant_ref_id',
            't.transaction_ref',
            't.amount',
            't.vat_amount',
            't.vatable_sales',
            't.net_amount',
            't.payment_method',
            't.region',
            't.branch',
            DB::raw("COALESCE(NULLIF(t.rdo_code, ''), NULLIF(r.rdo_code, ''), NULLIF(m.rdo_code, '')) as rdo_code"),
            DB::raw("COALESCE(NULLIF(t.rdo_name, ''), NULLIF(r.rdo_name, ''), NULLIF(m.rdo_name, '')) as rdo_name"),
            't.channel',
            't.transaction_type',
            't.customer_name',
            't.customer_tin',
            't.status',
            't.created_at',
            't.receipt_id',
            DB::raw("COALESCE(NULLIF(m.business_name, ''), NULLIF(r.merchant_name, ''), 'Unregistered Merchant') as merchant_name"),
            DB::raw("COALESCE(NULLIF(m.tin, ''), NULLIF(r.merchant_tin, ''), '') as merchant_tin"),
            'r.receipt_number',
        ];
    }

    private function transactionPayload(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'merchant_id' => (string) ($row->merchant_id ?? ''),
            'merchant_ref_id' => $row->merchant_ref_id,
            'transaction_ref' => $row->transaction_ref,
            'amount' => (float) $row->amount,
            'vat_amount' => (float) $row->vat_amount,
            'vatable_sales' => (float) $row->vatable_sales,
            'net_amount' => (float) $row->net_amount,
            'payment_method' => (string) ($row->payment_method ?: 'cash'),
            'region' => (string) ($row->region ?: 'Unclassified'),
            'branch' => $row->branch,
            'rdo_code' => $row->rdo_code,
            'rdo_name' => $row->rdo_name,
            'rdo_branch' => $this->formatRdoBranch($row->rdo_code, $row->rdo_name),
            'channel' => (string) ($row->channel ?: 'pos'),
            'transaction_type' => (string) ($row->transaction_type ?: 'sale'),
            'customer_name' => $row->customer_name,
            'customer_tin' => $row->customer_tin,
            'status' => (string) ($row->status ?: 'pending'),
            'created_at' => $row->created_at,
            'receipt_id' => $row->receipt_id,
            'merchant_name' => $row->merchant_name,
            'merchant_tin' => $row->merchant_tin,
            'receipt_number' => $row->receipt_number,
        ];
    }

    private function metrics(Request $request): array
    {
        $query = $this->applyFilters($this->baseTransactionQuery(), $request);
        $total = (clone $query)->count('t.id');
        $successful = (clone $query)->whereIn('t.status', self::SUCCESS_STATUSES)->count('t.id');
        $pending = (clone $query)->whereIn('t.status', self::PENDING_STATUSES)->count('t.id');
        $failed = (clone $query)->whereIn('t.status', self::FAILURE_STATUSES)->count('t.id');
        $lastMinute = (clone $query)
            ->where('t.created_at', '>=', now()->subMinute())
            ->count();
        $receipts = Schema::hasTable('transaction_receipts')
            ? (clone $query)->whereIn('r.status', ['issued', 'verified'])->whereNotNull('r.id')->count('r.id')
            : 0;

        return [
            'throughput_tps' => round($lastMinute / 60, 2),
            'latest_transactions_60s' => $lastMinute,
            'avg_latency_ms' => $this->avgLatency(),
            'latency_source' => Schema::hasTable('system_health_checks')
                ? 'Average of database-backed system health latency telemetry.'
                : 'No system health telemetry table is available.',
            'queue_depth' => $pending,
            'success_rate' => $total > 0 ? round(($successful / $total) * 100, 2) : 0,
            'total_transactions' => $total,
            'pending_transactions' => $pending,
            'successful_transactions' => $successful,
            'failed_transactions' => $failed,
            'receipts_issued' => $receipts,
            'throughput_window' => $this->throughputWindow($request)['label'],
        ];
    }

    private function avgLatency(): ?float
    {
        if (! Schema::hasTable('system_health_checks')) {
            return null;
        }

        $latency = DB::table('system_health_checks')
            ->whereNotNull('p95_latency_ms')
            ->avg('p95_latency_ms');

        return $latency === null ? null : round((float) $latency, 1);
    }

    private function throughputSeries(Request $request): array
    {
        $window = $this->throughputWindow($request);
        $start = $window['start'];
        $rows = $this->applyFilters($this->baseTransactionQuery(), $request)
            ->selectRaw("
                DATE_FORMAT(t.created_at, '%Y-%m-%d %H:%i:00') as bucket,
                COUNT(*) as transactions,
                SUM(CASE WHEN t.status IN ('completed', 'paid', 'issued', 'settled', 'verified') THEN 1 ELSE 0 END) as successful,
                SUM(CASE WHEN t.status IN ('pending', 'queued', 'processing', 'under_review') THEN 1 ELSE 0 END) as pending
            ")
            ->where('t.created_at', '>=', $start)
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');

        $series = [];

        for ($i = 0; $i < 30; $i++) {
            $time = $start->copy()->addMinutes($i);
            $key = $time->format('Y-m-d H:i:00');
            $row = $rows->get($key);
            $count = (int) ($row?->transactions ?? 0);

            $series[] = [
                'time' => $time->format('H:i'),
                'transactions' => $count,
                'successful' => (int) ($row?->successful ?? 0),
                'pending' => (int) ($row?->pending ?? 0),
                'tps' => round($count / 60, 2),
            ];
        }

        return $series;
    }

    private function throughputWindow(Request $request): array
    {
        $currentStart = Carbon::now()->subMinutes(29)->startOfMinute();
        $base = $this->applyFilters($this->baseTransactionQuery(), $request);
        $hasCurrentTraffic = (clone $base)
            ->where('t.created_at', '>=', $currentStart)
            ->exists();

        if ($hasCurrentTraffic) {
            return [
                'start' => $currentStart,
                'label' => 'Last 30 minutes',
            ];
        }

        $latest = (clone $base)->max('t.created_at');

        if ($latest) {
            $anchor = Carbon::parse($latest)->startOfMinute();

            return [
                'start' => $anchor->copy()->subMinutes(29),
                'label' => '30-minute window ending at latest transaction',
            ];
        }

        return [
            'start' => $currentStart,
            'label' => 'Last 30 minutes',
        ];
    }

    private function liveFeed(Request $request, int $totalFiltered): array
    {
        $rdo = (string) $request->query('rdo', 'all');
        $limit = $rdo === 'all'
            ? min(500, max(20, $totalFiltered))
            : 20;

        return $this->applyFilters($this->baseTransactionQuery(), $request)
            ->select($this->transactionSelect())
            ->orderByDesc('t.created_at')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'id' => (string) ($row->transaction_ref ?: $row->id),
                'merchant' => $row->merchant_name,
                'amount' => (float) $row->amount,
                'tax' => (float) $row->vat_amount,
                'region' => (string) ($row->region ?: 'Unclassified'),
                'rdo_code' => $row->rdo_code,
                'rdo_name' => $row->rdo_name,
                'rdo_branch' => $this->formatRdoBranch($row->rdo_code, $row->rdo_name),
                'time' => Carbon::parse($row->created_at)->timezone(config('app.timezone'))->format('H:i:s'),
                'method' => (string) ($row->payment_method ?: 'cash'),
                'status' => (string) ($row->status ?: 'pending'),
                'receipt_number' => $row->receipt_number,
            ])
            ->values()
            ->toArray();
    }

    private function receiptsForTransactions($transactions): array
    {
        if (! Schema::hasTable('transaction_receipts')) {
            return [];
        }

        $receiptIds = collect($transactions)->pluck('receipt_id')->filter()->unique()->values();
        $transactionIds = collect($transactions)->pluck('id')->filter()->unique()->values();

        $query = DB::table('transaction_receipts');

        if ($receiptIds->isNotEmpty() || $transactionIds->isNotEmpty()) {
            $query->where(function (Builder $inner) use ($receiptIds, $transactionIds) {
                if ($receiptIds->isNotEmpty()) {
                    $inner->whereIn('id', $receiptIds);
                }

                if ($transactionIds->isNotEmpty()) {
                    $method = $receiptIds->isNotEmpty() ? 'orWhereIn' : 'whereIn';
                    $inner->{$method}('transaction_id', $transactionIds);
                }
            });
        }

        return $query
            ->orderByRaw('COALESCE(issued_at, created_at) DESC')
            ->limit(50)
            ->get()
            ->map(fn ($row) => $this->receiptPayload($row))
            ->values()
            ->toArray();
    }

    private function receiptPayload(object $row): array
    {
        $items = json_decode((string) $row->items, true);

        if (! is_array($items)) {
            $items = [];
        }

        return [
            'id' => (string) $row->id,
            'transaction_id' => $row->transaction_id,
            'merchant_id' => $row->merchant_id,
            'receipt_number' => (string) $row->receipt_number,
            'series_number' => (string) ($row->series_number ?? ''),
            'bir_accreditation' => (string) ($row->bir_accreditation ?? ''),
            'receipt_type' => (string) ($row->receipt_type ?? 'official_receipt'),
            'merchant_name' => (string) $row->merchant_name,
            'merchant_tin' => (string) $row->merchant_tin,
            'rdo_code' => $row->rdo_code ?? null,
            'rdo_name' => $row->rdo_name ?? null,
            'rdo_branch' => $this->formatRdoBranch($row->rdo_code ?? null, $row->rdo_name ?? null),
            'merchant_address' => (string) ($row->merchant_address ?? ''),
            'merchant_vat_reg' => (string) ($row->merchant_vat_reg ?? ''),
            'buyer_name' => $row->buyer_name,
            'buyer_tin' => $row->buyer_tin,
            'gross_amount' => (float) $row->gross_amount,
            'discount_amount' => (float) $row->discount_amount,
            'vatable_sales' => (float) $row->vatable_sales,
            'vat_exempt_sales' => (float) $row->vat_exempt_sales,
            'zero_rated_sales' => (float) $row->zero_rated_sales,
            'vat_amount' => (float) $row->vat_amount,
            'total_due' => (float) $row->total_due,
            'items' => $this->receiptItems($items, $row),
            'status' => (string) ($row->status ?? 'issued'),
            'issued_at' => $row->issued_at ?: $row->created_at,
        ];
    }

    private function receiptItems(array $items, object $row): array
    {
        if ($items === []) {
            $items = [[
                'description' => (string) ($row->receipt_type ?? 'Transaction item'),
            ]];
        }

        $singleItem = count($items) === 1;

        return collect($items)
            ->filter(fn ($item) => is_array($item))
            ->values()
            ->map(function (array $item) use ($row, $singleItem) {
                $quantity = $this->numberFrom($item['quantity'] ?? $item['qty'] ?? 1, 1.0);
                $unitPrice = $this->numberFrom($item['unit_price'] ?? $item['price'] ?? null, null);
                $amount = $this->numberFrom(
                    $item['amount']
                        ?? $item['total']
                        ?? $item['line_total']
                        ?? $item['total_amount']
                        ?? $item['gross_amount']
                        ?? null,
                    null
                );
                $receiptTotal = $this->numberFrom($row->total_due ?? $row->gross_amount ?? null, 0.0);

                if ($amount === null && $unitPrice !== null) {
                    $amount = $quantity * $unitPrice;
                }

                if (($amount === null || ($singleItem && $amount <= 0 && $receiptTotal > 0)) && $singleItem) {
                    $amount = $receiptTotal;
                }

                $vat = $this->numberFrom($item['vat'] ?? $item['vat_amount'] ?? $item['tax'] ?? $item['tax_amount'] ?? null, null);
                $receiptVat = $this->numberFrom($row->vat_amount ?? null, 0.0);

                if (($vat === null || ($singleItem && $vat <= 0 && $receiptVat > 0)) && $singleItem) {
                    $vat = $receiptVat;
                }

                return array_merge($item, [
                    'description' => (string) ($item['description'] ?? $item['name'] ?? 'Transaction item'),
                    'quantity' => $quantity,
                    'qty' => $quantity,
                    'unit_price' => round($unitPrice ?? $amount ?? 0, 2),
                    'vat' => round($vat ?? 0, 2),
                    'amount' => round($amount ?? 0, 2),
                ]);
            })
            ->all();
    }

    private function numberFrom(mixed $value, ?float $fallback): ?float
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        if (! is_string($value)) {
            return $fallback;
        }

        $trimmed = trim($value);

        if ($trimmed === '' || strtolower($trimmed) === 'nan') {
            return $fallback;
        }

        $normalized = preg_replace('/[^\d.\-]/', '', $trimmed);

        if ($normalized === null || $normalized === '' || $normalized === '-' || $normalized === '.') {
            return $fallback;
        }

        return is_numeric($normalized) ? (float) $normalized : $fallback;
    }

    private function taxSummary(Request $request): array
    {
        $query = $this->applyFilters($this->baseTransactionQuery(), $request)
            ->whereIn('t.status', self::SUCCESS_STATUSES);

        $summary = (clone $query)
            ->selectRaw('
                COUNT(*) as completed_transactions,
                COALESCE(SUM(t.amount), 0) as total_gross,
                COALESCE(SUM(t.vat_amount), 0) as total_vat,
                COALESCE(SUM(t.vatable_sales), 0) as total_vatable,
                COALESCE(SUM(t.net_amount), 0) as total_net
            ')
            ->first();

        $gross = (float) ($summary?->total_gross ?? 0);
        $vat = (float) ($summary?->total_vat ?? 0);

        return [
            'completed_transactions' => (int) ($summary?->completed_transactions ?? 0),
            'total_gross' => $gross,
            'total_vat' => $vat,
            'total_vatable' => (float) ($summary?->total_vatable ?? 0),
            'total_net' => (float) ($summary?->total_net ?? 0),
            'vat_rate' => $gross > 0 ? round(($vat / $gross) * 100, 2) : 0,
            'by_region' => $this->taxByRegion($query),
            'by_payment' => $this->taxByPayment($query),
        ];
    }

    private function blankTaxSummary(): array
    {
        return [
            'completed_transactions' => 0,
            'total_gross' => 0,
            'total_vat' => 0,
            'total_vatable' => 0,
            'total_net' => 0,
            'vat_rate' => 0,
            'by_region' => [],
            'by_payment' => [],
        ];
    }

    private function taxByRegion(Builder $query): array
    {
        return (clone $query)
            ->selectRaw("COALESCE(NULLIF(t.region, ''), 'Unclassified') as region, COUNT(*) as count, COALESCE(SUM(t.amount), 0) as gross, COALESCE(SUM(t.vat_amount), 0) as vat")
            ->groupBy('region')
            ->orderByDesc('vat')
            ->get()
            ->map(fn ($row) => [
                'region' => (string) $row->region,
                'count' => (int) $row->count,
                'gross' => (float) $row->gross,
                'vat' => (float) $row->vat,
            ])
            ->values()
            ->toArray();
    }

    private function taxByPayment(Builder $query): array
    {
        return (clone $query)
            ->selectRaw("COALESCE(NULLIF(t.payment_method, ''), 'cash') as method, COUNT(*) as count, COALESCE(SUM(t.amount), 0) as amount")
            ->groupBy('method')
            ->orderByDesc('amount')
            ->get()
            ->map(fn ($row) => [
                'method' => (string) $row->method,
                'count' => (int) $row->count,
                'amount' => (float) $row->amount,
            ])
            ->values()
            ->toArray();
    }

    private function options(string $column, array $fallback): array
    {
        $values = DB::table('merchant_transactions')
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->map(fn ($value) => (string) $value)
            ->filter()
            ->values()
            ->all();

        return array_values(array_unique(['all', ...($values ?: $fallback)]));
    }

    private function rdoOptions(Request $request): array
    {
        $region = (string) $request->query('region', 'all');
        $query = $this->baseTransactionQuery()
            ->selectRaw("
                COALESCE(NULLIF(t.rdo_code, ''), NULLIF(r.rdo_code, ''), NULLIF(m.rdo_code, '')) as code,
                COALESCE(NULLIF(t.rdo_name, ''), NULLIF(r.rdo_name, ''), NULLIF(m.rdo_name, '')) as name
            ");

        if ($region !== '' && $region !== 'all') {
            $query->whereRaw("COALESCE(NULLIF(t.region, ''), NULLIF(m.region, ''), 'Unclassified') = ?", [$region]);
        }

        $rows = $query
            ->where(function (Builder $query) {
                $query
                    ->whereNotNull('t.rdo_code')
                    ->orWhereNotNull('r.rdo_code')
                    ->orWhereNotNull('m.rdo_code');
            })
            ->groupBy('code', 'name')
            ->orderBy('code')
            ->get()
            ->filter(fn ($row) => trim((string) $row->code) !== '')
            ->map(fn ($row) => [
                'code' => (string) $row->code,
                'name' => (string) ($row->name ?: "RDO {$row->code}"),
                'label' => $this->formatRdoBranch($row->code, $row->name),
            ])
            ->values()
            ->all();

        return [
            ['code' => 'all', 'name' => 'All RDO Branches', 'label' => 'All RDO Branches'],
            ...$rows,
        ];
    }

    private function formatRdoBranch(?string $code, ?string $name): string
    {
        $code = trim((string) $code);
        $name = trim((string) $name);

        if ($code !== '' && $name !== '') {
            return "{$code} · {$name}";
        }

        if ($code !== '') {
            return "RDO {$code}";
        }

        return $name !== '' ? $name : 'Unassigned RDO';
    }
}
