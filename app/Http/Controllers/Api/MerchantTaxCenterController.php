<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApiAccess;
use App\Services\EisDocumentReadiness;
use App\Services\ExpenseCreditEvidence;
use App\Services\IssuedDocumentLedger;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MerchantTaxCenterController extends Controller
{
    private const POSTED_TRANSACTION_STATUSES = ['completed', 'paid', 'issued', 'settled', 'verified'];

    private const POSTED_INVOICE_STATUSES = ['validated', 'sent', 'paid', 'partially_paid', 'overdue', 'issued'];

    private const CLAIMABLE_EXPENSE_STATUSES = ['available', 'matched', 'validated', 'valid', 'approved', 'claimed', 'validated_input_vat'];

    public function show(Request $request): JsonResponse
    {
        $merchant = $this->currentMerchant($request);
        $year = $this->requestedYear($request);
        $start = Carbon::create($year, 1, 1)->startOfDay();
        $end = Carbon::create($year, 12, 31)->endOfDay();

        $monthly = $this->blankMonthly($year);
        $ledger = collect();

        $this->applyCanonicalSales($merchant, $start, $end, $monthly, $ledger);
        $this->applyExpenseCredits($merchant, $start, $end, $monthly, $ledger);

        $monthly = array_values(array_map(function (array $row) {
            $row['vat_payable'] = round(max(0, $row['vat_output'] - $row['vat_input']), 2);
            $row['vat_receivable'] = round(max(0, $row['vat_input'] - $row['vat_output']), 2);
            $row['effective_rate'] = $row['gross'] > 0
                ? round(($row['vat_payable'] / $row['gross']) * 100, 2)
                : 0.0;

            return $row;
        }, $monthly));

        $summary = [
            'total_gross_sales' => round(array_sum(array_column($monthly, 'gross')), 2),
            'vat_output' => round(array_sum(array_column($monthly, 'vat_output')), 2),
            'input_vat_credit' => round(array_sum(array_column($monthly, 'vat_input')), 2),
            'net_vat_payable' => round(array_sum(array_column($monthly, 'vat_payable')), 2),
            'vat_receivable' => round(array_sum(array_column($monthly, 'vat_receivable')), 2),
            'tax_records_generated' => (int) array_sum(array_column($monthly, 'tx_count')),
            'sales_document_count' => (int) array_sum(array_column($monthly, 'sales_document_count')),
            'expense_document_count' => (int) array_sum(array_column($monthly, 'expense_document_count')),
        ];

        $summary['effective_tax_rate'] = $summary['total_gross_sales'] > 0
            ? round(($summary['net_vat_payable'] / $summary['total_gross_sales']) * 100, 2)
            : 0.0;

        return response()->json([
            'merchant' => [
                'id' => (string) $merchant->id,
                'business_name' => (string) $merchant->business_name,
                'tin' => (string) ($merchant->tin ?? ''),
                'vat_registered' => (bool) ($merchant->vat_registered ?? false),
            ],
            'reporting_state' => 'internal_draft_not_tax_return', 'notice' => 'Canonical source-reconciled document totals; input VAT requires separately recorded original-source review. This is not an official tax return.',
            'year' => $year,
            'available_years' => $this->availableYears($year, $merchant),
            'last_sync' => $this->lastSync($merchant),
            'summary' => $summary,
            'monthly' => $monthly,
            'ledger' => $ledger
                ->sortByDesc('sort_at')
                ->take(100)
                ->values()
                ->map(fn (array $row) => collect($row)->except('sort_at')->all())
                ->values(),
        ]);
    }

    private function currentMerchant(Request $request): object
    {
        $token = $request->bearerToken();

        abort_unless($token && Schema::hasTable('users'), 401, 'Authenticated Business Account session is required.');

        $user = app(ApiAccess::class)->user($request);

        abort_unless($user, 401, 'Authenticated Business Account session is required.');
        abort_unless(Schema::hasTable('merchants'), 500, 'merchants table is not migrated.');

        $merchant = DB::table('merchants')
            ->where(function ($query) use ($user) {
                $query
                    ->where('merchant_account_id', $user->id);
            })
            ->first();

        abort_unless($merchant, 404, 'No Business Account registry record is linked to this login.');

        return $merchant;
    }

    private function requestedYear(Request $request): int
    {
        $year = (int) $request->query('year', now()->year);

        if ($year < 2000 || $year > 2100) {
            return (int) now()->year;
        }

        return $year;
    }

    private function blankMonthly(int $year): array
    {
        $rows = [];

        for ($month = 1; $month <= 12; $month++) {
            $rows[$month] = [
                'month' => Carbon::create($year, $month, 1)->format('M'),
                'month_number' => $month,
                'gross' => 0.0,
                'vatable_sales' => 0.0,
                'vat_output' => 0.0,
                'vat_input' => 0.0,
                'vat_payable' => 0.0,
                'vat_receivable' => 0.0,
                'tx_count' => 0,
                'sales_document_count' => 0,
                'expense_document_count' => 0,
                'effective_rate' => 0.0,
            ];
        }

        return $rows;
    }

    private function applyCanonicalSales(object $merchant, Carbon $start, Carbon $end, array &$monthly, Collection $ledger): void
    {
        $rows = app(IssuedDocumentLedger::class)->query()->where('merchant_id', $merchant->id)->where('status', 'success')->where('identity_count', 1)->whereIn('document_family', ['invoice', 'credit', 'debit'])->whereBetween('business_date', [$start->toDateString(), $end->toDateString()])->orderBy('id')->cursor();
        foreach ($rows as $row) {
            if ($row->document_source === 'transaction') {
                continue;
            }
            if (in_array($row->document_family, ['credit', 'debit'], true) && (! $row->original_invoice_id || ! DB::table('business_invoices')->where('id', $row->original_invoice_id)->where('merchant_id', $merchant->id)->whereNotIn('status', ['draft', 'cancelled', 'voided'])->exists())) {
                continue;
            }
            if (! app(EisDocumentReadiness::class)->inspect($row->document_source, $row->document_id)['financial_validation_passed']) {
                continue;
            }
            $date = Carbon::parse($row->business_date);
            $month = $date->month;
            $sign = $row->document_family === 'credit' ? -1 : 1;
            $gross = $sign * (float) $row->amount;
            $vat = $sign * (float) $row->vat_amount;
            $monthly[$month]['gross'] += $gross;
            $monthly[$month]['vatable_sales'] += $sign * (float) $row->vatable_sales;
            $monthly[$month]['vat_output'] += $vat;
            $monthly[$month]['tx_count']++;
            $monthly[$month]['sales_document_count']++;
            $ledger->push($this->ledgerRow(['id' => $row->id, 'source' => 'Canonical issued document', 'tx_ref' => $row->receipt_number, 'date_at' => $date, 'channel' => 'document', 'branch' => $row->branch_code ?? 'Unverified', 'customer' => $row->customer_name ?? '', 'gross' => $gross, 'vat_output' => $vat, 'input_credit' => 0, 'tax_type' => $row->document_type, 'status' => 'internal_draft']));
        }
    }

    private function applyExpenseCredits(object $merchant, Carbon $start, Carbon $end, array &$monthly, Collection $ledger): void
    {
        if (! Schema::hasTable('merchant_expenses')) {
            return;
        }

        $startDateTime = $start->toDateTimeString();
        $endDateTime = $end->toDateTimeString();

        $rows = DB::table('merchant_expenses')
            ->where('merchant_id', $merchant->id)
            ->whereRaw('COALESCE(issued_at, created_at) BETWEEN ? AND ?', [$startDateTime, $endDateTime])
            ->where(function ($query) {
                $query
                    ->whereIn('claim_status', self::CLAIMABLE_EXPENSE_STATUSES)
                    ->orWhereIn('validation_status', self::CLAIMABLE_EXPENSE_STATUSES);
            })
            ->orderByRaw('COALESCE(issued_at, created_at) DESC')
            ->get();

        $recognizedSources = [];
        foreach ($rows as $row) {
            $original = app(ExpenseCreditEvidence::class)->source($row);
            if (! $original) {
                continue;
            }
            if (isset($recognizedSources[$original->id])) {
                continue;
            }
            $recognizedSources[$original->id] = true;
            $date = Carbon::parse($row->issued_at ?: $row->created_at);
            $month = (int) $date->month;
            $gross = (float) $row->gross_amount;
            $inputVat = (float) $row->input_vat_amount;

            $monthly[$month]['vat_input'] += $inputVat;
            $monthly[$month]['tx_count']++;
            $monthly[$month]['expense_document_count']++;

            $ledger->push($this->ledgerRow([
                'id' => 'TAX-EXP-'.$this->shortId($row->id),
                'source' => 'B2B Expense',
                'tx_ref' => $row->source_invoice_reference,
                'date_at' => $date,
                'channel' => 'Expense',
                'branch' => 'Input VAT Wallet',
                'customer' => (string) ($row->supplier_name ?: 'Supplier'),
                'gross' => $gross,
                'vat_output' => 0.0,
                'input_credit' => $inputVat,
                'tax_type' => 'Input VAT',
                'status' => (string) ($row->claim_status ?: $row->validation_status ?: 'validated'),
            ]));
        }
    }

    private function ledgerRow(array $data): array
    {
        /** @var Carbon $date */
        $date = $data['date_at'];
        $netVat = round(((float) $data['vat_output']) - ((float) $data['input_credit']), 2);

        return [
            'id' => (string) $data['id'],
            'source' => (string) $data['source'],
            'tx_ref' => (string) $data['tx_ref'],
            'date' => $date->format('M d, Y'),
            'time' => $date->format('h:i A'),
            'sort_at' => $date->toDateTimeString(),
            'channel' => (string) $data['channel'],
            'branch' => (string) $data['branch'],
            'customer' => (string) $data['customer'],
            'gross' => round((float) $data['gross'], 2),
            'vat_output' => round((float) $data['vat_output'], 2),
            'input_credit' => round((float) $data['input_credit'], 2),
            'net_vat' => $netVat,
            'quarter' => 'Q'.ceil($date->month / 3).' '.$date->year,
            'tax_type' => (string) $data['tax_type'],
            'status' => $this->headline((string) $data['status']),
        ];
    }

    private function availableYears(int $selectedYear, object $merchant): array
    {
        $years = [$selectedYear, (int) now()->year];

        if (Schema::hasTable('merchant_transactions')) {
            $years = array_merge($years, DB::table('merchant_transactions')
                ->where('merchant_id', $merchant->id)
                ->whereNotNull('created_at')
                ->selectRaw('YEAR(created_at) as year')
                ->distinct()
                ->pluck('year')
                ->map(fn ($year) => (int) $year)
                ->all());
        }

        if (Schema::hasTable('business_invoices')) {
            $years = array_merge($years, DB::table('business_invoices')
                ->where('merchant_id', $merchant->id)
                ->selectRaw('YEAR(COALESCE(issue_date, DATE(created_at))) as year')
                ->distinct()
                ->pluck('year')
                ->map(fn ($year) => (int) $year)
                ->all());
        }

        if (Schema::hasTable('merchant_expenses')) {
            $years = array_merge($years, DB::table('merchant_expenses')
                ->where('merchant_id', $merchant->id)
                ->selectRaw('YEAR(COALESCE(issued_at, created_at)) as year')
                ->distinct()
                ->pluck('year')
                ->map(fn ($year) => (int) $year)
                ->all());
        }

        $years = array_values(array_filter(array_unique($years), fn ($year) => $year >= 2000 && $year <= 2100));
        rsort($years);

        return $years;
    }

    private function lastSync(object $merchant): ?string
    {
        $dates = [];

        foreach ([
            ['merchant_transactions', 'updated_at'],
            ['business_invoices', 'updated_at'],
            ['merchant_expenses', 'updated_at'],
        ] as [$table, $column]) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                $value = DB::table($table)->where('merchant_id', $merchant->id)->max($column);

                if ($value) {
                    $dates[] = Carbon::parse($value);
                }
            }
        }

        if ($dates === []) {
            return null;
        }

        return collect($dates)->sortDesc()->first()->toIso8601String();
    }

    private function taxType(string $taxType, float $vatOutput, float $vatableSales, float $gross): string
    {
        $normalized = trim(str_replace('_', ' ', strtolower($taxType)));

        if ($normalized !== '') {
            return $this->headline($normalized);
        }

        if ($vatOutput > 0 || $vatableSales > 0) {
            return 'Vatable';
        }

        if ($gross > 0) {
            return 'Non VAT / Exempt';
        }

        return 'Unclassified';
    }

    private function shortId(?string $id): string
    {
        $id = (string) $id;

        return strtoupper(substr(str_replace('-', '', $id), 0, 10));
    }

    private function headline(string $value): string
    {
        return str($value)
            ->replace(['_', '-'], ' ')
            ->squish()
            ->title()
            ->toString();
    }
}
