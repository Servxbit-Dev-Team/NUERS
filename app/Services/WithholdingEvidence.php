<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;

/** Buyer-supplied CWT. Never changes sales, VAT, or the transaction fee basis. */
class WithholdingEvidence
{
    public function validate(array $input, int $gross): array
    {
        $v = Validator::make($input, [
            'withholding_agent' => 'required|accepted',
            'classification' => 'required|string|min:3|max:200',
            'atc' => ['required', 'regex:/^[A-Z]{2}[0-9]{3}$/D'],
            'rate' => 'required|numeric|min:0|max:100|decimal:0,2',
            'base' => 'required|numeric|min:0|max:10000000000|decimal:0,2',
            'amount' => 'required|numeric|min:0|max:10000000000|decimal:0,2',
            'evidence_reference' => 'required|string|min:3|max:2000',
        ])->validate();
        $c = app(InvoiceCalculator::class);
        $base = $c->cents($v['base'], 'withholding.base');
        $amount = $c->cents($v['amount'], 'withholding.amount');
        $expected = intdiv($base * (int) round((float) $v['rate'] * 100) + 5000, 10000);
        abort_unless($base <= $gross && $amount <= $gross && $amount === $expected, 422, 'CWT base, rate and amount must reconcile and cannot exceed the original sale.');
        return [...$v, 'base_centavos' => $base, 'amount_centavos' => $amount, 'determined_by' => 'buyer_payor'];
    }
}
