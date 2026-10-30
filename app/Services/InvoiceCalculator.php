<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/** PHP amounts at the API boundary; all arithmetic below uses integer centavos. Prices include VAT. */
class InvoiceCalculator
{
    private const MAX_CENTS = 1000000000000;

    public function cents(mixed $value, string $field, bool $alreadyCents = false): int
    {
        if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0) {
            $this->fail($field, 'Use a non-negative monetary amount.');
        }
        $scaled = (float) $value * ($alreadyCents ? 1 : 100);
        if ($scaled > self::MAX_CENTS || abs($scaled - round($scaled)) > 0.00001) {
            $this->fail($field, 'Amount exceeds supported precision or limit.');
        }

        return (int) round($scaled);
    }

    public function calculate(array $lines, bool $vatRegistered, int $withholding = 0, int $otherCharges = 0, bool $pricesIncludeVat = true, ?array $benefit = null): array
    {
        if (! $lines || count($lines) > 100) {
            $this->fail('line_items', 'Provide between 1 and 100 line items.');
        }
        // Extra charges must be classified as line items, so their VAT treatment cannot be ambiguous.
        if ($otherCharges !== 0) {
            $this->fail('other_charges', 'Add charges as classified line items instead.');
        }
        $s = array_fill_keys(['subtotal_amount', 'discount_amount', 'taxable_amount', 'vat_amount', 'zero_rated_amount', 'vat_exempt_amount', 'statutory_discount_amount', 'vat_relief_amount'], 0);
        $items = [];
        $eligibleCount = 0;
        foreach ($lines as $i => $line) {
            $field = 'line_items.'.$i;
            $quantity = $line['quantity'] ?? 0;
            if (! is_numeric($quantity) || (float) $quantity <= 0 || (float) $quantity > 1000000 || abs((float) $quantity * 10000 - round((float) $quantity * 10000)) > 0.00001) {
                $this->fail($field.'.quantity', 'Quantity must be positive with at most four decimals.');
            }
            $qty = (int) round((float) $quantity * 10000);
            $price = $this->cents($line['unit_price'] ?? 0, $field.'.unit_price', true);
            if ($price * ((float) $quantity) > self::MAX_CENTS) {
                $this->fail($field, 'Line amount exceeds the supported limit.');
            }
            $gross = intdiv($price * $qty + 5000, 10000);
            $pct = $line['discount_pct'] ?? 0;
            if (! is_numeric($pct) || (float) $pct < 0 || (float) $pct > 100 || abs((float) $pct * 100 - round((float) $pct * 100)) > 0.00001) {
                $this->fail($field.'.discount_pct', 'Discount percentage must be between 0 and 100 with at most two decimals.');
            }
            $discount = intdiv($gross * (int) round((float) $pct * 100) + 5000, 10000);
            if (isset($line['fixed_discount_amount'])) {
                if ((float)$pct !== 0.0) {
                    $this->fail($field.'.fixed_discount_amount', 'Choose one discount method per line.');
                }
                $discount = $this->cents($line['fixed_discount_amount'], $field.'.fixed_discount_amount', true);
                if ($discount > $gross) {
                    $this->fail($field.'.fixed_discount_amount', 'Discount cannot exceed the original line price.');
                }
            }
            $net = $gross - $discount;
            $tax = $line['tax_type'] ?? ($vatRegistered ? 'vatable' : 'non_vat');
            if (! in_array($tax, ['vatable', 'vat_exempt', 'zero_rated', 'non_vat'], true)) {
                $this->fail($field.'.tax_type', 'Unsupported tax classification.');
            }
            if (! $vatRegistered && in_array($tax, ['vatable', 'zero_rated'], true)) {
                $this->fail($field.'.tax_type', 'This tax classification requires a VAT-registered seller.');
            }
            if ($tax === 'vatable' && isset($line['vat_rate']) && (float) $line['vat_rate'] !== 12.0) {
                $this->fail($field.'.vat_rate', 'This calculator supports ordinary 12% VAT only.');
            }
            $base = $tax === 'vatable' ? ($pricesIncludeVat ? intdiv($net * 100 + 56, 112) : $net) : 0;
            $vat = $tax === 'vatable' ? ($pricesIncludeVat ? $net - $base : intdiv($base * 12 + 50, 100)) : 0;
            $lineTotal = $pricesIncludeVat ? $net : $net + $vat;
            $statutoryDiscount = $vatRelief = 0;
            $benefitDetails = null;
            if (!empty($line['benefit_eligible'])) {
                if (!$benefit) {
                    $this->fail($field.'.benefit_eligible', 'Provide verified sales-benefit details before marking an eligible line.');
                }
                if ((float)$pct !== 0.0 || $discount !== 0) {
                    $this->fail($field.'.discount_pct', 'Do not stack promotional and statutory discounts on the same line. Use the applicable benefit and separate non-eligible items.');
                }
                if ($tax === 'zero_rated') {
                    $this->fail($field.'.tax_type', 'Do not combine a zero-rated export classification with a personal statutory benefit.');
                }
                $eligibleCount++;
                $originalTax = $tax;
                $originalBase = $tax === 'vatable' && $pricesIncludeVat ? intdiv($gross * 100 + 56, 112) : $gross;
                $originalVat = $tax === 'vatable' ? ($pricesIncludeVat ? $gross - $originalBase : intdiv($gross * 12 + 50, 100)) : 0;
                $rate = $benefit['category'] === 'solo_parent' ? 10 : 20;
                $discount = $statutoryDiscount = intdiv($originalBase * $rate + 50, 100);
                $discountedBase = $originalBase - $discount;
                $exempt = $benefit['category'] !== 'national_athlete_coach';
                if ($exempt && $tax === 'vatable') {
                    $tax = 'vat_exempt';
                }
                $base = $tax === 'vatable' ? $discountedBase : 0;
                $vat = $tax === 'vatable' ? intdiv($base * 12 + 50, 100) : 0;
                $lineTotal = $discountedBase + $vat;
                $net = $discountedBase;
                $vatRelief = $pricesIncludeVat ? $originalVat - $vat : 0;
                $benefitDetails = ['eligible' => true, 'original_tax_type' => $originalTax, 'rate' => $rate,
                    'discount_centavos' => $statutoryDiscount, 'vat_adjustment_centavos' => $vatRelief, 'base_centavos' => $originalBase];
            }
            $computed = ['discount_amount'  => $discount, 'taxable_amount' => $base, 'vat_amount' => $vat, 'line_total' => $lineTotal];
            foreach ($computed as $key => $value) {
                if (isset($line[$key]) && $this->cents($line[$key], $field.'.'.$key, true) !== $value) {
                    $this->fail($field.'.'.$key, 'Submitted amount does not match the server calculation.');
                }
            }
            $items[] = [...$line, ...$computed, 'quantity' => $qty / 10000, 'unit_price' => $price, 'vat_rate' => $tax === 'vatable' ? 12 : 0, 'tax_type' => $tax, 'vat_inclusive' => $pricesIncludeVat, 'benefit_eligible' => $benefitDetails !== null, 'benefit_details' => $benefitDetails];
            $s['statutory_discount_amount'] += $statutoryDiscount;
            $s['vat_relief_amount'] += $vatRelief;
            $s['subtotal_amount'] += $gross;
            $s['discount_amount'] += $discount;
            $s['taxable_amount'] += $base;
            $s['vat_amount'] += $vat;
            if ($tax === 'zero_rated') {
                $s['zero_rated_amount'] += $net;
            }
            if (in_array($tax, ['vat_exempt', 'non_vat'], true)) {
                $s['vat_exempt_amount'] += $net;
            }
        }
        if ($benefit && !$eligibleCount) {
            $this->fail('sales_benefit', 'Mark at least one eligible line. Split shared or partly eligible purchases into separate lines.');
        }
        $total = array_sum(array_column($items, 'line_total'));
        if ($total > self::MAX_CENTS || $withholding < 0 || $withholding > $total) {
            $this->fail('total_amount', 'Invalid total or withholding amount.');
        }

        return [...$s, 'manual_discount_amount' => $s['discount_amount'] - $s['statutory_discount_amount'], 'withholding_tax' => $withholding, 'other_charges' => 0, 'total_amount' => $total, 'items' => $items];
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
