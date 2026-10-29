<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApiAccess;
use App\Services\BirCompliance;
use App\Services\EisDocumentReadiness;
use App\Services\InvoiceCalculator;
use App\Services\InvoiceSequence;
use App\Services\MerchantWallet;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DocumentGovernanceController extends Controller
{
    private function document(Request $r, string $source, string $id): array
    {
        $u = app(ApiAccess::class)->require($r, ['merchant', 'admin', 'super_admin', 'bir']);
        abort_unless(in_array($source, ['invoice', 'receipt'], true), 404);
        $row = DB::table($source === 'invoice' ? 'business_invoices' : 'transaction_receipts')->where('id', $id)->first();
        abort_unless($row, 404);
        if ($r->attributes->get('api_role') === 'merchant') {
            abort_unless(app(ApiAccess::class)->merchants($u)->where('id', $row->merchant_id)->exists(), 404);
        }

        return [$u, $row];
    }

    public function requestReview(Request $r, string $source, string $id)
    {
        [$u,$row] = $this->document($r, $source, $id);
        $v = $r->validate(['request_id' => 'required|uuid', 'kind' => 'required|in:cancellation,correction', 'reason' => 'required|string|min:10|max:2000']);

        return DB::transaction(function () use ($u, $row, $source, $id, $v) {
            DB::table('merchants')->where('id', $row->merchant_id)->lockForUpdate()->first();
            $old = DB::table('document_review_requests')->where('request_id', $v['request_id'])->first();
            if ($old) {
                abort_unless($old->document_id === $id && $old->document_source === $source && $old->reason === $v['reason'] && $old->kind === $v['kind'] && $old->requested_by === (string) $u->id, 409, 'Request ID was reused.');

                return response()->json(['id' => $old->id, 'status' => $old->status, 'duplicate' => true]);
            }
            $review = (string) Str::uuid();
            DB::table('document_review_requests')->insert(['id' => $review, 'merchant_id' => $row->merchant_id, 'document_source' => $source, 'document_id' => $id, 'kind' => $v['kind'], 'status' => 'pending', 'reason' => $v['reason'], 'requested_by' => (string) $u->id, 'request_id' => $v['request_id'], 'created_at' => now(), 'updated_at' => now()]);
            app(BirCompliance::class)->audit('document.'.$v['kind'].'.requested', $review, (string) $u->id, ['source' => $source, 'document_id' => $id, 'reason' => $v['reason']]);

            return response()->json(['id' => $review, 'status' => 'pending', 'message' => 'Review request saved. Original document remains preserved; no legal cancellation or BIR acknowledgement has occurred.'], 202);
        });
    }

    public function decide(Request $r, string $id)
    {
        $u = app(ApiAccess::class)->require($r, ['admin', 'super_admin', 'bir']);
        $v = $r->validate(['revision' => 'required|integer|min:1', 'status' => 'required|in:resolved,rejected', 'evidence_reference' => 'required|string|min:10|max:2000', 'resolution_document_id' => 'required_if:status,resolved|nullable|string|max:80']);
        DB::transaction(function () use ($u, $v, $id) {
            $case = DB::table('document_review_requests')->where('id', $id)->lockForUpdate()->first();
            abort_unless($case, 404);
            abort_unless($case->status === 'pending' && $case->revision === $v['revision'], 409, 'Review changed. Refresh before recording a decision.');
            abort_if($case->requested_by === (string) $u->id, 403, 'An independent reviewer must record the decision.');
            if ($v['status'] === 'resolved') {
                $adjustment = DB::table('business_invoices')->where('id', $v['resolution_document_id'])->where('merchant_id', $case->merchant_id)->where('original_invoice_id', $case->document_id)->whereIn('document_type', ['credit_memo', 'debit_memo'])->where('status', 'issued')->first();
                abort_unless($adjustment, 409, 'Resolution requires an issued corrective document linked to this exact original. Originals are never silently cancelled.');
            }
            DB::table('document_review_requests')->where('id', $id)->update(['status' => $v['status'], 'evidence_reference' => $v['evidence_reference'], 'reviewed_by' => (string) $u->id, 'reviewed_at' => now(), 'revision' => $case->revision + 1, 'updated_at' => now()]);
            DB::table('invoice_lifecycle_events')->insert(['id' => (string) Str::uuid(), 'document_source' => $case->document_source, 'document_id' => $case->document_id, 'merchant_id' => $case->merchant_id, 'event' => 'review.'.$v['status'], 'actor' => (string) $u->id, 'reason' => $v['evidence_reference'], 'details' => json_encode(['request_id' => $id, 'resolution_document_id' => $v['resolution_document_id'] ?? null]), 'occurred_at' => now()]);
            app(BirCompliance::class)->audit('document.review.'.$v['status'], $id, (string) $u->id, $v);
        });

        return response()->json(['ok' => true, 'status' => $v['status'], 'original_preserved' => true]);
    }

    public function publish(Request $r, string $id)
    {
        app(ApiAccess::class)->require($r, ['merchant', 'admin', 'super_admin']);
        [$u,$row] = $this->document($r, 'invoice', $id);
        $v = $r->validate(['branch_code' => 'required|regex:/^[0-9]{5}$/']);

        return DB::transaction(function () use ($u, $row, $v) {
            DB::table('merchants')->where('id', $row->merchant_id)->lockForUpdate()->first();
            $draft = DB::table('business_invoices')->where('id', $row->id)->lockForUpdate()->first();
            abort_if($draft->issue_date && Carbon::parse($draft->issue_date)->isAfter(today()), 422, 'An issued document cannot have a future business date.');
            abort_unless($draft->status === 'draft', 409, 'Only a draft can be published. Existing originals are immutable.');
            $row = $draft;
            DB::table('merchants')->where('id', $row->merchant_id)->lockForUpdate()->first();
            $merchant = DB::table('merchants')->where('id', $row->merchant_id)->first();
            $calc = app(InvoiceCalculator::class);
            $items = DB::table('business_invoice_items')->where('invoice_id', $row->id)->orderBy('line_number')->get();
            $computed = $calc->calculate($items->map(fn ($i) => ['description' => $i->description, 'quantity' => $i->quantity, 'unit_price' => $calc->cents($i->unit_price, 'unit_price'), 'tax_type' => $i->tax_type, 'vat_rate' => $i->vat_rate, 'discount_pct' => $i->discount_pct, 'line_total' => $calc->cents($i->line_total, 'line_total')])->all(), (bool) $merchant->vat_registered, 0, 0, $row->prices_include_vat ?? true);
            foreach (['total_amount', 'vat_amount', 'taxable_amount', 'zero_rated_amount', 'vat_exempt_amount'] as $key) {
                abort_unless($computed[$key] === $calc->cents($row->$key, $key), 409, 'Draft totals do not reconcile. Review the source.');
            }
            $family = in_array($row->document_type, ['credit_memo', 'debit_memo'], true) ? $row->document_type : (in_array($row->document_type, ['official_receipt', 'payment_receipt'], true) ? 'payment' : 'invoice');
            if (in_array($family, ['credit_memo', 'debit_memo'], true)) {
                abort_unless($row->original_invoice_id && $row->correction_reason, 409, 'Adjustment must refer to the original invoice and reason.');
            }
            app(MerchantWallet::class)->check($row->merchant_id, (int) $computed['total_amount'], $row->document_type);
            $authority = app(BirCompliance::class)->assertCanIssue($row->merchant_id, $v['branch_code']);
            $series = app(InvoiceSequence::class)->reserve($row->merchant_id, $v['branch_code'], $family);
            app(MerchantWallet::class)->charge($row->merchant_id, (int) $computed['total_amount'], $row->document_type, 'invoice', $row->id, $series['number'], $v['branch_code'], 'portal', (string) $u->id);
            DB::table('business_invoices')->where('id', $row->id)->update($authority + ['invoice_number' => $series['number'], 'seller_branch_code' => $v['branch_code'], 'status' => 'issued', 'updated_at' => now()]);
            DB::table('invoice_lifecycle_events')->insert(['id' => (string) Str::uuid(), 'document_source' => 'invoice', 'document_id' => $row->id, 'merchant_id' => $row->merchant_id, 'event' => 'issued', 'actor' => (string) $u->id, 'reason' => 'Published from validated draft with recorded authority', 'details' => json_encode(['series_id' => $series['series_id'], 'original_issue_date' => $row->issue_date]), 'occurred_at' => now()]);
            $outbox = app(EisDocumentReadiness::class)->prepare('invoice', $row->id);
            app(BirCompliance::class)->audit('invoice.draft.published', $row->id, (string) $u->id, ['number' => $series['number'], 'series_id' => $series['series_id']]);

            return response()->json(['id' => $row->id, 'number' => $series['number'], 'outbox_id' => $outbox['id'], 'bir_acceptance' => 'not_verified']);
        });
    }

    public function hold(Request $r, string $source, string $id)
    {
        [$u,$row] = $this->document($r, $source, $id);
        app(ApiAccess::class)->require($r, ['admin', 'super_admin', 'bir']);
        $v = $r->validate(['authority_reference' => 'required|string|min:10|max:2000', 'reason' => 'required|string|min:10|max:2000']);
        $hold = (string) Str::uuid();
        DB::transaction(function () use ($hold, $row, $source, $id, $v, $u) {
            DB::table('document_legal_holds')->insert(['id' => $hold, 'merchant_id' => $row->merchant_id, 'source' => $source, 'document_id' => $id, ...$v, 'created_by' => (string) $u->id, 'created_at' => now(), 'updated_at' => now()]);
            app(BirCompliance::class)->audit('document.hold.recorded', $hold, (string) $u->id, $v);
        });

        return response()->json(['id' => $hold], 201);
    }

    public function releaseHold(Request $r, string $id)
    {
        $u = app(ApiAccess::class)->require($r, ['bir']);
        $v = $r->validate(['release_reference' => 'required|string|min:10|max:2000']);
        DB::transaction(function () use ($id, $u, $v) {
            $old = DB::table('document_legal_holds')->where('id', $id)->lockForUpdate()->first();
            abort_unless($old && ! $old->released_at, 409);
            abort_if($old->created_by === (string) $u->id, 403, 'Independent reviewer must record the written release.');
            DB::table('document_legal_holds')->where('id', $id)->update($v + ['released_by' => (string) $u->id, 'released_at' => now(), 'updated_at' => now()]);
            app(BirCompliance::class)->audit('document.hold.released', $id, (string) $u->id, $v);
        });

        return response()->json(['ok' => true, 'message' => 'Hold released; originals remain retained. No purge is implemented.']);
    }

    public function identityClaim(Request $r)
    {
        $u = app(ApiAccess::class)->require($r, ['client', 'consumer']);
        $v = $r->validate(['tin' => 'required|regex:/^[0-9]{9}$/|not_regex:/^0+$/', 'evidence_reference' => 'required|string|min:10|max:2000']);
        $id = (string) Str::uuid();
        DB::table('taxpayer_identity_reviews')->insert(['id' => $id, 'user_id' => (string) $u->id, ...$v, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        app(BirCompliance::class)->audit('identity.claim.requested', $id, (string) $u->id, ['tin_hash' => hash('sha256', $v['tin'])]);

        return response()->json(['id' => $id, 'status' => 'pending'], 202);
    }

    public function identityReview(Request $r, string $id)
    {
        $u = app(ApiAccess::class)->require($r, ['admin', 'super_admin', 'bir']);
        $v = $r->validate(['status' => 'required|in:approved,rejected', 'reason' => 'required|string|min:10|max:2000', 'evidence_reference' => 'required|string|min:10|max:2000']);
        DB::transaction(function () use ($u, $v, $id) {
            DB::table('eis_audit_head')->where('id', 1)->lockForUpdate()->first();
            $claim = DB::table('taxpayer_identity_reviews')->where('id', $id)->lockForUpdate()->first();
            abort_unless($claim && $claim->status === 'pending', 409);
            abort_if($claim->user_id === (string) $u->id, 403);
            if ($v['status'] === 'approved') {
                abort_if(DB::table('taxpayer_identity_reviews')->where('tin', $claim->tin)->where('status', 'approved')->where('user_id', '!=', $claim->user_id)->exists(), 409, 'TIN already linked to another verified identity.');
            }DB::table('taxpayer_identity_reviews')->where('id', $id)->update(['status' => $v['status'], 'review_reason' => $v['reason'], 'evidence_reference' => $v['evidence_reference'], 'reviewed_by' => (string) $u->id, 'reviewed_at' => now(), 'updated_at' => now()]);
            app(BirCompliance::class)->audit('identity.claim.reviewed', $id, (string) $u->id, $v);
        });

        return response()->json(['ok' => true, 'message' => 'Identity evidence review recorded; account activation is a separate action.']);
    }

    public function series(Request $r)
    {
        $u = app(ApiAccess::class)->require($r, ['bir']);
        $v = $r->validate(['merchant_id' => 'required|exists:merchants,id', 'branch_code' => 'required|regex:/^[0-9]{5}$/', 'document_family' => 'required|in:invoice,payment,credit_memo,debit_memo', 'prefix' => 'required|string|max:40|regex:/^[A-Z0-9-]*$/', 'range_start' => 'required|integer|min:1|max:99999999', 'range_end' => 'required|integer|gte:range_start|max:99999999', 'authority_reference' => 'required|string|min:10|max:2000', 'valid_from' => 'required|date|before_or_equal:now', 'valid_until' => 'required|date|after:valid_from']);
        $id = (string) Str::uuid();
        DB::transaction(function () use ($id, $v, $u) {
            app(BirCompliance::class)->assertCanIssue($v['merchant_id'], $v['branch_code']);
            DB::table('invoice_series')->insert(['id' => $id, ...$v, 'status' => 'active', 'verified_by' => (string) $u->id, 'created_at' => now(), 'updated_at' => now()]);
            app(BirCompliance::class)->audit('invoice.series.recorded', $id, (string) $u->id, $v);
        });

        return response()->json(['id' => $id], 201);
    }
}
