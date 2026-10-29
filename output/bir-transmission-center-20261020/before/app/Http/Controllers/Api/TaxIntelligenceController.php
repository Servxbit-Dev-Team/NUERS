<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApiAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TaxIntelligenceController extends Controller
{
    private const POSTED_TRANSACTION_STATUSES = ['completed', 'paid', 'issued', 'settled', 'verified'];

    private const POSTED_INVOICE_STATUSES = ['validated', 'sent', 'paid', 'partially_paid', 'overdue', 'issued'];

    private const CLAIMABLE_EXPENSE_STATUSES = ['available', 'matched', 'validated', 'valid', 'approved', 'claimed', 'validated_input_vat'];

    public function show(Request $request, string $scope): JsonResponse
    {
        $allowed = ['merchant', 'bir', 'super-admin'];
        abort_unless(in_array($scope, $allowed, true), 404, 'Unknown tax intelligence scope.');

        if ($scope === 'merchant') {
            return response()->json($this->merchantPayload($request));
        }

        app(ApiAccess::class)->require($request, ['admin', 'super_admin', 'bir']);

        return response()->json(['scope' => $scope, 'kpis' => [], 'series' => [], 'records' => [], 'graph' => [], 'reporting_state' => 'unverified_legacy_analytics', 'notice' => 'Legacy analytical/model metrics lack verified source provenance and are excluded. Use canonical transaction and sales reports.']);
    }

    private function merchantPayload(Request $request): array
    {
        $merchant = $this->currentMerchant($request);
        $canonical = app(MerchantTaxCenterController::class)->show($request)->getData(true);
        $summary = $canonical['summary'];
        $monthly = array_map(fn ($m) => ['label' => $m['month'], 'output_vat' => $m['vat_output'], 'input_vat' => $m['vat_input'], 'vat_payable' => $m['vat_payable'], 'pending_remittance' => null], $canonical['monthly']);

        return ['scope' => 'merchant', 'reporting_state' => 'internal_draft_not_tax_return', 'notice' => $canonical['notice'],
            'kpis' => [
                $this->kpi('total_sales_invoices', 'Reconciled Sales Documents', $this->compactNumber($summary['sales_document_count']), 'Canonical source-reconciled documents only', 'Receipt', 'border-l-primary'),
                $this->kpi('output_vat', 'Recorded Output VAT', $this->money($summary['vat_output']), 'Internal draft; no BIR acceptance claim', 'TrendingUp', 'border-l-success'),
                $this->kpi('input_vat', 'Reviewed Input VAT', $this->money($summary['input_vat_credit']), 'Original-source review evidence required', 'Wallet', 'border-l-chart-2'),
                $this->kpi('vat_payable', 'Draft Net VAT', $this->money($summary['net_vat_payable']), 'Internal calculation, not a tax return', 'Landmark', 'border-l-warning'),
            ], 'series' => ['vat_trend' => $monthly], 'records' => ['invoice_matches' => array_map(fn ($r) => ['reference' => $r['tx_ref'], 'party_name' => $merchant->business_name, 'counterparty_name' => $r['customer'], 'tin' => $merchant->tin, 'amount' => $r['gross'], 'vat_amount' => $r['source'] === 'B2B Expense' ? $r['input_credit'] : $r['vat_output'], 'withholding_amount' => null, 'status' => 'Internal source reconciled', 'risk_level' => null, 'score' => null, 'metadata' => ['source' => $r['source']]], $canonical['ledger']), 'supplier_compliance' => []], 'graph' => []];
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
            ->orderByRaw(
                'CASE WHEN merchant_account_id = ? THEN 0 WHEN merchant_account_email = ? THEN 1 WHEN email = ? THEN 2 ELSE 3 END',
                [$user->id, $user->email, $user->email],
            )
            ->orderByDesc('updated_at')
            ->first();

        abort_unless($merchant, 404, 'No Business Account registry record is linked to this login.');

        return $merchant;
    }

    private function kpi(string $key, string $label, string $value, ?string $subtext, ?string $icon, ?string $accent): array
    {
        return compact('key', 'label', 'value', 'subtext', 'icon', 'accent');
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

    private function riskFromStatus(string $status): string
    {
        $status = strtolower($status);

        if (str_contains($status, 'flag') || str_contains($status, 'critical') || str_contains($status, 'high')) {
            return 'High';
        }

        if (str_contains($status, 'mismatch') || str_contains($status, 'review') || str_contains($status, 'pending') || str_contains($status, 'draft')) {
            return 'Medium';
        }

        return 'Low';
    }

    private function scoreFromStatus(string $status): int
    {
        $risk = strtolower($this->riskFromStatus($status));

        return match ($risk) {
            'high' => 45,
            'medium' => 72,
            default => 96,
        };
    }
}
