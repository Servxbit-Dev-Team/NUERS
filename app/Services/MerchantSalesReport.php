<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** A bounded, tenant-owned sales projection. No issuance, filing or transmission side effects. */
class MerchantSalesReport
{
    public const LIMIT = 2000;

    public function generate(object $merchant, array $filters): array
    {
        $from = CarbonImmutable::parse($filters['from'], 'Asia/Manila')->startOfDay();
        $until = CarbonImmutable::parse($filters['to'], 'Asia/Manila')->addDay()->startOfDay();
        $q = app(IssuedDocumentLedger::class)->query((string) $merchant->id)->whereIn('document_source', ['invoice', 'receipt']);
        $q->where(function ($q) use ($from, $until) {
            $q->where(fn ($a) => $a->where('document_source', 'invoice')->where('business_date', '>=', $from->toDateString())->where('business_date', '<', $until->toDateString()))
                ->orWhere(fn ($a) => $a->where('document_source', 'receipt')->where('business_date', '>=', $from->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'))->where('business_date', '<', $until->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s')));
        });
        if (($filters['branch'] ?? 'all') !== 'all') {
            if ($filters['branch'] !== 'unrecorded') {
                $q->where('seller_branch_key', $filters['branch']);
            }
        }
        if (($filters['type'] ?? 'all') !== 'all') {
            $q->where('document_type', $filters['type']);
        }
        if (($filters['sale_category'] ?? 'all') !== 'all') {
            $q->where('sale_category', $filters['sale_category']);
        }
        if ($search = trim($filters['search'] ?? '')) {
            // Binding and literal wildcard escaping prevent both SQL injection and wildcard broadening.
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $q->where(fn ($a) => $a->whereRaw("receipt_number LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("customer_name LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("customer_tin LIKE ? ESCAPE '!'", [$pattern]));
        }
        $sources = $q->orderByDesc('business_date')->orderBy('document_source')->orderBy('document_id')->limit(self::LIMIT + 1)->get();
        abort_if($sources->count() > self::LIMIT, 422, 'This report exceeds 2,000 source documents. Narrow the dates, branch or document filters.');
        $financials = app(EisDocumentReadiness::class)->financialBatch($sources, (string) $merchant->id);
        $keys = $sources->pluck('document_id')->all();
        $jobs = DB::table('eis_document_outbox as o')->leftJoin('eis_delivery_jobs as j', fn ($j) => $j->on('j.outbox_id', '=', 'o.id')->on('j.merchant_id', '=', 'o.merchant_id'))
            ->where('o.merchant_id', $merchant->id)->whereIn('o.document_id', $keys)->orderByDesc('o.source_review_revision')->orderByDesc('o.prepared_at')->orderByDesc('o.id')
            ->get(['o.document_source', 'o.document_id', 'j.state'])->unique(fn ($r) => $r->document_source.':'.$r->document_id)->keyBy(fn ($r) => $r->document_source.':'.$r->document_id);
        $verified = $this->verified($merchant, $keys);
        // Customer classification is explicit registry metadata; source buyer names/TINs remain historical snapshots.
        $customers = DB::table('merchant_customer_documents as d')->join('merchant_customers as c', fn ($j) => $j->on('c.id', '=', 'd.customer_id')->on('c.merchant_id', '=', 'd.merchant_id'))
            ->where('d.merchant_id', $merchant->id)->whereIn('d.document_id', $keys)->get(['d.document_source', 'd.document_id', 'c.type'])->groupBy(fn ($r) => $r->document_source.':'.$r->document_id);
        $included = [];
        $excluded = [];
        $reportBranches = [];
        foreach ($sources as $source) {
            $key = $source->document_source.':'.$source->document_id;
            $inspection = $financials[$key] ?? null;
            $branch = $this->sourceBranch($source, $inspection);
            $reportBranches[$branch ?? ''] = true;
            if (($filters['branch'] ?? 'all') !== 'all' && ($filters['branch'] === 'unrecorded' ? $branch !== null : $branch !== $filters['branch'])) {
                continue;
            }
            $types = $customers->get($key, collect())->pluck('type')->unique();
            $customerType = $types->count() === 1 ? $types->first() : 'unclassified';
            if (($filters['customer_type'] ?? 'all') !== 'all' && $filters['customer_type'] !== $customerType) {
                continue;
            }
            $bir = $this->birState($key, $jobs, $verified);
            if (($filters['bir'] ?? 'all') !== 'all' && $filters['bir'] !== $bir) {
                continue;
            }
            $reason = null;
            if (! in_array($source->document_family, ['invoice', 'credit', 'debit'], true)) {
                $reason = 'Payment / supplementary document: no additional invoice sales';
            } elseif ($source->status !== 'success') {
                $reason = 'Not an active issued document ('.$source->record_status.')';
            } elseif ((int) $source->identity_count !== 1) {
                $reason = 'Duplicate document identity needs source review';
            } elseif (in_array($source->document_family, ['credit', 'debit'], true) && ! $this->linkedOriginal($source, $merchant)) {
                $reason = 'Adjustment requires a reconciled, active original invoice in this business';
            } elseif (! ($inspection['financial_validation_passed'] ?? false)) {
                $reason = 'Original line amounts / tax allocation do not reconcile';
            }
            $sign = $source->document_family === 'credit' ? -1 : 1;
            $row = ['manual_discount' => $reason ? 0 : $sign * $this->cents($source->manual_discount_amount), 'discount' => $reason ? 0 : $sign * $this->cents($source->discount_amount), 'sale_category' => $source->sale_category, 'statutory_discount' => $reason ? 0 : $sign * $this->cents($source->statutory_discount_amount), 'vat_adjustment' => $reason ? 0 : $sign * $this->cents($source->vat_relief_amount), 'id' => (string) $source->id, 'source' => $source->document_source, 'document_id' => $source->document_id, 'number' => $source->receipt_number, 'date' => $source->document_source === 'invoice' ? substr($source->business_date, 0, 10) : CarbonImmutable::parse($source->business_date, config('app.timezone'))->setTimezone('Asia/Manila')->toDateString(),
                'date_precision' => $source->document_source === 'invoice' ? 'day' : 'instant', 'customer' => $source->customer_name ?: 'Not recorded', 'tin' => $source->customer_tin, 'customer_type' => $customerType, 'branch' => $branch, 'type' => $source->document_type, 'family' => $source->document_family, 'original_invoice_id' => $source->original_invoice_id,
                'bir_status' => $bir, 'included' => $reason === null, 'exclusion_reason' => $reason, 'source_amount' => $this->cents($source->amount)];
            foreach (['gross' => 'amount', 'vatable' => 'vatable_sales', 'vat' => 'vat_amount', 'zero' => 'zero_rated_sales', 'exempt' => 'vat_exempt_sales', 'net' => 'net_amount'] as $key => $field) {
                $row[$key] = $reason ? 0 : $sign * $this->cents($source->$field);
            }
            $row['non_vat'] = $reason ? 0 : $sign * collect($inspection['items'])->where('tax_type', 'non_vat')->sum('line_total_centavos');
            // Legacy rows aggregate non-VAT into exempt; new API copies persist a separate SSPT column.
            $row['exempt'] -= $row['non_vat'] - ($reason ? 0 : $sign * $this->cents($source->sspt_sales ?? 0));
            // Do not allocate unclassified taxable bases or guess VAT rates to force totals.
            if (! $reason && $row['gross'] !== $row['net'] + $row['vat']) {
                $row['included'] = false;
                $row['exclusion_reason'] = 'Source tax bases and VAT do not equal the document total';
                foreach (['gross', 'vatable', 'vat', 'zero', 'exempt', 'non_vat', 'net', 'statutory_discount', 'vat_adjustment', 'manual_discount', 'discount'] as $key) {
                    $row[$key] = 0;
                }
            }
            if ($row['included']) {
                $included[] = $row;
            } else {
                $excluded[] = $row;
            }
        }
        $totals = array_fill_keys(['gross', 'vatable', 'vat', 'zero', 'exempt', 'non_vat', 'net', 'statutory_discount', 'vat_adjustment', 'manual_discount', 'discount'], 0);
        $birCounts = array_fill_keys(['acknowledged', 'queued', 'needs_review', 'blocked', 'not_verified'], 0);
        foreach ($included as $r) {
            foreach ($totals as $k => $_) {
                $totals[$k] += $r[$k];
            } $birCounts[$r['bir_status']]++;
        }
        $options = app(IssuedDocumentLedger::class)->query((string) $merchant->id)->whereIn('document_source', ['invoice', 'receipt']);
        $branches = (clone $options)->select('seller_branch_key')->distinct()->orderBy('seller_branch_key')->limit(201)->pluck('seller_branch_key')->all();
        $documentTypes = (clone $options)->select('document_type')->distinct()->orderBy('document_type')->limit(101)->pluck('document_type')->all();
        $result = ['merchant' => ['name' => $merchant->business_name, 'tin' => $merchant->tin], 'filters' => $filters, 'totals' => $totals, 'count' => count($included), 'excluded_count' => count($excluded), 'bir' => $birCounts,
            'included' => $included, 'excluded' => $excluded, 'options' => ['branches' => array_values(array_unique([...$branches, ...array_map('strval', array_keys($reportBranches))])), 'types' => $documentTypes], 'reporting_state' => 'internal_sales_report', 'currency' => 'PHP', 'amount_unit' => 'centavo', 'generated_at' => now()->toIso8601String(),
            'notice' => 'Internal sales report, not a filed tax return or prescribed BIR SLS format. Reconciled issued invoices and linked adjustments only; drafts, duplicate identities and payment/supplementary receipts do not add sales. Gross is the VAT-inclusive document total; net is the source sales base excluding VAT. Non-VAT sales are reported separately from VAT-exempt sales. BIR acknowledgment requires verified production evidence.'];
        $result['snapshot_hash'] = hash('sha256', json_encode([$merchant->id, $filters, $included, $excluded], JSON_THROW_ON_ERROR));

        return $result;
    }

