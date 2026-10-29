<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Call inside the same issuance transaction. No random, guessed or default legal number. */
class InvoiceSequence
{
    public function reserve(string $merchant, string $branch, string $family, ?string $expected = null): array
    {
        $series = DB::table('invoice_series')->where('merchant_id', $merchant)->where('branch_code', $branch)
            ->where('document_family', $family)->where('status', 'active')->where('valid_from', '<=', now())->where('valid_until', '>=', now())
            ->orderBy('created_at')->lockForUpdate()->get();
        abort_unless($series->count() === 1, 409, 'Exactly one current, evidence-backed invoice series must be configured for this business, branch and document family.');
        $s = $series->first();
        $next = $s->last_number === null ? (int) $s->range_start : (int) $s->last_number + 1;
        abort_if($next > (int) $s->range_end, 409, 'Authorized document series is exhausted.');
        $number = $s->prefix.str_pad((string) $next, 8, '0', STR_PAD_LEFT);
        abort_if($expected !== null && $expected !== $number, 409, 'Source document number does not match the next authorized sequence. Reconcile the source series before retrying.');
        DB::table('invoice_series')->where('id', $s->id)->update(['last_number' => $next, 'updated_at' => now()]);

        return ['number' => $number, 'series_id' => $s->id, 'authority_reference' => $s->authority_reference];
    }
}
