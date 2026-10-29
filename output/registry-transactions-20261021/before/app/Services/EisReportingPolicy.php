<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EisReportingPolicy
{
    public function forDocument(object $document): array
    {
        $day = $document->business_date ? CarbonImmutable::parse($document->business_date, 'Asia/Manila') : null;
        $identity = app(SellerIdentity::class)->parse($document->tin, $document->branch_code);
        $coverage = null;
        if (Schema::hasTable('eis_reporting_obligations')) {
            $coverage = DB::table('eis_reporting_obligations')->where('merchant_id', $document->merchant_id)
                ->whereIn('branch_code', array_values(array_unique(['', $identity['branch_code'] ?? ''])))
                ->orderByRaw("CASE WHEN branch_code='' THEN 1 ELSE 0 END")->first();
        }
        $status = $coverage?->coverage_status ?? 'unknown';
        if (! $day) {
            $status = 'date_unverified';
        } elseif ($coverage && (($coverage->effective_from && $day->lt(CarbonImmutable::parse($coverage->effective_from, 'Asia/Manila')->startOfDay())) || ($coverage->effective_to && $day->gt(CarbonImmutable::parse($coverage->effective_to, 'Asia/Manila')->endOfDay())))) {
            $status = 'outside_effective_period';
        }
        if (in_array($document->document_family, ['receipt', 'supplementary'], true)) {
            $status = 'supplementary_document';
        }
        $target = $day ? app(BirCompliance::class)->deadline($day->toIso8601String()) : null;
        $branchReview = app(HistoricalBranchEvidence::class)->latest($document->document_source, $document->document_id ?? '');
        if ($branchReview && ! hash_equals($branchReview->source_fingerprint, app(HistoricalBranchEvidence::class)->fingerprint($document->document_source, $document->document_id))) {
            $status = 'source_evidence_unverified';
        }
        $required = $status === 'required';

        return ['submission_applicability' => $status, 'coverage_evidence_reference' => $coverage?->evidence_reference,
            'original_transmission_target' => $target?->toIso8601String(),
            'transmission_target' => $required ? $target?->toIso8601String() : null,
            'deadline_basis' => $day ? 'original_document_date' : 'missing_original_document_date',
            'overdue' => $required && $target && $target->isPast() && $document->status !== 'cancelled' && ($document->bir_status ?? '') !== 'acknowledged',
            'deadline_notice' => 'Operational target only; applicable BIR advisories and outage evidence require review.'];
    }
}
