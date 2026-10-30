<?php

namespace App\Services;

use Brick\Math\BigInteger;
use Illuminate\Validation\ValidationException;

/** Convert API discounts to deterministic line inputs; all allocations use centavos. */
class ManualDiscounts
{
    public function apply(array $lines, array $payload, bool $hasBenefit): array
    {
        $calculator = app(InvoiceCalculator::class);
        $header = $payload['manual_discount'] ?? null;
        if ($header !== null && $hasBenefit) {
            $this->fail('manual_discount', 'Use line discounts on non-eligible items when claiming a statutory benefit.');
        }
        foreach ($lines as $i => &$line) {
            $discount = $payload['items'][$i]['manual_discount'] ?? null;
            if ($header !== null && ($discount !== null || (float)($line['discount_pct'] ?? 0) !== 0.0)) {
                $this->fail('manual_discount', 'Use either one document discount or line discounts, not both.');
            }
            if ($discount === null) {
                continue;
            }
            if ((float)($line['discount_pct'] ?? 0) !== 0.0 || !empty($line['benefit_eligible'])) {
                $this->fail("items.$i.manual_discount", 'Do not combine manual, legacy percentage and statutory discounts on the same line.');
            }
            $this->validate($discount, "items.$i.manual_discount");
            if ($discount['type'] === 'amount') {
                $line['fixed_discount_amount'] = $calculator->cents($discount['value'], "items.$i.manual_discount.value");
            } else {
                $line['discount_pct'] = $discount['value'];
            }
            $line['discount_reason'] = trim($discount['reason']);
        }
        unset($line);
        if ($header === null) {
            return $lines;
        }
        $this->validate($header, 'manual_discount');
        // Validate quantities/prices before calculating weights; ignore final-total assertions here.
        $weights = array_column($calculator->calculate(array_map(function ($line) {
            unset($line['line_total']);
            return [...$line, 'tax_type' => 'non_vat', 'vat_rate' => 0];
        }, $lines), false)['items'], 'line_total');
        $total = array_sum($weights);
        $discount = $header['type'] === 'amount'
            ? $calculator->cents($header['value'], 'manual_discount.value')
            : intdiv($total * (int)round((float)$header['value'] * 100) + 5000, 10000);
        if ($discount > $total) {
            $this->fail('manual_discount.value', 'Discount cannot exceed the original document price.');
        }
        $allocated = $remainders = [];
        foreach ($weights as $i => $weight) {
            // BigInteger avoids overflowing PHP integers for large, valid invoice amounts.
            [$share, $remainder] = $total === 0 ? [BigInteger::zero(), BigInteger::zero()]
                : BigInteger::of($discount)->multipliedBy($weight)->quotientAndRemainder($total);
            $allocated[$i] = $share->toInt();
            $remainders[$i] = $remainder->toInt();
        }
        $order = array_keys($weights);
        usort($order, fn ($a, $b) => ($remainders[$b] <=> $remainders[$a]) ?: ($a <=> $b));
        $remaining = $discount - array_sum($allocated);
        foreach (array_slice($order, 0, $remaining) as $i) {
            $allocated[$i]++;
        }
        foreach ($lines as $i => &$line) {
            $line['fixed_discount_amount'] = $allocated[$i];
            $line['discount_reason'] = trim($header['reason']);
            $line['discount_scope'] = 'document';
        }
        return $lines;
    }

    private function validate(mixed $discount, string $field): void
    {
        if (!is_array($discount) || array_diff(array_keys($discount), ['type', 'value', 'reason'])
            || !in_array($discount['type'] ?? null, ['amount', 'percentage'], true)
            || !is_string($discount['reason'] ?? null) || !trim($discount['reason']) || mb_strlen($discount['reason']) > 500) {
            $this->fail($field, 'Provide type (amount or percentage), value and a reason of 1–500 characters.');
        }
        $value = app(InvoiceCalculator::class)->cents($discount['value'] ?? null, $field.'.value');
        if ($discount['type'] === 'percentage' && $value > 10000) {
            $this->fail($field.'.value', 'Percentage must be between 0 and 100, with at most two decimals.');
        }
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
