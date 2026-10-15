<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MerchantReceiptController extends Controller
{
    private const VALID_STATUSES = ['issued', 'verified', 'valid', 'completed', 'paid', 'settled'];
    private const VOID_STATUSES = ['voided', 'void', 'cancelled', 'canceled', 'refunded', 'refund'];

    public function index(Request $request): JsonResponse
    {
        $merchant = $this->currentMerchant($request);

        abort_unless(Schema::hasTable('transaction_receipts'), 500, 'transaction_receipts table is not migrated.');
        abort_unless(Schema::hasTable('merchant_transactions'), 500, 'merchant_transactions table is not migrated.');

        $base = $this->baseReceiptQuery($merchant);
        $filtered = $this->applyFilters(clone $base, $request);

        $receipts = $filtered
            ->orderByRaw('COALESCE(r.issued_at, r.created_at) DESC')
            ->limit(500)
            ->get()
            ->map(fn ($row) => $this->receiptPayload($row))
            ->values();

        return response()->json([
            'merchant' => [
                'id' => (string) $merchant->id,
                'business_name' => (string) $merchant->business_name,
                'tin' => (string) ($merchant->tin ?? ''),
            ],
            'summary' => $this->summary(clone $base),
            'filters' => [
                'channels' => $this->channels(clone $base),
                'sources' => $this->sources(clone $base),
            ],
            'receipts' => $receipts,
        ]);
    }

    public function verify(Request $request): JsonResponse
    {
        $merchant = $this->currentMerchant($request);
        $needle = trim((string) $request->query('receipt', ''));

        abort_if($needle === '', 422, 'Receipt number or series number is required.');

        $receipt = $this->baseReceiptQuery($merchant)
            ->where(function (Builder $query) use ($needle) {
                $query
                    ->where('r.receipt_number', $needle)
                    ->orWhere('r.series_number', $needle)
                    ->orWhere('t.transaction_ref', $needle);
            })
            ->first();

        abort_unless($receipt, 404, 'No API-generated receipt was found for this Business Account.');

        return response()->json([
            'receipt' => $this->receiptPayload($receipt),
        ]);
    }

    private function currentMerchant(Request $request): object
    {
        $token = $request->bearerToken();

        abort_unless($token && Schema::hasTable('users'), 401, 'Authenticated Business Account session is required.');

        $user = DB::table('users')->where('api_token', $token)->first();

        abort_unless($user, 401, 'Authenticated Business Account session is required.');
        abort_unless(Schema::hasTable('merchants'), 500, 'merchants table is not migrated.');

        $merchant = DB::table('merchants')
            ->where(function ($query) use ($user) {
                $query
                    ->where('merchant_account_id', $user->id)
                    ->orWhere('merchant_account_email', $user->email)
                    ->orWhere('email', $user->email);
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

    private function baseReceiptQuery(object $merchant): Builder
    {
        $query = DB::table('transaction_receipts as r')
            ->leftJoin('merchant_transactions as t', 'r.transaction_id', '=', 't.id')
            ->where(function (Builder $scope) use ($merchant) {
                $scope
                    ->where('r.merchant_id', $merchant->id)
                    ->orWhere('t.merchant_id', $merchant->id);
            })
            ->where(function (Builder $source) {
                $source->where('r.receipt_type', 'api_transaction_receipt');

                if (Schema::hasColumn('merchant_transactions', 'source_api_key_id')) {
                    $source->orWhereNotNull('t.source_api_key_id');
                }

                if (Schema::hasColumn('merchant_transactions', 'source_system')) {
                    $source->orWhereNotNull('t.source_system');
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
            'r.id',
            'r.transaction_id',
            'r.merchant_id',
            'r.receipt_number',
            'r.permit_to_issue_number',
            'r.esp_accreditation_number',
            'r.series_number',
            'r.bir_accreditation',
            'r.receipt_type',
            'r.merchant_name',
            'r.merchant_tin',
            'r.merchant_address',
            'r.merchant_vat_reg',
            'r.buyer_name',
            'r.buyer_tin',
            'r.gross_amount',
            'r.discount_amount',
            'r.vatable_sales',
            'r.vat_exempt_sales',
            'r.zero_rated_sales',
            'r.vat_amount',
            'r.total_due',
            'r.items',
            'r.status',
            'r.issued_at',
            'r.created_at',
            't.transaction_ref',
            't.payment_method',
            't.channel',
            't.branch',
            't.transaction_type',
            't.customer_name',
            't.customer_tin',
            't.status as transaction_status',
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

        $hasReceiptDocument = Schema::hasColumn('transaction_receipts', 'document_type');
        $hasTransactionDocument = Schema::hasColumn('merchant_transactions', 'document_type');
        $hasReceiptTemplate = Schema::hasColumn('transaction_receipts', 'bir_template_code');
        $hasTransactionTemplate = Schema::hasColumn('merchant_transactions', 'bir_template_code');

        if ($hasReceiptDocument && $hasTransactionDocument) {
            $columns[] = DB::raw("COALESCE(NULLIF(r.document_type, ''), NULLIF(t.document_type, '')) as resolved_document_type");
        } elseif ($hasReceiptDocument) {
            $columns[] = DB::raw("NULLIF(r.document_type, '') as resolved_document_type");
        } elseif ($hasTransactionDocument) {
            $columns[] = DB::raw("NULLIF(t.document_type, '') as resolved_document_type");
        } else {
            $columns[] = DB::raw("NULL as resolved_document_type");
        }

        if ($hasReceiptTemplate && $hasTransactionTemplate) {
            $columns[] = DB::raw("COALESCE(NULLIF(r.bir_template_code, ''), NULLIF(t.bir_template_code, '')) as resolved_bir_template_code");
        } elseif ($hasReceiptTemplate) {
            $columns[] = DB::raw("NULLIF(r.bir_template_code, '') as resolved_bir_template_code");
        } elseif ($hasTransactionTemplate) {
            $columns[] = DB::raw("NULLIF(t.bir_template_code, '') as resolved_bir_template_code");
        } else {
            $columns[] = DB::raw("NULL as resolved_bir_template_code");
        }

        if (Schema::hasColumn('transaction_receipts', 'rdo_code')) {
            $columns[] = 'r.rdo_code as receipt_rdo_code';
            $columns[] = 'r.rdo_name as receipt_rdo_name';
        }

        if (Schema::hasTable('merchant_api_keys') && Schema::hasColumn('merchant_transactions', 'source_api_key_id')) {
            $columns[] = 'k.name as api_key_name';
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

        if ($search !== '') {
            $like = "%{$search}%";
            $query->where(function (Builder $inner) use ($like) {
                $inner
                    ->where('r.receipt_number', 'like', $like)
                    ->orWhere('r.series_number', 'like', $like)
                    ->orWhere('r.buyer_name', 'like', $like)
                    ->orWhere('r.buyer_tin', 'like', $like)
                    ->orWhere('t.transaction_ref', 'like', $like)
                    ->orWhere('t.customer_name', 'like', $like);

                if (Schema::hasColumn('merchant_transactions', 'source_system')) {
                    $inner->orWhere('t.source_system', 'like', $like);
                }
            });
        }

        if ($status === 'valid') {
            $query->whereIn(DB::raw('LOWER(COALESCE(r.status, t.status, ""))'), self::VALID_STATUSES);
        } elseif ($status === 'voided') {
            $query->whereIn(DB::raw('LOWER(COALESCE(r.status, t.status, ""))'), self::VOID_STATUSES);
        } elseif ($status === 'pending') {
            $query->whereNotIn(DB::raw('LOWER(COALESCE(r.status, t.status, ""))'), [...self::VALID_STATUSES, ...self::VOID_STATUSES]);
        }

        if ($channel !== 'all' && $channel !== '') {
            $query->whereRaw('LOWER(COALESCE(t.channel, t.source_system, r.receipt_type, "")) = ?', [strtolower($channel)]);
        }

        return $query;
    }

    private function summary(Builder $query): array
    {
        $rows = $query->get();
        $valid = $rows->filter(fn ($row) => $this->statusGroup((string) ($row->status ?? $row->transaction_status ?? '')) === 'valid');
        $voided = $rows->filter(fn ($row) => $this->statusGroup((string) ($row->status ?? $row->transaction_status ?? '')) === 'voided');

        return [
            'total_receipts' => $rows->count(),
            'valid_receipts' => $valid->count(),
            'voided_receipts' => $voided->count(),
            'pending_receipts' => max(0, $rows->count() - $valid->count() - $voided->count()),
            'total_revenue' => round((float) $valid->sum(fn ($row) => (float) ($row->total_due ?: $row->gross_amount)), 2),
            'total_vat' => round((float) $valid->sum(fn ($row) => (float) $row->vat_amount), 2),
            'void_rate' => $rows->count() > 0 ? round(($voided->count() / $rows->count()) * 100, 1) : 0.0,
        ];
    }

    private function channels(Builder $query): array
    {
        return $query
            ->get()
            ->map(fn ($row) => $this->channel($row))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function sources(Builder $query): array
    {
        return $query
            ->get()
            ->map(fn ($row) => (string) (($row->source_system ?? null) ?: ($row->api_key_name ?? null) ?: 'NUERS API'))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function receiptPayload(object $row): array
    {
        $issuedAt = Carbon::parse($row->issued_at ?: $row->created_at);
        $status = $this->statusGroup((string) ($row->status ?: $row->transaction_status ?: 'issued'));
        $items = $this->items($row);
        $documentType = $this->documentType($row);
        $templateCode = $row->resolved_bir_template_code ?: $this->templateCode($documentType);

        return [
            'db_id' => (string) $row->id,
            'id' => (string) $row->receipt_number,
            'transaction_id' => $row->transaction_id,
            'transaction_ref' => $row->transaction_ref,
            'series_no' => (string) (($row->series_number ?? null) ?: $this->seriesFromReceipt((string) $row->receipt_number)),
            'date' => $issuedAt->format('n/j/Y'),
            'time' => $issuedAt->format('H:i'),
            'issued_at' => $issuedAt->toIso8601String(),
            'type' => $this->channel($row),
            'source_system' => (string) (($row->source_system ?? null) ?: ($row->api_key_name ?? null) ?: 'NUERS API'),
            'api_key_name' => $row->api_key_name ?? null,
            'customer' => (string) (($row->buyer_name ?? null) ?: ($row->customer_name ?? null) ?: 'Walk-in Customer'),
            'amount' => round((float) ($row->total_due ?: $row->gross_amount), 2),
            'vat' => round((float) $row->vat_amount, 2),
            'vatable_sales' => round((float) $row->vatable_sales, 2),
            'vat_exempt_sales' => round((float) $row->vat_exempt_sales, 2),
            'zero_rated_sales' => round((float) $row->zero_rated_sales, 2),
            'status' => $status,
            'raw_status' => (string) ($row->status ?? ''),
            'tin_buyer' => ($row->buyer_tin ?? null) ?: ($row->customer_tin ?? null),
            'branch' => (string) (($row->api_branch_name ?? null) ?: ($row->branch ?? null) ?: 'Main Office'),
            'branch_code' => ($row->api_branch_code ?? null) ?: ($row->branch_code ?? null),
            'branch_type' => ($row->api_branch_type ?? null) ?: ($row->branch_type ?? null),
            'cashier' => (string) (($row->source_system ?? null) ?: ($row->api_key_name ?? null) ?: 'API Integration'),
            'receipt_type' => (string) ($row->receipt_type ?? 'api_transaction_receipt'),
            'merchant_name' => (string) $row->merchant_name,
            'merchant_tin' => (string) $row->merchant_tin,
            'permit_to_issue_number' => $row->permit_to_issue_number ?? null,
            'esp_accreditation_number' => $row->esp_accreditation_number ?? null,
            'merchant_address' => (string) ($row->merchant_address ?? ''),
            'merchant_vat_reg' => (string) ($row->merchant_vat_reg ?? ''),
            'rdo_code' => ($row->receipt_rdo_code ?? null) ?: ($row->rdo_code ?? null),
            'rdo_name' => ($row->receipt_rdo_name ?? null) ?: ($row->rdo_name ?? null),
            'rdo_branch' => $this->formatRdo(($row->receipt_rdo_code ?? null) ?: ($row->rdo_code ?? null), ($row->receipt_rdo_name ?? null) ?: ($row->rdo_name ?? null)),
            'tax_type' => (string) ($row->tax_type ?? ''),
            'document_type' => $documentType,
            'bir_template_code' => $templateCode,
            'document_label' => $this->documentLabel($documentType),
            'payment_method' => (string) ($row->payment_method ?? ''),
            'items' => $items,
        ];
    }

    private function items(object $row): array
    {
        $items = json_decode((string) ($row->items ?? '[]'), true);

        if (! is_array($items)) {
            $items = [];
        }

        $mapped = collect($items)
            ->filter(fn ($item) => is_array($item))
            ->map(function (array $item) {
                $qty = (float) ($item['quantity'] ?? $item['qty'] ?? 1);
                $unitPrice = (float) ($item['unit_price'] ?? $item['price'] ?? 0);
                $lineTotal = (float) ($item['line_total'] ?? $item['amount'] ?? ($unitPrice * $qty));

                return [
                    'desc' => (string) ($item['description'] ?? $item['desc'] ?? $item['name'] ?? 'External line item'),
                    'qty' => $qty,
                    'unit' => (string) ($item['unit'] ?? 'pc'),
                    'price' => round($lineTotal, 2),
                ];
            })
            ->values()
            ->all();

        if ($mapped !== []) {
            return $mapped;
        }

        return [[
            'desc' => 'API transaction total',
            'qty' => 1,
            'unit' => 'txn',
            'price' => round((float) ($row->total_due ?: $row->gross_amount), 2),
        ]];
    }

    private function channel(object $row): string
    {
        $value = (string) (($row->channel ?? null) ?: ($row->source_system ?? null) ?: 'api');
        $normalized = strtolower(str_replace(['_', '-'], ' ', $value));

        return match (true) {
            str_contains($normalized, 'pos') => 'POS',
            str_contains($normalized, 'e commerce'), str_contains($normalized, 'ecommerce'), str_contains($normalized, 'online') => 'E-Commerce',
            str_contains($normalized, 'mobile') => 'Mobile',
            default => 'API',
        };
    }

    private function statusGroup(string $status): string
    {
        $status = strtolower(trim($status));

        if (in_array($status, self::VOID_STATUSES, true)) {
            return 'voided';
        }

        if (in_array($status, self::VALID_STATUSES, true)) {
            return 'valid';
        }

        return 'pending';
    }

    private function seriesFromReceipt(string $receiptNumber): string
    {
        if (preg_match('/(20\d{2}).*?(\d{4,})$/', $receiptNumber, $matches)) {
            return "{$matches[1]}-{$matches[2]}";
        }

        return $receiptNumber;
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
            $taxType === 'zero_rated' || (float) $row->zero_rated_sales > 0 => 'zero_rated_invoice',
            $taxType === 'vat_exempt' || (float) $row->vat_exempt_sales > 0 => 'vat_exempt_invoice',
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
