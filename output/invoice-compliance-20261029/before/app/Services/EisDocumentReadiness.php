<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Internal normalized evidence only. This does not implement or claim an official BIR wire schema. */
class EisDocumentReadiness
{
    public const INTERNAL_SCHEMA = 'NUERS-normalized-document-5';

    /**
     * Financial report projection only. Reuses the same calculator as full readiness,
     * without repeating identity ranking, schema discovery and BIR policy per document.
     * Every source and item lookup is tenant scoped; no cross-request cache is used.
     */
    public function financialBatch(iterable $rows, string $merchantId): array
    {
        $rows = collect($rows)->filter(fn ($row) => in_array($row->document_source, ['invoice', 'receipt'], true));
        abort_if($rows->contains(fn ($row) => (string) $row->merchant_id !== $merchantId), 403, 'Document scope mismatch.');
        $result = [];
        foreach (['invoice' => 'business_invoices', 'receipt' => 'transaction_receipts'] as $source => $table) {
            $sources = $rows->where('document_source', $source)->keyBy('document_id');
            foreach ($sources->keys()->chunk(500) as $ids) {
                $records = DB::table($table)->where('merchant_id', $merchantId)->whereIn('id', $ids)->get()->keyBy('id');
                $lines = $source === 'invoice'
                    ? DB::table('business_invoice_items')->where('merchant_id', $merchantId)->whereIn('invoice_id', $ids)->orderBy('line_number')->get()->groupBy('invoice_id')
                    : collect();
                foreach ($ids as $id) {
                    $row = $sources->get($id);
                    $record = $records->get($id);
                    $key = $source.':'.$id;
                    if (!$record) {
                        $result[$key] = ['financial_validation_passed' => false, 'items' => [], 'seller_branch_code' => null, 'merchant_tin' => null];
                        continue;
                    }
                    $rawItems = $source === 'invoice'
                        ? $lines->get($id, collect())->map(fn ($item) => (array) $item)->all()
                        : json_decode($record->items ?? '[]', true);
                    [$items, $errors] = $this->normalizeItems($source, $id, $rawItems, $row, $record);
                    $amount = (int) round((float) $row->amount * 100);
                    $result[$key] = [
                        'financial_validation_passed' => !$errors && $amount > 0
                            && (int) round((float) $row->net_amount * 100) + (int) round((float) $row->vat_amount * 100) === $amount,
                        'items' => $items, 'seller_branch_code' => $record->seller_branch_code ?? null,
                        'merchant_tin' => $record->merchant_tin ?? null,
                    ];
                }
            }
        }

        return $result;
    }

