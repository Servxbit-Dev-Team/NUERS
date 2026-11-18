<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Keep the expensive source/payload checks in bounded projection batches. */
class ReconciliationEvidence
{
    public function payload(?object $payload): array
    {
        $out = ['payload_comparison' => 'No readable linked payload'];
        if (!$payload) {
            return $out;
        }
        try {
            $body = json_decode($payload->payload, true, 512, JSON_THROW_ON_ERROR);
            $raw = isset($body['encrypted']) ? Crypt::decryptString($body['encrypted']) : $payload->payload;
            $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $out['payload_hash_verified'] = hash_equals((string) $payload->payload_hash, hash('sha256', $raw));
            $total = $body['total_due'] ?? $body['gross_amount'] ?? $body['total_amount'] ?? data_get($body, 'totals.total_due');
            $tin = $body['seller_tin'] ?? data_get($body, 'seller.tin') ?? $body['merchant_tin'] ?? null;
            $out += ['payload_amount' => $total === null ? null : (float) $total, 'payload_tin' => $tin, 'payload_document_type' => $body['document_type'] ?? null];
            $out['payload_comparison'] = $total !== null && $tin && $out['payload_hash_verified'] ? 'Comparable' : 'Incomplete payload evidence';
        } catch (\Throwable) {
            $out['payload_hash_verified'] = false;
            $out['payload_comparison'] = 'Payload could not be verified';
        }
        return $out;
    }

    public function batch(iterable $documents, array $financial): array
    {
        $documents = collect($documents);
        $payloads = DB::table('eis_transmissions as t')->join('eis_invoice_payloads as p', 'p.id', '=', 't.payload_id')
            ->whereIn('t.id', $documents->pluck('transmission_id')->filter()->unique())->get(['t.id', 'p.payload', 'p.payload_hash'])->keyBy('id');
        $originals = DB::table('business_invoices')->whereIn('id', $documents->pluck('original_invoice_id')->filter()->unique())
            ->whereNotIn('status', ['draft', 'cancelled', 'voided'])
            ->whereNotIn('document_type', ['credit_memo', 'debit_memo', 'credit_note', 'debit_note', 'payment_receipt', 'official_receipt', 'purchase_invoice', 'delivery_receipt'])
            ->get(['id', 'merchant_id'])->keyBy('id');
        $result = [];
        foreach ($documents as $row) {
            $key = $row->document_source.':'.($row->document_id ?: $row->transaction_id);
            $p = $this->payload($payloads->get($row->transmission_id));
            $original = $originals->get($row->original_invoice_id);
            $prefix = match (true) {
                $row->amount < 0 => 'Invalid Amount',
                in_array($row->transmission_status, ['rejected', 'failed'], true) => 'Transmission Rejected',
                $row->status === 'failed' => 'Failed Transaction',
                $row->status === 'cancelled' => 'Cancelled',
                $row->document_source === 'transaction' => 'Missing Receipt',
                !$row->tin => 'Missing TIN',
                !app(SellerIdentity::class)->parse($row->tin, $row->branch_code)['valid'] => 'Invalid TIN',
                !($financial[$key]['financial_validation_passed'] ?? false) => 'Source Financial Mismatch',
                array_key_exists('payload_hash_verified', $p) && !$p['payload_hash_verified'] => 'Payload Integrity',
                in_array($row->document_family, ['credit', 'debit'], true) && ($row->status !== 'success' || !$original || $original->merchant_id !== $row->merchant_id) => 'Adjustment Reference Unverified',
                default => '',
            };
            $suffix = match (true) {
                isset($p['payload_amount']) && abs($p['payload_amount'] - $row->amount) > 0.005 => 'Amount Mismatch',
                !empty($p['payload_tin']) && preg_replace('/\D/', '', $p['payload_tin']) !== preg_replace('/\D/', '', $row->tin ?? '') => 'TIN Mismatch',
                !empty($p['payload_document_type']) && $p['payload_document_type'] !== $row->document_type => 'Document Type Mismatch',
                $row->bir_status === 'acknowledged' && $p['payload_comparison'] === 'Comparable' && app(SubmissionEvidence::class)->verified($row->transmission_id) => 'Matched',
                default => 'For Review',
            };
            // Duplicate identity sits between these checks and is evaluated at read
            // time, after canonical ranking (including changes to sibling records).
            $result[$key] = ['reconciliation_prefix' => $prefix, 'reconciliation_suffix' => $suffix];
        }
        return $result;
    }
}
