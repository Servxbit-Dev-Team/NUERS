<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Company-scoped operational preparation. The official transport remains a separately verified adapter. */
class EisTransmissionCenter
{
    public const LIMIT = 100; // NUERS operator safety limit, not a claim about the BIR wire protocol.

    public function filters(Request $r): array
    {
        $v = $r->validate([
            'merchant' => 'nullable|string|max:80', 'search' => 'nullable|string|max:160',
            'from' => 'nullable|date_format:Y-m-d', 'to' => ['nullable', 'date_format:Y-m-d', ...($r->filled('from') ? ['after_or_equal:from'] : [])],
            'date_basis' => 'nullable|in:document,received', 'document' => 'nullable|string|max:80',
            'status' => ['nullable', Rule::in(['all','unprepared','needs_review','prepared','queued','sending','accepted','rejected','retry','uncertain','dead_letter'])],
            'branch' => 'nullable|string|max:80', 'rdo' => 'nullable|string|max:20', 'region' => 'nullable|string|max:120',
            'source' => 'nullable|in:all,invoice,receipt', 'sale_category' => 'nullable|string|max:40',
            'min' => 'nullable|numeric|min:0|max:999999999999', 'max' => ['nullable','numeric','min:0','max:999999999999', ...($r->filled('min') ? ['gte:min'] : [])],
            'deadline' => 'nullable|in:all,overdue,due_today,upcoming', 'sort' => 'nullable|in:newest,oldest,amount_desc,amount_asc',
            'page' => 'nullable|integer|min:1|max:100000', 'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        return array_filter($v, fn ($value) => $value !== null && $value !== '' && $value !== 'all');
    }

    public function query(array $f, bool $summary = false): Builder
    {
        $ledger = app(IssuedDocumentLedger::class)->query($f['merchant'] ?? null)->whereIn('document_source', ['receipt','invoice']);
        $hasOutbox = DB::table('eis_document_outbox')->when(isset($f['merchant']), fn ($q) => $q->where('merchant_id', $f['merchant']))->exists();
        if (!$hasOutbox) {
            if (app(LedgerProjection::class)->ready() && \Illuminate\Support\Facades\Schema::hasColumn('ledger_documents', 'is_draft')) {
                $ledger = DB::table('ledger_documents')->where('is_draft', false)->whereIn('document_source', ['receipt', 'invoice']);
                if (isset($f['merchant'])) {
                    $ledger->where('merchant_id', $f['merchant'])->where('tenant_identity_rank', 1)
                        ->select(array_values(array_diff(\Illuminate\Support\Facades\Schema::getColumnListing('ledger_documents'), ['identity_count','identity_rank'])))
                        ->selectRaw('tenant_identity_count as identity_count,tenant_identity_rank as identity_rank');
                } else {
                    $ledger->where('identity_rank', 1);
                }
            }
            if ($summary) {
                $columns = ['amount'];
                foreach (['document' => 'document_type','branch' => 'seller_branch_key','rdo' => 'rdo_code','region' => 'region','source' => 'document_source','sale_category' => 'sale_category'] as $key => $column) {
                    if (isset($f[$key])) {
                        $columns[] = $column;
                    }
                }
                if (isset($f['from']) || isset($f['to']) || isset($f['deadline'])) {
                    $columns[] = ($f['date_basis'] ?? 'document') === 'received' ? 'recorded_at' : 'business_date';
                }
                if (isset($f['deadline'])) {
                    $columns[] = 'business_date';
                }
                if (!empty($f['search'])) {
                    $columns = array_merge($columns, ['business','tin','receipt_number','transaction_ref','document_id','tin_digits']);
                }
                $ledger->select(array_unique($columns));
                if (app(LedgerProjection::class)->ready() && DB::getDriverName() === 'mysql' && \Illuminate\Support\Facades\Schema::hasIndex('ledger_documents', 'ledger_eis_summary') && !array_diff(array_keys($f), ['page','per_page','sort','status','source','min','max','date_basis'])) {
                    $ledger->forceIndex('ledger_eis_summary');
                }
            }
            $base = DB::query()->fromSub($ledger, 'd')->select('d.*')->selectRaw("NULL as outbox_id,NULL as document_hash,NULL as blockers,NULL as prepared_at,NULL as preparation_status,NULL as job_id,NULL as delivery_state,NULL as attempts,NULL as provider_reference,NULL as last_error,NULL as available_at,NULL as completed_at,'unprepared' as queue_status");
        } else {
            $base = DB::query()->fromSub($ledger, 'd')->leftJoin('eis_document_outbox as o', function ($j) {
                $j->on('o.id', '=', DB::raw("(SELECT latest.id FROM eis_document_outbox latest WHERE latest.merchant_id=d.merchant_id AND latest.document_source=d.document_source AND latest.document_id=d.document_id AND latest.internal_schema='".EisDocumentReadiness::INTERNAL_SCHEMA."' ORDER BY latest.source_review_revision DESC LIMIT 1)"));
            })->leftJoin('eis_delivery_jobs as j', fn ($j) => $j->on('j.outbox_id', '=', 'o.id')->on('j.merchant_id', '=', 'd.merchant_id'))
                ->select('d.*', 'o.id as outbox_id', 'o.document_hash', 'o.blockers', 'o.prepared_at', 'o.status as preparation_status', 'j.id as job_id', 'j.state as delivery_state', 'j.attempts', 'j.provider_reference', 'j.last_error', 'j.available_at', 'j.completed_at')
                ->selectRaw("CASE WHEN j.state IN ('accepted','rejected','sending','queued','retry','uncertain','dead_letter') THEN j.state WHEN o.status='needs_source_review' THEN 'needs_review' WHEN o.id IS NOT NULL THEN 'prepared' ELSE 'unprepared' END as queue_status");
        }
        $q = DB::query()->fromSub($base, 'c');
        foreach (['document' => 'document_type','branch' => 'seller_branch_key','rdo' => 'rdo_code','region' => 'region','source' => 'document_source','sale_category' => 'sale_category','status' => 'queue_status'] as $key => $column) {
            if (isset($f[$key])) {
                $q->where($column, $f[$key]);
            }
        }
        $basis = ($f['date_basis'] ?? 'document') === 'received' ? 'recorded_at' : 'business_date';
        if (isset($f['from'])) {
            $q->where($basis, '>=', ($basis === 'business_date' ? $f['from'] : $this->boundary($f['from'])));
        }
        if (isset($f['to'])) {
            $q->where($basis, '<', ($basis === 'business_date' ? CarbonImmutable::parse($f['to'])->addDay()->toDateString() : $this->boundary($f['to'], true)));
        }
        if (isset($f['min'])) {
            $q->where('amount', '>=', $f['min']);
        }
        if (isset($f['max'])) {
            $q->where('amount', '<=', $f['max']);
        }
        if (!empty($f['search'])) {
            $like = '%'.addcslashes($f['search'], '%_\\').'%';
            $tin = preg_replace('/\D/', '', $f['search']);
            $q->where(function ($q) use ($like, $tin) {
                foreach (['business','tin','receipt_number','transaction_ref','provider_reference','document_id'] as $c) {
                    $q->orWhere($c, 'like', $like);
                }
                if (strlen($tin) >= 9) {
                    $q->orWhere('tin_digits', 'like', $tin.'%');
                }
            });
        }
        // Calendar-day deadlines are displayed as the end of issue-date + configured days in PHT.
        if (isset($f['deadline'])) {
            $cut = CarbonImmutable::now('Asia/Manila')->subDays(config('bir_compliance.transmission_calendar_days', 3))->toDateString();
            $q->whereNotIn('queue_status', ['accepted','rejected'])->whereNotNull('business_date');
            match ($f['deadline']) {
                'overdue' => $q->where('business_date', '<', $cut),
                'due_today' => $q->where('business_date', '>=', $cut)->where('business_date', '<', CarbonImmutable::parse($cut)->addDay()->toDateString()),
                default => $q->where('business_date', '>=', CarbonImmutable::parse($cut)->addDay()->toDateString()),
            };
        }
        return $q;
    }

    private function boundary(string $date, bool $next = false): string
    {
        $d = CarbonImmutable::parse($date, 'Asia/Manila')->startOfDay();
        if ($next) {
            $d = $d->addDay();
        }
        return $d->setTimezone(config('app.timezone'))->toDateTimeString();
    }

    public function ordered(Builder $q, array $f): Builder
    {
        $sort = $f['sort'] ?? 'newest';
        $field = str_starts_with($sort, 'amount') ? 'amount' : (($f['date_basis'] ?? 'document') === 'received' ? 'recorded_at' : 'business_date');
        return $q->orderBy($field, in_array($sort, ['oldest','amount_asc']) ? 'asc' : 'desc')->orderBy('id', in_array($sort, ['oldest','amount_asc']) ? 'asc' : 'desc');
    }

    public function row(object $r): array
    {
        $iso = fn ($v) => $v ? CarbonImmutable::parse($v, config('app.timezone'))->toIso8601String() : null;
        return [
            'key' => $r->document_source.':'.$r->document_id,'source' => $r->document_source,'id' => $r->document_id,
            'merchant_id' => $r->merchant_id,'business' => $r->business,'tin' => $r->tin,'number' => $r->receipt_number,'reference' => $r->transaction_ref,
            'document_type' => $r->document_type,'sale_category' => $r->sale_category,'branch' => $r->seller_branch_key,'rdo' => $r->rdo_code,'region' => $r->region,
            'business_date' => $r->business_date ? substr($r->business_date, 0, 10) : null,'received_at' => $iso($r->recorded_at),
            'amount' => (float)$r->amount,'vat_amount' => (float)$r->vat_amount,'status' => $r->queue_status,'document_status' => $r->status,
            'outbox_id' => $r->outbox_id,'job_id' => $r->job_id,'attempts' => (int)($r->attempts ?? 0),'provider_reference' => $r->provider_reference,
            'blockers' => json_decode($r->blockers ?? '[]', true) ?: [],'last_error' => $r->last_error,'document_hash' => $r->document_hash,
            'prepared_at' => $iso($r->prepared_at),'next_attempt_at' => $iso($r->available_at),'completed_at' => $iso($r->completed_at),
            'due_at' => $r->business_date ? app(BirCompliance::class)->deadline($r->business_date)->toIso8601String() : null,
            'identity_count' => (int)$r->identity_count,'environment' => 'production',
        ];
    }

    public function inspect(string $merchant, string $source, string $id): array
    {
        $table = $source === 'invoice' ? 'business_invoices' : 'transaction_receipts';
        abort_unless(in_array($source, ['invoice','receipt'], true) && DB::table($table)->where('merchant_id', $merchant)->where('id', $id)->exists(), 404, 'Document not found in this company.');
        $result = app(EisDocumentReadiness::class)->inspect($source, $id);
        return array_diff_key($result, ['canonical' => true,'model' => true]);
    }

    public function work(int $limit = 10): int
    {
        $done = 0;
        for ($i = 0;$i < max(1, min(100, $limit));$i++) {
            $item = DB::table('eis_preparation_batch_items')->where('status', 'pending')->orderBy('created_at')->orderBy('id')->first();
            if (!$item) {
                break;
            }
            DB::transaction(function () use ($item) {
                $item = DB::table('eis_preparation_batch_items')->where('id', $item->id)->lockForUpdate()->first();
                if ($item->status !== 'pending') {
                    return;
                }
                try {
                    // A failed preparation rolls back its writes before the failure outcome is recorded.
                    $p = DB::transaction(function () use ($item) {
                        DB::table($item->document_source === 'invoice' ? 'business_invoices' : 'transaction_receipts')->where('id', $item->document_id)->where('merchant_id', $item->merchant_id)->lockForUpdate()->first();
                        $current = $this->inspect($item->merchant_id, $item->document_source, $item->document_id);
                        abort_unless(hash_equals($item->expected_hash, $current['document_hash']), 409, 'Source changed after batch review. Review a new batch.');
                        return app(EisDocumentReadiness::class)->prepare($item->document_source, $item->document_id);
                    });
                    $status = $p['local_validation_passed'] ? 'prepared' : 'needs_review';
                    $data = ['outbox_id' => $p['id'],'status' => $status,'message' => implode(' ', $p['blockers'])];
                } catch (\Throwable $e) {
                    report($e);
                    $data = ['status' => 'failed','message' => $e instanceof HttpExceptionInterface ? $e->getMessage() : 'Preparation could not complete. Review the source and retry in a new batch.'];
                }
                DB::table('eis_preparation_batch_items')->where('id', $item->id)->update($data + ['updated_at' => now()]);
            }, 3);
            $done++;
            if (!DB::table('eis_preparation_batch_items')->where('batch_id', $item->batch_id)->where('status', 'pending')->exists()) {
                DB::table('eis_preparation_batches')->where('id', $item->batch_id)->update(['status' => 'completed','completed_at' => now(),'updated_at' => now()]);
            }
        }
        // Recover a crash between committing the last item and completing its parent batch.
        DB::table('eis_preparation_batches')->where('status', 'pending')->whereNotExists(function ($q) {
            $q->selectRaw('1')->from('eis_preparation_batch_items as i')
                ->whereColumn('i.batch_id','eis_preparation_batches.id')->where('i.status','pending');
        })->update(['status' => 'completed','completed_at' => now(),'updated_at' => now()]);
        return $done;
    }
}
