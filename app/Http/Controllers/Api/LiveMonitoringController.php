<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BirCompliance;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LiveMonitoringController extends Controller
{
    private const SUCCESS = ['completed', 'paid', 'issued', 'settled', 'verified', 'valid'];
    private const FAILED = ['failed', 'invalid', 'rejected'];
    private const PENDING = ['pending', 'queued', 'processing', 'under_review'];

    public function show(Request $request)
    {
        $policy = app(BirCompliance::class);
        $user = $policy->administrativeUser($request);
        $canViewFinancials = in_array(DB::table('profiles')->where('id', $user->id)->value('role'), ['admin', 'super_admin'], true);
        $now = CarbonImmutable::now('Asia/Manila');
        $today = $this->summary($now->startOfDay(), $now);
        $yesterday = $this->summary($now->subDay()->startOfDay(), $now->subDay());
        $minuteRows = [];
        for ($i = 59; $i >= 0; $i--) {
            $at = $now->subMinutes($i)->startOfMinute();
            $minuteRows[$at->format('Y-m-d H:i')] = ['time' => $at->format('H:i'), 'successful' => 0, 'failed' => 0, 'pending' => 0, 'other' => 0];
        }
        $this->window(DB::table('merchant_transactions'), $now->subMinutes(59)->startOfMinute(), $now)
            ->select('created_at', 'status')->orderBy('id')->chunk(1000, function ($rows) use (&$minuteRows) {
                foreach ($rows as $row) {
                    $key = $this->date($row->created_at)->format('Y-m-d H:i');
                    if (isset($minuteRows[$key])) $minuteRows[$key][$this->state($row->status)]++;
                }
            });
        $lastMinute = $this->window(DB::table('merchant_transactions'), $now->subSeconds(60), $now)->count();
        $pending = DB::table('eis_transmissions')->whereIn('status', ['queued', 'submitting', 'submitted', 'recovered']);
        $queue = ['production' => (clone $pending)->where('environment', 'production')->count(), 'certification' => (clone $pending)->where('environment', '!=', 'production')->count(),
            'overdue' => (clone $pending)->where('environment', 'production')->where('due_at', '<', $this->dbDate($now))->count()];
        $query = $this->transactions($request, $now);
        $total = (clone $query)->count();
        $page = max(1, $request->integer('page', 1));
        $perPage = min(100, max(10, $request->integer('per_page', 10)));
        $rows = (clone $query)->orderByDesc('t.created_at')->orderByDesc('t.id')->offset(($page - 1) * $perPage)->limit($perPage)->get($this->columns());
        $top = $this->window(DB::table('merchant_transactions as t'), $now->startOfDay(), $now, 't.created_at')->leftJoin('merchants as m', 'm.id', '=', 't.merchant_id')
            ->select('t.merchant_id', 'm.business_name')->selectRaw('COUNT(*) AS total')->groupBy('t.merchant_id', 'm.business_name')->orderByDesc('total')->limit(5)->get();
        $integration = $policy->integration();
        $gate = $policy->gate($integration);
        $activities = $this->activities();
        return response()->json([
            'generated_at' => $now->toIso8601String(), 'today' => $today, 'yesterday' => $yesterday,
            'financial_summary' => $canViewFinancials ? app(\App\Services\AdminFinancialSummary::class)->overview($now->startOfDay(), $now) : null,
            'tps' => round($lastMinute / 60, 2), 'tps_count' => $lastMinute, 'queue' => $queue,
            'flow' => array_values($minuteRows), 'top_taxpayers' => $top, 'transactions' => $this->formatRows($rows),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'pages' => max(1, (int) ceil($total / $perPage))],
            'activities' => $activities, 'production' => ['ready' => $gate['can_transmit'], 'blockers' => $gate['blockers'], 'environment' => $integration?->environment ?? 'unconfigured'],
            'health' => ['application' => 'online', 'bir_gateway' => $gate['can_transmit'] ? 'authorization_ready' : 'not_connected', 'last_bir_success_at' => $integration?->last_successful_transmission_at],
            'solution' => ['name' => config('bir_compliance.solution_name'), 'version' => config('bir_compliance.solution_version')],
        ])->header('Cache-Control', 'private, no-store');
    }

    private function summary(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $counts = $this->window(DB::table('merchant_transactions'), $start, $end)->select('status')->selectRaw('COUNT(*) AS total')->groupBy('status')->get();
        $result = ['total' => 0, 'successful' => 0, 'failed' => 0, 'pending' => 0, 'other' => 0];
        foreach ($counts as $row) { $result['total'] += $row->total; $result[$this->state($row->status)] += $row->total; }
        return $result;
    }

    private function state(string $status): string
    {
        return in_array($status, self::SUCCESS, true) ? 'successful' : (in_array($status, self::FAILED, true) ? 'failed' : (in_array($status, self::PENDING, true) ? 'pending' : 'other'));
    }

    private function transactions(Request $request, CarbonImmutable $cutoff): Builder
    {
        $query = DB::table('merchant_transactions as t')->leftJoin('merchants as m', 'm.id', '=', 't.merchant_id')->leftJoin('transaction_receipts as r', 'r.id', '=', 't.receipt_id')->where('t.created_at', '<=', $this->dbDate($cutoff));
        $search = trim((string) $request->query('search', ''));
        if ($search !== '') $query->where(function ($q) use ($search) {
            $like = '%'.$search.'%';
            $q->where('t.transaction_ref', 'like', $like)->orWhere('r.receipt_number', 'like', $like)->orWhere('m.business_name', 'like', $like)->orWhere('m.tin', 'like', $like)
                ->orWhereRaw("REPLACE(REPLACE(m.tin, '-', ''), ' ', '') LIKE ?", ['%'.str_replace(['-', ' '], '', $search).'%']);
        });
        if ($request->filled('status') && $request->query('status') !== 'all') {
            $status = (string) $request->query('status');
            match ($status) { 'successful' => $query->whereIn('t.status', self::SUCCESS), 'failed' => $query->whereIn('t.status', self::FAILED), 'pending' => $query->whereIn('t.status', self::PENDING), default => $query->where('t.status', $status) };
        }
        return $query;
    }

    private function columns(): array
    {
        return ['t.id', 't.merchant_id', 't.transaction_ref', 't.created_at', 't.amount', 't.transaction_type', 't.status', 't.receipt_id', 'r.receipt_number', 'r.receipt_type', 'm.tin', 'm.business_name'];
    }

    private function formatRows($rows): array
    {
        if ($rows->isEmpty()) return [];
        $receipts = $rows->pluck('receipt_id')->filter()->values()->all();
        $numbers = $rows->pluck('receipt_number')->merge($rows->pluck('transaction_ref'))->filter()->values()->all();
        $transmissions = DB::table('eis_transmissions as e')->join('eis_invoice_payloads as p', 'p.id', '=', 'e.payload_id')
            ->where(function ($q) use ($receipts, $numbers, $rows) {
                $q->whereIn('p.receipt_id', $receipts)->orWhere(function ($q) use ($numbers, $rows) { $q->whereIn('p.invoice_number', $numbers)->whereIn('e.merchant_id', $rows->pluck('merchant_id')->filter()->values()->all()); });
            })->orderByRaw("CASE WHEN e.environment='production' THEN 0 ELSE 1 END")->orderByDesc('e.created_at')->orderByDesc('e.id')->get(['e.*', 'p.receipt_id as linked_receipt', 'p.invoice_number']);
        $byReceipt = []; $byNumber = [];
        foreach ($transmissions as $t) {
            if ($t->linked_receipt) $byReceipt[$t->linked_receipt] ??= $t;
            if ($t->merchant_id && $t->invoice_number) $byNumber[$t->merchant_id.'|'.$t->invoice_number] ??= $t;
        }
        $ackIds=$transmissions->filter(fn($t)=>app(\App\Services\SubmissionEvidence::class)->verified($t->id))->pluck('id')->all();
        return $rows->map(function ($row) use ($byReceipt, $byNumber, $ackIds) {
            $t = $byReceipt[$row->receipt_id] ?? $byNumber[$row->merchant_id.'|'.$row->receipt_number] ?? $byNumber[$row->merchant_id.'|'.$row->transaction_ref] ?? null;
            $birStatus = $t?->status ?? 'not_submitted';
            $test = $t && ($t->environment !== 'production' || str_starts_with($t->bir_reference_number ?? '', 'CERT-'));
            if ($t && in_array($birStatus,['acknowledged','accepted','success'],true) && (! in_array($t->id, $ackIds, true) || ! $t->bir_reference_number)) $birStatus = 'unverified_ack';
            return ['id' => $row->id, 'reference' => $row->transaction_ref, 'created_at' => $this->date($row->created_at)->toIso8601String(),
                'receipt_number' => $row->receipt_number, 'tin' => $row->tin, 'business_name' => $row->business_name,
                'type' => $row->receipt_type ?? $row->transaction_type, 'amount' => (float) $row->amount, 'status' => $row->status, 'state' => $this->state($row->status),
                'transmission_id' => $t?->id, 'bir_status' => $birStatus, 'test' => (bool) $test, 'bir_reference' => $t?->bir_reference_number,
                'response_ms' => $t?->latency_ms, 'response_code' => $t?->response_code, 'response_message' => $t?->response_message,
                'due_at' => $t?->due_at ? $this->date($t->due_at)->toIso8601String() : null,
                'recovery_due_at' => ($t->recovery_due_at ?? null) ? $this->date($t->recovery_due_at)->toIso8601String() : null,
                'payload_hash' => $t?->payload_hash];
        })->all();
    }

    private function activities(): array
    {
        $items = collect();
        if (Schema::hasTable('eis_audit_events')) {
            $items = DB::table('eis_audit_events')->orderByDesc('id')->limit(6)->get()->map(fn ($r) => ['id' => 'audit-'.$r->id, 'title' => str_replace(['eis.', '.'], ['', ' '], $r->event), 'detail' => $r->subject_id, 'at' => CarbonImmutable::parse($r->occurred_at, 'UTC')->toIso8601String(), 'kind' => str_contains($r->event, 'certification') ? 'test' : 'info']);
        }
        if (Schema::hasTable('eis_operational_incidents')) {
            foreach (DB::table('eis_operational_incidents')->orderByDesc('detected_at')->limit(6)->get() as $r) {
                $due = $this->date($r->detected_at)->addHours(24);
                $items->push(['id' => $r->id, 'title' => $r->title, 'detail' => $r->reported_at ? 'Incident report recorded' : ($r->severity === 'critical' ? 'Report due '.$due->format('M j, H:i').' PHT' : 'Incident review pending'), 'at' => $this->date($r->detected_at)->toIso8601String(), 'kind' => $r->severity === 'critical' ? 'danger' : 'warning']);
            }
        }
        foreach (DB::table('merchants')->orderByDesc('created_at')->limit(3)->get(['id', 'business_name', 'created_at']) as $r) {
            if ($r->created_at) $items->push(['id' => 'merchant-'.$r->id, 'title' => 'Taxpayer registered', 'detail' => $r->business_name, 'at' => $this->date($r->created_at)->toIso8601String(), 'kind' => 'info']);
        }
        return $items->sortByDesc('at')->take(6)->values()->all();
    }

    public function auditLog(Request $request)
    {
        $policy = app(BirCompliance::class); $actor = $policy->administrativeUser($request);
        if ($request->boolean('download')) {
            $policy->audit('eis.audit.exported', null, (string) $actor->id);
            return response()->streamDownload(function () use ($policy) {
                echo '{"integrity":'.json_encode($policy->auditIntegrity()).',"events":['; $comma = '';
                foreach (DB::table('eis_audit_events')->orderBy('id')->cursor() as $row) { echo $comma.json_encode($row, JSON_THROW_ON_ERROR); $comma = ','; }
                echo ']}';
            }, 'nuers-audit-journal.json', ['Content-Type' => 'application/json', 'Cache-Control' => 'private, no-store']);
        }
        $query = DB::table('eis_audit_events');
        if ($request->filled('search')) $query->where(function ($q) use ($request) { $value = '%'.$request->query('search').'%'; $q->where('event', 'like', $value)->orWhere('subject_id', 'like', $value)->orWhere('actor_id', 'like', $value); });
        $total = (clone $query)->count(); $page = max(1, $request->integer('page', 1));
        return response()->json(['integrity' => $policy->auditIntegrity(), 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / 25)), 'events' => $query->orderByDesc('id')->offset(($page - 1) * 25)->limit(25)->get()->map(fn ($r) => [...(array) $r, 'occurred_at' => CarbonImmutable::parse($r->occurred_at, 'UTC')->toIso8601String(), 'details' => json_decode($r->details, true)])])->header('Cache-Control', 'private, no-store');
    }

    public function export(Request $request)
    {
        $policy = app(BirCompliance::class); $actor = $policy->administrativeUser($request);
        $format = $request->query('format', 'json');
        abort_unless(in_array($format, ['csv', 'json'], true), 422);
        $cutoff = CarbonImmutable::now('Asia/Manila');
        $query = $this->transactions($request, $cutoff)->select($this->columns())->orderBy('t.id');
        $policy->audit('eis.records.exported', null, (string) $actor->id, ['format' => $format, 'cutoff' => $cutoff->toIso8601String(), 'count' => (clone $query)->count()]);
        return response()->streamDownload(function () use ($query, $format, $cutoff) {
            $handle = fopen('php://output', 'w'); $first = true;
            if ($format === 'csv') fputcsv($handle, ['id', 'reference', 'created_at', 'receipt_number', 'tin', 'business_name', 'type', 'amount', 'status', 'state', 'transmission_id', 'bir_status', 'test', 'bir_reference', 'response_ms', 'response_code', 'response_message', 'due_at', 'recovery_due_at', 'payload_hash'], ',', '"', '');
            if ($format === 'json') fwrite($handle, '{"generated_at":'.json_encode($cutoff->toIso8601String()).',"transactions":[');
            $query->chunk(500, function ($rows) use ($handle, $format, &$first) {
                foreach ($this->formatRows($rows) as $row) {
                    if ($format === 'csv') {
                        fputcsv($handle, array_map(fn ($value) => is_string($value) && preg_match('/^[=+@\-\t\r]/', $value) ? "'".$value : $value, $row), ',', '"', '');
                    } else { fwrite($handle, ($first ? '' : ',').json_encode($row, JSON_THROW_ON_ERROR)); }
                    $first = false;
                }
            });
            if ($format === 'json') {
                fwrite($handle, '],"transmissions":['); $comma = '';
                foreach (DB::table('eis_transmissions')->orderBy('id')->cursor() as $row) { fwrite($handle, $comma.json_encode($row, JSON_THROW_ON_ERROR)); $comma = ','; }
                fwrite($handle, '],"payloads":['); $comma = '';
                foreach (DB::table('eis_invoice_payloads')->orderBy('id')->cursor() as $row) {
                    $body = json_decode($row->payload, true);
                    $row->payload = isset($body['encrypted']) ? json_decode(Crypt::decryptString($body['encrypted']), true, flags: JSON_THROW_ON_ERROR) : $body;
                    fwrite($handle, $comma.json_encode($row, JSON_THROW_ON_ERROR)); $comma = ',';
                }
                foreach (['acknowledgements' => 'eis_acknowledgements', 'retry_records' => 'eis_retry_queue', 'api_requests' => 'eis_api_request_logs', 'audit_events' => 'eis_audit_events', 'legacy_audit_events' => 'audit_events', 'source_transactions' => 'merchant_transactions', 'receipts' => 'transaction_receipts', 'receipt_items' => 'transaction_receipt_items', 'business_invoices' => 'business_invoices', 'business_invoice_items' => 'business_invoice_items', 'incidents' => 'eis_operational_incidents'] as $key => $table) {
                    fwrite($handle, '],'.json_encode($key).':['); $comma = '';
                    if (! Schema::hasTable($table)) continue;
                    foreach (DB::table($table)->orderBy('id')->cursor() as $row) { fwrite($handle, $comma.json_encode($row, JSON_THROW_ON_ERROR)); $comma = ','; }
                }
                fwrite($handle, ']}');
            }
            fclose($handle);
        }, 'nuers-transactions-'.$cutoff->format('Ymd-His').'.'.$format, ['Content-Type' => $format === 'json' ? 'application/json' : 'text/csv', 'Cache-Control' => 'private, no-store']);
    }

    private function date(string $date): CarbonImmutable { return CarbonImmutable::parse($date, config('app.timezone', 'UTC'))->setTimezone('Asia/Manila'); }
    private function dbDate(CarbonImmutable $date): string { return $date->setTimezone(config('app.timezone', 'UTC'))->format('Y-m-d H:i:s'); }
    private function window(Builder $q, CarbonImmutable $start, CarbonImmutable $end, string $column = 'created_at'): Builder { return $q->where($column, '>=', $this->dbDate($start))->where($column, '<=', $this->dbDate($end)); }
}
