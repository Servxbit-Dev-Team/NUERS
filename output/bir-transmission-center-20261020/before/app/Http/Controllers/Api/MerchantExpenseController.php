<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApiAccess;
use App\Services\BirCompliance;
use App\Services\EisDocumentReadiness;
use App\Services\ExpenseCreditEvidence;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MerchantExpenseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless(Schema::hasTable('merchant_expenses'), 500, 'merchant_expenses table is not migrated.');

        $merchant = $this->resolveMerchant($request);
        // Reads never import or mutate financial records.

        $query = $this->expenseQuery($merchant);
        $filtered = $this->applyFilters(clone $query, $request);
        $rows = $filtered
            ->orderByRaw('COALESCE(issued_at, created_at) DESC')
            ->limit(250)
            ->get()
            ->map(fn ($row) => $this->expensePayload($row))
            ->values();

        return response()->json([
            'merchant' => $merchant ? [
                'id' => $merchant->id,
                'business_name' => $merchant->business_name,
                'tin' => $merchant->tin,
            ] : null,
            'summary' => $this->summary(clone $query),
            'series' => [
                'monthly' => $this->monthlySeries(clone $query),
                'categories' => $this->categorySeries(clone $query),
            ],
            'filters' => [
                'categories' => $this->distinctOptions(clone $query, 'expense_category'),
                'statuses' => $this->distinctOptions(clone $query, 'validation_status'),
                'suppliers' => $this->distinctOptions(clone $query, 'supplier_name'),
            ],
            'expenses' => $rows,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $merchant = app(ApiAccess::class)->merchant($request);
        $v = $request->validate(['source_invoice_id' => 'required|string|max:80', 'expense_category' => 'required|string|max:120', 'evidence_reference' => 'required|string|min:10|max:2000']);
        $invoice = DB::table('business_invoices')->where('id', $v['source_invoice_id'])->whereIn('document_type', ['vat_invoice', 'non_vat_invoice', 'vat_exempt_invoice', 'zero_rated_invoice', 'mixed_sales_invoice', 'sales_invoice', 'service_invoice'])->whereNotIn('status', ['draft', 'cancelled', 'voided'])->first();
        abort_unless($invoice && $invoice->merchant_id !== $merchant->id && $this->normalizeTin($invoice->buyer_tin) === $this->normalizeTin($merchant->tin), 404, 'No original supplier invoice addressed to this business was found.');
        $review = app(EisDocumentReadiness::class)->inspect('invoice', $invoice->id);
        abort_unless($review['financial_validation_passed'], 409, 'Original supplier invoice does not reconcile.');
        $id = (string) Str::uuid();

        return DB::transaction(function () use ($id, $merchant, $invoice, $v) {
            DB::table('merchants')->where('id', $merchant->id)->lockForUpdate()->first();
            $old = DB::table('merchant_expenses')->where('merchant_id', $merchant->id)->where('source_invoice_id', $invoice->id)->first();
            if ($old) {
                return response()->json(['id' => $old->id, 'duplicate' => true, 'claim_status' => $old->claim_status]);
            }
            DB::table('merchant_expenses')->insert(['id' => $id, 'merchant_id' => $merchant->id, 'supplier_merchant_id' => $invoice->merchant_id, 'source_invoice_id' => $invoice->id, 'source_invoice_reference' => $invoice->invoice_number, 'supplier_name' => $invoice->merchant_name, 'supplier_tin' => $invoice->merchant_tin, 'buyer_name' => $merchant->business_name, 'buyer_tin' => $merchant->tin, 'expense_category' => $v['expense_category'], 'gross_amount' => $invoice->total_amount, 'vatable_amount' => $invoice->taxable_amount, 'input_vat_amount' => $invoice->vat_amount, 'withholding_tax_amount' => $invoice->withholding_tax, 'net_payable' => $invoice->amount_due, 'issued_at' => $invoice->issue_date, 'validation_status' => 'pending_review', 'reconciliation_status' => 'source_reconciled', 'claim_status' => 'pending_review', 'metadata' => json_encode(['source_invoice_id' => $invoice->id, 'evidence_reference' => $v['evidence_reference']]), 'created_at' => now(), 'updated_at' => now()]);
            app(BirCompliance::class)->audit('expense.review.requested', $id, 'merchant:'.$merchant->id, ['source_invoice_id' => $invoice->id, 'claim_status' => 'pending_review']);

            return response()->json(['id' => $id, 'claim_status' => 'pending_review'], 202);
        });
    }

    public function review(Request $r, string $id): JsonResponse
    {
        $u = app(ApiAccess::class)->require($r, ['admin', 'super_admin']);
        $v = $r->validate(['status' => 'required|in:approved,rejected', 'evidence_reference' => 'required|string|min:10|max:2000', 'reason' => 'required|string|min:10|max:2000']);
        DB::transaction(function () use ($u, $v, $id) {
            $expense = DB::table('merchant_expenses')->where('id', $id)->lockForUpdate()->first();
            abort_unless($expense, 404);
            $meta = json_decode($expense->metadata ?? '{}', true) ?: [];
            $source = DB::table('business_invoices')->where('id', $expense->source_invoice_id ?? $meta['source_invoice_id'] ?? $meta['business_invoice_id'] ?? '')->whereNotIn('status', ['draft', 'cancelled', 'voided'])->first();
            abort_unless($source && in_array($source->document_type, ['vat_invoice', 'non_vat_invoice', 'vat_exempt_invoice', 'zero_rated_invoice', 'mixed_sales_invoice', 'sales_invoice', 'service_invoice'], true), 409, 'An original primary supplier invoice is required. Corrective documents require a separate lawful allocation review.');
            $buyer = DB::table('merchants')->where('id', $expense->merchant_id)->first();
            abort_if((string) ($buyer->merchant_account_id ?? '') === (string) $u->id, 403, 'An independent reviewer is required.');
            abort_unless($this->normalizeTin($source->buyer_tin) === $this->normalizeTin($buyer->tin) && (int) round($source->vat_amount * 100) === (int) round($expense->input_vat_amount * 100), 409, 'Original buyer identity and tax amount must reconcile.');
            abort_unless(app(EisDocumentReadiness::class)->inspect('invoice', $source->id)['financial_validation_passed'], 409, 'Source financial review failed.');
            $meta['claim_review'] = ['reviewed_by' => (string) $u->id, 'reviewed_at' => now()->toIso8601String(), 'evidence_reference' => $v['evidence_reference'], 'reason' => $v['reason'], 'source_hash' => app(ExpenseCreditEvidence::class)->fingerprint($source)];
            DB::table('merchant_expenses')->where('id', $id)->update(['claim_status' => $v['status'], 'validation_status' => $v['status'], 'metadata' => json_encode($meta), 'updated_at' => now()]);
            app(BirCompliance::class)->audit('expense.claim.reviewed', $id, (string) $u->id, $v);
        });

        return response()->json(['ok' => true, 'message' => 'Original-source expense review recorded. Tax treatment remains subject to the cited evidence.']);
    }

    private function resolveMerchant(Request $request): ?object
    {
        return app(ApiAccess::class)->merchant($request);
    }

    private function expenseQuery(?object $merchant): Builder
    {
        $query = DB::table('merchant_expenses');

        if ($merchant) {
            $query->where('merchant_id', $merchant->id);
        }

        return $query;
    }

    private function applyFilters(Builder $query, Request $request): Builder
    {
        $search = trim((string) $request->query('search', ''));
        $category = (string) $request->query('category', 'all');
        $status = (string) $request->query('status', 'all');
        $supplier = (string) $request->query('supplier', 'all');

        if ($category !== 'all' && $category !== '') {
            $query->where('expense_category', $category);
        }

        if ($status !== 'all' && $status !== '') {
            $query->where('validation_status', $status);
        }

        if ($supplier !== 'all' && $supplier !== '') {
            $query->where('supplier_name', $supplier);
        }

        if ($search !== '') {
            $like = "%{$search}%";
            $query->where(function (Builder $inner) use ($like) {
                $inner
                    ->where('source_invoice_reference', 'like', $like)
                    ->orWhere('purchase_order_reference', 'like', $like)
                    ->orWhere('supplier_name', 'like', $like)
                    ->orWhere('supplier_tin', 'like', $like)
                    ->orWhere('expense_category', 'like', $like)
                    ->orWhere('description', 'like', $like);
            });
        }

        return $query;
    }

    private function summary(Builder $query): array
    {
        $totalRows = (clone $query)->count();
        $totals = (clone $query)->selectRaw('
            COALESCE(SUM(gross_amount), 0) as gross,
            COALESCE(SUM(input_vat_amount), 0) as input_vat,
            COALESCE(SUM(withholding_tax_amount), 0) as withholding,
            COALESCE(SUM(net_payable), 0) as net_payable
        ')->first();

        $credit = 0;
        $seen = [];
        foreach ((clone $query)->where('claim_status', 'approved')->cursor() as $expense) {
            $source = app(ExpenseCreditEvidence::class)->source($expense);
            if ($source && ! isset($seen[$source->id])) {
                $seen[$source->id] = true;
                $credit += (int) round($expense->input_vat_amount * 100);
            }
        }

        return [
            'total_expenses' => (float) ($totals?->gross ?? 0),
            'input_vat' => $credit / 100,
            'recorded_input_vat' => (float) ($totals?->input_vat ?? 0),
            'withholding_tax' => (float) ($totals?->withholding ?? 0),
            'net_payable' => (float) ($totals?->net_payable ?? 0),
            'expense_count' => $totalRows,
            'supplier_count' => (clone $query)->distinct('supplier_tin')->count('supplier_tin'),
            'pending_validation' => (clone $query)->whereIn('validation_status', ['pending', 'pending_review', 'pending_validation', 'under_review', 'mismatch', 'bir_flagged'])->count(),
            'high_risk' => (clone $query)->whereIn('risk_level', ['High', 'Critical', 'Red'])->count(),
        ];
    }

    private function monthlySeries(Builder $query): array
    {
        $start = now()->copy()->subMonths(5)->startOfMonth();
        $rows = (clone $query)
            ->selectRaw("DATE_FORMAT(COALESCE(issued_at, created_at), '%Y-%m') as bucket, SUM(gross_amount) as gross, SUM(input_vat_amount) as input_vat, SUM(withholding_tax_amount) as withholding")
            ->whereRaw('COALESCE(issued_at, created_at) >= ?', [$start])
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');

        $series = [];
        for ($i = 5; $i >= 0; $i--) {
            $date = now()->copy()->subMonths($i)->startOfMonth();
            $key = $date->format('Y-m');
            $row = $rows->get($key);

            $series[] = [
                'label' => $date->format('M'),
                'gross' => (float) ($row?->gross ?? 0),
                'input_vat' => (float) ($row?->input_vat ?? 0),
                'withholding' => (float) ($row?->withholding ?? 0),
            ];
        }

        return $series;
    }

    private function categorySeries(Builder $query): array
    {
        return (clone $query)
            ->selectRaw('expense_category as label, COUNT(*) as count, SUM(gross_amount) as amount')
            ->groupBy('expense_category')
            ->orderByDesc('amount')
            ->get()
            ->map(fn ($row) => [
                'label' => $row->label,
                'count' => (int) $row->count,
                'amount' => (float) $row->amount,
            ])
            ->values()
            ->toArray();
    }

    private function distinctOptions(Builder $query, string $column): array
    {
        $values = (clone $query)
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->map(fn ($value) => (string) $value)
            ->values()
            ->all();

        return ['all', ...$values];
    }

    private function expensePayload(object $row): array
    {
        return [
            'id' => $row->id,
            'merchant_id' => $row->merchant_id,
            'supplier_merchant_id' => $row->supplier_merchant_id,
            'source_invoice_reference' => $row->source_invoice_reference,
            'purchase_order_reference' => $row->purchase_order_reference,
            'supplier_name' => $row->supplier_name,
            'supplier_tin' => $row->supplier_tin,
            'buyer_name' => $row->buyer_name,
            'buyer_tin' => $row->buyer_tin,
            'expense_category' => $row->expense_category,
            'description' => $row->description,
            'gross_amount' => (float) $row->gross_amount,
            'vatable_amount' => (float) $row->vatable_amount,
            'input_vat_amount' => (float) $row->input_vat_amount,
            'withholding_tax_amount' => (float) $row->withholding_tax_amount,
            'net_payable' => (float) $row->net_payable,
            'document_type' => $row->document_type,
            'payment_status' => $row->payment_status,
            'validation_status' => $row->validation_status,
            'reconciliation_status' => $row->reconciliation_status,
            'declared_claim_status' => $row->claim_status, 'source_invoice_id' => $row->source_invoice_id ?? null,
            'claim_status' => app(ExpenseCreditEvidence::class)->source($row) ? 'approved' : ($row->claim_status === 'rejected' ? 'rejected' : 'pending_review'),
            'risk_level' => $row->risk_level,
            'ai_score' => $row->ai_score === null ? null : (int) $row->ai_score,
            'issued_at' => $row->issued_at,
            'due_at' => $row->due_at,
            'paid_at' => $row->paid_at,
            'metadata' => json_decode($row->metadata ?? '{}', true) ?: [],
        ];
    }

    private function normalizeStatus(string $status): string
    {
        return Str::of($status)->lower()->replace([' ', '-'], '_')->toString();
    }

    private function expenseId(string $sourceInvoice, string $buyerTin): string
    {
        return $this->expenseRecord($sourceInvoice, $buyerTin)?->id ?: (string) Str::uuid();
    }

    private function expenseRecord(string $sourceInvoice, string $buyerTin): ?object
    {
        return DB::table('merchant_expenses')
            ->where('source_invoice_reference', $sourceInvoice)
            ->where('buyer_tin', $buyerTin)
            ->first(['id', 'issued_at', 'due_at', 'created_at']);
    }

    private function categoryFromDocumentType(string $documentType): string
    {
        $normalized = $this->normalizeStatus($documentType);

        return match (true) {
            str_contains($normalized, 'service') => 'Professional services',
            str_contains($normalized, 'purchase') => 'Inventory',
            str_contains($normalized, 'delivery') => 'Freight',
            str_contains($normalized, 'utility') => 'Utilities',
            default => 'Supplier Invoice',
        };
    }

    private function payloadFirst(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = data_get($payload, $key);

            if ($value !== null && $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    private function supplierMerchantId(?string $tin): ?string
    {
        $normalizedTin = $this->normalizeTin($tin);

        if ($normalizedTin === '' || ! Schema::hasTable('merchants')) {
            return null;
        }

        return DB::table('merchants')
            ->whereRaw($this->normalizedTinSql('tin').' = ?', [$normalizedTin])
            ->value('id');
    }

    private function normalizeTin(?string $tin): string
    {
        return preg_replace('/\D+/', '', (string) $tin) ?: '';
    }

    private function normalizedTinSql(string $column): string
    {
        return "REPLACE(REPLACE(REPLACE(REPLACE({$column}, '-', ''), ' ', ''), '.', ''), '/', '')";
    }
}
