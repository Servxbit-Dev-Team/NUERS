<?php

namespace App\Services;

use Illuminate\Validation\Rule;

class IntegrationContract
{
    public const DOCUMENT_TYPES = ['vat_invoice', 'non_vat_invoice', 'vat_exempt_invoice', 'zero_rated_invoice', 'mixed_sales_invoice', 'payment_receipt', 'sales_invoice', 'official_receipt'];

    public function rules(): array
    {
        return [
            'external_reference' => ['required', 'string', 'max:120'],
            'source_system' => ['nullable', 'string', 'max:120'],
            'channel' => ['nullable', 'string', 'max:80'],
            'transaction_type' => ['nullable', 'string', 'max:80'],
            'payment_method' => ['nullable', 'string', 'max:80'],
            'document_type' => ['nullable', Rule::in(self::DOCUMENT_TYPES)],
            'amount' => ['required_without:gross_amount', 'nullable', 'numeric', 'min:0'],
            'gross_amount' => ['required_without:amount', 'nullable', 'numeric', 'min:0'],
            'tax_type' => ['required', Rule::in(['vatable', 'vat_exempt', 'zero_rated', 'non_vat'])],
            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'vat_inclusive' => ['nullable', 'boolean'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_tin' => ['nullable', 'string', 'max:80'],
            'branch' => ['nullable', 'string', 'max:255'],
            'branch_name' => ['nullable', 'string', 'max:255'],
            'branch_code' => ['nullable', 'string', 'max:80'],
            'branch_type' => ['nullable', Rule::in(['main', 'branch'])],
            'receipt_number' => ['nullable', 'string', 'max:120'],
            'items' => ['nullable', 'array'],
        ];
    }

    public function computeTax(float $amount, string $taxType, float $vatRate, bool $vatInclusive): array
    {
        $gross = round($amount, 2);
        $vatable = 0.0;
        $vatExempt = 0.0;
        $zeroRated = 0.0;
        $vat = 0.0;

        if ($taxType === 'vatable') {
            if ($vatInclusive) {
                $vatable = round($gross / (1 + ($vatRate / 100)), 2);
                $vat = round($gross - $vatable, 2);
            } else {
                $vatable = round($gross, 2);
                $vat = round($vatable * ($vatRate / 100), 2);
                $gross = round($vatable + $vat, 2);
            }
        } elseif ($taxType === 'zero_rated') {
            $zeroRated = $gross;
        } else {
            $vatExempt = $gross;
        }

        return [
            'gross_amount' => $gross,
            'vatable_sales' => $vatable,
            'vat_exempt_sales' => $vatExempt,
            'zero_rated_sales' => $zeroRated,
            'vat_amount' => $vat,
            'net_amount' => $vatable ?: $vatExempt ?: $zeroRated,
        ];
    }
}
