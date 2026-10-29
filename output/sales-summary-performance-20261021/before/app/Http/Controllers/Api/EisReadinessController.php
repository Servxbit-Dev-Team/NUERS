<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BirCompliance;
use App\Services\EisDocumentReadiness;
use App\Services\HistoricalBranchEvidence;
use App\Services\IssuedDocumentLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EisReadinessController extends Controller
{
    private function actor(Request $r): object
    {
        $u = $r->bearerToken() ? app(\App\Services\ApiAccess::class)->user($r) : null;
        abort_unless($u, 401);
        $u->role = DB::table('profiles')->where('id', $u->id)->value('role');
        abort_unless(in_array($u->role, ['admin', 'super_admin', 'bir', 'merchant'], true), 403);

        return $u;
    }

    private function scoped(object $u)
    {
        $q = app(IssuedDocumentLedger::class)->candidates();
        if ($u->role === 'merchant') {
            $q->whereIn('merchant_id', DB::table('merchants')->where(fn ($q) => $q->where('merchant_account_id', $u->id))->select('id'));
        }

        return $q;
    }

    public function index(Request $r)
    {
        $u = $this->actor($r);
        $v = $r->validate(['page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);
        $p = $this->scoped($u)->whereIn('document_source', ['receipt', 'invoice'])->orderByDesc('created_at')->orderBy('id')->paginate($v['per_page'] ?? 10);
        $rows = collect($p->items())->map(function ($row) {
            $result = app(EisDocumentReadiness::class)->inspect($row->document_source, $row->document_id);
            $outbox = DB::table('eis_document_outbox')->where('document_source', $row->document_source)->where('document_id', $row->document_id)->where('internal_schema', EisDocumentReadiness::INTERNAL_SCHEMA)->orderByDesc('source_review_revision')->first(['id', 'status', 'document_hash', 'prepared_at']);

            return ['source' => $row->document_source, 'id' => $row->document_id, 'number' => $row->receipt_number, 'business' => $row->business, 'tin' => $row->tin, ...array_diff_key($result, ['canonical' => true, 'model' => true]), 'outbox' => $outbox];
        });

        return response()->json(['data' => $rows, 'total' => $p->total(), 'page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'can_prepare' => $u->role !== 'bir', 'can_review_branch' => in_array($u->role, ['admin', 'super_admin'], true), 'can_transmit' => false, 'notice' => 'Local validation and secure preparation only. Official BIR API access, interface verification, taxpayer coverage and approvals remain required.']);
    }

    public function prepare(Request $r, string $source, string $id)
    {
        $u = $this->actor($r);
        abort_if($u->role === 'bir', 403);
        abort_unless($this->scoped($u)->where('document_source', $source)->where('document_id', $id)->exists(), 404);

        $prepared = app(EisDocumentReadiness::class)->prepare($source, $id);
        app(BirCompliance::class)->audit('eis.document.preparation.requested', $prepared['id'], (string) $u->id, ['source' => $source, 'document_id' => $id]);

        return response()->json($prepared);
    }

    public function branchReview(Request $r, string $source, string $id)
    {
        $u = $this->actor($r);
        abort_unless(in_array($u->role, ['admin', 'super_admin'], true), 403);
        abort_unless($this->scoped($u)->where('document_source', $source)->where('document_id', $id)->exists(), 404);
        $v = $r->validate(['branch_code' => 'required|regex:/^\d{5}$/', 'evidence_reference' => 'required|string|min:10|max:2000', 'revision' => 'required|integer|min:0']);

        return response()->json(app(HistoricalBranchEvidence::class)->record($source, $id, $v['branch_code'], $v['evidence_reference'], $v['revision'], (string) $u->id));
    }

    public function coverage(Request $r)
    {
        $u = $this->actor($r);
        abort_unless(in_array($u->role, ['admin', 'super_admin'], true), 403);
        $v = $r->validate(['merchant_id' => 'required|string|max:80', 'branch_code' => 'nullable|regex:/^\d{5}$/',
            'coverage_status' => ['required', Rule::in(['unknown', 'required', 'not_required'])],
            'effective_from' => 'required_unless:coverage_status,unknown|nullable|date_format:Y-m-d',
            'effective_to' => ['nullable', 'date_format:Y-m-d', ...($r->filled('effective_from') ? ['after_or_equal:effective_from'] : [])],
            'evidence_reference' => 'required|string|min:10|max:2000', 'revision' => 'required|integer|min:0']);
        abort_unless(DB::table('merchants')->where('id', $v['merchant_id'])->exists(), 404);

        return DB::transaction(function () use ($v, $u) {
            DB::table('merchants')->where('id', $v['merchant_id'])->lockForUpdate()->first();
            $branch = $v['branch_code'] ?? '';
            $old = DB::table('eis_reporting_obligations')->where('merchant_id', $v['merchant_id'])->where('branch_code', $branch)->first();
            abort_unless(($old?->revision ?? 0) === $v['revision'], 409, 'Coverage evidence changed. Refresh before saving.');
            $data = [...$v, 'branch_code' => $branch, 'verified_by' => (string) $u->id, 'verified_at' => now(), 'revision' => $v['revision'] + 1, 'updated_at' => now()];
            if ($old) {
                DB::table('eis_reporting_obligations')->where('id', $old->id)->update($data);
            } else {
                DB::table('eis_reporting_obligations')->insert([...$data, 'created_at' => now()]);
            }
            app(BirCompliance::class)->audit('eis.coverage.recorded', $v['merchant_id'], (string) $u->id, ['before' => $old, 'after' => $data]);

            return response()->json(['revision' => $data['revision'], 'coverage_status' => $data['coverage_status'], 'notice' => 'Recorded administrative evidence; this does not grant BIR authority or enable transmission.']);
        });
    }
}
