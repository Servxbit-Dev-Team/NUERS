<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Call inside the same issuance transaction. No random, guessed or default legal number. */
class InvoiceSequence
{
    public function reserve(string $merchant, string $branch, string $family, ?string $expected = null): array
    {
        abort_unless(DB::transactionLevel() > 0, 500, 'Invoice numbers must be reserved inside the issuance transaction.');
        DB::table('merchants')->where('id', $merchant)->lockForUpdate()->first();
        $series = DB::table('invoice_series')->where('merchant_id', $merchant)->where('branch_code', $branch)
            ->where('document_family', $family)->where('status', 'active')->where('valid_from', '<=', now())->where('valid_until', '>=', now())
            ->orderBy('created_at')->lockForUpdate()->get();
        abort_unless($series->count() === 1, 409, 'Exactly one current, evidence-backed invoice series must be configured for this business, branch and document family.');
        $s = $series->first();
        $next = $s->last_number === null ? (int) $s->range_start : (int) $s->last_number + 1;
        abort_if($next > (int) $s->range_end, 409, 'Authorized document series is exhausted.');
        $digits = (int) ($s->serial_digits ?? 8);
        abort_unless($digits >= 1 && $digits <= 12 && strlen((string)$s->range_end) <= $digits && $next >= (int)$s->range_start, 409, 'Document series counter or padding requires review.');
        $number = $s->prefix.str_pad((string) $next, $digits, '0', STR_PAD_LEFT);
        app(InvoiceDocumentRules::class)->assertNumber($number);
        $registration = app(InvoiceDocumentRules::class)->registration(json_decode($s->document_registration ?? '{}', true), true);
        $registration['series_start'] = $s->prefix.str_pad((string)$s->range_start, $digits, '0', STR_PAD_LEFT);
        $registration['series_end'] = $s->prefix.str_pad((string)$s->range_end, $digits, '0', STR_PAD_LEFT);
        abort_if($expected !== null && $expected !== $number, 409, 'Source document number does not match the next authorized sequence. Reconcile the source series before retrying.');
        $collision = DB::query()->fromSub(app(IssuedDocumentLedger::class)->normalizedCandidates($merchant), 'issued_identity')
            ->where('merchant_id', $merchant)->where('seller_branch_key', $branch)
            ->where('document_family', match ($family) {
                'payment' => 'receipt', 'credit_memo' => 'credit', 'debit_memo' => 'debit', default => 'invoice'
            })
            ->where('document_number_key', strtoupper($number))->exists();
        // Even voided documents retain their identity. Never skip a collision or reset a series.
        abort_if($collision || DB::table('business_invoices')->where('merchant_id', $merchant)->where('invoice_number', $number)->exists(), 409, 'The next authorized number already exists. Reconcile the series; issued numbers cannot be reused.');
        DB::table('invoice_series')->where('id', $s->id)->update(['last_number' => $next, 'updated_at' => now()]);

        return ['number' => $number, 'series_id' => $s->id, 'authority_reference' => $s->authority_reference, 'document_registration' => $registration];
    }
}
