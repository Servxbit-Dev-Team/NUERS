<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class IntegrationContract
{
    public const DOCUMENT_TYPES = ['vat_invoice', 'non_vat_invoice', 'vat_exempt_invoice', 'zero_rated_invoice', 'mixed_sales_invoice', 'payment_receipt', 'sales_invoice', 'official_receipt'];

    public function documentCatalog(): array
    {
        return array_map(fn ($code, $label, $event, $tax) => compact('code', 'label', 'event', 'tax'), self::DOCUMENT_TYPES,
            ['VAT Invoice', 'Non-VAT Invoice', 'VAT-Exempt Sales Invoice', 'Zero-Rated Sales Invoice', 'Mixed Sales Invoice', 'Payment Receipt (Supplementary)', 'Sales Invoice (Auto-classify)', 'Official Receipt (Payment Alias)'],
            ['invoice.finalized', 'invoice.finalized', 'invoice.finalized', 'invoice.finalized', 'invoice.finalized', 'payment.recorded', 'invoice.finalized', 'payment.recorded'],
            ['vatable', 'non_vat', 'vat_exempt', 'zero_rated', 'mixed', 'source', 'source', 'source']);
    }

    public function rules(bool $ingestion = false): array
    {
        $rules = [
            'manual_discount' => ['nullable', 'array:type,value,reason'],
            'sales_benefit' => ['nullable', 'array'],
            'items.*.benefit_eligible' => ['sometimes', 'boolean'],
            'record_mode' => ['sometimes', 'required', Rule::in(['source_capture', 'nuers_issuance'])],
            'source_profile_id' => ['required_if:record_mode,source_capture', 'nullable', 'uuid'],
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
            'customer_email' => ['nullable', 'string', 'email:rfc,filter', 'max:254', 'not_regex:/[\r\n]/'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_tin' => ['nullable', 'string', 'max:80'],
            'customer_address' => ['nullable', 'string', 'max:2000'],
            'withholding_tax' => ['nullable', 'numeric', 'min:0', 'max:10000000000'],
            'withholding_basis_reference' => ['required_with:withholding_tax', 'nullable', 'string', 'min:3', 'max:2000'],
            'tax_basis_reference' => ['nullable', 'string', 'max:2000'],
            'branch' => ['nullable', 'string', 'max:255'],
            'branch_name' => ['nullable', 'string', 'max:255'],
            'branch_code' => ['nullable', 'string', 'max:80'],
            'branch_type' => ['nullable', Rule::in(['main', 'branch'])],
            'receipt_number' => ['nullable', 'string', 'max:120'],
            'items' => ['nullable', 'array', 'max:100'],
            'items.*' => ['array:description,quantity,qty,unit_price,price,tax_type,vat_rate,discount_pct,manual_discount,line_total,amount,tax_basis_reference,benefit_eligible'],
            'items.*.manual_discount' => ['nullable', 'array:type,value,reason'],
            'items.*.qty' => ['sometimes', 'numeric', 'gt:0', 'max:1000000'],
            'items.*.price' => ['sometimes', 'numeric', 'min:0', 'max:100000000'],
            'items.*.tax_basis_reference' => ['nullable', 'string', 'max:2000'],
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

    public function validateEvent(array $payload): array
    {
        $rules = $this->rules(true);
        $unknown = array_diff(array_keys($payload), array_filter(array_keys($rules), fn ($key) => ! str_contains($key, '.')));
        if ($unknown) {
            throw ValidationException::withMessages(['payload' => 'Unsupported fields: '.implode(', ', $unknown).'. Use documented fields; no supplied fields are silently discarded.']);
        }

        return Validator::make($payload, $rules)->validate();
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
        $benefit = app(StatutorySales::class)->validate($payload['sales_benefit'] ?? null, $payload['occurred_at'] ?? now()->toIso8601String());
        $calculator = app(InvoiceCalculator::class);
        $lines = array_map(function ($item) use ($payload, $calculator, $vatRegistered) {
            if (! is_array($item) || ! trim((string) ($item['description'] ?? ''))) {
                throw ValidationException::withMessages(['items' => 'Every item requires a description.']);
            }

            if ((isset($item['quantity'], $item['qty']) && (float) $item['quantity'] !== (float) $item['qty']) || (isset($item['unit_price'], $item['price']) && $calculator->cents($item['unit_price'], 'unit_price') !== $calculator->cents($item['price'], 'price'))) {
                throw ValidationException::withMessages(['items' => 'Quantity or price aliases conflict. Supply one consistent value.']);
            }
            if (isset($item['line_total'], $item['amount']) && $calculator->cents($item['line_total'], 'line_total') !== $calculator->cents($item['amount'], 'amount')) {
                throw ValidationException::withMessages(['items' => 'Line-total aliases conflict.']);
            }
            $tax = $item['tax_type'] ?? $payload['tax_type'];
            if ($tax !== 'vatable' && isset($item['vat_rate']) && (float) $item['vat_rate'] !== 0.0) {
                throw ValidationException::withMessages(['items.vat_rate' => 'A non-taxable line must have a zero VAT rate.']);
            }
            if ($vatRegistered && $tax === 'non_vat') {
                throw ValidationException::withMessages(['items.tax_type' => 'A VAT-registered seller must use vatable, vat_exempt or zero_rated lines; non_vat belongs to a non-VAT seller.']);
            }

            return [
                'benefit_eligible' => (bool) ($item['benefit_eligible'] ?? false), 'description' => $item['description'], 'quantity' => $item['quantity'] ?? $item['qty'] ?? 0,
                'unit_price' => $calculator->cents($item['unit_price'] ?? $item['price'] ?? -1, 'items.unit_price'),
                'tax_basis_reference' => $item['tax_basis_reference'] ?? $payload['tax_basis_reference'] ?? null,
                'discount_pct' => $item['discount_pct'] ?? 0, 'tax_type' => $item['tax_type'] ?? $payload['tax_type'],
                'vat_rate' => $item['vat_rate'] ?? $payload['vat_rate'] ?? 12,
                ...(isset($item['line_total']) || isset($item['amount']) ? ['line_total' => $calculator->cents($item['line_total'] ?? $item['amount'], 'items.line_total')] : []),
            ];
        }, $payload['items'] ?? []);
        $lines = app(ManualDiscounts::class)->apply($lines, $payload, $benefit !== null);
        $inclusive = (bool) ($payload['vat_inclusive'] ?? true);
        $withholding = $calculator->cents($payload['withholding_tax'] ?? 0, 'withholding_tax');
        $computed = $calculator->calculate($lines, $vatRegistered, $withholding, 0, $inclusive, $benefit);
        $expected = isset($payload['gross_amount']) ? $computed['total_amount'] : ($inclusive ? $computed['total_amount'] : $computed['subtotal_amount'] - $computed['discount_amount']);
        if (isset($payload['gross_amount'], $payload['amount']) && $calculator->cents($payload['amount'], 'amount') !== ($inclusive ? $computed['total_amount'] : $computed['subtotal_amount'] - $computed['discount_amount'])) {
            throw ValidationException::withMessages(['amount' => 'The amount alias conflicts with the calculated price basis.']);
        }
        $supplied = $calculator->cents($payload['gross_amount'] ?? $payload['amount'], 'amount');
        if ($supplied !== $expected) {
            throw ValidationException::withMessages(['amount' => 'Submitted amount does not reconcile to the server-calculated line items.']);
        }

        $sspt = array_sum(array_map(fn ($line) => $line['tax_type'] === 'non_vat' ? $line['line_total'] : 0, $computed['items']));

        return ['sale_category' => $benefit['category'] ?? 'regular', 'statutory_discount_amount' => $computed['statutory_discount_amount'] / 100, 'vat_relief_amount' => $computed['vat_relief_amount'] / 100, 'seller_vat_registered' => $vatRegistered, 'sspt_sales' => $sspt / 100, 'withholding_tax' => $withholding / 100, 'net_payable' => ($computed['total_amount'] - $withholding) / 100, 'gross_amount' => $computed['total_amount'] / 100, 'vatable_sales' => $computed['taxable_amount'] / 100, 'vat_exempt_sales' => ($computed['vat_exempt_amount'] - $sspt) / 100, 'zero_rated_sales' => $computed['zero_rated_amount'] / 100, 'vat_amount' => $computed['vat_amount'] / 100, 'net_amount' => ($computed['total_amount'] - $computed['vat_amount']) / 100,
            'subtotal_amount' => $computed['subtotal_amount'] / 100, 'discount_amount' => $computed['discount_amount'] / 100, 'manual_discount_amount' => $computed['manual_discount_amount'] / 100,
            'items' => array_map(function ($line) {
                foreach (['unit_price', 'line_total', 'discount_amount', 'taxable_amount', 'vat_amount'] as $key) {
                    $line[$key] /= 100;
                }
                if (isset($line['fixed_discount_amount'])) {
                    $line['fixed_discount_amount'] /= 100;
                }

                return $line;
            }, $computed['items'])];
    }

    public function resolveDocument(array $v, array $tax): array
    {
        $family = in_array($v['document_type'], ['payment_receipt', 'official_receipt'], true) ? 'payment' : 'invoice';
        abort_unless($v['event_type'] === ($family === 'payment' ? 'payment.recorded' : 'invoice.finalized'), 422, 'Event trigger and document family conflict.');
        $types = collect($tax['items'])->pluck('tax_type')->unique();
        abort_if($types->count() === 1 && $types->first() !== $v['tax_type'] && empty($v['sales_benefit']), 422, 'Header and line tax classification must agree.');
        $type = $family === 'payment' ? 'payment_receipt' : ($types->count() > 1 ? 'mixed_sales_invoice' : match ($types->first()) {
            'vatable' => 'vat_invoice', 'non_vat' => 'non_vat_invoice', 'vat_exempt' => ($tax['seller_vat_registered'] ? 'vat_exempt_invoice' : 'non_vat_invoice'), 'zero_rated' => 'zero_rated_invoice'
        });
        abort_unless(in_array($v['document_type'], [$type, 'sales_invoice', 'official_receipt'], true), 422, 'Document type conflicts with calculated line tax classification.');

        return ['family' => $family, 'type' => $type, 'tax_type' => $types->count() > 1 ? 'mixed' : $types->first()];
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
