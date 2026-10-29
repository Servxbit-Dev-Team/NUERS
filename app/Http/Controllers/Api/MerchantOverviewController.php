<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApiAccess;
use App\Services\EisDocumentReadiness;
use App\Services\IssuedDocumentLedger;
use App\Services\MerchantWallet;
use App\Services\SubmissionEvidence;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Read-only merchant projection. Local issuance, payments and BIR proof stay distinct. */
class MerchantOverviewController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $merchant = app(ApiAccess::class)->merchant($request);
        $v = $request->validate(['days' => 'sometimes|integer|in:7,30']);
        $days = (int) ($v['days'] ?? 7);
        $now = CarbonImmutable::now('Asia/Manila');
        $today = $now->startOfDay();
        $start = $today->subDays($days - 1);
        $zone = config('app.timezone', 'UTC');
        $series = [];
        for ($date = $start; $date->lte($today); $date = $date->addDay()) {
            $series[$date->toDateString()] = ['date' => $date->toDateString(), 'label' => $date->format('M j'), 'sales' => 0, 'documents' => 0];
        }
        $daily = [$today->toDateString() => ['sales' => 0, 'invoices' => 0], $today->subDay()->toDateString() => ['sales' => 0, 'invoices' => 0]];
        $documents = ['issued' => 0, 'for_review' => 0, 'cancelled' => 0, 'pending' => 0];
        $bir = ['acknowledged' => 0, 'queued' => 0, 'needs_attention' => 0, 'not_verified' => 0];
        $indexed = app(\App\Services\IndexedSalesReport::class)->available();
        $jobs = collect(); $verified = [];
        if ($indexed) {
            $groups = app(\App\Services\IndexedMerchantOverview::class)->aggregate((string) $merchant->id, $start->toDateString(), $today->toDateString());
            foreach ($groups as $key => $row) {
                $series[$key]['sales'] = (int) $row->sales;
                $series[$key]['documents'] = (int) $row->documents;
                if (isset($daily[$key])) $daily[$key] = ['sales' => (int) $row->sales, 'invoices' => (int) $row->invoices];
                foreach (array_keys($documents) as $state) $documents[$state] += (int) $row->$state;
                $bir['queued'] += (int) $row->queued;
                $bir['needs_attention'] += (int) $row->needs_attention;
                $bir['not_verified'] += (int) $row->documents - (int) $row->queued - (int) $row->needs_attention;
            }
        } else {
        $jobs = DB::table('eis_document_outbox as o')->leftJoin('eis_delivery_jobs as j', 'j.outbox_id', '=', 'o.id')
            ->where('o.merchant_id', $merchant->id)->orderByDesc('o.prepared_at')->orderByDesc('o.source_review_revision')->get(['o.document_source', 'o.document_id', 'j.state'])
            ->unique(fn ($j) => $j->document_source.':'.$j->document_id)->keyBy(fn ($j) => $j->document_source.':'.$j->document_id);
        $verified = [];
        if (config('bir_compliance.production_response_verification_implemented')) {
            $rows = DB::table('eis_transmissions as t')->join('eis_invoice_payloads as p', 'p.id', '=', 't.payload_id')
                ->where('t.merchant_id', $merchant->id)->where('p.merchant_id', $merchant->id)->where('t.environment', 'production')
                ->get(['t.id', 'p.source_document_type', 'p.source_document_id']);
            foreach ($rows as $row) {
                if (app(SubmissionEvidence::class)->verified($row->id)) {
                    $verified[$row->source_document_type.':'.$row->source_document_id] = true;
                }
            }
        }
        $query = app(IssuedDocumentLedger::class)->query((string) $merchant->id)
            ->where('business_date', '>=', $start->subDay()->toDateString())->where('business_date', '<', $today->addDays(2)->toDateString());
        foreach ($query->orderBy('id')->lazy(500)->chunk(500) as $batch) {
            $financials = app(EisDocumentReadiness::class)->financialBatch($batch, (string) $merchant->id);
            foreach ($batch as $row) {
                $key = $this->businessDay($row, $zone);
                if (! isset($series[$key])) {
                    continue;
                }
                $review = $financials[$row->document_source.':'.$row->document_id] ?? null;
                $sound = $row->identity_count === 1 || (int) $row->identity_count === 1;
                $sound = $sound && ($review['financial_validation_passed'] ?? false);
                $state = $row->status === 'cancelled' ? 'cancelled' : (! $sound || $row->status === 'failed' ? 'for_review' : ($row->status === 'success' ? 'issued' : 'pending'));
                $documents[$state]++;
                $series[$key]['documents']++;
                if ($row->document_family === 'invoice' && $row->status === 'success' && isset($daily[$key])) {
                    $daily[$key]['invoices']++;
                }
                $linkedAdjustment = ! in_array($row->document_family, ['credit', 'debit'], true) || ($row->original_invoice_id && DB::table('business_invoices')->where('id', $row->original_invoice_id)->where('merchant_id', $merchant->id)->whereNotIn('status', ['draft', 'cancelled', 'voided'])->whereNotIn('document_type', ['credit_memo', 'debit_memo', 'credit_note', 'debit_note', 'payment_receipt', 'official_receipt', 'purchase_invoice', 'delivery_receipt'])->exists());
                if ($sound && $row->status === 'success' && in_array($row->document_family, ['invoice', 'credit', 'debit'], true) && $linkedAdjustment) {
                    $amount = (int) round((float) $row->amount * 100) * ($row->document_family === 'credit' ? -1 : 1);
                    $series[$key]['sales'] += $amount;
                    if (isset($daily[$key])) {
                        $daily[$key]['sales'] += $amount;
                    }
                }
                $bir[$this->birState($row, $jobs, $verified)]++;
            }
        }
        }
        $paymentDaily = [];
        foreach ([$today, $today->subDay()] as $date) {
            $q = DB::table('merchant_transactions')->where('merchant_id', $merchant->id)
                ->where('created_at', '>=', $date->setTimezone($zone)->format('Y-m-d H:i:s'))
                ->where('created_at', '<', $date->addDay()->setTimezone($zone)->format('Y-m-d H:i:s'));
            $paymentDaily[$date->toDateString()] = ['transactions' => (clone $q)->count(), 'attention' => (clone $q)->whereNotIn('status', ['completed', 'paid', 'settled', 'verified', 'success', 'valid'])->count()];
        }
        $current = $daily[$today->toDateString()] + $paymentDaily[$today->toDateString()];
        $previous = $daily[$today->subDay()->toDateString()] + $paymentDaily[$today->subDay()->toDateString()];
        // Expose PHP amounts consistently; sums above use integer centavos.
        $current['sales'] /= 100;
        $previous['sales'] /= 100;
        $recent = app(IssuedDocumentLedger::class)->query((string) $merchant->id)->whereIn('document_source', ['invoice', 'receipt'])->orderByDesc('recorded_at')->limit(8)->get();
        if ($indexed && $recent->isNotEmpty()) {
            $jobs = DB::table('eis_document_outbox as o')->leftJoin('eis_delivery_jobs as j', 'j.outbox_id', '=', 'o.id')->where('o.merchant_id', $merchant->id)->whereIn('o.document_id', $recent->pluck('document_id'))->orderByDesc('o.source_review_revision')->orderByDesc('o.prepared_at')->get(['o.document_source', 'o.document_id', 'j.state'])->unique(fn ($j) => $j->document_source.':'.$j->document_id)->keyBy(fn ($j) => $j->document_source.':'.$j->document_id);
        }
        $recent = $recent->map(function ($row) use ($jobs, $verified, $zone) {
            return ['id' => $row->id, 'document_id' => $row->document_id, 'source' => $row->document_source, 'number' => $row->receipt_number ?: $row->transaction_ref, 'customer' => $row->customer_name ?: 'Unspecified customer', 'date' => $this->businessDay($row, $zone), 'amount' => (float) $row->amount, 'status' => $row->record_status, 'bir_status' => $this->birState($row, $jobs, $verified)];
        });
        $drafts = DB::table('business_invoices')->where('merchant_id', $merchant->id)->where('status', 'draft')->count();
        $alerts = array_values(array_filter([
            ! config('bir_compliance.transport_implemented') ? ['id' => 'bir', 'title' => 'BIR connection pending', 'message' => 'Official API access and a verified adapter are required. Local issuance is not BIR acknowledgment.', 'href' => '/merchant?panel=bir'] : null,
            $documents['for_review'] ? ['id' => 'review', 'title' => 'Source review required', 'message' => $documents['for_review'].' document(s) in this period need original financial or identity evidence.', 'href' => '/merchant/invoices'] : null,
            $drafts ? ['id' => 'drafts', 'title' => 'Draft invoices', 'message' => $drafts.' draft(s) remain unissued.', 'href' => '/merchant/invoices'] : null,
        ]));

        $wallet = app(MerchantWallet::class)->state($merchant->id);
        return response()->json([
            'wallet' => ['available_centavos' => (int) $wallet->available_centavos, 'currency' => 'PHP'],
            'account' => ['id' => $merchant->id, 'name' => $merchant->business_name, 'tin' => $merchant->tin, 'logo' => $merchant->logo_url ?? null, 'rdo' => $merchant->rdo_code ?? null, 'rdo_name' => $merchant->rdo_name ?? null, 'vat_registered' => (bool) $merchant->vat_registered, 'status' => $merchant->status, 'plan' => $merchant->subscription_tier ?? $merchant->plan ?? null],
            'today' => $today->toDateString(), 'days' => $days, 'current' => $current, 'previous' => $previous,
            'sales' => array_values(array_map(fn ($r) => [...$r, 'sales' => $r['sales'] / 100], $series)),
            'documents' => $documents, 'bir' => $bir, 'recent' => $recent, 'draft_count' => $drafts, 'alerts' => $alerts,
            'connection' => ['application' => 'observed', 'bir' => 'not_verified'], 'updated_at' => $now->toIso8601String(),
            'notice' => 'Internal operational view. Sales use source-reconciled canonical invoices and linked adjustments once; payments are separate. BIR acknowledgment requires verified production response evidence. No accreditation or filing approval is implied.',
        ])->header('Cache-Control', 'private, no-store');
    }

    private function businessDay(object $row, string $zone): string
    {
        return $row->document_source === 'invoice' ? substr($row->business_date ?? $row->created_at, 0, 10) : CarbonImmutable::parse($row->business_date ?? $row->created_at, $zone)->setTimezone('Asia/Manila')->toDateString();
    }

    private function birState(object $row, $jobs, array $verified): string
    {
        $key = $row->document_source.':'.$row->document_id;
        if (isset($verified[$key])) {
            return 'acknowledged';
        }
        $state = $jobs->get($key)?->state;
        if (in_array($state, ['queued', 'retry', 'leased', 'sending', 'inquiry'], true)) {
            return 'queued';
        }
        if (in_array($state, ['dead_letter', 'rejected', 'uncertain'], true)) {
            return 'needs_attention';
        }

        return 'not_verified';
    }
}
