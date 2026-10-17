<?php

namespace App\Services;

use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class IntegrationContract
{
    public const DOCUMENT_TYPES = ['vat_invoice', 'non_vat_invoice', 'vat_exempt_invoice', 'zero_rated_invoice', 'mixed_sales_invoice', 'payment_receipt', 'sales_invoice', 'official_receipt'];

    public function rules(bool $ingestion = false): array
    {
        $rules = [
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
            'items' => ['nullable', 'array', 'max:100'],
            'items.*.description' => ['required', 'string', 'max:1000'],
            'items.*.quantity' => ['required_without:items.*.qty', 'numeric', 'gt:0', 'max:1000000'],
            'items.*.unit_price' => ['required_without:items.*.price', 'numeric', 'min:0', 'max:100000000'],
            'items.*.tax_type' => ['nullable', Rule::in(['vatable', 'vat_exempt', 'zero_rated', 'non_vat'])],
            'items.*.vat_rate' => ['nullable', 'numeric', Rule::in([0, 12])],
            'items.*.discount_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.line_total' => ['nullable', 'numeric', 'min:0', 'max:100000000000'],
            'items.*.amount' => ['nullable', 'numeric', 'min:0', 'max:100000000000'],

        ];
        if ($ingestion) {
            $rules = array_replace($rules, [
                'source_system' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9_.-]+$/'],
                'schema_version' => ['required', Rule::in(['NUERS-ingestion-1'])],
                'source_event_id' => ['required', 'string', 'max:120'],
                'event_type' => ['required', Rule::in(['invoice.finalized', 'payment.recorded'])],
                'occurred_at' => ['required', 'date', 'before_or_equal:'.now()->addMinutes(5)->toIso8601String(), 'regex:/T.*(?:Z|[+-][0-9]{2}:[0-9]{2})$/'],
                'branch_code' => ['required', 'regex:/^[0-9]{5}$/'],
                'receipt_number' => ['required', 'string', 'max:120'],
                'items' => ['required', 'array', 'min:1', 'max:100'],
                'payment_method' => ['required', Rule::in(['cash', 'card', 'bank_transfer', 'e_wallet', 'credit', 'other'])],
                'document_type' => ['required', Rule::in(self::DOCUMENT_TYPES)],
                'customer_tin' => ['nullable', 'regex:/^(?:[0-9]{9}|[0-9]{12}|[0-9]{14}|[0-9]{3}-[0-9]{3}-[0-9]{3}(?:-[0-9]{3}|-[0-9]{5})?)$/'],
            ]);
        }

        return $rules;
    }

    public function canonicalHash(array $value): string
    {
        $sort = function ($v) use (&$sort) {
            if (! is_array($v)) {
                return $v;
            }if (! array_is_list($v)) {
                ksort($v);
            }

            return array_map($sort, $v);
        };

        return hash('sha256', json_encode($sort($value), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function calculateDocument(array $payload, bool $vatRegistered): array
    {
        $calculator = app(InvoiceCalculator::class);
        $lines = array_map(function ($item) use ($payload, $calculator) {
            if (! is_array($item) || ! trim((string) ($item['description'] ?? ''))) {
                throw ValidationException::withMessages(['items' => 'Every item requires a description.']);
            }

            return [
                'description' => $item['description'], 'quantity' => $item['quantity'] ?? $item['qty'] ?? 0,
                'unit_price' => $calculator->cents($item['unit_price'] ?? $item['price'] ?? -1, 'items.unit_price'),
                'discount_pct' => $item['discount_pct'] ?? 0, 'tax_type' => $item['tax_type'] ?? $payload['tax_type'],
                'vat_rate' => $item['vat_rate'] ?? $payload['vat_rate'] ?? 12,
                ...(isset($item['line_total']) || isset($item['amount']) ? ['line_total' => $calculator->cents($item['line_total'] ?? $item['amount'], 'items.line_total')] : []),
            ];
        }, $payload['items'] ?? []);
        $inclusive = (bool) ($payload['vat_inclusive'] ?? true);
        $computed = $calculator->calculate($lines, $vatRegistered, 0, 0, $inclusive);
        $expected = isset($payload['gross_amount']) ? $computed['total_amount'] : ($inclusive ? $computed['total_amount'] : $computed['subtotal_amount'] - $computed['discount_amount']);
        $supplied = $calculator->cents($payload['gross_amount'] ?? $payload['amount'], 'amount');
        if ($supplied !== $expected) {
            throw ValidationException::withMessages(['amount' => 'Submitted amount does not reconcile to the server-calculated line items.']);
        }

        return ['gross_amount' => $computed['total_amount'] / 100, 'vatable_sales' => $computed['taxable_amount'] / 100, 'vat_exempt_sales' => $computed['vat_exempt_amount'] / 100, 'zero_rated_sales' => $computed['zero_rated_amount'] / 100, 'vat_amount' => $computed['vat_amount'] / 100, 'net_amount' => ($computed['total_amount'] - $computed['vat_amount']) / 100,
            'discount_amount' => $computed['discount_amount'] / 100,
            'items' => array_map(function ($line) {
                foreach (['unit_price', 'line_total', 'discount_amount', 'taxable_amount', 'vat_amount'] as $key) {
                    $line[$key] /= 100;
                }

                return $line;
            }, $computed['items'])];
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
