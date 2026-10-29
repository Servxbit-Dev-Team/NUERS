<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Read-only presentation of a seller's original snapshot. Never a tax approval or issuance path. */
class ElectronicDocumentView
{
    public function make(string $source, object $row): array
    {
        $invoice = $source === 'invoice';
        $number = $invoice ? $row->invoice_number : $row->receipt_number;
        $status = (string) ($row->status ?? 'not_recorded');
        $type = $row->document_type ?? $row->receipt_type ?? 'unclassified_document';
        $category = in_array($type, ['credit_memo', 'credit_note', 'debit_memo', 'debit_note'], true) ? 'notes' : (in_array($type, ['payment_receipt', 'official_receipt', 'delivery_receipt', 'purchase_invoice'], true) ? 'receipts' : 'invoices');
        $date = $invoice ? $row->issue_date : ($row->issued_at ?? null);
        $transaction = ! $invoice && ($row->transaction_id ?? null)
            ? DB::table('merchant_transactions')->where('id', $row->transaction_id)->where('merchant_id', $row->merchant_id)->first() : null;
        $raw = $invoice
            ? DB::table('business_invoice_items')->where('invoice_id', $row->id)->where('merchant_id', $row->merchant_id)->orderBy('line_number')->get()->map(fn ($i) => (array) $i)->all()
            : (json_decode($row->items ?? '[]', true) ?: []);
        $items = [];
        foreach ($raw as $i) {
            if (! is_array($i)) {
                continue;
            }
            $qty = $i['quantity'] ?? $i['qty'] ?? null;
            $price = $i['unit_price'] ?? $i['unitPrice'] ?? null;
            $amount = $i['amount'] ?? $i['line_total'] ?? $i['total'] ?? null;
            $derived = $amount === null && is_numeric($qty) && is_numeric($price);
            if ($derived) {
                $amount = round((float) $qty * (float) $price, 2);
            }
            $items[] = ['description' => $i['description'] ?? $i['desc'] ?? $i['name'] ?? null, 'quantity' => is_numeric($qty) ? (float) $qty : null, 'unit' => $i['unit'] ?? null, 'unit_price' => is_numeric($price) ? (float) $price : null, 'amount' => is_numeric($amount) ? (float) $amount : null, 'amount_derived' => $derived, 'tax_type' => $i['tax_type'] ?? null, 'benefit_eligible' => ! empty($i['benefit_details']), 'discount' => (float) ($i['discount_amount'] ?? 0), 'discount_reason' => $i['discount_reason'] ?? null];
        }
        $amount = (float) ($invoice ? $row->total_amount : $row->total_due);
        $withholding = (float) ($row->withholding_tax ?? 0);
        $settlementValid = $invoice && $status !== 'draft' && isset($row->withholding_tax, $row->amount_paid, $row->amount_due)
            ? (int) round($amount * 100) - (int) round($withholding * 100) - (int) round($row->amount_paid * 100) === (int) round($row->amount_due * 100) : null;
        $check = $status === 'draft' ? null : app(EisDocumentReadiness::class)->inspect($source, (string) $row->id);
        $registration = $row->vat_registered ?? ($row->merchant_vat_reg ?? null);
        $registered = is_bool($registration) || in_array($registration, [0, 1, '0', '1'], true) ? (bool) $registration : match (strtolower(trim((string) $registration))) {
            'vat', 'vat registered', 'vat-registered' => true,
            'non-vat', 'non vat', 'non-vat registered', 'non-vat-registered' => false,
            default => null,
        };

        // New portal snapshots identify non-VAT lines separately from VAT-exempt lines.
        // Historical/source-capture presentations keep their recorded tax aggregates.
        $splitNonVat = $invoice && ! empty($row->presentation_settings) && $registered === false;
        $nonVatSales = $splitNonVat ? array_sum(array_map(fn ($i) => ($i['tax_type'] ?? null) === 'non_vat' ? (float) ($i['amount'] ?? 0) : 0, $items)) : null;

        // API receipt copies and dashboard previews share the current saved visual branding.
        // Original seller identity, items and totals remain the recorded financial snapshot.
        $presentation = ! empty($row->presentation_settings) ? json_decode($row->presentation_settings, true) : null;
        if ($merchant = DB::table('merchants')->where('id', $row->merchant_id)->first()) {
            if (! $invoice || ! $presentation) {
                $presentation = app(MerchantInvoiceSettings::class)->snapshot($merchant);
            } else {
                // Current document copies use the dedicated receipt logo; original amounts stay immutable.
                $presentation['logo_url'] = $merchant->invoice_logo_url ?? null;
                $presentation['show_logo'] = app(MerchantInvoiceSettings::class)->get($merchant)['settings']['show_logo'];
            }
        }

        return [
            'sale_category' => $row->sale_category ?? 'regular', 'sales_benefit' => json_decode($row->sales_benefit ?? 'null', true),
            'manual_discount' => max(0, round((float) ($row->discount_amount ?? 0) - (float) ($row->statutory_discount_amount ?? 0), 2)),
            'statutory_discount' => (float) ($row->statutory_discount_amount ?? 0), 'vat_adjustment' => (float) ($row->vat_relief_amount ?? 0),
            'presentation' => $presentation,
            'footer_note' => $row->footer_note ?? ($presentation['footer_text'] ?? null), 'notes' => $row->notes ?? ($presentation['additional_notes'] ?? null), 'terms_and_conditions' => $row->terms_and_conditions ?? ($presentation['terms_and_conditions'] ?? null),
            'id' => (string) $row->id, 'source' => $source, 'number' => $number, 'business' => $row->merchant_name, 'tin' => $row->merchant_tin,
            'document_type' => $type, 'record_status' => $status,
            'status' => in_array($status, ['cancelled', 'voided', 'void', 'refunded']) ? 'cancelled' : (in_array($status, ['issued', 'sent', 'paid', 'partially_paid', 'validated', 'completed', 'settled', 'verified', 'valid', 'overdue'], true) ? 'valid' : 'pending'),
            'category' => $category, 'issued_at' => $date ? ($invoice ? substr($date, 0, 10) : CarbonImmutable::parse($date, config('app.timezone'))->setTimezone('Asia/Manila')->toIso8601String()) : '', 'date_precision' => $invoice ? 'day' : 'instant',
            'amount' => $amount, 'currency' => $row->currency ?? 'PHP', 'seller_address' => $row->merchant_address, 'seller_business_style' => $row->seller_business_style ?? null,
            'buyer_name' => $row->buyer_name ?: 'Not recorded', 'buyer_tin' => $row->buyer_tin, 'buyer_address' => $row->buyer_address ?? null, 'buyer_business_style' => $row->buyer_business_style ?? null,
            'reference' => $row->reference_number ?? (json_decode($transaction->external_payload ?? '{}', true)['manual_invoice_number'] ?? null) ?? $transaction->transaction_ref ?? null, 'original_invoice_id' => $row->original_invoice_id ?? null,
            'payment_method' => $transaction->payment_method ?? ($row->payment_method ?? null), 'branch' => $row->seller_branch_code ?? null,
            'vat_registered' => $registered, 'items' => $items, 'subtotal' => $this->number($row->subtotal_amount ?? $row->gross_amount ?? null), 'discount' => $this->number($row->discount_amount ?? null),
            'vatable' => $this->number($row->taxable_amount ?? $row->vatable_sales ?? null), 'vat_exempt' => $splitNonVat ? max(0, round((float) ($row->vat_exempt_amount ?? 0) - $nonVatSales, 2)) : $this->number($row->vat_exempt_amount ?? $row->vat_exempt_sales ?? null), 'zero_rated' => $this->number($row->zero_rated_amount ?? $row->zero_rated_sales ?? null), 'vat' => $this->number($row->vat_amount ?? null),
            'record_mode' => $row->record_mode ?? 'nuers_issuance', 'sspt_sales' => $nonVatSales ?? $this->number($row->sspt_sales ?? null),
            'withholding' => $this->number($row->withholding_tax ?? null), 'other_charges' => $this->number($row->other_charges ?? null),
            'net_payable' => isset($row->withholding_tax) ? (round($amount * 100) - round($withholding * 100)) / 100 : null,
            'amount_paid' => $this->number($row->amount_paid ?? null), 'balance_due' => $this->number($row->amount_due ?? null),
            'permit_number' => $row->permit_to_issue_number ?? null, 'accreditation_number' => $row->esp_accreditation_number ?? null,
            // No approved public-token issuer exists yet. A record number/digest is not a verification URL.
            'verification_url' => null, 'authority_status' => 'BIR acceptance not verified', 'bir_status' => 'not_verified',
            'financial_validation_passed' => $check['financial_validation_passed'] ?? null,
            'settlement_validation_passed' => $settlementValid,
            'source_notice' => $status === 'draft' ? 'Preview only; local checks do not authorize issuance.' : 'Recorded source copy. Source amounts are preserved; local checks, a PDF or a QR code do not establish BIR approval.',
            'history' => DB::table('invoice_lifecycle_events')->where('merchant_id', $row->merchant_id)->where('document_source', $source)->where('document_id', $row->id)->orderByDesc('occurred_at')->limit(30)->get(['id', 'event', 'occurred_at', 'reason', 'actor'])->map(function ($e) {
                $e->occurred_at = CarbonImmutable::parse($e->occurred_at, config('app.timezone'))->toIso8601String();

                return $e;
            })->all(),
        ];
    }

    private function number(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
