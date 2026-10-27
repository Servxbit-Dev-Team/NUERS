<?php

namespace App\Services;

use App\Support\SchemaMap;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Supplemental original-document evidence; never changes an issued invoice or its amounts. */
class HistoricalBranchEvidence
{
    public function fingerprint(string $source, string $id): string
    {
        $row = DB::table($source === 'invoice' ? 'business_invoices' : 'transaction_receipts')->where('id', $id)->first();
        abort_unless($row, 404);
        // Preserve fingerprints recorded before the optional phone snapshot column existed.
        if ($source === 'invoice' && property_exists($row, 'buyer_phone') && $row->buyer_phone === null) {
            unset($row->buyer_phone);
        }
        $items = $source === 'invoice' ? DB::table('business_invoice_items')->where('invoice_id', $id)->orderBy('line_number')->orderBy('id')->get()->all() : null;

        return hash('sha256', json_encode([$row, $items], JSON_THROW_ON_ERROR));
    }

    public function latest(string $source, string $id): ?object
    {
        return SchemaMap::hasTable('eis_document_branch_reviews') ? DB::table('eis_document_branch_reviews')->where('document_source', $source)->where('document_id', $id)->orderByDesc('revision')->first() : null;
    }

    public function record(string $source, string $id, string $branch, string $reference, int $revision, string $actor): array
    {
        abort_unless(in_array($source, ['invoice', 'receipt'], true), 422);

        return DB::transaction(function () use ($source, $id, $branch, $reference, $revision, $actor) {
            $row = DB::table($source === 'invoice' ? 'business_invoices' : 'transaction_receipts')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_if(! empty($row->seller_branch_code), 409, 'This document already has a recorded branch snapshot. Use a traceable correction for incorrect issued information.');
            abort_unless(preg_match('/^\d{5}$/', $branch), 422, 'A five-digit branch code is required.');
            $identity = app(SellerIdentity::class)->parse($row->merchant_tin ?? null, $branch);
            abort_unless($identity['valid'], 422, 'Branch evidence conflicts with the original issued TIN or the TIN requires source review.');
            $previous = $this->latest($source, $id);
            abort_unless(($previous?->revision ?? 0) === $revision, 409, 'Branch evidence changed. Refresh before reviewing.');
            DB::table('eis_document_branch_reviews')->insert(['id' => (string) Str::uuid(), 'document_source' => $source, 'document_id' => $id, 'branch_code' => $branch, 'source_fingerprint' => $this->fingerprint($source, $id), 'evidence_reference' => $reference, 'reviewed_by' => $actor, 'reviewed_at' => now(), 'revision' => $revision + 1]);
            app(BirCompliance::class)->audit('eis.historical_branch.reviewed', $id, $actor, ['source' => $source, 'branch_code' => $branch, 'evidence_reference' => $reference, 'revision' => $revision + 1]);

            return app(EisDocumentReadiness::class)->prepare($source, $id);
        });
    }
}
