<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardController extends Controller
{
    private const BUSINESS_POSTED_TRANSACTION_STATUSES = ['completed', 'paid', 'issued', 'settled', 'verified'];
    private const BUSINESS_POSTED_INVOICE_STATUSES = ['validated', 'sent', 'paid', 'partially_paid', 'overdue', 'issued'];

    private array $tableExistsCache = [];

    public function show(Request $request, string $portal): JsonResponse
    {
        $allowed = ['super-admin', 'bir', 'merchant', 'client'];
        abort_unless(in_array($portal, $allowed, true), 404, 'Unknown dashboard portal.');
        ini_set('serialize_precision', '-1');

        if ($portal === 'super-admin') {
            return response()->json($this->superAdminPayload());
        }

        if ($portal === 'bir') {
            return response()
                ->json($this->birPayload($request))
                ->header('Cache-Control', 'private, max-age=15');
        }

        if ($portal === 'merchant') {
            return response()
                ->json($this->businessAccountPayload($request))
                ->header('Cache-Control', 'private, max-age=15');
        }

        if ($portal === 'client') {
            return response()
                ->json($this->clientPayload($request))
                ->header('Cache-Control', 'private, no-store');
        }

        return response()->json($this->storedDashboardPayload($portal));
    }

    private function storedDashboardPayload(string $portal): array
    {
        $kpis = DB::table('dashboard_kpis')
            ->where('portal', $portal)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($row) => [
                'key' => $row->widget_key,
                'label' => $row->label,
                'value' => $row->value,
                'subtext' => $row->subtext,
                'icon' => $row->icon,
                'accent' => $row->accent,
            ])
            ->values();

        $series = DB::table('dashboard_series_points')
            ->where('portal', $portal)
            ->orderBy('series_key')
            ->orderBy('sort_order')
            ->get()
            ->groupBy('series_key')
            ->map(fn ($points) => $points->map(fn ($row) => [
                'label' => $row->label,
                ...((array) json_decode($row->values, true)),
            ])->values())
            ->toArray();

        $lists = $this->storedLists($portal);

        return [
            'portal' => $portal,
            'kpis' => $kpis,
            'series' => $series,
            'lists' => $lists,
        ];
    }

    private function clientPayload(Request $request): array
    {
        $payload = $this->storedDashboardPayload('client');
        $payload['lists']['notifications'] = $this->clientNotificationRows($request);

        return $payload;
    }

    private function clientNotificationRows(Request $request): array
    {
        $user = $this->userFromRequest($request);

        if (! $user) {
            return [];
        }

        $profile = $this->tableExists('profiles')
            ? DB::table('profiles')->where('id', $user->id)->first()
            : null;

        $account = null;
        if ($this->tableExists('client_accounts')) {
            $accountQuery = DB::table('client_accounts')->where('user_id', $user->id);

            if (! empty($user->email)) {
                $accountQuery;
            }

            $account = $accountQuery->first();
        }

        $rows = collect();
        $accountStatus = strtolower((string) ($account?->status ?? ''));
        $tinValues = $this->tinVariants(app(\App\Services\ApiAccess::class)->taxpayerTin($user));

        if ($account && $accountStatus !== 'active') {
            $rows->push($this->item(
                'Account pending activation',
                'TIN barcode and scan posting are hidden until this client account is active.',
                $this->headline((string) $account->status),
                'Account',
                null,
                null,
                [
                    'account_id' => $account->id,
                    'account_number' => $account->account_number,
                    'user_id' => (int) $user->id,
                ],
            ));
        }

        if ($account && $accountStatus === 'active' && ! empty($tinValues)) {
            $rows->push($this->item(
                'TIN barcode active',
                'Your active client account can receive business transaction scans matched to your bound TIN.',
                'Active',
                'Security',
                null,
                null,
                [
                    'account_id' => $account->id,
                    'account_number' => $account->account_number,
                    'tin' => reset($tinValues),
                    'user_id' => (int) $user->id,
                ],
            ));
        }

        if (! empty($tinValues) && $this->tableExists('transaction_receipts')) {
            DB::table('transaction_receipts')
                ->whereIn('buyer_tin', $tinValues)
                ->orderByDesc(DB::raw('COALESCE(issued_at, created_at)'))
                ->limit(4)
                ->get()
                ->each(function ($receipt) use ($rows, $user, $account) {
                    $rows->push($this->item(
                        'Receipt '.$receipt->receipt_number,
                        "{$receipt->merchant_name} issued {$receipt->receipt_number} to your account.",
                        $this->headline((string) $receipt->status),
                        'Receipt',
                        (float) $receipt->total_due,
                        null,
                        [
                            'receipt_id' => $receipt->id,
                            'receipt_number' => $receipt->receipt_number,
                            'merchant_name' => $receipt->merchant_name,
                            'account_id' => $account?->id,
                            'user_id' => (int) $user->id,
                        ],
                    ));
                });
        }

        if (! empty($tinValues) && $this->tableExists('merchant_transactions')) {
            $query = DB::table('merchant_transactions')
                ->whereIn('merchant_transactions.customer_tin', $tinValues)
                ->orderByDesc('merchant_transactions.created_at')
                ->limit(4);

            if ($this->tableExists('merchants')) {
                $query
                    ->leftJoin('merchants', 'merchant_transactions.merchant_id', '=', 'merchants.id')
                    ->select('merchant_transactions.*', 'merchants.business_name as merchant_name');
            } else {
                $query->select('merchant_transactions.*');
            }

            $query->get()->each(function ($transaction) use ($rows, $user, $account) {
                $merchantName = $transaction->merchant_name ?? 'Business account';
                $reference = $transaction->transaction_ref ?: $transaction->id;

                $rows->push($this->item(
                    'Transaction '.$reference,
                    "{$merchantName} posted a {$this->money((float) $transaction->amount)} transaction to your account.",
                    $this->headline((string) $transaction->status),
                    'Payment',
                    (float) $transaction->amount,
                    null,
                    [
                        'transaction_id' => $transaction->id,
                        'transaction_ref' => $reference,
                        'merchant_name' => $merchantName,
                        'account_id' => $account?->id,
                        'user_id' => (int) $user->id,
                    ],
                ));
            });
        }

        return $rows
            ->unique(fn ($row) => $row['title'].'|'.($row['metadata']['receipt_id'] ?? $row['metadata']['transaction_id'] ?? ''))
            ->take(6)
            ->values()
            ->all();
    }

    private function birPayload(Request $request): array
    {
        $cacheKey = 'nuers.dashboard.bir';

        try {
            if ($request->has('refresh')) {
                Cache::store('file')->forget($cacheKey);
            }

            return Cache::store('file')->remember($cacheKey, now()->addSeconds(20), fn () => $this->birFreshPayload());
        } catch (\Throwable) {
            return $this->birFreshPayload();
        }
    }

    private function birFreshPayload(): array
    {
        return [
            'portal' => 'bir',
            'kpis' => $this->birKpis(),
            'series' => [
                'collection_trend' => $this->birCollectionTrend(),
                'revenue_by_region' => $this->birRevenueByRegion(),
            ],
            'lists' => [
                'jurisdiction_filters' => $this->birJurisdictionFilterRows(),
                'transaction_watchlist' => $this->birTransactionWatchlistRows(),
                'rdo_coverage' => $this->birRdoCoverageRows(),
                'merchant_compliance' => $this->merchantComplianceRows(),
                'audit_queue' => $this->birAuditQueue(),
                'reports' => $this->birReportRows(),
                'receipt_verification' => $this->birReceiptVerificationRows(),
                'forecast_cards' => $this->birForecastCards(),
            ],
        ];
    }

    private function birKpis(): array
    {
        $today = now()->startOfDay();
        $month = now()->startOfMonth();

        $totalTransactions = $this->count('merchant_transactions');
        $todayTransactions = $this->tableExists('merchant_transactions')
            ? DB::table('merchant_transactions')->where('created_at', '>=', $today)->count()
            : 0;

        $receiptsIssued = $this->tableExists('transaction_receipts')
            ? DB::table('transaction_receipts')->whereIn('status', ['issued', 'verified'])->count()
            : 0;
        $receiptsToday = $this->tableExists('transaction_receipts')
            ? DB::table('transaction_receipts')
                ->whereRaw('COALESCE(issued_at, created_at) >= ?', [$today->toDateTimeString()])
                ->count()
            : 0;

        $monthRevenue = $this->tableExists('merchant_transactions')
            ? (float) DB::table('merchant_transactions')->where('created_at', '>=', $month)->sum('amount')
            : 0.0;
        if ($monthRevenue <= 0 && $this->tableExists('transaction_receipts')) {
            $monthRevenue = (float) DB::table('transaction_receipts')
                ->whereRaw('COALESCE(issued_at, created_at) >= ?', [$month->toDateTimeString()])
                ->sum('total_due');
        }

        $monthVat = $this->tableExists('merchant_transactions')
            ? (float) DB::table('merchant_transactions')->where('created_at', '>=', $month)->sum('vat_amount')
            : 0.0;
        if ($monthVat <= 0 && $this->tableExists('transaction_receipts')) {
            $monthVat = (float) DB::table('transaction_receipts')
                ->whereRaw('COALESCE(issued_at, created_at) >= ?', [$month->toDateTimeString()])
                ->sum('vat_amount');
        }

        $activeMerchants = $this->tableExists('merchants')
            ? DB::table('merchants')->where('status', 'active')->count()
            : 0;
        $pendingMerchants = $this->tableExists('merchants')
            ? DB::table('merchants')->whereIn('status', ['pending', 'under_review'])->count()
            : 0;

        $activeRdos = $this->tableExists('rdo_offices')
            ? DB::table('rdo_offices')->where('status', 'active')->count()
            : 0;
        $birAccounts = $this->count('bir_accounts');

        return [
            $this->kpi('total_transactions', 'Total Transactions', $this->compactNumber($totalTransactions), "{$this->compactNumber($todayTransactions)} posted today", 'Activity', 'border-l-primary'),
            $this->kpi('eor_issued', 'EOR Issued', $this->compactNumber($receiptsIssued), "{$this->compactNumber($receiptsToday)} issued today", 'Receipt', 'border-l-chart-2'),
            $this->kpi('revenue_this_month', 'Revenue This Month', $this->money($monthRevenue), 'Live merchant transaction ledger', 'TrendingUp', 'border-l-success'),
            $this->kpi('vat_this_month', 'VAT This Month', $this->money($monthVat), 'Recorded VAT on posted activity', 'ShieldCheck', 'border-l-chart-3'),
            $this->kpi('active_merchants', 'Active Merchants', $this->compactNumber($activeMerchants), "{$this->compactNumber($pendingMerchants)} pending or under review", 'Building2', 'border-l-chart-4'),
            $this->kpi('active_rdos', 'Active RDO Offices', $this->compactNumber($activeRdos), "{$this->compactNumber($birAccounts)} BIR regulator accounts", 'Landmark', 'border-l-warning'),
        ];
    }

    private function birCollectionTrend(): array
    {
        $start = now()->copy()->subMonths(5)->startOfMonth();
        $trend = [];

        $transactionRows = collect();
        if ($this->tableExists('merchant_transactions')) {
            [$yearExpression, $monthExpression] = $this->monthPartExpressions('created_at');
            $transactionRows = DB::table('merchant_transactions')
                ->selectRaw("{$yearExpression} as year_value, {$monthExpression} as month_value, SUM(amount) as revenue_total, SUM(vat_amount) as vat_total")
                ->where('created_at', '>=', $start)
                ->groupByRaw("{$yearExpression}, {$monthExpression}")
                ->get()
                ->keyBy(fn ($row) => "{$row->year_value}-{$row->month_value}");
        }

        $receiptRows = collect();
        if ($this->tableExists('transaction_receipts')) {
            [$receiptYearExpression, $receiptMonthExpression] = $this->monthPartExpressions('COALESCE(issued_at, created_at)');
            $receiptRows = DB::table('transaction_receipts')
                ->selectRaw("{$receiptYearExpression} as year_value, {$receiptMonthExpression} as month_value, COUNT(*) as receipt_total")
                ->whereRaw('COALESCE(issued_at, created_at) >= ?', [$start->toDateTimeString()])
                ->groupByRaw("{$receiptYearExpression}, {$receiptMonthExpression}")
                ->get()
                ->keyBy(fn ($row) => "{$row->year_value}-{$row->month_value}");
        }

        for ($i = 5; $i >= 0; $i--) {
            $date = now()->copy()->subMonths($i)->startOfMonth();
            $key = "{$date->year}-{$date->month}";

            $trend[] = [
                'label' => $date->format('M'),
                'revenue' => round(((float) ($transactionRows[$key]?->revenue_total ?? 0)) / 1000, 2),
                'tax' => round(((float) ($transactionRows[$key]?->vat_total ?? 0)) / 1000, 2),
                'receipts' => (int) ($receiptRows[$key]?->receipt_total ?? 0),
            ];
        }

        return $trend;
    }

    private function birRevenueByRegion(): array
    {
        if ($this->tableExists('merchant_transactions')) {
            $rows = DB::table('merchant_transactions as transactions')
                ->leftJoin('merchants as merchants', 'transactions.merchant_id', '=', 'merchants.id')
                ->selectRaw("COALESCE(NULLIF(transactions.region, ''), NULLIF(merchants.region, ''), 'Unclassified') as region_name")
                ->selectRaw('SUM(transactions.amount) as revenue_total')
                ->selectRaw('SUM(transactions.vat_amount) as vat_total')
                ->selectRaw('AVG(COALESCE(merchants.compliance_score, 0)) as compliance_score')
                ->groupBy('region_name')
                ->orderByDesc('revenue_total')
                ->limit(6)
                ->get();

            if ($rows->isNotEmpty()) {
                return $rows->map(fn ($row) => [
                    'label' => (string) $row->region_name,
                    'revenue' => round(((float) ($row->revenue_total ?? 0)) / 1000, 2),
                    'tax' => round(((float) ($row->vat_total ?? 0)) / 1000, 2),
                    'compliance' => round((float) ($row->compliance_score ?? 0), 1),
                ])->values()->all();
            }
        }

        if (! $this->tableExists('merchants')) {
            return [];
        }

        return DB::table('merchants')
            ->selectRaw("COALESCE(NULLIF(region, ''), 'Unclassified') as region_name")
            ->selectRaw('AVG(compliance_score) as compliance_score')
            ->groupBy('region_name')
            ->orderByDesc('compliance_score')
            ->limit(6)
            ->get()
            ->map(fn ($row) => [
                'label' => (string) $row->region_name,
                'revenue' => 0,
                'tax' => 0,
                'compliance' => round((float) ($row->compliance_score ?? 0), 1),
            ])
            ->values()
            ->all();
    }

    private function birJurisdictionFilterRows(): array
    {
        $items = collect();

        if ($this->tableExists('merchant_transactions')) {
            DB::table('merchant_transactions')
                ->selectRaw("COALESCE(NULLIF(region, ''), 'Unclassified') as value")
                ->selectRaw('COUNT(*) as total_count')
                ->selectRaw('SUM(amount) as total_amount')
                ->groupBy('value')
                ->orderBy('value')
                ->get()
                ->each(function ($row) use ($items): void {
                    $items->push($this->item(
                        (string) $row->value,
                        "{$this->compactNumber((int) $row->total_count)} transactions",
                        'Region',
                        null,
                        (int) $row->total_count,
                        (float) $row->total_amount,
                        [
                            'type' => 'region',
                            'value' => $row->value,
                            'display' => (string) $row->value,
                            'amount_display' => $this->money((float) $row->total_amount),
                        ],
                    ));
                });

            if (Schema::hasColumn('merchant_transactions', 'rdo_code')) {
                $rdoNameExpression = Schema::hasColumn('merchant_transactions', 'rdo_name')
                    ? "COALESCE(NULLIF(rdo_name, ''), CONCAT('RDO ', COALESCE(NULLIF(rdo_code, ''), 'Unassigned')))"
                    : "CONCAT('RDO ', COALESCE(NULLIF(rdo_code, ''), 'Unassigned'))";

                DB::table('merchant_transactions')
                    ->selectRaw("COALESCE(NULLIF(rdo_code, ''), 'Unassigned') as code")
                    ->selectRaw("{$rdoNameExpression} as name")
                    ->selectRaw('COUNT(*) as total_count')
                    ->selectRaw('SUM(amount) as total_amount')
                    ->groupBy('code', 'name')
                    ->orderBy('code')
                    ->get()
                    ->each(function ($row) use ($items): void {
                        $display = trim(((string) $row->code === 'Unassigned' ? '' : 'RDO '.$row->code.' · ').(string) $row->name) ?: 'Unassigned RDO';

                        $items->push($this->item(
                            $display,
                            "{$this->compactNumber((int) $row->total_count)} transactions",
                            'RDO',
                            null,
                            (int) $row->total_count,
                            (float) $row->total_amount,
                            [
                                'type' => 'rdo',
                                'value' => $row->code,
                                'display' => $display,
                                'amount_display' => $this->money((float) $row->total_amount),
                            ],
                        ));
                    });
            }

            DB::table('merchant_transactions')
                ->selectRaw("COALESCE(NULLIF(status, ''), 'unknown') as value")
                ->selectRaw('COUNT(*) as total_count')
                ->selectRaw('SUM(amount) as total_amount')
                ->groupBy('value')
                ->orderBy('value')
                ->get()
                ->each(function ($row) use ($items): void {
                    $items->push($this->item(
                        $this->headline((string) $row->value),
                        "{$this->compactNumber((int) $row->total_count)} transactions",
                        'Status',
                        null,
                        (int) $row->total_count,
                        (float) $row->total_amount,
                        [
                            'type' => 'status',
                            'value' => $row->value,
                            'display' => $this->headline((string) $row->value),
                            'amount_display' => $this->money((float) $row->total_amount),
                        ],
                    ));
                });
        }

        return $items->values()->all();
    }

    private function birTransactionWatchlistRows(): array
    {
        if (! $this->tableExists('merchant_transactions')) {
            return [];
        }

        $hasRdo = Schema::hasColumn('merchant_transactions', 'rdo_code');
        $hasRdoName = Schema::hasColumn('merchant_transactions', 'rdo_name');
        $hasSource = Schema::hasColumn('merchant_transactions', 'source_system');
        $hasDocument = Schema::hasColumn('merchant_transactions', 'document_type');

        $select = [
            'transactions.transaction_ref',
            'transactions.amount',
            'transactions.vat_amount',
            'transactions.payment_method',
            'transactions.region',
            'transactions.channel',
            'transactions.transaction_type',
            'transactions.customer_name',
            'transactions.customer_tin',
            'transactions.status',
            'transactions.created_at',
            'merchants.business_name',
            'merchants.tin as merchant_tin',
            'merchants.compliance_score',
            'merchants.rdo_code as merchant_rdo_code',
            'merchants.rdo_name as merchant_rdo_name',
        ];

        if ($hasRdo) {
            $select[] = 'transactions.rdo_code';
        }

        if ($hasRdoName) {
            $select[] = 'transactions.rdo_name';
        }

        if ($hasSource) {
            $select[] = 'transactions.source_system';
        }

        if ($hasDocument) {
            $select[] = 'transactions.document_type';
        }

        return DB::table('merchant_transactions as transactions')
            ->leftJoin('merchants', 'transactions.merchant_id', '=', 'merchants.id')
            ->select($select)
            ->orderByDesc('transactions.created_at')
            ->limit(16)
            ->get()
            ->map(function ($row) use ($hasRdo, $hasRdoName, $hasSource, $hasDocument) {
                $rdoCode = $hasRdo ? ($row->rdo_code ?: $row->merchant_rdo_code) : $row->merchant_rdo_code;
                $rdoName = $hasRdoName ? ($row->rdo_name ?: $row->merchant_rdo_name) : $row->merchant_rdo_name;
                $region = $row->region ?: 'Unclassified';

                return $this->item(
                    (string) ($row->transaction_ref ?: 'Unreferenced transaction'),
                    trim(implode(' · ', array_filter([
                        $row->business_name ?: 'Unknown business account',
                        $row->customer_tin ? 'TIN '.$row->customer_tin : null,
                    ]))),
                    $this->headline((string) $row->status),
                    null,
                    (float) $row->amount,
                    (float) $row->vat_amount,
                    [
                        'merchant' => $row->business_name ?: 'Unknown business account',
                        'merchant_tin' => $row->merchant_tin,
                        'customer' => $row->customer_name,
                        'customer_tin' => $row->customer_tin,
                        'region' => $region,
                        'rdo_code' => $rdoCode ?: 'Unassigned',
                        'rdo_name' => $rdoName ?: 'Unassigned RDO',
                        'channel' => $this->headline((string) $row->channel),
                        'payment_method' => $this->headline((string) $row->payment_method),
                        'transaction_type' => $this->headline((string) $row->transaction_type),
                        'status_value' => $row->status,
                        'source_system' => $hasSource ? $this->headline((string) $row->source_system) : null,
                        'document_type' => $hasDocument ? $this->headline((string) $row->document_type) : null,
                        'compliance_score' => (float) ($row->compliance_score ?? 0),
                        'created_at' => $row->created_at,
                        'amount_display' => $this->money((float) $row->amount),
                        'vat_display' => $this->money((float) $row->vat_amount),
                    ],
                );
            })
            ->values()
            ->all();
    }

    private function birRdoCoverageRows(): array
    {
        if (! $this->tableExists('rdo_offices')) {
            return [];
        }

        $query = DB::table('rdo_offices as rdos')
            ->select(
                'rdos.rdo_code',
                'rdos.rdo_name',
                'rdos.region',
                'rdos.status',
            );

        if ($this->tableExists('merchant_transactions') && Schema::hasColumn('merchant_transactions', 'rdo_code')) {
            $query
                ->leftJoin('merchant_transactions as transactions', function ($join): void {
                    $join->on('transactions.rdo_code', '=', 'rdos.rdo_code');
                })
                ->selectRaw('COUNT(transactions.id) as transaction_count')
                ->selectRaw('COALESCE(SUM(transactions.amount), 0) as transaction_amount')
                ->groupBy('rdos.rdo_code', 'rdos.rdo_name', 'rdos.region', 'rdos.status');
        } else {
            $query
                ->selectRaw('0 as transaction_count')
                ->selectRaw('0 as transaction_amount');
        }

        return $query
            ->orderBy('rdos.rdo_code')
            ->limit(8)
            ->get()
            ->map(fn ($row) => $this->item(
                'RDO '.$row->rdo_code,
                trim(implode(' · ', array_filter([
                    $row->rdo_name,
                    $row->region,
                ]))),
                $this->headline((string) $row->status),
                null,
                (int) $row->transaction_count,
                (float) $row->transaction_amount,
                [
                    'rdo_code' => $row->rdo_code,
                    'rdo_name' => $row->rdo_name,
                    'region' => $row->region,
                    'amount_display' => $this->money((float) $row->transaction_amount),
                ],
            ))
            ->values()
            ->all();
    }

    private function birReceiptVerificationRows(): array
    {
        if (! $this->tableExists('receipt_verifications')) {
            return [];
        }

        $row = DB::table('receipt_verifications as verifications')
            ->leftJoin('transaction_receipts as receipts', 'verifications.receipt_id', '=', 'receipts.id')
            ->select(
                'verifications.receipt_number',
                'verifications.verification_method',
                'verifications.status',
                'verifications.signature_valid',
                'verifications.verified_at',
                'verifications.created_at',
                'receipts.merchant_name'
            )
            ->orderByDesc(DB::raw('COALESCE(verifications.verified_at, verifications.created_at)'))
            ->first();

        if (! $row) {
            return [];
        }

        return [
            $this->item(
                'Latest receipt verification',
                trim(implode(' · ', array_filter([
                    $row->merchant_name ?: null,
                    $this->headline((string) $row->verification_method),
                ]))) ?: 'Receipt verification record',
                ! $row->signature_valid ? 'Signature Invalid' : $this->headline((string) $row->status),
                ! $row->signature_valid ? 'Flagged' : 'Verified',
                null,
                null,
                [
                    'receipt_number' => $row->receipt_number,
                    'merchant' => $row->merchant_name ?: 'Unknown merchant',
                    'method' => $this->headline((string) $row->verification_method),
                    'verified_at' => $row->verified_at ?: $row->created_at,
                    'signature_valid' => (bool) $row->signature_valid,
                ],
            ),
        ];
    }

    private function birForecastCards(): array
    {
        $startOfMonth = now()->copy()->startOfMonth();
        $startOfPreviousMonth = now()->copy()->subMonthNoOverflow()->startOfMonth();
        $endOfPreviousMonth = now()->copy()->subMonthNoOverflow()->endOfMonth();
        $daysElapsed = max(1, now()->day);
        $daysInMonth = now()->daysInMonth;

        $monthRevenue = $this->tableExists('merchant_transactions')
            ? (float) DB::table('merchant_transactions')->where('created_at', '>=', $startOfMonth)->sum('amount')
            : 0.0;
        $monthVat = $this->tableExists('merchant_transactions')
            ? (float) DB::table('merchant_transactions')->where('created_at', '>=', $startOfMonth)->sum('vat_amount')
            : 0.0;
        $previousMonthRevenue = $this->tableExists('merchant_transactions')
            ? (float) DB::table('merchant_transactions')
                ->whereBetween('created_at', [$startOfPreviousMonth->toDateTimeString(), $endOfPreviousMonth->toDateTimeString()])
                ->sum('amount')
            : 0.0;

        $projectedMonthEnd = $monthRevenue > 0 ? ($monthRevenue / $daysElapsed) * $daysInMonth : 0.0;
        $projectedMonthVat = $monthVat > 0 ? ($monthVat / $daysElapsed) * $daysInMonth : 0.0;
        $variance = $previousMonthRevenue > 0 ? (($projectedMonthEnd - $previousMonthRevenue) / $previousMonthRevenue) * 100 : null;

        return [
            $this->item('Month-to-date revenue', 'Live transactions posted this month', 'Actual', null, $monthRevenue, null, [
                'display' => $this->money($monthRevenue),
            ]),
            $this->item('Projected month end', 'Current daily pace extrapolated to month end', 'Projected', null, $projectedMonthEnd, null, [
                'display' => $this->money($projectedMonthEnd),
            ]),
            $this->item('Projected month VAT', 'Derived from the same live posting pace', 'Forecast', null, $projectedMonthVat, null, [
                'display' => $this->money($projectedMonthVat),
                'trend' => $variance === null ? 'No prior month baseline' : sprintf('%s%s vs last month', $variance >= 0 ? '+' : '', number_format($variance, 1)).'%',
            ]),
        ];
    }

    private function birAuditQueue(): array
    {
        $queue = collect();

        if ($this->tableExists('fraud_signals')) {
            DB::table('fraud_signals')
                ->leftJoin('merchants', 'fraud_signals.merchant_id', '=', 'merchants.id')
                ->select(
                    'fraud_signals.id',
                    'fraud_signals.signal_type',
                    'fraud_signals.severity',
                    'fraud_signals.risk_score',
                    'fraud_signals.status',
                    'fraud_signals.detected_at',
                    'merchants.business_name',
                    'merchants.region'
                )
                ->whereIn('fraud_signals.status', ['open', 'under_review'])
                ->orderByDesc('fraud_signals.risk_score')
                ->limit(4)
                ->get()
                ->each(function ($row) use ($queue): void {
                    $queue->push([
                        'priority' => match (strtolower((string) $row->severity)) {
                            'critical' => 400,
                            'high' => 300,
                            'medium' => 200,
                            default => 100,
                        },
                        'item' => $this->item(
                            strtoupper(substr((string) $row->id, 0, 8)),
                            trim(implode(' · ', array_filter([
                                $this->headline((string) $row->signal_type),
                                $row->business_name ?: 'Unassigned merchant',
                            ]))),
                            $this->riskLabel((string) $row->severity),
                            null,
                            null,
                            null,
                            [
                                'scope' => trim(implode(' · ', array_filter([
                                    $this->headline((string) $row->status),
                                    $row->region ?: 'National',
                                    $row->detected_at ? Carbon::parse($row->detected_at)->diffForHumans() : null,
                                ]))),
                            ],
                        ),
                    ]);
                });
        }

        if ($this->tableExists('eis_transmissions')) {
            DB::table('eis_transmissions')
                ->leftJoin('eis_integrations', 'eis_transmissions.integration_id', '=', 'eis_integrations.id')
                ->select(
                    'eis_transmissions.id',
                    'eis_transmissions.status',
                    'eis_transmissions.transmission_type',
                    'eis_transmissions.attempt_number',
                    'eis_transmissions.created_at',
                    'eis_transmissions.response_message',
                    'eis_integrations.merchant_name'
                )
                ->whereNotIn('eis_transmissions.status', ['acknowledged', 'accepted', 'completed'])
                ->orderByDesc('eis_transmissions.created_at')
                ->limit(3)
                ->get()
                ->each(function ($row) use ($queue): void {
                    $status = strtolower((string) $row->status);
                    $queue->push([
                        'priority' => in_array($status, ['failed', 'error', 'rejected'], true) ? 250 : 150,
                        'item' => $this->item(
                            strtoupper(substr((string) $row->id, 0, 8)),
                            trim(implode(' · ', array_filter([
                                $this->headline((string) $row->transmission_type),
                                $row->merchant_name ?: 'BIR EIS queue',
                            ]))),
                            in_array($status, ['failed', 'error', 'rejected'], true) ? 'High' : 'Medium',
                            null,
                            null,
                            null,
                            [
                                'scope' => trim(implode(' · ', array_filter([
                                    $this->headline((string) $row->status),
                                    'Attempt '.(int) $row->attempt_number,
                                    $row->created_at ? Carbon::parse($row->created_at)->diffForHumans() : null,
                                ]))),
                                'detail' => $row->response_message,
                            ],
                        ),
                    ]);
                });
        }

        if ($this->tableExists('receipt_verifications')) {
            DB::table('receipt_verifications')
                ->leftJoin('transaction_receipts', 'receipt_verifications.receipt_id', '=', 'transaction_receipts.id')
                ->select(
                    'receipt_verifications.receipt_number',
                    'receipt_verifications.status',
                    'receipt_verifications.signature_valid',
                    'receipt_verifications.verification_method',
                    'receipt_verifications.created_at',
                    'transaction_receipts.merchant_name'
                )
                ->where(function ($query): void {
                    $query
                        ->where('receipt_verifications.signature_valid', false)
                        ->orWhereNotIn('receipt_verifications.status', ['verified']);
                })
                ->orderByDesc('receipt_verifications.created_at')
                ->limit(3)
                ->get()
                ->each(function ($row) use ($queue): void {
                    $queue->push([
                        'priority' => ! $row->signature_valid ? 240 : 140,
                        'item' => $this->item(
                            (string) $row->receipt_number,
                            trim(implode(' · ', array_filter([
                                $row->merchant_name ?: 'Receipt verification',
                                $this->headline((string) $row->verification_method),
                            ]))),
                            ! $row->signature_valid ? 'High' : $this->headline((string) $row->status),
                            null,
                            null,
                            null,
                            [
                                'scope' => trim(implode(' · ', array_filter([
                                    ! $row->signature_valid ? 'Signature invalid' : null,
                                    $row->created_at ? Carbon::parse($row->created_at)->diffForHumans() : null,
                                ]))),
                            ],
                        ),
                    ]);
                });
        }

        return $queue
            ->sortByDesc('priority')
            ->pluck('item')
            ->take(6)
            ->values()
            ->all();
    }

    private function birReportRows(): array
    {
        $definitions = [
            ['Transactions Export', 'merchant_transactions', 'Live merchant transaction ledger'],
            ['Receipt Register', 'transaction_receipts', 'Issued electronic receipt records'],
            ['Merchant Compliance', 'merchants', 'Registered business account compliance scores'],
            ['Receipt Verification Log', 'receipt_verifications', 'Public and portal receipt verification records'],
            ['Audit Events', 'audit_events', 'Operator and system audit trail'],
            ['EIS Transmission Log', 'eis_transmissions', 'Queued and acknowledged BIR EIS transmissions'],
        ];

        return collect($definitions)
            ->filter(fn (array $definition) => $this->tableExists($definition[1]))
            ->map(function (array $definition) {
                [$title, $table, $subtitle] = $definition;
                $count = $this->count($table);

                return $this->item(
                    $title,
                    $subtitle,
                    $count > 0 ? 'Live' : 'Empty',
                    null,
                    $count,
                    null,
                    [
                        'record_count' => $count,
                    ],
                );
            })
            ->values()
            ->all();
    }

    private function superAdminPayload(): array
    {
        return [
            'portal' => 'super-admin',
            'kpis' => $this->superAdminKpis(),
            'series' => [
                'collection_trend' => $this->collectionTrend(),
                'source_mix' => $this->sourceMix(),
            ],
            'lists' => [
                'heatmap' => $this->regionalHeatmap(),
                'compliance_signals' => $this->complianceSignals(),
                'ai_signals' => $this->aiSignals(),
                'gateways' => $this->gatewayRows(),
                'architecture_controls' => $this->architectureControls(),
                'architecture_tags' => $this->storedList('super-admin', 'architecture_tags'),
                'ecosystem_accounts' => $this->ecosystemAccounts(),
                'live_transactions' => $this->liveTransactionStream(),
                'security_operations' => $this->securityOperations(),
                'api_operations' => $this->apiOperations(),
                'subscription_governance' => $this->subscriptionGovernance(),
                'settlement_reconciliation' => $this->settlementReconciliation(),
                'audit_operations' => $this->auditOperations(),
                'backup_operations' => $this->backupOperations(),
                'notification_center' => $this->notificationCenter(),
                'enterprise_modules' => $this->enterpriseModules(),
            ],
        ];
    }

    private function superAdminKpis(): array
    {
        $today = now()->startOfDay();
        $month = now()->startOfMonth();

        $totalTransactions = $this->count('merchant_transactions');
        $todayTransactions = $this->tableExists('merchant_transactions')
            ? DB::table('merchant_transactions')->where('created_at', '>=', $today)->count()
            : 0;

        $receiptsIssued = $this->tableExists('transaction_receipts')
            ? DB::table('transaction_receipts')->whereIn('status', ['issued', 'verified'])->count()
            : 0;
        $receiptsToday = $this->tableExists('transaction_receipts')
            ? DB::table('transaction_receipts')->where('created_at', '>=', $today)->count()
            : 0;

        $totalRevenue = $this->sum('merchant_transactions', 'amount');
        $monthlyRevenue = $this->tableExists('merchant_transactions')
            ? (float) DB::table('merchant_transactions')->where('created_at', '>=', $month)->sum('amount')
            : 0.0;

        $activeMerchants = $this->tableExists('merchants')
            ? DB::table('merchants')->where('status', 'active')->count()
            : 0;
        $pendingMerchants = $this->tableExists('merchants')
            ? DB::table('merchants')->whereIn('status', ['pending', 'under_review'])->count()
            : 0;

        $activeClients = $this->tableExists('client_accounts')
            ? DB::table('client_accounts')->where('status', 'active')->count()
            : 0;
        if ($activeClients === 0 && $this->tableExists('profiles')) {
            $activeClients = DB::table('profiles')->whereIn('role', ['client', 'consumer'])->count();
        }

        return [
            $this->kpi('total_transactions', 'Total Transactions', $this->compactNumber($totalTransactions), "{$this->compactNumber($todayTransactions)} today", 'Activity', 'border-l-primary'),
            $this->kpi('eor_issued', 'EOR Issued', $this->compactNumber($receiptsIssued), "{$this->compactNumber($receiptsToday)} issued today", 'Receipt', 'border-l-chart-2'),
            $this->kpi('revenue_processed', 'Revenue Processed', $this->money($totalRevenue), "{$this->money($monthlyRevenue)} this month", 'Wallet', 'border-l-success'),
            $this->kpi('active_merchants', 'Active Merchants', $this->compactNumber($activeMerchants), "{$this->compactNumber($pendingMerchants)} pending or under review", 'Building2', 'border-l-chart-3'),
            $this->kpi('active_clients', 'Active Clients', $this->compactNumber($activeClients), 'Client accounts in the database', 'Users', 'border-l-chart-4'),
        ];
    }

    private function collectionTrend(): array
    {
        if (! $this->tableExists('merchant_transactions')) {
            return [];
        }

        $start = now()->copy()->subMonths(5)->startOfMonth();
        [$yearExpression, $monthExpression] = $this->monthPartExpressions('created_at');
        $rows = DB::table('merchant_transactions')
            ->selectRaw("{$yearExpression} as year_value, {$monthExpression} as month_value, SUM(amount) as monthly_revenue")
            ->where('created_at', '>=', $start)
            ->groupByRaw("{$yearExpression}, {$monthExpression}")
            ->get()
            ->keyBy(fn ($row) => "{$row->year_value}-{$row->month_value}");

        $trend = [];
        $yearToDate = 0.0;

        for ($i = 5; $i >= 0; $i--) {
            $date = now()->copy()->subMonths($i)->startOfMonth();
            $key = "{$date->year}-{$date->month}";
            $monthly = (float) ($rows[$key]?->monthly_revenue ?? 0);
            $yearToDate += $monthly;

            $trend[] = [
                'label' => $date->format('M'),
                'daily' => round(($monthly / max(1, $date->daysInMonth)) / 1000, 2),
                'monthly' => round($monthly / 1000, 2),
                'annual' => round($yearToDate / 1000, 2),
            ];
        }

        return $trend;
    }

    private function sourceMix(): array
    {
        if (! $this->tableExists('merchant_transactions') || ! $this->tableExists('merchants')) {
            return [];
        }

        $rows = DB::table('merchant_transactions')
            ->leftJoin('merchants', 'merchant_transactions.merchant_id', '=', 'merchants.id')
            ->selectRaw("COALESCE(NULLIF(merchants.sector, ''), 'Unclassified') as label, SUM(merchant_transactions.amount) as amount")
            ->groupBy('label')
            ->orderByDesc('amount')
            ->limit(5)
            ->get();

        $total = (float) $rows->sum('amount');

        if ($total <= 0) {
            return [];
        }

        return $rows->map(fn ($row) => [
            'label' => $this->headline((string) $row->label),
            'value' => round(((float) $row->amount / $total) * 100, 1),
        ])->values()->toArray();
    }

    private function regionalHeatmap(): array
    {
        if (! $this->tableExists('merchant_transactions')) {
            return [];
        }

        $rows = DB::table('merchant_transactions')
            ->selectRaw("COALESCE(NULLIF(region, ''), 'Unclassified') as region_name, SUM(amount) as revenue, COUNT(*) as transactions")
            ->groupBy('region_name')
            ->orderByDesc('revenue')
            ->limit(6)
            ->get();

        $max = max(1.0, (float) $rows->max('revenue'));

        return $rows->map(fn ($row) => $this->item(
            (string) $row->region_name,
            $this->money((float) $row->revenue),
            ((int) $row->transactions) > 0 ? 'active' : 'monitoring',
            null,
            round(((float) $row->revenue / $max) * 100, 1),
            null,
            ['transactions' => (int) $row->transactions],
        ))->values()->toArray();
    }

    private function complianceSignals(): array
    {
        $transactions = $this->count('merchant_transactions');
        $receipts = $this->count('transaction_receipts');
        $eorCoverage = $transactions > 0 ? min(100, ($receipts / $transactions) * 100) : 0;

        $merchantCompliance = $this->tableExists('merchants')
            ? (float) DB::table('merchants')->avg('compliance_score')
            : 0.0;

        $birTotal = $this->count('bir_accounts');
        $clientTotal = $this->count('client_accounts');
        $mfaEnabled = 0;

        if ($this->tableExists('bir_accounts')) {
            $mfaEnabled += DB::table('bir_accounts')->where('mfa_enabled', true)->count();
        }

        if ($this->tableExists('client_accounts')) {
            $mfaEnabled += DB::table('client_accounts')->where('mfa_enabled', true)->count();
        }

        $mfaCoverage = ($birTotal + $clientTotal) > 0 ? ($mfaEnabled / ($birTotal + $clientTotal)) * 100 : 0;
        $backupScore = (float) ($this->healthRows('backup')->avg('uptime_percentage') ?: 0);

        return [
            $this->item('EOR Compliance', 'Receipts issued against transaction ledger', $this->scoreStatus($eorCoverage), 'Receipt', round($eorCoverage, 1)),
            $this->item('Merchant Compliance', 'Average merchant compliance score', $this->scoreStatus($merchantCompliance), 'ShieldCheck', round($merchantCompliance, 1)),
            $this->item('MFA Coverage', 'BIR and client accounts with MFA enabled', $this->scoreStatus($mfaCoverage), 'Fingerprint', round($mfaCoverage, 1)),
            $this->item('Backup Success Rate', 'Latest database and recovery health checks', $this->scoreStatus($backupScore), 'HardDrive', round($backupScore, 1)),
        ];
    }

    private function aiSignals(): array
    {
        if (! $this->tableExists('fraud_signals')) {
            return [];
        }

        $signals = DB::table('fraud_signals')
            ->leftJoin('merchants', 'fraud_signals.merchant_id', '=', 'merchants.id')
            ->select(
                'fraud_signals.signal_type',
                'fraud_signals.risk_score',
                'fraud_signals.severity',
                'fraud_signals.recommendation',
                'merchants.business_name',
                'merchants.region',
            )
            ->whereIn('fraud_signals.status', ['open', 'under_review'])
            ->orderByDesc('fraud_signals.risk_score')
            ->limit(3)
            ->get();

        if ($signals->isEmpty() && $this->tableExists('ai_insights')) {
            return DB::table('ai_insights')
                ->orderByDesc('confidence_score')
                ->limit(3)
                ->get()
                ->map(fn ($row) => $this->item(
                    $row->title,
                    $row->summary,
                    'Medium',
                    'Brain',
                    (float) $row->confidence_score,
                ))
                ->values()
                ->toArray();
        }

        return $signals->map(fn ($row) => $this->item(
            $this->headline((string) $row->signal_type),
            trim(($row->business_name ?: 'Unassigned merchant').' · '.($row->region ?: 'National')),
            $this->riskLabel((string) $row->severity),
            'Brain',
            (float) $row->risk_score,
            null,
            ['recommendation' => $row->recommendation],
        ))->values()->toArray();
    }

    private function gatewayRows(): array
    {
        if (! $this->tableExists('payment_gateways')) {
            return [];
        }

        return DB::table('payment_gateways')
            ->orderByDesc('uptime_percentage')
            ->get()
            ->map(fn ($row) => $this->item(
                $row->provider,
                $this->headline((string) $row->channel),
                $this->headline((string) $row->status),
                null,
                (float) $row->uptime_percentage,
                null,
                [
                    'settlement_window' => $row->settlement_window,
                    'last_health_check_at' => $row->last_health_check_at,
                ],
            ))
            ->values()
            ->toArray();
    }

    private function ecosystemAccounts(): array
    {
        $superAdmins = $this->tableExists('profiles')
            ? DB::table('profiles')->whereIn('role', ['admin', 'super_admin'])->count()
            : 0;
        $birAccounts = $this->count('bir_accounts');
        $merchants = $this->count('merchants');
        $clients = $this->count('client_accounts');
        $agencies = $this->count('agency_accounts');

        return [
            $this->item('Super Admin Seats', 'RBAC operators with command-center access', 'Operational', 'UserCheck', $superAdmins, null, ['display' => $this->compactNumber($superAdmins), 'trend' => $this->roleCountLabel()]),
            $this->item('BIR Regulator Accounts', 'National and RDO revenue/audit users', 'Active', 'Landmark', $birAccounts, null, ['display' => $this->compactNumber($birAccounts), 'trend' => $this->mfaPendingLabel('bir_accounts')]),
            $this->item('Merchant Tenants', 'Businesses connected to NUERS', 'Monitoring', 'Building2', $merchants, null, ['display' => $this->compactNumber($merchants), 'trend' => $this->merchantStatusLabel()]),
            $this->item('Client and Agency Accounts', 'Citizens, taxpayers, agencies, schools, and hospitals', 'Active', 'Users', $clients + $agencies, null, ['display' => $this->compactNumber($clients + $agencies), 'trend' => "{$this->compactNumber($agencies)} agency tenants"]),
        ];
    }

    private function liveTransactionStream(): array
    {
        $today = now()->startOfDay();
        $receiptsToday = $this->tableExists('transaction_receipts')
            ? DB::table('transaction_receipts')->where('created_at', '>=', $today)->count()
            : 0;
        $paymentsToday = $this->tableExists('merchant_transactions')
            ? DB::table('merchant_transactions')->where('created_at', '>=', $today)->whereNotIn('payment_method', ['cash'])->count()
            : 0;
        $verificationsToday = $this->tableExists('receipt_verifications')
            ? DB::table('receipt_verifications')->where('created_at', '>=', $today)->count()
            : 0;
        $exceptions = $this->tableExists('merchant_transactions')
            ? DB::table('merchant_transactions')->whereNotIn('status', ['completed', 'paid', 'issued'])->count()
            : 0;
        $openFraud = $this->tableExists('fraud_signals')
            ? DB::table('fraud_signals')->whereIn('status', ['open', 'under_review'])->whereIn('severity', ['high', 'critical'])->count()
            : 0;

        return [
            $this->item('EOR issuance stream', 'POS, API, kiosk, and cashier receipt creation', $this->scoreStatus($this->healthScore('receipt_engine')), 'Receipt', $receiptsToday, null, ['display' => "{$this->compactNumber($receiptsToday)} today", 'sla' => $this->sla('receipt_engine'), 'owner' => 'Receipt Ops']),
            $this->item('Payment authorization rail', 'Cashless payment transactions captured today', $this->scoreStatus($this->healthScore('payment_gateway')), 'CreditCard', $paymentsToday, null, ['display' => "{$this->compactNumber($paymentsToday)} today", 'sla' => $this->sla('payment_gateway'), 'owner' => 'Gateway Ops']),
            $this->item('Verification portal hits', 'QR, receipt number, and signature authentication', $this->scoreStatus($this->healthScore('public_verification_api')), 'Globe', $verificationsToday, null, ['display' => "{$this->compactNumber($verificationsToday)} scans", 'sla' => $this->sla('public_verification_api'), 'owner' => 'Trust Services']),
            $this->item('Exception reconciliation', 'Pending, failed, void, refund, and suspense records', $exceptions > 0 ? 'Warning' : 'Good', 'AlertTriangle', $exceptions, null, ['display' => "{$this->compactNumber($exceptions)} cases", 'sla' => "{$this->compactNumber($exceptions)} open", 'owner' => 'Revenue Assurance']),
            $this->item('Fraud model queue', 'AI anomaly score above investigation threshold', $openFraud > 0 ? 'High' : 'Good', 'Brain', $openFraud, null, ['display' => "{$this->compactNumber($openFraud)} high risk", 'sla' => "{$this->compactNumber($openFraud)} critical/high", 'owner' => 'AI Audit']),
        ];
    }

    private function securityOperations(): array
    {
        $rows = [
            $this->item('MFA Enforcement', 'Strong authentication coverage across privileged roles', $this->scoreStatus($this->mfaCoverage()), 'Fingerprint', round($this->mfaCoverage(), 1)),
            $this->item('RBAC Policy Sync', 'Roles and permissions assigned in the database', $this->scoreStatus($this->rbacCoverage()), 'Lock', round($this->rbacCoverage(), 1)),
        ];

        return array_merge($rows, $this->healthItems('security'));
    }

    private function apiOperations(): array
    {
        $activeApiClients = $this->tableExists('api_clients')
            ? DB::table('api_clients')->where('status', 'active')->count()
            : 0;

        return array_merge([
            $this->item('Registered API Clients', 'Active OAuth/JWT integration clients', $activeApiClients > 0 ? 'Online' : 'Monitoring', 'Key', min(100, $activeApiClients * 25), null, ['display' => $this->compactNumber($activeApiClients)]),
        ], $this->healthItems('api'));
    }

    private function subscriptionGovernance(): array
    {
        $agencyTenants = $this->count('agency_accounts');
        $merchantTenants = $this->tableExists('merchants')
            ? DB::table('merchants')->where('status', 'active')->count()
            : 0;
        $billingExceptions = $this->tableExists('subscriptions')
            ? DB::table('subscriptions')->whereNotIn('status', ['active', 'paid'])->count()
            : 0;
        $settlementValue = $this->sum('settlement_batches', 'gross_amount');

        return [
            $this->item('Government Accounts', 'NGA, GOCC, SUC, hospital, and LGU deployments', 'Active', 'Landmark', $agencyTenants, null, ['display' => "{$this->compactNumber($agencyTenants)} tenants", 'trend' => "{$this->compactNumber($this->count('rdo_offices'))} RDO offices"]),
            $this->item('Merchant Accounts', 'Registered businesses using NUERS transaction pricing', 'Active', 'Building2', $merchantTenants, null, ['display' => $this->compactNumber($merchantTenants), 'trend' => $this->merchantStatusLabel()]),
            $this->item('Billing Exceptions', 'Historical billing records requiring review', $billingExceptions > 0 ? 'Review' : 'Good', 'AlertTriangle', $billingExceptions, null, ['display' => "{$this->compactNumber($billingExceptions)} reviews", 'trend' => 'From subscriptions table']),
            $this->item('Revenue Share Settlements', 'Platform, gateway, agency, and treasury settlement value', 'Operational', 'Wallet', $settlementValue, null, ['display' => $this->money($settlementValue), 'trend' => 'From settlement batches']),
        ];
    }

    private function settlementReconciliation(): array
    {
        $total = $this->count('settlement_batches');
        $settled = $this->tableExists('settlement_batches')
            ? DB::table('settlement_batches')->where('status', 'settled')->count()
            : 0;
        $pending = $this->tableExists('settlement_batches')
            ? DB::table('settlement_batches')->whereNotIn('status', ['settled'])->count()
            : 0;
        $matchRate = $total > 0 ? ($settled / $total) * 100 : 0;
        $refundVoids = $this->tableExists('merchant_transactions')
            ? DB::table('merchant_transactions')->whereIn('status', ['void', 'voided', 'refund', 'refunded', 'cancelled'])->count()
            : 0;

        return [
            $this->item('Daily settlement close', 'Bank, wallet, and agency clearing matched', $this->scoreStatus($matchRate), 'Wallet', $matchRate, null, ['display' => $this->percent($matchRate).' matched']),
            $this->item('Suspense ledger', 'Unmatched payments awaiting revenue assurance', $pending > 0 ? 'Warning' : 'Good', 'FileText', $pending, null, ['display' => "{$this->compactNumber($pending)} items"]),
            $this->item('Treasury remittance', 'Settled batches prepared for fund transfer and reporting', $this->scoreStatus($matchRate), 'Landmark', $matchRate, null, ['display' => $this->percent($matchRate).' ready']),
            $this->item('Refund and void controls', 'Cancellation workflow and approval queue', $refundVoids > 0 ? 'Monitoring' : 'Good', 'ShieldCheck', $refundVoids, null, ['display' => "{$this->compactNumber($refundVoids)} pending"]),
        ];
    }

    private function auditOperations(): array
    {
        $auditEvents = $this->count('audit_events');
        $openInvestigations = $this->tableExists('fraud_signals')
            ? DB::table('fraud_signals')->whereIn('status', ['open', 'under_review'])->count()
            : 0;
        $configChanges = $this->tableExists('audit_events')
            ? DB::table('audit_events')->where('event_type', 'like', '%configuration%')->count()
            : 0;
        $fraudEscalations = $this->tableExists('fraud_signals')
            ? DB::table('fraud_signals')->whereIn('severity', ['high', 'critical'])->count()
            : 0;

        return [
            $this->item('Audit Trail Coverage', 'Login, transaction, receipt, report, and config events', $auditEvents > 0 ? 'Good' : 'Monitoring', 'FileText', $auditEvents > 0 ? 100 : 0, null, ['display' => "{$this->compactNumber($auditEvents)} events"]),
            $this->item('Open Investigation Queue', 'Transaction investigation and exception review cases', $openInvestigations > 0 ? 'Review' : 'Good', 'ShieldAlert', $openInvestigations, null, ['display' => "{$this->compactNumber($openInvestigations)} cases"]),
            $this->item('Config Change Review', 'System configuration changes pending approval', $configChanges > 0 ? 'Monitoring' : 'Good', 'Clock', $configChanges, null, ['display' => "{$this->compactNumber($configChanges)} changes"]),
            $this->item('Fraud Escalations', 'Cases escalated from AI risk signals', $fraudEscalations > 0 ? 'High' : 'Good', 'Brain', $fraudEscalations, null, ['display' => "{$this->compactNumber($fraudEscalations)} urgent"]),
        ];
    }

    private function backupOperations(): array
    {
        return $this->healthItems('backup');
    }

    private function notificationCenter(): array
    {
        $critical = $this->tableExists('notifications')
            ? DB::table('notifications')->whereIn('priority', ['critical', 'high'])->count()
            : 0;
        $alerts = $this->tableExists('notifications')
            ? DB::table('notifications')->whereIn('type', ['risk', 'deadline', 'compliance'])->count()
            : 0;
        $delivery = $this->healthScore('notification_delivery');

        return [
            $this->item('Critical Broadcasts', 'System-wide notices for agencies and merchants', $critical > 0 ? 'Active' : 'Good', 'Bell', $critical, null, ['display' => "{$this->compactNumber($critical)} active"]),
            $this->item('Compliance Alerts Sent', 'Automated reminders and non-compliance notices', $alerts > 0 ? 'Active' : 'Good', 'AlertTriangle', $alerts, null, ['display' => "{$this->compactNumber($alerts)} sent"]),
            $this->item('User Inbox Delivery', 'Email, SMS, push, and portal notification delivery rate', $this->scoreStatus($delivery), 'Bell', $delivery),
        ];
    }

    private function architectureControls(): array
    {
        return [
            $this->item('Laravel API Runtime', 'Current live API backend', 'ready', 'Server'),
            $this->item('MySQL NUERS Database', $this->tableExists('migrations') ? 'Schema migrations available' : 'Schema check unavailable', $this->tableExists('migrations') ? 'ready' : 'monitoring', 'Database'),
            $this->item('RBAC + JWT Token Layer', "{$this->compactNumber($this->count('roles'))} roles / {$this->compactNumber($this->count('permissions'))} permissions", $this->rbacCoverage() > 0 ? 'ready' : 'monitoring', 'ShieldCheck'),
            $this->item('API / Webhook Gateway', "{$this->compactNumber($this->count('api_clients'))} API clients", $this->count('api_clients') > 0 ? 'ready' : 'monitoring', 'Network'),
            $this->item('Receipt Verification Portal', "{$this->compactNumber($this->count('receipt_verifications'))} verification records", $this->tableExists('receipt_verifications') ? 'ready' : 'monitoring', 'Globe'),
            $this->item('AI Analytics Layer', "{$this->compactNumber($this->count('ai_insights'))} insights / {$this->compactNumber($this->count('fraud_signals'))} fraud signals", $this->tableExists('ai_insights') ? 'ready' : 'monitoring', 'Brain'),
        ];
    }

    private function enterpriseModules(): array
    {
        $modules = [
            ['Merchant Management', 'merchants', 'Registry, onboarding, branch, POS, and status controls', 'Building2'],
            ['BIR Account Management', 'bir_accounts', 'Regulator accounts, regional access, and audit privileges', 'Landmark'],
            ['Client Account Management', 'client_accounts', 'Citizen, taxpayer, student, and patient account governance', 'Users'],
            ['User and RBAC Administration', 'roles', 'Roles, permissions, MFA, and policy assignments', 'UserCheck'],
            ['RDO Registration', 'rdo_offices', 'Revenue District Office registration and scoped monitoring', 'Landmark'],
            ['Audit Trail Management', 'audit_events', 'Traceability for every sensitive platform action', 'FileText'],
            ['Fraud Detection Monitoring', 'fraud_signals', 'AI anomaly detection and smart audit recommendations', 'Brain'],
            ['Revenue Analytics', 'merchant_transactions', 'Forecasting, heatmaps, source mix, and national trends', 'BarChart3'],
            ['Wallets & Transaction Pricing', 'wallets', 'Prepaid merchant funds and the published fee for eligible sales of ₱500 and above', 'CreditCard'],
            ['Payment Gateway Management', 'payment_gateways', 'QRPH, GCash, Maya, cards, banks, and reconciliation', 'Wallet'],
            ['System Health Monitoring', 'system_health_checks', 'Runtime, database, API, webhook, and BCDR telemetry', 'Server'],
            ['Notification Center', 'notifications', 'Email, SMS, push, and portal notification operations', 'Bell'],
        ];

        return collect($modules)->map(fn ($module) => $this->item(
            $module[0],
            $module[2].' · '.$this->compactNumber($this->count($module[1])).' records',
            $this->tableExists($module[1]) ? 'Active' : 'Missing',
            $module[3],
        ))->values()->toArray();
    }

    private function businessAccountPayload(Request $request): array
    {
        $merchant = $this->resolveBusinessAccountMerchant($request);

        if (! $merchant) {
            return $this->missingBusinessAccountPayload($request);
        }

        $cacheKey = 'nuers.dashboard.merchant.'.md5((string) $merchant->id);

        try {
            if ($request->has('refresh')) {
                Cache::store('file')->forget($cacheKey);
            }

            return Cache::store('file')->remember($cacheKey, now()->addSeconds(20), fn () => $this->businessAccountFreshPayload($merchant));
        } catch (\Throwable) {
            return $this->businessAccountFreshPayload($merchant);
        }
    }

    private function businessAccountFreshPayload(object $merchant): array
    {
        return [
            'portal' => 'merchant',
            'kpis' => $this->businessAccountKpis($merchant),
            'series' => [
                'daily_sales' => $this->businessAccountDailySales($merchant),
                'monthly_sales' => $this->businessAccountMonthlySales($merchant),
            ],
            'lists' => [
                'merchant_profile' => [$this->businessAccountProfile($merchant)],
                'payment_mix' => $this->businessAccountPaymentMix($merchant),
                'top_products' => $this->businessAccountTopProducts($merchant),
                'recent_transactions' => $this->businessAccountRecentTransactions($merchant),
                'alerts' => $this->businessAccountAlerts($merchant),
                'integration_health' => $this->businessAccountIntegrationHealth($merchant),
            ],
        ];
    }

    private function missingBusinessAccountPayload(Request $request): array
    {
        $user = $this->dashboardRequestUser($request);
        $email = $user?->email ?: 'No authenticated Business Account session';

        return [
            'portal' => 'merchant',
            'kpis' => [
                $this->kpi('account_link', 'Linked Business Account', 'Missing', $email, 'AlertTriangle', 'border-l-destructive'),
                $this->kpi('transactions', 'Transactions', '0', 'No account-scoped records loaded', 'Activity', 'border-l-primary'),
                $this->kpi('receipts', 'Receipts Issued', '0', 'No linked receipt ledger', 'Receipt', 'border-l-chart-2'),
                $this->kpi('revenue', 'Revenue', $this->money(0), 'Link a business to load revenue', 'Wallet', 'border-l-success'),
            ],
            'series' => [
                'daily_sales' => [],
                'monthly_sales' => [],
            ],
            'lists' => [
                'merchant_profile' => [
                    $this->item(
                        'No linked Business Account',
                        $email,
                        'Missing',
                        'Needs Linking',
                        0,
                        null,
                        ['pos_system' => 'Not connected'],
                    ),
                ],
                'payment_mix' => [],
                'top_products' => [],
                'recent_transactions' => [],
                'alerts' => [
                    $this->item(
                        'Business Account record not found',
                        'Create or link a Business Account record for this login before showing dashboard data.',
                        'warning',
                        'warning',
                    ),
                ],
                'integration_health' => [],
            ],
        ];
    }

    private function resolveBusinessAccountMerchant(Request $request): ?object
    {
        if (! $this->tableExists('merchants')) {
            return null;
        }

        $user = $this->dashboardRequestUser($request);

        if (! $user) {
            return null;
        }

        $merchant = DB::table('merchants')
            ->where(function (Builder $query) use ($user) {
                $query
                    ->where('merchant_account_id', $user->id);
            })
            ->first();

        if ($merchant) {
            return $merchant;
        }

        return null;
    }

    private function dashboardRequestUser(Request $request): ?object
    {
        $token = $request->bearerToken();

        if (! $token || ! $this->tableExists('users')) {
            return null;
        }

        return app(\App\Services\ApiAccess::class)->user($request);
    }

    private function businessAccountKpis(object $merchant): array
    {
        $today = now()->startOfDay();
        $month = now()->startOfMonth();

        $transactionSummary = $this->businessAccountTransactionSummary($merchant, $today, $month);
        $invoiceSummary = $this->businessAccountInvoiceSummary($merchant, $today, $month);
        $receiptSummary = $this->businessAccountReceiptSummary($merchant);

        $todaySales = $transactionSummary['today_sales'] + $invoiceSummary['today_sales'];
        $monthlyRevenue = $transactionSummary['monthly_revenue'] + $invoiceSummary['monthly_revenue'];
        $pendingTransactions = $transactionSummary['pending_count'] + $invoiceSummary['pending_count'];
        $monthlyVat = $transactionSummary['monthly_vat'] + $invoiceSummary['monthly_vat'];
        $postedInvoiceCount = $invoiceSummary['posted_count'];
        $receiptsIssued = $receiptSummary['issued_count'];
        $salesDocuments = $postedInvoiceCount + $receiptsIssued;

        $complianceScore = (float) ($merchant->compliance_score ?? 0);

        return [
            $this->kpi('today_sales', "Today's Sales", $this->money($todaySales), "From this account's invoices and API/POS transactions today", 'CreditCard', 'border-l-primary'),
            $this->kpi('monthly_revenue', 'Monthly Revenue', $this->money($monthlyRevenue), 'Invoices plus API/POS transaction ledger', 'TrendingUp', 'border-l-success'),
            $this->kpi('sales_documents', 'Sales Documents', $this->compactNumber($salesDocuments), "{$this->compactNumber($postedInvoiceCount)} invoices · {$this->compactNumber($receiptsIssued)} API receipts", 'Receipt', 'border-l-chart-2'),
            $this->kpi('pending_transactions', 'Pending Transactions', $this->compactNumber($pendingTransactions), 'Requires settlement or review', 'Activity', 'border-l-warning'),
            $this->kpi('vat_collected', 'VAT Collected', $this->money($monthlyVat), 'Output VAT this month', 'Wallet', 'border-l-chart-3'),
            $this->kpi('compliance_score', 'Compliance Score', $this->percent($complianceScore), 'From the business compliance record', 'ShieldCheck', 'border-l-chart-4'),
        ];
    }

    private function businessAccountTransactionSummary(object $merchant, Carbon $today, Carbon $month): array
    {
        $summary = [
            'today_sales' => 0.0,
            'monthly_revenue' => 0.0,
            'monthly_vat' => 0.0,
            'pending_count' => 0,
        ];

        if (! $this->tableExists('merchant_transactions')) {
            return $summary;
        }

        $postedSql = $this->sqlPlaceholders(self::BUSINESS_POSTED_TRANSACTION_STATUSES);
        $row = DB::table('merchant_transactions')
            ->where('merchant_id', $merchant->id)
            ->selectRaw('SUM(CASE WHEN created_at >= ? THEN amount ELSE 0 END) as today_sales', [$today->toDateTimeString()])
            ->selectRaw('SUM(CASE WHEN created_at >= ? THEN amount ELSE 0 END) as monthly_revenue', [$month->toDateTimeString()])
            ->selectRaw('SUM(CASE WHEN created_at >= ? THEN vat_amount ELSE 0 END) as monthly_vat', [$month->toDateTimeString()])
            ->selectRaw("SUM(CASE WHEN status NOT IN ({$postedSql}) THEN 1 ELSE 0 END) as pending_count", self::BUSINESS_POSTED_TRANSACTION_STATUSES)
            ->first();

        return [
            'today_sales' => (float) ($row->today_sales ?? 0),
            'monthly_revenue' => (float) ($row->monthly_revenue ?? 0),
            'monthly_vat' => (float) ($row->monthly_vat ?? 0),
            'pending_count' => (int) ($row->pending_count ?? 0),
        ];
    }

    private function businessAccountInvoiceSummary(object $merchant, Carbon $today, Carbon $month): array
    {
        $summary = [
            'today_sales' => 0.0,
            'monthly_revenue' => 0.0,
            'monthly_vat' => 0.0,
            'pending_count' => 0,
            'posted_count' => 0,
        ];

        $query = $this->businessAccountStandaloneInvoices($merchant);

        if (! $query) {
            return $summary;
        }

        $postedSql = $this->sqlPlaceholders(self::BUSINESS_POSTED_INVOICE_STATUSES);
        $dateExpression = 'COALESCE(issue_date, DATE(created_at))';
        $row = $query
            ->selectRaw("SUM(CASE WHEN status IN ({$postedSql}) THEN 1 ELSE 0 END) as posted_count", self::BUSINESS_POSTED_INVOICE_STATUSES)
            ->selectRaw("SUM(CASE WHEN status NOT IN ({$postedSql}) THEN 1 ELSE 0 END) as pending_count", self::BUSINESS_POSTED_INVOICE_STATUSES)
            ->selectRaw("SUM(CASE WHEN status IN ({$postedSql}) AND {$dateExpression} >= ? THEN total_amount ELSE 0 END) as today_sales", [...self::BUSINESS_POSTED_INVOICE_STATUSES, $today->toDateString()])
            ->selectRaw("SUM(CASE WHEN status IN ({$postedSql}) AND {$dateExpression} >= ? THEN total_amount ELSE 0 END) as monthly_revenue", [...self::BUSINESS_POSTED_INVOICE_STATUSES, $month->toDateString()])
            ->selectRaw("SUM(CASE WHEN status IN ({$postedSql}) AND {$dateExpression} >= ? THEN vat_amount ELSE 0 END) as monthly_vat", [...self::BUSINESS_POSTED_INVOICE_STATUSES, $month->toDateString()])
            ->first();

        return [
            'today_sales' => (float) ($row->today_sales ?? 0),
            'monthly_revenue' => (float) ($row->monthly_revenue ?? 0),
            'monthly_vat' => (float) ($row->monthly_vat ?? 0),
            'pending_count' => (int) ($row->pending_count ?? 0),
            'posted_count' => (int) ($row->posted_count ?? 0),
        ];
    }

    private function businessAccountReceiptSummary(object $merchant): array
    {
        if (! $this->tableExists('transaction_receipts')) {
            return ['issued_count' => 0];
        }

        return [
            'issued_count' => (int) DB::table('transaction_receipts')
                ->where('merchant_id', $merchant->id)
                ->whereIn('status', ['issued', 'verified'])
                ->count(),
        ];
    }

    private function businessAccountProfile(object $merchant): array
    {
        return $this->item(
            $merchant->business_name ?: 'Business Account',
            $merchant->tin ? "TIN: {$merchant->tin}" : 'TIN not registered',
            $merchant->status ?: 'review',
            (bool) ($merchant->vat_registered ?? false) ? 'VAT Registered' : 'Non-VAT / Pending',
            (float) ($merchant->compliance_score ?? 0),
            null,
            [
                'pos_system' => $merchant->pos_system ?: 'No POS integration',
                'sector' => $merchant->sector,
                'region' => $merchant->region,
                'business_type' => $merchant->business_type,
                'email' => $merchant->email ?: $merchant->merchant_account_email,
                'phone' => $merchant->phone,
                'city' => $merchant->city,
                'next_audit_date' => $merchant->next_audit_date,
            ],
        );
    }

    private function businessAccountDailySales(object $merchant): array
    {
        $transactions = $this->businessAccountTransactions($merchant);
        $start = now()->copy()->subDays(6)->startOfDay();
        $totals = [];

        if ($transactions) {
            (clone $transactions)
                ->selectRaw('DATE(created_at) as date_value, SUM(amount) as sales, SUM(vat_amount) as vat')
                ->where('created_at', '>=', $start)
                ->groupByRaw('DATE(created_at)')
                ->get()
                ->each(function ($row) use (&$totals) {
                    $key = (string) $row->date_value;
                    $totals[$key]['sales'] = ($totals[$key]['sales'] ?? 0) + (float) $row->sales;
                    $totals[$key]['vat'] = ($totals[$key]['vat'] ?? 0) + (float) $row->vat;
                });
        }

        $invoices = $this->businessAccountStandaloneInvoices($merchant);
        if ($invoices) {
            (clone $invoices)
                ->selectRaw('COALESCE(issue_date, DATE(created_at)) as date_value, SUM(total_amount) as sales, SUM(vat_amount) as vat')
                ->whereIn('status', self::BUSINESS_POSTED_INVOICE_STATUSES)
                ->whereRaw('COALESCE(issue_date, DATE(created_at)) >= ?', [$start->toDateString()])
                ->groupByRaw('COALESCE(issue_date, DATE(created_at))')
                ->get()
                ->each(function ($row) use (&$totals) {
                    $key = (string) $row->date_value;
                    $totals[$key]['sales'] = ($totals[$key]['sales'] ?? 0) + (float) $row->sales;
                    $totals[$key]['vat'] = ($totals[$key]['vat'] ?? 0) + (float) $row->vat;
                });
        }

        $series = [];

        for ($i = 6; $i >= 0; $i--) {
            $date = now()->copy()->subDays($i)->startOfDay();
            $row = $totals[$date->toDateString()] ?? ['sales' => 0, 'vat' => 0];

            $series[] = [
                'label' => $date->format('D'),
                'sales' => round((float) ($row['sales'] ?? 0), 2),
                'vat' => round((float) ($row['vat'] ?? 0), 2),
            ];
        }

        return $series;
    }

    private function businessAccountMonthlySales(object $merchant): array
    {
        $transactions = $this->businessAccountTransactions($merchant);
        $start = now()->copy()->subMonths(5)->startOfMonth();
        $rows = [];

        if ($transactions) {
            [$yearExpression, $monthExpression] = $this->monthPartExpressions('created_at');

            (clone $transactions)
                ->selectRaw("{$yearExpression} as year_value, {$monthExpression} as month_value, SUM(amount) as revenue, SUM(vat_amount) as vat")
                ->where('created_at', '>=', $start)
                ->groupByRaw("{$yearExpression}, {$monthExpression}")
                ->get()
                ->each(function ($row) use (&$rows) {
                    $key = "{$row->year_value}-{$row->month_value}";
                    $rows[$key]['revenue'] = ($rows[$key]['revenue'] ?? 0) + (float) $row->revenue;
                    $rows[$key]['vat'] = ($rows[$key]['vat'] ?? 0) + (float) $row->vat;
                });
        }

        $invoices = $this->businessAccountStandaloneInvoices($merchant);
        if ($invoices) {
            $invoiceDateExpression = 'COALESCE(issue_date, DATE(created_at))';
            [$yearExpression, $monthExpression] = $this->monthPartExpressions($invoiceDateExpression);

            (clone $invoices)
                ->selectRaw("{$yearExpression} as year_value, {$monthExpression} as month_value, SUM(total_amount) as revenue, SUM(vat_amount) as vat")
                ->whereIn('status', self::BUSINESS_POSTED_INVOICE_STATUSES)
                ->whereRaw("{$invoiceDateExpression} >= ?", [$start->toDateString()])
                ->groupByRaw("{$yearExpression}, {$monthExpression}")
                ->get()
                ->each(function ($row) use (&$rows) {
                    $key = "{$row->year_value}-{$row->month_value}";
                    $rows[$key]['revenue'] = ($rows[$key]['revenue'] ?? 0) + (float) $row->revenue;
                    $rows[$key]['vat'] = ($rows[$key]['vat'] ?? 0) + (float) $row->vat;
                });
        }

        $target = (float) ($merchant->monthly_revenue ?? 0);
        if ($target <= 0 && (float) ($merchant->annual_revenue ?? 0) > 0) {
            $target = round(((float) $merchant->annual_revenue) / 12, 2);
        }

        $series = [];

        for ($i = 5; $i >= 0; $i--) {
            $date = now()->copy()->subMonths($i)->startOfMonth();
            $key = "{$date->year}-{$date->month}";
            $row = $rows[$key] ?? ['revenue' => 0, 'vat' => 0];

            $series[] = [
                'label' => $date->format('M'),
                'revenue' => round((float) ($row['revenue'] ?? 0), 2),
                'vat' => round((float) ($row['vat'] ?? 0), 2),
                'target' => round($target, 2),
            ];
        }

        return $series;
    }

    private function businessAccountPaymentMix(object $merchant): array
    {
        $totals = [];
        $transactions = $this->businessAccountTransactions($merchant);

        if ($transactions) {
            (clone $transactions)
                ->selectRaw("COALESCE(NULLIF(payment_method, ''), 'Unspecified') as method, SUM(amount) as amount, COUNT(*) as transaction_count")
                ->groupBy('method')
                ->orderByDesc('amount')
                ->limit(6)
                ->get()
                ->each(function ($row) use (&$totals) {
                    $method = (string) $row->method;
                    $totals[$method]['amount'] = ($totals[$method]['amount'] ?? 0) + (float) $row->amount;
                    $totals[$method]['count'] = ($totals[$method]['count'] ?? 0) + (int) $row->transaction_count;
                });
        }

        $invoices = $this->businessAccountStandaloneInvoices($merchant);
        if ($invoices) {
            $invoiceTotal = (float) (clone $invoices)
                ->whereIn('status', self::BUSINESS_POSTED_INVOICE_STATUSES)
                ->sum('total_amount');
            $invoiceCount = (clone $invoices)
                ->whereIn('status', self::BUSINESS_POSTED_INVOICE_STATUSES)
                ->count();

            if ($invoiceTotal > 0) {
                $totals['Invoice']['amount'] = ($totals['Invoice']['amount'] ?? 0) + $invoiceTotal;
                $totals['Invoice']['count'] = ($totals['Invoice']['count'] ?? 0) + $invoiceCount;
            }
        }

        uasort($totals, fn ($a, $b) => ($b['amount'] ?? 0) <=> ($a['amount'] ?? 0));

        $total = array_sum(array_column($totals, 'amount'));
        $colors = ['var(--chart-1)', 'var(--chart-2)', 'var(--chart-3)', 'var(--chart-4)', 'var(--chart-5)', 'var(--primary)'];

        if ($total <= 0) {
            return [];
        }

        return collect(array_slice($totals, 0, 6, true))->map(fn ($row, $method) => $this->item(
            $this->headline((string) $method),
            "{$this->compactNumber((int) ($row['count'] ?? 0))} records",
            'active',
            null,
            round(((float) ($row['amount'] ?? 0) / $total) * 100, 1),
            null,
            ['color' => $colors[array_search($method, array_keys(array_slice($totals, 0, 6, true)), true) ?: 0] ?? 'var(--primary)', 'amount' => round((float) ($row['amount'] ?? 0), 2)],
        ))->values()->toArray();
    }

    private function businessAccountTopProducts(object $merchant): array
    {
        $totals = [];

        if ($this->tableExists('transaction_receipts')) {
            DB::table('transaction_receipts')
                ->where('merchant_id', $merchant->id)
                ->whereNotNull('items')
                ->orderByDesc('issued_at')
                ->limit(500)
                ->get(['items'])
                ->each(function ($receipt) use (&$totals) {
                    $items = json_decode($receipt->items ?? '[]', true);

                    if (! is_array($items)) {
                        return;
                    }

                    foreach ($items as $item) {
                        if (! is_array($item)) {
                            continue;
                        }

                        $label = (string) ($item['category'] ?? $item['description'] ?? $item['name'] ?? 'Uncategorized');
                        $amount = (float) ($item['total'] ?? $item['amount'] ?? 0);

                        if ($amount <= 0) {
                            $amount = ((float) ($item['quantity'] ?? 1)) * ((float) ($item['unit_price'] ?? $item['price'] ?? 0));
                        }

                        $label = trim($label) ?: 'Uncategorized';
                        $totals[$label] = ($totals[$label] ?? 0) + $amount;
                    }
                });
        }

        if ($totals === [] && $this->businessAccountTransactions($merchant)) {
            (clone $this->businessAccountTransactions($merchant))
                ->selectRaw("COALESCE(NULLIF(transaction_type, ''), COALESCE(NULLIF(channel, ''), 'Unclassified')) as category, SUM(amount) as amount")
                ->groupBy('category')
                ->orderByDesc('amount')
                ->limit(5)
                ->get()
                ->each(fn ($row) => $totals[(string) $row->category] = (float) $row->amount);
        }

        if ($totals === [] && $this->tableExists('business_invoice_items')) {
            DB::table('business_invoice_items')
                ->where('merchant_id', $merchant->id)
                ->selectRaw("COALESCE(NULLIF(description, ''), COALESCE(NULLIF(item_code, ''), 'Invoice Item')) as category, SUM(line_total) as amount")
                ->groupBy('category')
                ->orderByDesc('amount')
                ->limit(5)
                ->get()
                ->each(fn ($row) => $totals[(string) $row->category] = (float) $row->amount);
        }

        if ($totals === [] && $this->businessAccountStandaloneInvoices($merchant)) {
            (clone $this->businessAccountStandaloneInvoices($merchant))
                ->whereIn('status', self::BUSINESS_POSTED_INVOICE_STATUSES)
                ->selectRaw("COALESCE(NULLIF(document_type, ''), 'Sales Invoice') as category, SUM(total_amount) as amount")
                ->groupBy('category')
                ->orderByDesc('amount')
                ->limit(5)
                ->get()
                ->each(fn ($row) => $totals[$this->headline((string) $row->category)] = (float) $row->amount);
        }

        arsort($totals);
        $top = array_slice($totals, 0, 5, true);
        $max = max(1, ...array_values($top ?: [1]));

        return collect($top)->map(fn ($amount, $label) => $this->item(
            (string) $label,
            'From receipt items / transaction ledger',
            'active',
            null,
            round((float) $amount, 2),
            round(((float) $amount / $max) * 100, 1),
        ))->values()->toArray();
    }

    private function businessAccountRecentTransactions(object $merchant): array
    {
        $items = [];

        if ($this->tableExists('merchant_transactions')) {
            $query = DB::table('merchant_transactions')
                ->where('merchant_transactions.merchant_id', $merchant->id)
                ->orderByDesc('merchant_transactions.created_at')
                ->limit(8);

            if ($this->tableExists('transaction_receipts')) {
                $query
                    ->leftJoin('transaction_receipts', 'transaction_receipts.transaction_id', '=', 'merchant_transactions.id')
                    ->select(
                        'merchant_transactions.transaction_ref',
                        'merchant_transactions.amount',
                        'merchant_transactions.status',
                        'merchant_transactions.channel',
                        'merchant_transactions.transaction_type',
                        'merchant_transactions.payment_method',
                        'merchant_transactions.created_at',
                        'transaction_receipts.receipt_number',
                    );
            } else {
                $query->select(
                    'merchant_transactions.transaction_ref',
                    'merchant_transactions.amount',
                    'merchant_transactions.status',
                    'merchant_transactions.channel',
                    'merchant_transactions.transaction_type',
                    'merchant_transactions.payment_method',
                    'merchant_transactions.created_at',
                    DB::raw('NULL as receipt_number'),
                );
            }

            foreach ($query->get() as $row) {
                $sortAt = $row->created_at ? Carbon::parse($row->created_at) : null;
                $items[] = $this->item(
                    $row->receipt_number ?: $row->transaction_ref,
                    $sortAt ? $sortAt->diffForHumans() : null,
                    strtolower((string) ($row->status ?: 'pending')),
                    $this->headline((string) ($row->channel ?: $row->transaction_type ?: $row->payment_method ?: 'Transaction')),
                    (float) $row->amount,
                    null,
                    [
                        'sort_at' => $sortAt?->timestamp ?? 0,
                        'transaction_ref' => $row->transaction_ref,
                        'payment_method' => $row->payment_method,
                    ],
                );
            }
        }

        if ($this->businessAccountStandaloneInvoices($merchant)) {
            (clone $this->businessAccountStandaloneInvoices($merchant))
                ->orderByDesc(DB::raw('COALESCE(issue_date, DATE(created_at))'))
                ->orderByDesc('created_at')
                ->limit(8)
                ->get()
                ->each(function ($row) use (&$items) {
                    $sortAt = Carbon::parse($row->issue_date ?: $row->created_at);
                    $items[] = $this->item(
                        (string) $row->invoice_number,
                        $sortAt->diffForHumans(),
                        strtolower((string) ($row->status ?: 'draft')),
                        $this->headline((string) (($row->document_type ?? null) ?: 'Sales Invoice')),
                        (float) $row->total_amount,
                        null,
                        [
                            'sort_at' => $sortAt->timestamp,
                            'transaction_ref' => $row->invoice_number,
                            'payment_method' => 'invoice',
                            'source' => 'business_invoices',
                        ],
                    );
                });
        }

        return collect($items)
            ->sortByDesc(fn ($item) => (int) ($item['metadata']['sort_at'] ?? 0))
            ->take(8)
            ->map(function (array $item) {
                unset($item['metadata']['sort_at']);

                return $item;
            })
            ->values()
            ->toArray();
    }

    private function businessAccountAlerts(object $merchant): array
    {
        $alerts = [];
        $compliance = (float) ($merchant->compliance_score ?? 0);

        if (! $merchant->tin) {
            $alerts[] = $this->item('TIN is not registered', 'Bind the business TIN before BIR reporting and EIS submission.', 'warning', 'warning');
        }

        if ($compliance > 0 && $compliance < 80) {
            $alerts[] = $this->item('Compliance score needs attention', "Current score is {$this->percent($compliance)} from the business compliance record.", 'warning', 'warning');
        }

        if ($this->tableExists('merchant_transactions')) {
            $pending = DB::table('merchant_transactions')
                ->where('merchant_id', $merchant->id)
                ->whereNotIn('status', ['completed', 'paid', 'issued', 'settled', 'verified'])
                ->count();

            if ($pending > 0) {
                $alerts[] = $this->item('Pending transaction review', "{$this->compactNumber($pending)} transaction records require settlement or review.", 'warning', 'warning');
            }
        }

        if ($this->tableExists('merchant_expenses')) {
            $pendingExpenses = DB::table('merchant_expenses')
                ->where('merchant_id', $merchant->id)
                ->whereIn('validation_status', ['pending_validation', 'under_review'])
                ->count();

            if ($pendingExpenses > 0) {
                $alerts[] = $this->item('Input VAT validation queue', "{$this->compactNumber($pendingExpenses)} B2B expense invoices await validation.", 'info', 'info');
            }
        }

        if ($alerts === []) {
            $alerts[] = $this->item('Business account records are synced', 'No open database-backed compliance alerts for this account.', 'success', 'success');
        }

        return $alerts;
    }

    private function businessAccountIntegrationHealth(object $merchant): array
    {
        $posName = $merchant->pos_system ?: 'POS integration not registered';
        $posScore = $merchant->pos_system ? 96.5 : 0;
        $receiptScore = $this->healthScore('receipt_engine') ?: ($this->tableExists('transaction_receipts') ? 95.0 : 0.0);
        $apiScore = $this->healthScore('api_gateway') ?: $this->healthScore('public_verification_api');
        $eisScore = $this->healthScore('bir_eis_gateway') ?: ($this->tableExists('eis_transmissions') ? 90.0 : 0.0);

        return [
            $this->item($posName, 'Business POS integration', $merchant->pos_system ? 'Online' : 'Pending', null, $posScore),
            $this->item('NUERS API', 'Account-scoped API and receipt services', $this->scoreStatus($apiScore), null, round($apiScore, 1)),
            $this->item('Receipt Engine', 'Electronic receipt issuance and archive', $this->scoreStatus($receiptScore), null, round($receiptScore, 1)),
            $this->item('BIR EIS Readiness', 'Transmission remains gated by registered credentials', $this->scoreStatus($eisScore), null, round($eisScore, 1)),
        ];
    }

    private function businessAccountTransactions(object $merchant): ?Builder
    {
        if (! $this->tableExists('merchant_transactions')) {
            return null;
        }

        return DB::table('merchant_transactions')->where('merchant_id', $merchant->id);
    }

    private function businessAccountStandaloneInvoices(object $merchant): ?Builder
    {
        if (! $this->tableExists('business_invoices')) {
            return null;
        }

        $query = DB::table('business_invoices')->where('merchant_id', $merchant->id);

        if ($this->tableExists('merchant_transactions')) {
            $query->whereNotExists(function (Builder $inner) use ($merchant) {
                $inner
                    ->select(DB::raw(1))
                    ->from('merchant_transactions')
                    ->whereColumn('merchant_transactions.transaction_ref', 'business_invoices.invoice_number')
                    ->where('merchant_transactions.merchant_id', $merchant->id);
            });
        }

        return $query;
    }

    private function businessAccountReceipts(object $merchant): ?Builder
    {
        if (! $this->tableExists('transaction_receipts')) {
            return null;
        }

        return DB::table('transaction_receipts')->where('merchant_id', $merchant->id);
    }

    private function monthPartExpressions(string $dateExpression): array
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => [
                "CAST(strftime('%Y', {$dateExpression}) AS INTEGER)",
                "CAST(strftime('%m', {$dateExpression}) AS INTEGER)",
            ],
            'pgsql' => [
                "EXTRACT(YEAR FROM {$dateExpression})",
                "EXTRACT(MONTH FROM {$dateExpression})",
            ],
            default => [
                "YEAR({$dateExpression})",
                "MONTH({$dateExpression})",
            ],
        };
    }

    private function storedLists(string $portal): array
    {
        $lists = DB::table('dashboard_list_items')
            ->where('portal', $portal)
            ->orderBy('list_key')
            ->orderBy('sort_order')
            ->get()
            ->groupBy('list_key')
            ->map(fn ($items) => $items->map(fn ($row) => $this->normalizeListRow($row))->values())
            ->toArray();

        if ($portal === 'bir' && $this->tableExists('merchants')) {
            $lists['merchant_compliance'] = $this->merchantComplianceRows();
        }

        return $lists;
    }

    private function merchantComplianceRows(): array
    {
        $hasLogo = Schema::hasColumn('merchants', 'logo_url');

        return DB::table('merchants')
            ->orderByDesc('compliance_score')
            ->orderBy('business_name')
            ->limit(8)
            ->get()
            ->map(fn ($merchant) => $this->item(
                (string) $merchant->business_name,
                trim(implode(' · ', array_filter([
                    $merchant->tin ?: null,
                    $merchant->rdo_code ? 'RDO '.$merchant->rdo_code : null,
                    $merchant->region ?: null,
                ]))) ?: 'Business Account',
                $merchant->status,
                $merchant->vat_registered ? 'VAT' : 'Non-VAT',
                (float) ($merchant->compliance_score ?? 0),
                null,
                [
                    'logo_url' => $hasLogo ? ($merchant->logo_url ?? null) : null,
                    'email' => $merchant->email ?: $merchant->merchant_account_email,
                    'sector' => $merchant->sector,
                    'region' => $merchant->region,
                    'rdo_code' => $merchant->rdo_code,
                    'rdo_name' => $merchant->rdo_name,
                ],
            ))
            ->values()
            ->all();
    }

    private function storedList(string $portal, string $key): array
    {
        return DB::table('dashboard_list_items')
            ->where('portal', $portal)
            ->where('list_key', $key)
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($row) => $this->normalizeListRow($row))
            ->values()
            ->toArray();
    }

    private function userFromRequest(Request $request): ?object
    {
        $token = $request->bearerToken();

        if (! $token || ! $this->tableExists('users')) {
            return null;
        }

        return app(\App\Services\ApiAccess::class)->user($request);
    }

    private function tinVariants(?string ...$values): array
    {
        $variants = [];

        foreach ($values as $value) {
            $value = trim((string) $value);
            $digits = preg_replace('/\D+/', '', $value) ?: '';

            foreach ([$value, $digits, $this->formatTin($digits)] as $variant) {
                $variant = trim((string) $variant);
                if ($variant !== '') {
                    $variants[] = $variant;
                }
            }
        }

        return array_values(array_unique($variants));
    }

    private function formatTin(string $digits): string
    {
        if (strlen($digits) < 9) {
            return $digits;
        }

        $parts = [substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6, 3)];
        $branch = substr($digits, 9);

        if ($branch !== '') {
            $parts[] = $branch;
        }

        return implode('-', array_filter($parts, fn ($part) => $part !== ''));
    }

    private function normalizeListRow(object $row): array
    {
        return [
            'title' => $row->title,
            'subtitle' => $row->subtitle,
            'status' => $row->status,
            'badge' => $row->badge,
            'primary_value' => $row->primary_value === null ? null : (float) $row->primary_value,
            'secondary_value' => $row->secondary_value === null ? null : (float) $row->secondary_value,
            'metadata' => json_decode($row->metadata ?? '{}', true) ?: [],
        ];
    }

    private function healthItems(string $category): array
    {
        return $this->healthRows($category)
            ->map(fn ($row) => $this->item(
                $row->service_name,
                $this->healthSubtitle($row),
                $this->headline((string) $row->status),
                $row->metadata['icon'] ?? null,
                (float) $row->uptime_percentage,
                null,
                [
                    'display' => $this->percent((float) $row->uptime_percentage),
                    'sla' => $row->p95_latency_ms ? "P95 {$row->p95_latency_ms}ms" : null,
                    'errors' => (int) $row->error_count,
                    'checked_at' => $row->checked_at,
                ],
            ))
            ->values()
            ->toArray();
    }

    private function healthRows(?string $category = null): Collection
    {
        if (! $this->tableExists('system_health_checks')) {
            return collect();
        }

        $query = DB::table('system_health_checks')->orderBy('category')->orderBy('service_name');

        if ($category) {
            $query->where('category', $category);
        }

        return $query->get()->map(function ($row) {
            $row->metadata = json_decode($row->metadata ?? '{}', true) ?: [];
            return $row;
        });
    }

    private function healthScore(string $key): float
    {
        if (! $this->tableExists('system_health_checks')) {
            return 0.0;
        }

        return (float) (DB::table('system_health_checks')->where('service_key', $key)->value('uptime_percentage') ?? 0);
    }

    private function sla(string $key): string
    {
        if (! $this->tableExists('system_health_checks')) {
            return 'No SLA';
        }

        $latency = DB::table('system_health_checks')->where('service_key', $key)->value('p95_latency_ms');

        return $latency ? "P95 {$latency}ms" : 'Tracked';
    }

    private function healthSubtitle(object $row): string
    {
        $parts = [];

        if ($row->p95_latency_ms) {
            $parts[] = "P95 {$row->p95_latency_ms}ms";
        }

        $parts[] = "{$this->compactNumber((int) $row->error_count)} errors";

        if ($row->checked_at) {
            $parts[] = 'checked '.Carbon::parse($row->checked_at)->diffForHumans();
        }

        return implode(' · ', $parts);
    }

    private function mfaCoverage(): float
    {
        $total = $this->count('bir_accounts') + $this->count('client_accounts');

        if ($total === 0) {
            return 0.0;
        }

        $enabled = 0;

        if ($this->tableExists('bir_accounts')) {
            $enabled += DB::table('bir_accounts')->where('mfa_enabled', true)->count();
        }

        if ($this->tableExists('client_accounts')) {
            $enabled += DB::table('client_accounts')->where('mfa_enabled', true)->count();
        }

        return ($enabled / $total) * 100;
    }

    private function rbacCoverage(): float
    {
        if (! $this->tableExists('roles') || ! $this->tableExists('role_permissions')) {
            return 0.0;
        }

        $roles = DB::table('roles')->count();

        if ($roles === 0) {
            return 0.0;
        }

        $rolesWithPermissions = DB::table('role_permissions')->distinct('role_id')->count('role_id');

        return min(100, ($rolesWithPermissions / $roles) * 100);
    }

    private function count(string $table): int
    {
        return $this->tableExists($table) ? DB::table($table)->count() : 0;
    }

    private function sum(string $table, string $column): float
    {
        return $this->tableExists($table) ? (float) DB::table($table)->sum($column) : 0.0;
    }

    private function sqlPlaceholders(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }

    private function tableExists(string $table): bool
    {
        if (array_key_exists($table, $this->tableExistsCache)) {
            return $this->tableExistsCache[$table];
        }

        try {
            $exists = (bool) Cache::store('file')->remember(
                "nuers.schema.table.{$table}",
                now()->addMinutes(10),
                fn () => Schema::hasTable($table),
            );
        } catch (\Throwable) {
            $exists = Schema::hasTable($table);
        }

        return $this->tableExistsCache[$table] = $exists;
    }

    private function kpi(string $key, string $label, string $value, ?string $subtext, ?string $icon, ?string $accent): array
    {
        return compact('key', 'label', 'value', 'subtext', 'icon', 'accent');
    }

    private function item(string $title, ?string $subtitle = null, ?string $status = null, ?string $badge = null, mixed $primaryValue = null, mixed $secondaryValue = null, array $metadata = []): array
    {
        return [
            'title' => $title,
            'subtitle' => $subtitle,
            'status' => $status,
            'badge' => $badge,
            'primary_value' => $primaryValue === null ? null : (float) $primaryValue,
            'secondary_value' => $secondaryValue === null ? null : (float) $secondaryValue,
            'metadata' => array_filter($metadata, fn ($value) => $value !== null),
        ];
    }

    private function scoreStatus(float $score): string
    {
        if ($score >= 90) {
            return 'Good';
        }

        if ($score >= 70) {
            return 'Monitoring';
        }

        if ($score > 0) {
            return 'Warning';
        }

        return 'Review';
    }

    private function riskLabel(string $severity): string
    {
        return match (strtolower($severity)) {
            'critical' => 'Critical',
            'high' => 'High',
            'medium' => 'Medium',
            default => 'Low',
        };
    }

    private function roleCountLabel(): string
    {
        return "{$this->compactNumber($this->count('roles'))} roles / {$this->compactNumber($this->count('permissions'))} permissions";
    }

    private function mfaPendingLabel(string $table): string
    {
        if (! $this->tableExists($table) || ! Schema::hasColumn($table, 'mfa_enabled')) {
            return 'MFA not tracked';
        }

        return $this->compactNumber(DB::table($table)->where('mfa_enabled', false)->count()).' pending MFA';
    }

    private function merchantStatusLabel(): string
    {
        if (! $this->tableExists('merchants')) {
            return 'Merchant table unavailable';
        }

        $pending = DB::table('merchants')->whereIn('status', ['pending', 'under_review'])->count();

        return "{$this->compactNumber($pending)} pending onboarding";
    }

    private function money(float $amount): string
    {
        $absolute = abs($amount);

        if ($absolute >= 1_000_000_000) {
            return 'PHP '.number_format($amount / 1_000_000_000, 1).'B';
        }

        if ($absolute >= 1_000_000) {
            return 'PHP '.number_format($amount / 1_000_000, 2).'M';
        }

        if ($absolute >= 1_000) {
            return 'PHP '.number_format($amount, 2);
        }

        return 'PHP '.number_format($amount, 2);
    }

    private function compactNumber(float|int $value): string
    {
        $absolute = abs((float) $value);

        if ($absolute >= 1_000_000_000) {
            return number_format($value / 1_000_000_000, 1).'B';
        }

        if ($absolute >= 1_000_000) {
            return number_format($value / 1_000_000, 1).'M';
        }

        if ($absolute >= 1_000) {
            return number_format($value / 1_000, 1).'K';
        }

        return number_format($value);
    }

    private function percent(float $value): string
    {
        return number_format($value, $value === floor($value) ? 0 : 1).'%';
    }

    private function headline(string $value): string
    {
        return str($value)->replace(['_', '-'], ' ')->headline()->toString();
    }
}
