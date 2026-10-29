<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class ExpenseCreditEvidence
{
    public function fingerprint(object $invoice): string
    {
        $snapshot = (array) $invoice;
        // A newly added, unrecorded optional field must not invalidate legacy review evidence.
        if (array_key_exists('buyer_phone', $snapshot) && $snapshot['buyer_phone'] === null) {
            unset($snapshot['buyer_phone']);
        }

        return hash('sha256', json_encode(['invoice' => $snapshot, 'lines' => DB::table('business_invoice_items')->where('invoice_id', $invoice->id)->orderBy('line_number')->orderBy('id')->get()->all()], JSON_THROW_ON_ERROR));
    }

    public function source(object $expense): ?object
    {
        if ($expense->claim_status !== 'approved') {
            return null;
        }
        $meta = json_decode($expense->metadata ?? '{}', true) ?: [];
        $proof = $meta['claim_review'] ?? [];
        if (empty($proof['reviewed_by']) || empty($proof['reviewed_at']) || empty($proof['evidence_reference']) || empty($proof['source_hash'])) {
            return null;
        }
        $source = DB::table('business_invoices')->where('id', $expense->source_invoice_id ?? $meta['source_invoice_id'] ?? $meta['business_invoice_id'] ?? '')->whereNotIn('status', ['draft', 'cancelled', 'voided'])->first();
        if ($source && (! in_array($source->document_type, ['vat_invoice', 'non_vat_invoice', 'vat_exempt_invoice', 'zero_rated_invoice', 'mixed_sales_invoice', 'sales_invoice', 'service_invoice'], true) || DB::table('business_invoices')->where('merchant_id', $source->merchant_id)->where('original_invoice_id', $source->id)->whereIn('document_type', ['credit_memo', 'debit_memo', 'credit_note', 'debit_note'])->whereNotIn('status', ['draft', 'cancelled', 'voided'])->exists())) {
            return null;
        }
        if (! $source || ! hash_equals($proof['source_hash'], $this->fingerprint($source))) {
            return null;
        }
        if (preg_replace('/\D/', '', (string) $source->buyer_tin) !== preg_replace('/\D/', '', (string) $expense->buyer_tin)) {
            return null;
        }
        foreach (['gross_amount' => 'total_amount', 'input_vat_amount' => 'vat_amount', 'withholding_tax_amount' => 'withholding_tax'] as $expenseField => $invoiceField) {
            if ((int) round($expense->$expenseField * 100) !== (int) round($source->$invoiceField * 100)) {
                return null;
            }
        }

        return $source;
    }
}
