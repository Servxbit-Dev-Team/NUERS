<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\{ApiAccess,BirCompliance,EisDelivery,EisTransmissionCenter};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache,DB};
use Illuminate\Support\Str;

class EisTransmissionCenterController extends Controller
{
    private function actor(Request $r, bool $write = false): object
    {
        return app(ApiAccess::class)->require($r, $write ? ['admin','super_admin'] : ['admin','super_admin','bir']);
    }

    public function index(Request $r, EisTransmissionCenter $center)
    {
        $u = $this->actor($r);
        $f = $center->filters($r);
        $q = $center->query($f);
        $cacheFilters = array_diff_key($f, array_flip(['page', 'per_page', 'sort']));
        $cacheFilters['operations_revision'] = app(\App\Services\DashboardRevision::class)->token('eis-center');
        $summary = app(\App\Services\ReportingSnapshotCache::class)->remember('eis-center-v3', 'authorized-admins', $cacheFilters, fn () => $center->query($f, true)->selectRaw("COUNT(*) as total, COALESCE(SUM(amount),0) as amount,
            SUM(CASE WHEN queue_status='unprepared' THEN 1 ELSE 0 END) as unprepared,
            SUM(CASE WHEN queue_status='needs_review' THEN 1 ELSE 0 END) as needs_review,
            SUM(CASE WHEN queue_status='prepared' THEN 1 ELSE 0 END) as prepared,
            SUM(CASE WHEN queue_status IN ('queued','sending','retry') THEN 1 ELSE 0 END) as queued,
            SUM(CASE WHEN queue_status='accepted' THEN 1 ELSE 0 END) as accepted,
            SUM(CASE WHEN queue_status IN ('rejected','uncertain','dead_letter') THEN 1 ELSE 0 END) as attention")->first());
        $size = (int)($f['per_page'] ?? 25);
        $page = (int)($f['page'] ?? 1);
        $total = (int)$summary->total;
        $sort = $f['sort'] ?? 'newest';
        $field = str_starts_with($sort, 'amount') ? 'amount' : (($f['date_basis'] ?? 'document') === 'received' ? 'recorded_at' : 'business_date');
        $selection = app(\App\Services\ReportPagination::class)->page($q, $total, $page, $size, $field, in_array($sort, ['oldest','amount_asc']) ? 'asc' : 'desc');
        $documents = $selection['ids']->isEmpty() ? collect() : (clone $q)->whereIn('id', $selection['ids'])->get()->keyBy('id');
        $rows = $selection['ids']->filter(fn ($id) => $documents->has($id))->map(fn ($id) => $center->row($documents->get($id)))->values();
        $gate = app(BirCompliance::class)->gate(isset($f['merchant']) ? app(BirCompliance::class)->integration($f['merchant'], null, 'production') : null);
        return response()->json(['data' => $rows,'total' => $total,'last_page' => $selection['last_page'],'page' => $selection['page'],'summary' => $summary,'generated_at' => now()->toIso8601String(),
            'can_manage' => in_array(DB::table('profiles')->where('id', $u->id)->value('role'), ['admin','super_admin']), 'batch_limit' => EisTransmissionCenter::LIMIT,
            'gateway' => ['can_send' => isset($f['merchant']) && $gate['can_transmit'] && app(EisDelivery::class)->ready(),'transport_ready' => app(EisDelivery::class)->ready(),'blockers' => $gate['blockers']],
            'companies' => DB::table('merchants')->orderBy('business_name')->limit(1000)->get(['id','business_name','tin','status']),
            'options' => ['documents' => ['sales_invoice','vat_invoice','non_vat_invoice','mixed_sales_invoice','zero_rated_invoice','credit_note','debit_note','credit_memo','debit_memo','official_receipt','payment_receipt','delivery_receipt']],
            'notice' => 'Live document preparation is local to NUERS. BIR acceptance requires an authenticated production response. Certification records are kept in separate transmission history.']);
    }

    public function detail(Request $r, string $source, string $id, EisTransmissionCenter $center)
    {
        $this->actor($r);
        $row = $center->query([])->where('document_source', $source)->where('document_id', $id)->first();
        abort_unless($row, 404);
        $inspection = $center->inspect($row->merchant_id, $source, $id);
        $attempts = $row->job_id ? DB::table('eis_delivery_attempts')->where('job_id', $row->job_id)->orderByDesc('started_at')->limit(30)->get(['id','operation','outcome','authenticated','provider_reference','started_at','finished_at','response_hash']) : [];
        return response()->json(['document' => $center->row($row),'validation' => $inspection,'attempts' => $attempts]);
    }

    public function preview(Request $r, EisTransmissionCenter $center)
    {
        $u = $this->actor($r, true);
        $v = $r->validate(['merchant' => 'required|string|max:80','scope' => 'required|in:selected,filtered','documents' => 'exclude_unless:scope,selected|required|array|min:1|max:100','documents.*' => 'string|max:110|distinct']);
        abort_unless(DB::table('merchants')->where('id', $v['merchant'])->exists(), 404);
        $f = $center->filters($r);
        $q = $center->query($f);
        if ($v['scope'] === 'selected') {
            $q->where(function ($q) use ($v) {
                foreach ($v['documents'] as $key) {
                    [$source,$id] = array_pad(explode(':', $key, 2), 2, '');
                    $q->orWhere(fn ($q) => $q->where('document_source', $source)->where('document_id', $id));
                }
            });
        }
        $rows = $center->ordered($q, $f)->limit(EisTransmissionCenter::LIMIT + 1)->get();
        abort_if($rows->count() > EisTransmissionCenter::LIMIT, 422, 'Narrow the company filters to 100 documents or fewer, or select rows on the current page.');
        abort_if($rows->isEmpty(), 422, 'No documents match this company and the applied filters.');
        if ($v['scope'] === 'selected') {
            abort_unless($rows->count() === count($v['documents']), 422, 'Some selected documents are outside the company or filters. Refresh the selection.');
        }
        $review = [];
        foreach ($rows as $row) {
            $inspection = $center->inspect($v['merchant'], $row->document_source, $row->document_id);
            $review[] = ['source' => $row->document_source,'id' => $row->document_id,'number' => $row->receipt_number,'amount' => (float)$row->amount,
                'hash' => $inspection['document_hash'],'valid' => $inspection['local_validation_passed'],'blockers' => $inspection['blockers'],'status' => $row->queue_status];
        }
        $id = (string)Str::uuid();
        $snapshot = ['id' => $id,'actor' => (string)$u->id,'merchant' => $v['merchant'],'filters' => $f,'documents' => $review];
        Cache::put('eis-center-preview:'.$id, $snapshot, now()->addMinutes(10));
        return response()->json(['preview_id' => $id,'expires_at' => now()->addMinutes(10)->toIso8601String(),'documents' => $review,'total' => $rows->count(),'amount' => $rows->sum('amount'),'valid' => collect($review)->where('valid', true)->count(),'can_send' => false]);
    }

    public function createBatch(Request $r)
    {
        $u = $this->actor($r, true);
        $v = $r->validate(['preview_id' => 'required|uuid','reason' => 'required|string|min:10|max:1000']);
        $existing = DB::table('eis_preparation_batches')->where('id', $v['preview_id'])->first();
        if ($existing) {
            abort_unless($existing->actor_id === (string)$u->id, 404);
            return response()->json(['id' => $existing->id,'duplicate' => true], 200);
        }
        $s = Cache::get('eis-center-preview:'.$v['preview_id']);
        abort_unless($s && $s['actor'] === (string)$u->id, 409, 'Review expired or belongs to a different session. Preview the documents again.');
        DB::transaction(function () use ($s, $v, $u) {
            $created = DB::table('eis_preparation_batches')->insertOrIgnore(['id' => $s['id'],'actor_id' => $u->id,'merchant_id' => $s['merchant'],'request_hash' => hash('sha256', json_encode($s['documents'])),'document_count' => count($s['documents']),'status' => 'pending','reason' => $v['reason'],'filters' => json_encode($s['filters']),'created_at' => now(),'updated_at' => now()]);
            if (!$created) {
                return;
            }
            foreach ($s['documents'] as $d) {
                DB::table('eis_preparation_batch_items')->insert(['id' => (string)Str::uuid(),'batch_id' => $s['id'],'merchant_id' => $s['merchant'],'document_source' => $d['source'],'document_id' => $d['id'],'document_number' => $d['number'],'expected_hash' => $d['hash'],'status' => 'pending','created_at' => now(),'updated_at' => now()]);
            }
            app(BirCompliance::class)->audit('eis.batch.preparation.requested', $s['id'], (string)$u->id, ['merchant_id' => $s['merchant'],'count' => count($s['documents']),'reason' => $v['reason'],'request_hash' => hash('sha256', json_encode($s['documents']))]);
        });
        return response()->json(['id' => $s['id'],'message' => 'Batch queued for local preparation. BIR delivery remains subject to verified connection and company authority.'], 202);
    }

    public function batches(Request $r, ?string $id = null)
    {
        $this->actor($r);
        $v = $r->validate(['merchant' => 'nullable|string|max:80','page' => 'nullable|integer|min:1|max:100000']);
        $q = DB::table('eis_preparation_batches as b')->join('merchants as m', 'm.id', '=', 'b.merchant_id');
        if ($id) {
            $b = (clone $q)->where('b.id', $id)->first(['b.*','m.business_name']);
            abort_unless($b, 404);
            return response()->json(['batch' => $b,'items' => DB::table('eis_preparation_batch_items')->where('batch_id', $id)->orderBy('document_number')->get()]);
        }
        if (!empty($v['merchant']) && $v['merchant'] !== 'all') {
            $q->where('b.merchant_id', $v['merchant']);
        }
        $p = $q->orderByDesc('b.created_at')->orderBy('b.id')->paginate(15, ['b.id','b.merchant_id','b.status','b.document_count','b.reason','b.created_at','b.completed_at','m.business_name']);
        return response()->json($p);
    }

    public function export(Request $r, EisTransmissionCenter $center)
    {
        $this->actor($r);
        $f = $center->filters($r);
        $q = $center->ordered($center->query($f), $f);
        if ($r->has('report_limit')) {
            \App\Services\ReportCsv::limit($r, (clone $q)->count());
        }
        abort_if((clone $q)->limit(10001)->get(['id'])->count() > 10000, 422, 'Narrow filters to 10,000 records or fewer for this operational CSV.');
        return response()->streamDownload(function () use ($q, $center) {
            $s = fopen('php://output', 'w');
            fwrite($s, "\xEF\xBB\xBF");
            $keys = ['business','tin','number','reference','source','document_type','sale_category','branch','rdo','region','business_date','received_at','amount','vat_amount','status','document_status','due_at','attempts','provider_reference','last_error','environment','outbox_id','document_hash'];
            fputcsv($s, $keys, ',', '"', '');
            foreach ($q->cursor() as $row) {
                $r = $center->row($row);
                fputcsv($s, array_map(fn ($key) => preg_replace('/^(\s*[=+@-])/', "'$1", (string)($r[$key] ?? '')), $keys), ',', '"', '');
            }
            fclose($s);
        }, 'NUERS-BIR-Transmission-Register-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8','Cache-Control' => 'no-store']);
    }

    public function release(Request $r, string $id, EisTransmissionCenter $center)
    {
        $u = $this->actor($r, true);
        $r->validate(['reason' => 'required|string|min:10|max:1000']);
        abort_unless(app(EisDelivery::class)->ready(), 409, 'The verified BIR adapter is not connected. Documents remain safely prepared in NUERS.');
        DB::transaction(function () use ($r, $id, $u, $center) {
            $b = DB::table('eis_preparation_batches')->where('id', $id)->lockForUpdate()->first();
            abort_unless($b, 404);
            abort_unless($b->status === 'completed', 409, 'Complete local preparation first.');
            $items = DB::table('eis_preparation_batch_items')->where('batch_id', $id)->get();
            foreach ($items as $item) {
                abort_unless($item->status === 'prepared' && $item->outbox_id, 409, 'Every batch document must pass local validation before release.');
                $inspection = $center->inspect($b->merchant_id, $item->document_source, $item->document_id);
                abort_unless($inspection['local_validation_passed'] && $inspection['submission_applicability'] === 'required' && hash_equals($item->expected_hash, $inspection['document_hash']), 409, 'Source review, coverage or snapshot changed. Revalidate this batch.');
                $o = DB::table('eis_document_outbox')->where('id', $item->outbox_id)->where('merchant_id', $b->merchant_id)->first();
                abort_unless($o, 409);
                $doc = json_decode(\Illuminate\Support\Facades\Crypt::decryptString($o->encrypted_document), true);
                abort_if(($doc['record_mode'] ?? null) === 'source_capture', 409, 'External issuer copy needs reviewed reporting coverage and duplicate-reporting reconciliation.');
                $gate = app(BirCompliance::class)->gate(app(BirCompliance::class)->integration($b->merchant_id, $doc['seller']['branch_code'] ?? null, 'production'));
                abort_unless($gate['can_transmit'], 409, implode(' ', $gate['blockers']));
                $job = DB::table('eis_delivery_jobs')->where('outbox_id', $o->id)->lockForUpdate()->first();
                abort_unless($job, 409);
                // Never reset terminal outcomes or replay an uncertain/in-flight request.
                if (in_array($job->state, ['accepted','queued','sending'], true)) {
                    continue;
                }
                abort_unless($job->state === 'blocked' && (int)$job->attempts === 0, 409, 'This delivery requires inquiry or reconciliation, not another send.');
                DB::table('eis_delivery_jobs')->where('id',$job->id)->update(['state' => 'queued','available_at' => now(),'last_error' => null,'updated_at' => now()]);
            }
            app(BirCompliance::class)->audit('eis.batch.released',$id,(string)$u->id,['count' => $items->count(),'reason' => $r->input('reason')]);
        });
        return response()->json(['message' => 'Batch released to the verified delivery worker. Acceptance is recorded only from authenticated BIR results.']);
    }
}