    private function sourceBranch(object $source, ?array $snapshot): ?string
    {
        if (!$snapshot) {
            return null;
        }
        $branch = $snapshot['seller_branch_code'];
        if (! $branch) {
            $evidence = app(HistoricalBranchEvidence::class)->latest($source->document_source, $source->document_id);
            if ($evidence && hash_equals($evidence->source_fingerprint, app(HistoricalBranchEvidence::class)->fingerprint($source->document_source, $source->document_id))) {
                $branch = $evidence->branch_code;
            }
        }
        $identity = app(SellerIdentity::class)->parse($snapshot['merchant_tin'], $branch);

        return $identity['valid'] && preg_match('/^\d{5}$/', $identity['branch_code'] ?? '') ? $identity['branch_code'] : null;
    }

    private function cents(mixed $value): int
    {
        return (int) round((float) $value * 100);
    }

    private function linkedOriginal(object $source, object $merchant): bool
    {
        if (! $source->original_invoice_id) {
            return false;
        }
        $row = app(IssuedDocumentLedger::class)->query((string) $merchant->id)->where('document_source', 'invoice')->where('document_id', $source->original_invoice_id)->where('document_family', 'invoice')->where('status', 'success')->where('identity_count', 1)->first();

        return $row && app(EisDocumentReadiness::class)->inspect('invoice', $row->document_id)['financial_validation_passed'];
    }

