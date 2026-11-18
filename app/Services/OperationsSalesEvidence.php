<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Validate source evidence once per changed document, never once per dashboard row. */
class OperationsSalesEvidence
{
    public function batch(iterable $documents, array $financial): array
    {
        $documents = collect($documents);
        $payloads = DB::table('eis_transmissions as t')->join('eis_invoice_payloads as p', 'p.id', '=', 't.payload_id')
            ->whereIn('t.id', $documents->pluck('transmission_id')->filter()->unique())
            ->get(['t.id', 'p.payload', 'p.payload_hash'])->keyBy('id');
        $originals = DB::table('business_invoices')->whereIn('id', $documents->pluck('original_invoice_id')->filter()->unique())
            ->whereNotIn('status', ['draft','cancelled','voided'])
            ->whereNotIn('document_type', ['credit_memo','debit_memo','credit_note','debit_note','payment_receipt','official_receipt','purchase_invoice','delivery_receipt'])
            ->get(['id','merchant_id'])->keyBy('id');
        $result = [];
        foreach ($documents as $row) {
            $key = $row->document_source.':'.($row->document_id ?: $row->transaction_id);
            $original = $originals->get($row->original_invoice_id);
            $valid = $row->document_source !== 'transaction' && $row->business_date !== null
                && in_array($row->document_family, ['invoice','credit','debit'], true)
                && ($row->document_family === 'invoice' || ($original && $original->merchant_id === $row->merchant_id))
                && $row->amount >= 0 && !in_array($row->transmission_status, ['failed', 'rejected'], true)
                && $row->tin && app(SellerIdentity::class)->parse($row->tin, $row->branch_code)['valid']
                && ($financial[$key]['financial_validation_passed'] ?? false);
            if ($valid && ($p = $payloads->get($row->transmission_id))) {
                try {
                    $body = json_decode($p->payload, true, 512, JSON_THROW_ON_ERROR);
                    $raw = isset($body['encrypted']) ? Crypt::decryptString($body['encrypted']) : $p->payload;
                    $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                    $total = $body['total_due'] ?? $body['gross_amount'] ?? $body['total_amount'] ?? data_get($body, 'totals.total_due');
                    $tin = $body['seller_tin'] ?? data_get($body, 'seller.tin') ?? $body['merchant_tin'] ?? null;
                    $valid = hash_equals((string) $p->payload_hash, hash('sha256', $raw))
                        && ($total === null || abs((float) $total - $row->amount) <= 0.005)
                        && (!$tin || preg_replace('/\D/', '', $tin) === preg_replace('/\D/', '', $row->tin))
                        && (empty($body['document_type']) || $body['document_type'] === $row->document_type);
                } catch (\Throwable) {
                    // Unreadable linked evidence must be reviewed, never counted as verified sales.
                    $valid = false;
                }
            }
            $result[$key] = (bool) $valid;
        }
        return $result;
    }
}