    public function inspect(string $source, string $id): array
    {
        abort_unless(in_array($source, ['receipt', 'invoice'], true), 422, 'Unsupported document source.');
        $row = app(IssuedDocumentLedger::class)->normalizedCandidates(null, [$source => [$id]])->where('document_source', $source)->where('document_id', $id)->first();
        abort_unless($row, 404, 'Issued document not found. Drafts cannot be prepared for submission.');
        $family = $row->document_family;
        $branchReview = app(HistoricalBranchEvidence::class)->latest($source, $id);
        $reviewValid = $branchReview && hash_equals($branchReview->source_fingerprint, app(HistoricalBranchEvidence::class)->fingerprint($source, $id));
        if ($reviewValid && ! $row->branch_code) {
            $row->branch_code = $branchReview->branch_code;
        }
        $identity = app(SellerIdentity::class)->parse($row->tin, $row->branch_code);
        $row->branch_code = $identity['branch_code'];
        if ($reviewValid) {
            $identity['branch_source'] = 'reviewed_historical_evidence';
        }
        $policy = app(EisReportingPolicy::class)->forDocument($row);
        $errors = [];
        $originalRecord = DB::table($source === 'invoice' ? 'business_invoices' : 'transaction_receipts')->where('id', $id)->first();
        $sourceCopy = ($originalRecord->record_mode ?? null) === 'source_capture';
        if ($branchReview && ! $reviewValid) {
            $errors[] = 'Historical branch evidence no longer matches the original source snapshot.';
        }
        if (! trim((string) $row->business) || ! $identity['valid']) {
            $errors[] = 'Seller name/TIN snapshot is incomplete.';
        }
        if (! trim((string) $row->seller_address)) {
            $errors[] = 'Seller address snapshot is missing.';
        }
        if (! preg_match('/^\d{5}$/', (string) $row->branch_code)) {
            $errors[] = 'Verified five-digit seller branch snapshot is missing.';
        }
        if (! $row->business_date) {
            $errors[] = 'Original document date is unverified.';
        }
        if (! $row->receipt_number) {
            $errors[] = 'Document number is missing.';
        }
        if (! in_array($row->record_status, ['issued', 'sent', 'paid', 'partially_paid', 'validated', 'completed', 'settled', 'verified', 'valid', 'overdue', 'cancelled', 'voided', 'void', 'refunded', 'refund'], true)) {
            $errors[] = 'The source is not an issued document.';
        }
        if ($row->status === 'cancelled') {
            $errors[] = 'Cancelled documents require a reviewed adjustment workflow.';
        }
        if (in_array($family, ['receipt', 'supplementary'], true)) {
            $errors[] = 'Supplementary payment/delivery documents cannot be submitted as primary invoice sales.';
        }
        $identityCandidates = app(LedgerProjection::class)->ready() ? app(LedgerProjection::class)->query(null, true, false, false) : app(IssuedDocumentLedger::class)->normalizedCandidates();
        $identityMatches = DB::query()->fromSub($identityCandidates, 'identity_documents')->when(! $row->seller_tin_key, fn ($q) => $q->where('merchant_id', $row->merchant_id))->where('seller_tin_key', $row->seller_tin_key)->where('seller_branch_key', $row->seller_branch_key)->where('document_number_key', $row->document_number_key)->where('document_family', $row->document_family)->count();
        if ($identityMatches > 1) {
            $errors[] = 'Multiple source documents share this seller/branch/document identity; reconcile them first.';
        }
        if (in_array($family, ['credit', 'debit'], true)) {
            $original = DB::table('business_invoices')->where('id', $row->original_invoice_id)->where('merchant_id', $row->merchant_id)->whereNotIn('status', ['draft', 'cancelled', 'voided'])->whereNotIn('document_type', ['credit_memo', 'debit_memo', 'credit_note', 'debit_note', 'payment_receipt', 'official_receipt', 'purchase_invoice', 'delivery_receipt'])->first();
            if (! $original) {
                $errors[] = 'Adjustment requires a verifiable original issued invoice of this seller.';
            }
        }
        $rawItems = $source === 'invoice' ? DB::table('business_invoice_items')->where('invoice_id', $id)->where('merchant_id', $row->merchant_id)->orderBy('line_number')->get()->map(fn ($x) => (array) $x)->all() : json_decode(DB::table('transaction_receipts')->where('id', $id)->value('items') ?? '[]', true);
        [$items,$itemErrors,$priceBasis] = $this->normalizeItems($source, $id, $rawItems, $row, $originalRecord);
        $errors = [...$errors, ...$itemErrors];
        $amount = (int) round((float) $row->amount * 100);
        $vat = (int) round((float) $row->vat_amount * 100);
        if ($amount <= 0) {
            $errors[] = 'A positive issued-document amount is required.';
        }
        if ((int) round((float) $row->net_amount * 100) + $vat !== $amount) {
            $errors[] = 'Tax classifications plus VAT do not reconcile to the document total.';
        }
        $model = ['schema' => self::INTERNAL_SCHEMA, 'official_bir_schema_verified' => false,
            'source' => ['type' => $source, 'id' => $id], 'seller' => ['vat_registered' => $source === 'invoice' ? (bool)$originalRecord->vat_registered : (trim((string)($originalRecord->merchant_vat_reg??'')) !== '' ? trim($originalRecord->merchant_vat_reg)==='VAT Registered' : null), 'name' => $row->business, 'tin' => $row->tin, 'taxpayer_tin' => $identity['tin'], 'branch_code_source' => $identity['branch_source'], 'address' => $row->seller_address, 'branch_code' => $row->branch_code, 'rdo_code' => $row->rdo_code],
            'number' => $row->receipt_number, 'document_type' => $row->document_type, 'issue_date' => $row->business_date,
            'record_mode' => $sourceCopy ? 'source_capture' : 'nuers_issuance',
            'source_profile_id' => $originalRecord->source_profile_id ?? null,
            'buyer' => ['name' => $row->customer_name, 'tin' => $row->customer_tin, 'address' => $originalRecord->buyer_address ?? null], 'currency' => 'PHP',
            'withholding_basis_reference' => $originalRecord->withholding_basis_reference ?? null,
            'totals' => ['sspt_centavos' => (int) round((float) ($row->sspt_sales ?? 0) * 100), 'withholding_centavos' => (int) round((float) ($originalRecord->withholding_tax ?? 0) * 100), 'amount_centavos' => $amount, 'vat_centavos' => $vat, 'vatable_centavos' => (int) round((float) $row->vatable_sales * 100), 'exempt_centavos' => (int) round((float) $row->vat_exempt_sales * 100), 'zero_rated_centavos' => (int) round((float) $row->zero_rated_sales * 100)],
            'original_invoice_id' => $row->original_invoice_id, 'line_price_basis' => $priceBasis, 'items' => $items];
        if ($reviewValid) {
            $model['historical_branch_evidence'] = ['revision' => $branchReview->revision, 'evidence_reference' => $branchReview->evidence_reference, 'source_fingerprint' => $branchReview->source_fingerprint, 'reviewed_by' => $branchReview->reviewed_by, 'reviewed_at' => $branchReview->reviewed_at];
        }
        if (!empty($originalRecord->sales_benefit)) $model['sales_benefit'] = ['eligibility' => json_decode($originalRecord->sales_benefit, true), 'statutory_discount_centavos' => (int)round($originalRecord->statutory_discount_amount * 100), 'vat_adjustment_centavos' => (int)round($originalRecord->vat_relief_amount * 100)];
        $canonical = json_encode($model, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $blockers = $errors;
        if ($sourceCopy) {
            $blockers[] = 'External issuer source copy: BIR reporting requires separate reviewed source-system coverage and duplicate-reporting reconciliation.';
        }
        if ($policy['submission_applicability'] !== 'required') {
            $blockers[] = 'Documented taxpayer transmission coverage is not active for this document.';
        }
        $blockers[] = 'Use BIR EIS Setup for official payload mapping, issued credentials, authority checks and authenticated result verification.';

        return ['model' => $model, 'canonical' => $canonical, 'document_hash' => hash('sha256', $canonical), 'source_review_revision' => $branchReview?->revision ?? 0, 'financial_validation_passed' => ! $itemErrors && $amount > 0 && (int) round((float) $row->net_amount * 100) + $vat === $amount, 'local_errors' => array_values(array_unique($errors)), 'local_validation_passed' => ! $errors, 'blockers' => array_values(array_unique($blockers)), ...$policy, 'can_transmit' => false];
    }

    private function normalizeItems(string $source, string $id, mixed $rawItems, object $row, object $record): array
    {
        if (! is_array($rawItems) || ! $rawItems) {
            return [[], ['Document line items are missing or incomplete.'], 'unverified'];
        }
        $calculator = app(InvoiceCalculator::class);
        $lines = [];
        $declared = [];
        foreach ($rawItems as $item) {
            if (! is_array($item)) {
                return [[], ['Invalid line item structure.'], 'unverified'];
            }
            $benefitDetails = is_string($item['benefit_details'] ?? null) ? json_decode($item['benefit_details'], true) : ($item['benefit_details'] ?? null);
            $description = $item['description'] ?? $item['desc'] ?? $item['name'] ?? '';
            $quantity = $item['quantity'] ?? $item['qty'] ?? null;
            $price = $item['unit_price'] ?? $item['unitPrice'] ?? $item['price'] ?? null;
            if (! trim((string) $description) || ! is_numeric($quantity) || $quantity <= 0 || ! is_numeric($price) || $price < 0) {
                return [[], ['Each line needs a description, positive quantity and original unit price.'], 'unverified'];
            }
            try {
                $line = ['description' => $description, 'quantity' => $quantity, 'unit_price' => $calculator->cents($price, 'unit_price'),
                    'discount_pct' => $item['discount_pct'] ?? 0, 'benefit_eligible' => !empty($benefitDetails['eligible']), 'tax_type' => $benefitDetails['original_tax_type'] ?? $item['tax_type'] ?? null, 'vat_rate' => !empty($benefitDetails) ? (($benefitDetails['original_tax_type'] ?? null) === 'vatable' ? 12 : 0) : ($item['vat_rate'] ?? 12)];
                if (! $line['tax_type']) {
                    return [[], ['Line tax classification is missing; do not infer a mixed allocation.'], 'unverified'];
                }
                foreach (['line_total', 'discount_amount', 'fixed_discount_amount', 'taxable_amount', 'vat_amount'] as $key) {
                    if (isset($item[$key])) {
                        $line[$key] = $calculator->cents($item[$key], 'line.'.$key);
                    }
                }
                if (! isset($line['line_total']) && (isset($item['amount']) || isset($item['total']))) {
                    $line['line_total'] = $calculator->cents($item['amount'] ?? $item['total'], 'line_total');
                }
            } catch (ValidationException $e) {
                return [[], ['Line monetary precision is invalid.'], 'unverified'];
            }
            if (isset($item['vat_inclusive'])) {
                $declared[] = (bool) $item['vat_inclusive'];
            }
            $lines[] = $line;
        }
        $vatRegistered = $source === 'invoice' ? (bool) $record->vat_registered : (trim((string) $record->merchant_vat_reg) !== '' ? trim((string) $record->merchant_vat_reg) === 'VAT Registered' : ((float) $row->vat_amount > 0 || in_array($row->document_type, ['vat_invoice', 'zero_rated_invoice', 'mixed_sales_invoice'], true)));
        $modes = $source === 'invoice' && isset($record->prices_include_vat) ? [(bool) $record->prices_include_vat] : [true, false];
        if ($declared) {
            if (count(array_unique($declared)) > 1) {
                return [[], ['Mixed inclusive/exclusive price modes require a reviewed source mapping.'], 'unverified'];
            }
            $modes = [(bool) $declared[0]];
        }
        foreach ($modes as $inclusive) {
            try {
                $computed = $calculator->calculate($lines, $vatRegistered, 0, 0, $inclusive, json_decode($record->sales_benefit ?? 'null', true));
            } catch (ValidationException $e) {
                continue;
            }
            $expected = ['total_amount' => $row->amount, 'vat_amount' => $row->vat_amount, 'taxable_amount' => $row->vatable_sales, 'vat_exempt_amount' => (float) $row->vat_exempt_sales + (float) ($row->sspt_sales ?? 0), 'zero_rated_amount' => $row->zero_rated_sales];
            $matches = true;
            foreach ($expected as $key => $amount) {
                if ($computed[$key] !== (int) round((float) $amount * 100)) {
                    $matches = false;
                }
            }
            if (! $matches) {
                continue;
            }
            $normalized = [];
            foreach ($computed['items'] as $n => $line) {
                $gross = (int) round($line['unit_price'] * $line['quantity']);
                $grossBase = $line['benefit_details']['base_centavos'] ?? ($inclusive && $line['tax_type'] === 'vatable' ? intdiv($gross * 100 + 56, 112) : $gross);
                $netBase = $line['line_total'] - $line['vat_amount'];
                $normalized[] = ['line' => $n + 1, 'description' => $line['description'], 'quantity' => (string) $line['quantity'], 'unit_price_centavos' => $line['unit_price'], 'discount_centavos' => $line['discount_amount'], 'taxable_centavos' => $line['taxable_amount'], 'vat_centavos' => $line['vat_amount'], 'line_total_centavos' => $line['line_total'], 'tax_type' => $line['tax_type'], 'tax_basis_reference' => $rawItems[$n]['tax_basis_reference'] ?? null,
                    'gross_base_centavos' => $grossBase, 'net_base_centavos' => $netBase,
                    'statutory_discount_centavos' => $line['benefit_details']['discount_centavos'] ?? 0,
                    'regular_discount_base_centavos' => $grossBase - $netBase - ($line['benefit_details']['discount_centavos'] ?? 0)];
            }

            return [$normalized, [], $inclusive ? 'vat_inclusive_reconciled' : 'vat_exclusive_reconciled'];
        }

        return [[], ['Stored line arithmetic, tax allocation or document total does not reconcile; source review is required.'], 'unverified'];
    }

    public function prepare(string $source, string $id): array
    {
        abort_unless(in_array($source, ['invoice', 'receipt'], true), 422);

        return DB::transaction(function () use ($source, $id) {
            // Serialize preparation per source document and commit the outbox with issuance when called from an issuance transaction.
            DB::table($source === 'invoice' ? 'business_invoices' : 'transaction_receipts')->where('id', $id)->lockForUpdate()->first();
            $result = $this->inspect($source, $id);
            $existing = DB::table('eis_document_outbox')->where('document_source', $source)->where('document_id', $id)->where('internal_schema', self::INTERNAL_SCHEMA)->where('source_review_revision', $result['source_review_revision'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->document_hash, $result['document_hash']), 409, 'The prepared issued-document snapshot changed. Preserve evidence and reconcile the source; do not overwrite it.');
                DB::table('eis_document_outbox')->where('id', $existing->id)->update(['blockers' => json_encode($result['blockers']), 'status' => $result['local_validation_passed'] ? 'blocked_pending_api' : 'needs_source_review', 'updated_at' => now()]);

                return ['id' => $existing->id, 'duplicate' => true, ...array_diff_key($result, ['canonical' => true, 'model' => true])];
            }
            $outboxId = (string) Str::uuid();
            DB::table('eis_document_outbox')->insert(['id' => $outboxId, 'merchant_id' => $result['model']['source']['type'] === 'invoice' ? DB::table('business_invoices')->where('id', $id)->value('merchant_id') : DB::table('transaction_receipts')->where('id', $id)->value('merchant_id'),
                'document_source' => $source, 'document_id' => $id, 'source_review_revision' => $result['source_review_revision'], 'internal_schema' => $result['model']['schema'], 'encrypted_document' => Crypt::encryptString($result['canonical']),
                'document_hash' => $result['document_hash'], 'status' => $result['local_validation_passed'] ? 'blocked_pending_api' : 'needs_source_review', 'blockers' => json_encode($result['blockers']), 'prepared_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            if (Schema::hasTable('eis_delivery_jobs')) {
                app(EisDelivery::class)->enqueue($outboxId);
            }
            app(BirCompliance::class)->audit('eis.document.prepared', $outboxId, 'system', ['source' => $source, 'document_id' => $id, 'document_hash' => $result['document_hash'], 'can_transmit' => false]);

            return ['id' => $outboxId, 'duplicate' => false, ...array_diff_key($result, ['canonical' => true, 'model' => true])];
        });
    }
}