    private function verified(object $merchant, array $ids): array
    {
        if (! config('bir_compliance.transport_implemented') || ! config('bir_compliance.official_interface_verified') || ! config('bir_compliance.production_response_verification_implemented')) {
            return [];
        }
        $rows = DB::table('eis_transmissions as t')->join('eis_invoice_payloads as p', 'p.id', '=', 't.payload_id')->where('t.merchant_id', $merchant->id)->where('p.merchant_id', $merchant->id)->where('t.environment', 'production')->whereIn('t.status', ['accepted', 'acknowledged', 'success'])
            ->where(fn ($q) => $q->whereIn('p.source_document_id', $ids)->orWhereIn('p.receipt_id', $ids))->get(['t.id', 'p.source_document_type', 'p.source_document_id', 'p.receipt_id']);
        $verified = [];
        foreach ($rows as $r) {
            if (app(SubmissionEvidence::class)->verified($r->id)) {
                $verified[($r->source_document_type ?: 'receipt').':'.($r->source_document_id ?: $r->receipt_id)] = true;
            }
        }

        return $verified;
    }

    private function birState(string $key, $jobs, array $verified): string
    {
        if (isset($verified[$key])) {
            return 'acknowledged';
        }

        return match ($jobs->get($key)?->state) {
            'queued', 'sending', 'leased', 'retry' => 'queued', 'uncertain', 'inquiry', 'rejected', 'dead_letter' => 'needs_review', 'blocked' => 'blocked', default => 'not_verified'
        };
    }
}
