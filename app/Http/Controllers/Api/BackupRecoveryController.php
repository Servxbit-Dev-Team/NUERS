<?php

namespace App\Http\Controllers\Api;

use App\Services\ApiAccess;
use App\Services\BackupRecovery;
use App\Services\BirCompliance;
use Carbon\CarbonImmutable as C;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class BackupRecoveryController
{
    private function actor(Request $r)
    {
        return app(ApiAccess::class)->require($r, ['admin', 'super_admin']);
    }

    public function index(Request $r)
    {
        $this->actor($r);

        return response()->json(app(BackupRecovery::class)->overview())->header('Cache-Control', 'private, no-store');
    }

    public function records(Request $r)
    {
        $this->actor($r);
        $v = $r->validate(['type' => 'required|in:requests,evidence', 'kind' => 'nullable|in:backup,snapshot,drill,replication,restore,incident,policy', 'page' => 'nullable|integer|min:1']);
        $rows = DB::table('bcdr_'.$v['type'])->when($v['kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind))->orderByDesc('created_at')->paginate(25);

        return response()->json(['data' => collect($rows->items())->map(fn ($row) => app(BackupRecovery::class)->row($row)), 'total' => $rows->total(), 'last_page' => $rows->lastPage()])->header('Cache-Control', 'private, no-store');
    }

    private function referenceRules(): array
    {
        return ['required', 'string', 'min:10', 'max:500', 'not_regex:/[\r\n]|(?:password|secret|token|api_key)\s*[:=]/i'];
    }

    private function dateRule(bool $past = true): array
    {
        return ['bail', 'required', 'string', 'date', ...($past ? ['before_or_equal:now'] : []), function ($a, $v, $fail) {
            if (! preg_match('/(?:Z|[+-]\d{2}:\d{2})$/', $v)) {
                $fail('Use an ISO timestamp with a timezone.');
            }
        }];
    }

    private function payload(Request $r, string $type): array
    {
        $kind = $r->input('kind');
        $base = ['payload' => 'required|array', 'kind' => ['required', Rule::in($type === 'requests' ? ['backup', 'restore', 'drill', 'incident', 'policy'] : ['backup', 'snapshot', 'drill', 'replication'])], 'request_id' => 'required|uuid', 'reason' => 'required|string|min:10|max:2000', 'evidence_reference' => $this->referenceRules()];
        $r->validate($base);
        $environment = 'required|in:local,staging,production,dr';
        $asset = 'required|in:database,objects,transaction_logs,configuration,keys';
        $text = 'required|string|min:3|max:200';
        $fields = match ($type.'.'.$kind) {
            'requests.backup' => ['asset_type' => $asset, 'environment' => $environment, 'scope' => $text, 'target_label' => $text],
            'requests.restore' => ['snapshot_id' => 'required|uuid', 'environment' => $environment, 'scope' => $text, 'target_label' => $text],
            'requests.drill' => ['snapshot_id' => 'nullable|uuid', 'environment' => 'required|in:local,staging,dr', 'scope' => $text, 'target_label' => $text, 'scheduled_at' => $this->dateRule(false)],
            'requests.incident' => ['environment' => $environment, 'scope' => $text, 'severity' => 'required|in:critical,high,medium', 'detected_at' => $this->dateRule()],
            'requests.policy' => $this->policyRules(),
            'evidence.backup' => ['asset_type' => $asset, 'scope' => $text, 'environment' => $environment, 'started_at' => $this->dateRule(), 'finished_at' => $this->dateRule(), 'result' => 'required|in:successful,failed', 'sha256' => 'nullable|regex:/^[a-f0-9]{64}$/', 'size_bytes' => 'nullable|integer|min:1|max:9007199254740991', 'location_label' => $text, 'encrypted' => 'required|boolean', 'immutable' => 'required|boolean', 'offsite' => 'required|boolean'],
            'evidence.snapshot' => ['backup_id' => 'required|uuid', 'environment' => $environment, 'snapshot_at' => $this->dateRule(), 'consistency_marker' => $text, 'sha256' => 'required|regex:/^[a-f0-9]{64}$/'],
            'evidence.drill' => ['snapshot_id' => 'nullable|uuid', 'test_type' => 'required|in:restore,full_dr,tabletop', 'environment' => 'required|in:local,staging,dr', 'target_label' => $text, 'result' => 'required|in:passed,failed', 'outage_at' => $this->dateRule(), 'recovered_at' => $this->dateRule(), 'source_commit_at' => 'nullable|date', 'restored_commit_at' => 'nullable|date', 'checks' => 'required|array:'.implode(',', BackupRecovery::CHECKS), ...array_combine(array_map(fn ($s) => 'checks.'.$s, BackupRecovery::CHECKS), array_fill(0, count(BackupRecovery::CHECKS), 'required|boolean'))],
            'evidence.replication' => ['component' => 'required|in:database,storage,queue,failover,environment', 'primary_status' => 'required|in:healthy,in_sync,degraded,failed,unknown', 'recovery_status' => 'required|in:healthy,in_sync,degraded,failed,unknown', 'observed_at' => $this->dateRule()],
        };
        $roots = array_values(array_unique(array_map(fn ($key) => explode('.', $key)[0], array_keys($fields))));
        $payload = Validator::make($r->input('payload', []), $fields)->validate();
        Validator::make(['payload' => $r->input('payload')], ['payload' => 'required|array:'.implode(',', $roots)])->validate();
        if ($type === 'evidence' && $kind === 'backup') {
            abort_if(C::parse($payload['finished_at'])->lt(C::parse($payload['started_at'])), 422, 'Backup finish cannot precede its start.');
            abort_if($payload['result'] === 'successful' && (empty($payload['sha256']) || empty($payload['size_bytes'])), 422, 'A successful backup result needs a SHA-256 digest and artifact size.');
        }
        if (in_array($kind, ['snapshot', 'restore']) && ! empty($payload['backup_id'] ?? $payload['snapshot_id'] ?? null)) {
            $this->related($kind === 'snapshot' ? 'backup' : 'snapshot', $payload['backup_id'] ?? $payload['snapshot_id']);
        }
        if ($type === 'evidence' && $kind === 'drill') {
            abort_if(C::parse($payload['recovered_at'])->lt(C::parse($payload['outage_at'])), 422, 'Recovery cannot precede the outage start.');
            if ($payload['test_type'] !== 'tabletop') {
                abort_unless(! empty($payload['snapshot_id']) && ! empty($payload['source_commit_at']) && ! empty($payload['restored_commit_at']), 422, 'A measured recovery test needs a snapshot and both source and recovered durable commit timestamps.');
                $this->related('snapshot', $payload['snapshot_id']);
                foreach (['source_commit_at', 'restored_commit_at'] as $key) {
                    Validator::make([$key => $payload[$key]], [$key => $this->dateRule()])->validate();
                }
                abort_if(C::parse($payload['restored_commit_at'])->gt(C::parse($payload['source_commit_at'])) || C::parse($payload['source_commit_at'])->gt(C::parse($payload['outage_at'])), 422, 'Recovered commit must not exceed the source commit; source commit cannot follow the outage.');
                abort_if($payload['result'] === 'passed' && count(array_filter($payload['checks'])) !== count(BackupRecovery::CHECKS), 422, 'A passing recovery test requires every recorded integrity and reconciliation check to pass.');
            }
        }
        foreach (['started_at', 'finished_at', 'snapshot_at', 'observed_at', 'outage_at', 'recovered_at', 'source_commit_at', 'restored_commit_at', 'scheduled_at', 'detected_at'] as $date) {
            if (! empty($payload[$date])) {
                $payload[$date] = C::parse($payload[$date])->utc()->toIso8601String();
            }
        }
        $payload['reason'] = $r->input('reason');

        return $payload;
    }

    private function policyRules(): array
    {
        return ['policy_revision' => 'required|integer|min:0', 'database_minutes' => 'required|integer|min:1|max:10080', 'objects_minutes' => 'required|integer|min:1|max:10080', 'logs_minutes' => 'required|integer|min:1|max:1440', 'configuration_minutes' => 'required|integer|min:1|max:43200', 'operational_days' => 'required|integer|min:1|max:3650', 'rpo_minutes' => 'required|integer|min:1|max:10080', 'rto_minutes' => 'required|integer|min:1|max:43200', 'drill_days' => 'required|integer|min:1|max:365', 'observation_minutes' => 'required|integer|min:1|max:1440', 'primary_site' => 'required|string|min:3|max:100', 'dr_site' => 'required|string|min:3|max:100', 'runbook_reference' => $this->referenceRules(), 'retention_reference' => $this->referenceRules(), 'key_custody_reference' => $this->referenceRules()];
    }

    private function related(string $kind, string $id): object
    {
        $row = DB::table('bcdr_evidence')->where('id', $id)->where('kind', $kind)->first();
        abort_unless($row, 422, 'Select a registered '.$kind.' evidence record.');

        return $row;
    }

    public function create(Request $r, string $type)
    {
        $actor = $this->actor($r);
        abort_unless(in_array($type, ['requests', 'evidence']), 404);
        $payload = $this->payload($r, $type);

        return DB::transaction(function () use ($r, $type, $actor, $payload) {
            DB::table('bcdr_policy')->where('id', 1)->lockForUpdate()->first();
            $hash = hash('sha256', json_encode([$r->kind, $payload, $r->evidence_reference], JSON_THROW_ON_ERROR));
            $old = DB::table('bcdr_'.$type)->where('actor_id', $actor->id)->where('request_id', $r->request_id)->first();
            if ($old) {
                abort_unless(hash_equals($old->request_hash, $hash), 409, 'This request identifier was already used for different content.');

                return response()->json(['data' => app(BackupRecovery::class)->row($old), 'message' => 'Existing record returned.'], 200);
            }
            $id = (string) Str::uuid();
            $status = $type === 'requests' && $r->kind === 'incident' ? 'open' : 'pending_review';
            DB::table('bcdr_'.$type)->insert(['id' => $id, 'request_id' => $r->request_id, 'actor_id' => $actor->id, 'kind' => $r->kind, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'request_hash' => $hash, 'status' => $status, 'evidence_reference' => $r->evidence_reference, 'created_at' => now(), 'updated_at' => now()]);
            if ($type === 'requests' && $r->kind === 'incident') {
                DB::table('eis_operational_incidents')->insert(['id' => $id, 'title' => 'DR incident: '.$payload['scope'], 'severity' => $payload['severity'], 'status' => 'open', 'detected_at' => C::parse($payload['detected_at'])->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'), 'evidence_reference' => $r->evidence_reference, 'actor_id' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            app(BirCompliance::class)->audit('bcdr.'.$type.'.created', $id, (string) $actor->id, ['kind' => $r->kind, 'status' => $status]);

            return response()->json(['data' => app(BackupRecovery::class)->row(DB::table('bcdr_'.$type)->where('id', $id)->first()), 'message' => $type === 'evidence' ? 'Evidence registered for independent review.' : 'Request recorded. No backup, restoration or failover has been executed.'], 201);
        }, 3);
    }

    public function review(Request $r, string $type, string $id)
    {
        $actor = $this->actor($r);
        abort_unless(in_array($type, ['requests', 'evidence']), 404);
        $v = $r->validate(['revision' => 'required|integer|min:1', 'decision' => 'required|in:approve,reject', 'note' => 'required|string|min:10|max:2000', 'evidence_reference' => $this->referenceRules()]);

        return DB::transaction(function () use ($actor, $type, $id, $v) {
            $policy = DB::table('bcdr_policy')->where('id', 1)->lockForUpdate()->first();
            $row = DB::table('bcdr_'.$type)->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless($row->revision === $v['revision'] && $row->status === 'pending_review', 409, 'The record changed or has already been reviewed.');
            abort_if((string) $row->actor_id === (string) $actor->id, 403, 'An independent administrator must review this record.');
            $payload = json_decode($row->payload, true);
            if ($v['decision'] === 'approve') {
                if ($type === 'evidence') {
                    $this->verifyEvidence($row, $payload);
                }
                if ($type === 'requests' && $row->kind === 'restore') {
                    abort_unless($policy->revision > 0, 409, 'Approve a recovery policy and runbook before approving a restore request.');
                    $snapshot = $this->related('snapshot', $payload['snapshot_id']);
                    abort_unless($snapshot->status === 'verified', 409, 'The snapshot requires independent integrity review.');
                    $test = DB::table('bcdr_evidence')->where('kind', 'drill')->where('status', 'verified')->where('payload->snapshot_id', $snapshot->id)->where('payload->test_type', '!=', 'tabletop')->orderByDesc('payload->recovered_at')->orderBy('payload->result')->first();
                    $testData = $test ? json_decode($test->payload, true) : null;
                    $days = json_decode($policy->payload, true)['drill_days'];
                    abort_unless($testData && $testData['result'] === 'passed' && C::parse($testData['recovered_at'])->gte(now()->subDays($days)), 409, 'A current independently reviewed recovery test is required before restore approval.');
                }
                if ($type === 'requests' && $row->kind === 'policy') {
                    abort_unless($policy->revision === $payload['policy_revision'], 409, 'The policy revision changed. Submit a new policy request.');
                    unset($payload['policy_revision'],$payload['reason']);
                    DB::table('bcdr_policy')->where('id', 1)->update(['payload' => json_encode($payload), 'revision' => $policy->revision + 1, 'approved_by' => $actor->id, 'evidence_reference' => $v['evidence_reference'], 'updated_at' => now()]);
                }
            }
            $status = $v['decision'] === 'reject' ? 'rejected' : ($type === 'evidence' ? 'verified' : 'approved');
            DB::table('bcdr_'.$type)->where('id', $id)->update(['status' => $status, 'reviewed_by' => $actor->id, 'review_note' => $v['note'].' | '.$v['evidence_reference'], 'reviewed_at' => now(), 'revision' => $row->revision + 1, 'updated_at' => now()]);
            app(BirCompliance::class)->audit('bcdr.'.$type.'.reviewed', $id, (string) $actor->id, ['kind' => $row->kind, 'status' => $status, 'revision' => $row->revision + 1]);

            return response()->json(['message' => $type === 'evidence' ? 'Independent evidence review recorded.' : 'Decision recorded. Execution remains an authorized operator action under the approved runbook.']);
        }, 3);
    }

    private function verifyEvidence(object $row, array $p): void
    {
        if ($row->kind === 'snapshot') {
            $backup = $this->related('backup', $p['backup_id']);
            $b = json_decode($backup->payload, true);
            abort_unless($backup->status === 'verified' && $b['result'] === 'successful' && hash_equals($b['sha256'], $p['sha256']) && $b['environment'] === $p['environment'], 409, 'Verify the successful source backup, environment and matching artifact digest first.');
        }
        if ($row->kind === 'drill' && $p['test_type'] !== 'tabletop') {
            $snapshot = $this->related('snapshot', $p['snapshot_id']);
            abort_unless($snapshot->status === 'verified', 409, 'Independently review the source snapshot before reviewing its recovery test.');
        }
    }

    public function export(Request $r)
    {
        $actor = $this->actor($r);
        $report = app(BackupRecovery::class)->overview(100);
        $report['report_scope'] = 'Readiness rollups use the latest 2,000 submitted evidence records; sections include up to 100 records. Full history is available through paginated records. Provider infrastructure and execution are not probed.';
        $report['references'] = ['RMC 110-2026, VII(6-8), VII(20), VIII(4-7,10)', 'Annex A item 16: DR plan with tested RTO/RPO'];
        app(BirCompliance::class)->audit('bcdr.report.exported', 'bcdr', (string) $actor->id);

        if ($r->query('format') === 'csv') {
            $rows = iterator_to_array(\App\Services\ReportCsv::flatten($report), false);
            if ($r->has('report_limit')) {
                \App\Services\ReportCsv::limit($r, count($rows));
            }
            return \App\Services\ReportCsv::download(['Evidence field / record path','Recorded value'], $rows, 'NUERS-backup-recovery-report.csv');
        }
        return response()->json($report)->header('Content-Disposition', 'attachment; filename="NUERS-backup-recovery-report.json"')->header('Cache-Control', 'private, no-store');
    }
}
