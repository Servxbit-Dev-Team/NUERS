<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MerchantTransactionController extends Controller
{
    private const SUCCESS_STATUSES = ['completed', 'paid', 'issued', 'settled', 'verified'];
    private const PENDING_STATUSES = ['pending', 'queued', 'processing', 'under_review'];
    private const VOID_STATUSES = ['voided', 'void', 'cancelled', 'canceled', 'refunded', 'refund', 'failed', 'rejected'];

    public function index(Request $request): JsonResponse
    {
        $merchant = $this->currentMerchant($request);

        abort_unless(Schema::hasTable('merchant_transactions'), 500, 'merchant_transactions table is not migrated.');

        $page = max(1, $request->integer('page', 1));
        $perPage = min(100, max(5, $request->integer('per_page', 15)));
        $base = $this->baseTransactionQuery($merchant);
        $filtered = $this->applyFilters(clone $base, $request);
        $totalFiltered = (clone $filtered)->count();

        $transactions = (clone $filtered)
            ->orderByDesc('t.created_at')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->map(fn ($row) => $this->transactionPayload($row))
            ->values();

        return response()->json([
            'merchant' => [
                'id' => (string) $merchant->id,
                'business_name' => (string) $merchant->business_name,
                'tin' => (string) ($merchant->tin ?? ''),
            ],
            'summary' => $this->summary(clone $base),
            'weekly' => $this->weeklySeries(clone $base),
            'channel_revenue' => $this->channelRevenue(clone $base),
            'filters' => [
                'channels' => $this->optionList(clone $base, 'channel'),
                'branches' => $this->branches(clone $base),
                'payments' => $this->optionList(clone $base, 'payment_method'),
                'statuses' => $this->statuses(clone $base),
            ],
            'transactions' => $transactions,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $totalFiltered,
                'total_pages' => (int) max(1, ceil($totalFiltered / $perPage)),
            ],
        ]);
    }

    private function currentMerchant(Request $request): object
    {
        $token = $request->bearerToken();

        abort_unless($token && Schema::hasTable('users'), 401, 'Authenticated Business Account session is required.');

        $user = app(\App\Services\ApiAccess::class)->user($request);

        abort_unless($user, 401, 'Authenticated Business Account session is required.');
        abort_unless(Schema::hasTable('merchants'), 500, 'merchants table is not migrated.');

        $merchant = DB::table('merchants')
            ->where(function ($query) use ($user) {
                $query
                    ->where('merchant_account_id', $user->id);
            })
            ->orderByRaw(
                'CASE WHEN merchant_account_id = ? THEN 0 WHEN merchant_account_email = ? THEN 1 WHEN email = ? THEN 2 ELSE 3 END',
                [$user->id, $user->email, $user->email],
            )
            ->orderByDesc('updated_at')
            ->first();

        abort_unless($merchant, 404, 'No Business Account registry record is linked to this login.');

        return $merchant;
    }

    private function baseTransactionQuery(object $merchant): Builder
    {
        $query = DB::table('merchant_transactions as t')
            ->leftJoin('transaction_receipts as r', 't.receipt_id', '=', 'r.id')
            ->leftJoin('transaction_receipts as rt', 'rt.transaction_id', '=', 't.id')
            ->where('t.merchant_id', $merchant->id)
            ->where(function (Builder $source) {
                $hasApiIdentity = false;

                if (Schema::hasColumn('merchant_transactions', 'source_api_key_id')) {
                    $source->orWhereNotNull('t.source_api_key_id');
                    $hasApiIdentity = true;
                }

                if (Schema::hasColumn('merchant_transactions', 'source_system')) {
                    $source->orWhereNotNull('t.source_system');
                    $hasApiIdentity = true;
                }

                if (Schema::hasTable('transaction_receipts')) {
                    $source
                        ->orWhere('r.receipt_type', 'api_transaction_receipt')
                        ->orWhere('rt.receipt_type', 'api_transaction_receipt');
                    $hasApiIdentity = true;
                }

                if (! $hasApiIdentity) {
                    $source->whereRaw('1 = 0');
                }
            });

        if (Schema::hasTable('merchant_api_keys') && Schema::hasColumn('merchant_transactions', 'source_api_key_id')) {
            $query->leftJoin('merchant_api_keys as k', 't.source_api_key_id', '=', 'k.id');
        }

        return $query->select($this->selectColumns());
    }

    private function selectColumns(): array
    {
        $columns = [
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
            't.channel',
            't.transaction_type',
            't.customer_name',
            't.customer_tin',
            't.status',
            't.receipt_id',
            't.created_at',
            DB::raw("COALESCE(r.receipt_number, rt.receipt_number) as receipt_number"),
            DB::raw("COALESCE(r.series_number, rt.series_number) as series_number"),
            DB::raw("COALESCE(r.permit_to_issue_number, rt.permit_to_issue_number) as permit_to_issue_number"),
            DB::raw("COALESCE(r.esp_accreditation_number, rt.esp_accreditation_number) as esp_accreditation_number"),
            DB::raw("COALESCE(r.receipt_type, rt.receipt_type) as receipt_type"),
        ];

        foreach ([
            'source_system',
            'source_api_key_id',
            'branch_code',
            'branch_type',
            'tax_type',
            'rdo_code',
            'rdo_name',
        ] as $column) {
            if (Schema::hasColumn('merchant_transactions', $column)) {
                $columns[] = "t.{$column}";
            }
        }

        $hasTransactionDocument = Schema::hasColumn('merchant_transactions', 'document_type');
        $hasReceiptDocument = Schema::hasColumn('transaction_receipts', 'document_type');
        $hasTransactionTemplate = Schema::hasColumn('merchant_transactions', 'bir_template_code');
        $hasReceiptTemplate = Schema::hasColumn('transaction_receipts', 'bir_template_code');

        if ($hasTransactionDocument && $hasReceiptDocument) {
            $columns[] = DB::raw("COALESCE(NULLIF(r.document_type, ''), NULLIF(rt.document_type, ''), NULLIF(t.document_type, '')) as resolved_document_type");
        } elseif ($hasReceiptDocument) {
            $columns[] = DB::raw("COALESCE(NULLIF(r.document_type, ''), NULLIF(rt.document_type, '')) as resolved_document_type");
        } elseif ($hasTransactionDocument) {
            $columns[] = DB::raw("NULLIF(t.document_type, '') as resolved_document_type");
        } else {
            $columns[] = DB::raw("NULL as resolved_document_type");
        }

        if ($hasTransactionTemplate && $hasReceiptTemplate) {
            $columns[] = DB::raw("COALESCE(NULLIF(r.bir_template_code, ''), NULLIF(rt.bir_template_code, ''), NULLIF(t.bir_template_code, '')) as resolved_bir_template_code");
        } elseif ($hasReceiptTemplate) {
            $columns[] = DB::raw("COALESCE(NULLIF(r.bir_template_code, ''), NULLIF(rt.bir_template_code, '')) as resolved_bir_template_code");
        } elseif ($hasTransactionTemplate) {
            $columns[] = DB::raw("NULLIF(t.bir_template_code, '') as resolved_bir_template_code");
        } else {
            $columns[] = DB::raw("NULL as resolved_bir_template_code");
        }

        if (Schema::hasColumn('transaction_receipts', 'rdo_code')) {
            $columns[] = DB::raw("COALESCE(NULLIF(t.rdo_code, ''), NULLIF(r.rdo_code, ''), NULLIF(rt.rdo_code, '')) as resolved_rdo_code");
            $columns[] = DB::raw("COALESCE(NULLIF(t.rdo_name, ''), NULLIF(r.rdo_name, ''), NULLIF(rt.rdo_name, '')) as resolved_rdo_name");
        } elseif (Schema::hasColumn('merchant_transactions', 'rdo_code')) {
            $columns[] = DB::raw("NULLIF(t.rdo_code, '') as resolved_rdo_code");
            $columns[] = DB::raw("NULLIF(t.rdo_name, '') as resolved_rdo_name");
        } else {
            $columns[] = DB::raw("NULL as resolved_rdo_code");
            $columns[] = DB::raw("NULL as resolved_rdo_name");
        }

        if (Schema::hasTable('merchant_api_keys') && Schema::hasColumn('merchant_transactions', 'source_api_key_id')) {
            $columns[] = 'k.name as api_key_name';
            $columns[] = 'k.environment as api_environment';
            $columns[] = 'k.branch_name as api_branch_name';
            $columns[] = 'k.branch_code as api_branch_code';
            $columns[] = 'k.branch_type as api_branch_type';
        }

        return $columns;
    }

    private function applyFilters(Builder $query, Request $request): Builder
    {
        $search = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', 'all');
        $channel = (string) $request->query('channel', 'all');
        $branch = (string) $request->query('branch', 'all');
        $payment = (string) $request->query('payment', 'all');

        if ($search !== '') {
            $like = "%{$search}%";
            $query->where(function (Builder $inner) use ($like) {
                $inner
                    ->where('t.id', 'like', $like)
                    ->orWhere('t.transaction_ref', 'like', $like)
                    ->orWhere('t.customer_name', 'like', $like)
                    ->orWhere('t.customer_tin', 'like', $like)
                    ->orWhere('t.branch', 'like', $like)
                    ->orWhere('r.receipt_number', 'like', $like)
                    ->orWhere('rt.receipt_number', 'like', $like);

                if (Schema::hasColumn('merchant_transactions', 'source_system')) {
                    $inner->orWhere('t.source_system', 'like', $like);
                }

                if (Schema::hasColumn('merchant_transactions', 'rdo_code')) {
                    $inner
                        ->orWhere('t.rdo_code', 'like', $like)
                        ->orWhere('t.rdo_name', 'like', $like);
                }
            });
        }

        if ($status === 'completed') {
            $query->whereIn(DB::raw('LOWER(COALESCE(t.status, ""))'), self::SUCCESS_STATUSES);
        } elseif ($status === 'voided') {
            $query->whereIn(DB::raw('LOWER(COALESCE(t.status, ""))'), self::VOID_STATUSES);
        } elseif ($status === 'pending') {
            $query->whereIn(DB::raw('LOWER(COALESCE(t.status, ""))'), self::PENDING_STATUSES);
        }

        if ($channel !== '' && $channel !== 'all') {
            $this->applyChannelFilter($query, $channel);
        }

        if ($branch !== '' && $branch !== 'all') {
            $branchExpression = Schema::hasTable('merchant_api_keys') && Schema::hasColumn('merchant_transactions', 'source_api_key_id')
                ? 'LOWER(COALESCE(k.branch_name, t.branch, "main office"))'
                : 'LOWER(COALESCE(t.branch, "main office"))';

            $query->whereRaw("{$branchExpression} = ?", [strtolower($branch)]);
        }

        if ($payment !== '' && $payment !== 'all') {
            $query->where('t.payment_method', $payment);
        }

        return $query;
    }

    private function applyChannelFilter(Builder $query, string $channel): void
    {
        $channel = strtolower($channel);
        $hasSourceSystem = Schema::hasColumn('merchant_transactions', 'source_system');

        $query->where(function (Builder $inner) use ($channel, $hasSourceSystem) {
            $channelExpressions = ['LOWER(COALESCE(t.channel, ""))'];

            if ($hasSourceSystem) {
                $channelExpressions[] = 'LOWER(COALESCE(t.source_system, ""))';
            }

            foreach ($channelExpressions as $expression) {
                match ($channel) {
                    'pos' => $inner->orWhereRaw("{$expression} LIKE ?", ['%pos%']),
                    'e-commerce' => $inner
                        ->orWhereRaw("{$expression} LIKE ?", ['%ecommerce%'])
                        ->orWhereRaw("{$expression} LIKE ?", ['%e-commerce%'])
                        ->orWhereRaw("{$expression} LIKE ?", ['%online%']),
                    'mobile' => $inner->orWhereRaw("{$expression} LIKE ?", ['%mobile%']),
                    'corporate' => $inner
                        ->orWhereRaw("{$expression} LIKE ?", ['%corp%'])
                        ->orWhereRaw("{$expression} LIKE ?", ['%b2b%']),
                    'api' => $inner->orWhereRaw("{$expression} LIKE ?", ['%api%']),
                    default => $inner->orWhereRaw("{$expression} = ?", [$channel]),
                };
            }
        });
    }

    private function summary(Builder $query): array
    {
        $rows = $query->get();
        $completed = $rows->filter(fn ($row) => $this->statusGroup((string) ($row->status ?? '')) === 'completed');
        $voided = $rows->filter(fn ($row) => $this->statusGroup((string) ($row->status ?? '')) === 'voided');
        $pending = $rows->filter(fn ($row) => $this->statusGroup((string) ($row->status ?? '')) === 'pending');
        $revenue = (float) $completed->sum(fn ($row) => (float) $row->amount);
        $vat = (float) $completed->sum(fn ($row) => (float) $row->vat_amount);

        return [
            'total_transactions' => $rows->count(),
            'completed_transactions' => $completed->count(),
            'voided_transactions' => $voided->count(),
            'pending_transactions' => $pending->count(),
            'total_revenue' => round($revenue, 2),
            'total_vat' => round($vat, 2),
            'average_transaction' => $completed->count() > 0 ? round($revenue / $completed->count(), 2) : 0,
            'void_rate' => $rows->count() > 0 ? round(($voided->count() / $rows->count()) * 100, 1) : 0.0,
        ];
    }

    private function weeklySeries(Builder $query): array
    {
        $rows = $query->get();
        $latest = $rows->max('created_at');
        $anchor = $latest ? Carbon::parse($latest)->startOfDay() : now()->startOfDay();
        $start = $anchor->copy()->subDays(6);

        return collect(range(0, 6))->map(function (int $offset) use ($rows, $start) {
            $day = $start->copy()->addDays($offset);
            $dayRows = $rows->filter(fn ($row) => Carbon::parse($row->created_at)->isSameDay($day));

            return [
                'day' => $day->format('D'),
                'date' => $day->format('Y-m-d'),
                'completed' => $dayRows->filter(fn ($row) => $this->statusGroup((string) ($row->status ?? '')) === 'completed')->count(),
                'voided' => $dayRows->filter(fn ($row) => $this->statusGroup((string) ($row->status ?? '')) === 'voided')->count(),
                'pending' => $dayRows->filter(fn ($row) => $this->statusGroup((string) ($row->status ?? '')) === 'pending')->count(),
                'revenue' => round((float) $dayRows->filter(fn ($row) => $this->statusGroup((string) ($row->status ?? '')) === 'completed')->sum(fn ($row) => (float) $row->amount), 2),
            ];
        })->values()->all();
    }

    private function channelRevenue(Builder $query): array
    {
        return $query
            ->get()
            ->filter(fn ($row) => $this->statusGroup((string) ($row->status ?? '')) === 'completed')
            ->groupBy(fn ($row) => $this->channel($row))
            ->map(fn ($rows, $channel) => [
                'channel' => $channel,
                'amount' => round((float) $rows->sum(fn ($row) => (float) $row->amount), 2),
                'transactions' => $rows->count(),
            ])
            ->sortByDesc('amount')
            ->values()
            ->all();
    }

    private function optionList(Builder $query, string $column): array
    {
        return $query
            ->get()
            ->map(fn ($row) => $column === 'channel' ? $this->channel($row) : (string) ($row->{$column} ?? ''))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function branches(Builder $query): array
    {
        return $query
            ->get()
            ->map(fn ($row) => (string) (($row->api_branch_name ?? null) ?: ($row->branch ?? null) ?: 'Main Office'))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function statuses(Builder $query): array
    {
        return $query
            ->get()
            ->map(fn ($row) => $this->statusGroup((string) ($row->status ?? '')))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function transactionPayload(object $row): array
    {
        $created = Carbon::parse($row->created_at)->timezone(config('app.timezone'));
        $channel = $this->channel($row);
        $documentType = $this->documentType($row);
        $templateCode = $row->resolved_bir_template_code ?: $this->templateCode($documentType);

        return [
            'db_id' => (string) $row->id,
            'id' => (string) (($row->transaction_ref ?? null) ?: $row->id),
            'transaction_ref' => $row->transaction_ref,
            'receipt_number' => $row->receipt_number,
            'series_number' => $row->series_number,
            'permit_to_issue_number' => $row->permit_to_issue_number ?? null,
            'esp_accreditation_number' => $row->esp_accreditation_number ?? null,
            'date' => $created->format('Y-m-d'),
            'time' => $created->format('H:i'),
            'created_at' => $created->toIso8601String(),
            'amount' => round((float) $row->amount, 2),
            'vat' => round((float) $row->vat_amount, 2),
            'vatable_sales' => round((float) $row->vatable_sales, 2),
            'net' => round((float) $row->net_amount, 2),
            'status' => $this->statusGroup((string) ($row->status ?? '')),
            'raw_status' => (string) ($row->status ?? ''),
            'type' => $channel,
            'channel' => (string) ($row->channel ?? 'api'),
            'source_system' => (string) (($row->source_system ?? null) ?: ($row->api_key_name ?? null) ?: 'NUERS API'),
            'api_key_name' => $row->api_key_name ?? null,
            'customer' => (string) (($row->customer_name ?? null) ?: 'Walk-in Customer'),
            'customer_tin' => $row->customer_tin ?? null,
            'branch' => (string) (($row->api_branch_name ?? null) ?: ($row->branch ?? null) ?: 'Main Office'),
            'branch_code' => ($row->api_branch_code ?? null) ?: ($row->branch_code ?? null),
            'branch_type' => ($row->api_branch_type ?? null) ?: ($row->branch_type ?? null),
            'rdo_code' => $row->resolved_rdo_code,
            'rdo_name' => $row->resolved_rdo_name,
            'rdo_branch' => $this->formatRdo($row->resolved_rdo_code, $row->resolved_rdo_name),
            'payment' => (string) ($row->payment_method ?: 'cash'),
            'cashier' => (string) (($row->source_system ?? null) ?: ($row->api_key_name ?? null) ?: 'API Integration'),
            'tax_type' => (string) ($row->tax_type ?? ''),
            'tax_classification' => $this->taxClassification($row),
            'document_type' => $documentType,
            'bir_template_code' => $templateCode,
            'document_label' => $this->documentLabel($documentType),
        ];
    }

    private function statusGroup(string $status): string
    {
        $status = strtolower(trim($status));

        if (in_array($status, self::VOID_STATUSES, true)) {
            return 'voided';
        }

        if (in_array($status, self::PENDING_STATUSES, true)) {
            return 'pending';
        }

        if (in_array($status, self::SUCCESS_STATUSES, true)) {
            return 'completed';
        }

        return 'pending';
    }

    private function channel(object $row): string
    {
        $value = (string) (($row->channel ?? null) ?: ($row->source_system ?? null) ?: 'api');
        $normalized = strtolower(str_replace(['_', '-'], ' ', $value));

        return match (true) {
            str_contains($normalized, 'pos') => 'POS',
            str_contains($normalized, 'e commerce'), str_contains($normalized, 'ecommerce'), str_contains($normalized, 'online') => 'E-Commerce',
            str_contains($normalized, 'mobile') => 'Mobile',
            str_contains($normalized, 'corp'), str_contains($normalized, 'b2b') => 'Corporate',
            default => 'API',
        };
    }

    private function taxClassification(object $row): string
    {
        $taxType = strtolower((string) ($row->tax_type ?? ''));

        return match (true) {
            $taxType === 'vatable', (float) $row->vat_amount > 0, (float) $row->vatable_sales > 0 => 'VATable',
            $taxType === 'zero_rated' => 'Zero-rated',
            $taxType === 'vat_exempt' => 'VAT-exempt',
            $taxType === 'non_vat' => 'Non-VAT',
            default => 'Unclassified',
        };
    }

    private function formatRdo(?string $code, ?string $name): ?string
    {
        $code = trim((string) $code);
        $name = trim((string) $name);

        if ($code === '' && $name === '') {
            return null;
        }

        return trim($code.' · '.$name, ' ·');
    }

    private function documentType(object $row): string
    {
        $explicit = trim((string) ($row->resolved_document_type ?? ''));

        if ($explicit !== '') {
            return match ($explicit) {
                'sales_invoice' => $this->documentTypeFromTax($row),
                'official_receipt' => 'payment_receipt',
                default => $explicit,
            };
        }

        $transactionType = strtolower((string) ($row->transaction_type ?? ''));

        if (str_contains($transactionType, 'payment') || str_contains($transactionType, 'receipt') || str_contains($transactionType, 'collection') || str_contains($transactionType, 'settlement')) {
            return 'payment_receipt';
        }

        return $this->documentTypeFromTax($row);
    }

    private function documentTypeFromTax(object $row): string
    {
        $taxType = strtolower((string) ($row->tax_type ?? ''));

        return match (true) {
            $taxType === 'zero_rated' || (float) ($row->zero_rated_sales ?? 0) > 0 => 'zero_rated_invoice',
            $taxType === 'vat_exempt' => 'vat_exempt_invoice',
            $taxType === 'non_vat' => 'non_vat_invoice',
            (float) $row->vat_amount > 0, (float) $row->vatable_sales > 0 => 'vat_invoice',
            default => 'non_vat_invoice',
        };
    }

    private function templateCode(string $documentType): string
    {
        return [
            'vat_invoice' => 'B1',
            'non_vat_invoice' => 'B2',
            'vat_exempt_invoice' => 'B3',
            'zero_rated_invoice' => 'B4',
            'mixed_sales_invoice' => 'B5',
            'payment_receipt' => 'B6',
        ][$documentType] ?? 'B1';
    }

    private function documentLabel(string $documentType): string
    {
        return [
            'vat_invoice' => 'B1 VAT Invoice',
            'non_vat_invoice' => 'B2 Non-VAT Invoice',
            'vat_exempt_invoice' => 'B3 VAT-Exempt Sales Invoice',
            'zero_rated_invoice' => 'B4 Zero-Rated Sales Invoice',
            'mixed_sales_invoice' => 'B5 Mixed Sales Invoice',
            'payment_receipt' => 'B6 Payment Receipt',
        ][$documentType] ?? 'B1 VAT Invoice';
    }
}
