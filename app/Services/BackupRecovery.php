<?php

namespace App\Services;

use Carbon\CarbonImmutable as C;
use Illuminate\Support\Facades\DB;

class BackupRecovery
{
    public const CHECKS = ['database', 'documents', 'key_recovery', 'audit_integrity', 'sequence_reconciliation', 'pending_transmissions'];

    public function row(object $r): array
    {
        $data = (array) $r;
        $data['payload'] = json_decode($r->payload, true, flags: JSON_THROW_ON_ERROR);
        unset($data['request_hash']);
        foreach (['created_at', 'updated_at', 'reviewed_at'] as $field) {
            if (! empty($data[$field])) {
                $data[$field] = C::parse($data[$field], config('app.timezone'))->toIso8601String();
            }
        }

        return $data;
    }

    public function policy(): array
    {
        $row = DB::table('bcdr_policy')->where('id', 1)->first();

        return ['revision' => $row->revision, 'approved' => $row->revision > 0, 'values' => json_decode($row->payload, true), 'evidence_reference' => $row->evidence_reference];
    }

    public function overview(int $limit = 6): array
    {
        $policy = $this->policy();
        $p = $policy['values'];
        $all = DB::table('bcdr_evidence')->orderByDesc('created_at')->limit(2000)->get()->map(fn ($r) => $this->row($r));
        $verified = $all->where('status', 'verified');
        $jobs = $all->where('kind', 'backup')->values();
        $snapshots = $all->where('kind', 'snapshot')->values();
        $drills = $all->where('kind', 'drill')->values();
        $verifiedDrills = $verified->where('kind', 'drill')->filter(fn ($r) => $r['payload']['result'] === 'passed' && $r['payload']['test_type'] !== 'tabletop');
        $latest = $verifiedDrills->sortByDesc(fn ($r) => $r['payload']['recovered_at'])->first();
        $lastAttempt = $verified->where('kind', 'drill')->filter(fn ($r) => $r['payload']['test_type'] !== 'tabletop')->sortByDesc(fn ($r) => $r['payload']['recovered_at'].'|'.($r['payload']['result'] === 'failed' ? '1' : '0'))->first();
        $freshDrill = $latest && $lastAttempt['payload']['result'] === 'passed' && C::parse($latest['payload']['recovered_at'])->gte(now()->subDays($p['drill_days']));
        $latestSnapshot = $latest ? $snapshots->firstWhere('id', $latest['payload']['snapshot_id']) : null;
        // A snapshot is recoverable only through an independently reviewed, successful restore drill.
        $snapshotViews = $snapshots->map(function ($s) use ($verified, $p) {
            $test = $verified->where('kind', 'drill')->filter(fn ($d) => $d['payload']['test_type'] !== 'tabletop' && $d['payload']['snapshot_id'] === $s['id'])->sortByDesc(fn ($d) => $d['payload']['recovered_at'].'|'.($d['payload']['result'] === 'failed' ? '1' : '0'))->first();
            $s['restore_verified'] = $s['status'] === 'verified' && $test && $test['payload']['result'] === 'passed';
            $s['last_restore_at'] = $test['payload']['recovered_at'] ?? null;
            $s['verification_current'] = $s['restore_verified'] && C::parse($test['payload']['recovered_at'])->gte(now()->subDays($p['drill_days']));

            return $s;
        })->values();
        $site = [];
        foreach (['database', 'storage', 'queue', 'failover', 'environment'] as $component) {
            $r = $all->where('kind', 'replication')->filter(fn ($r) => $r['payload']['component'] === $component)->sortByDesc(fn ($r) => $r['payload']['observed_at'].'|'.($r['status'] !== 'verified' || ! in_array($r['payload']['primary_status'], ['healthy', 'in_sync']) || ! in_array($r['payload']['recovery_status'], ['healthy', 'in_sync']) ? '1' : '0'))->first();
            $fresh = $r && C::parse($r['payload']['observed_at'])->gte(now()->subMinutes($p['observation_minutes']));
            $site[] = ['component' => $component, 'primary' => $r['payload']['primary_status'] ?? 'not_verified', 'recovery' => $r['payload']['recovery_status'] ?? 'not_verified', 'observed_at' => $r['payload']['observed_at'] ?? null, 'reviewed' => $r && $r['status'] === 'verified', 'fresh' => $fresh, 'evidence_id' => $r['id'] ?? null];
        }
        $healthy = collect($site)->every(fn ($r) => $r['fresh'] && $r['reviewed'] && in_array($r['primary'], ['healthy', 'in_sync']) && in_array($r['recovery'], ['healthy', 'in_sync']));
        $currentBackups = collect(['database' => $p['database_minutes'], 'objects' => $p['objects_minutes'], 'transaction_logs' => $p['logs_minutes'], 'configuration' => $p['configuration_minutes'], 'keys' => $p['configuration_minutes']])->map(function ($minutes, $asset) use ($verified) {
            return $verified->where('kind', 'backup')->filter(fn ($r) => $r['payload']['asset_type'] === $asset && $r['payload']['result'] === 'successful' && C::parse($r['payload']['finished_at'])->gte(now()->subMinutes($minutes * 2)))->sortByDesc(fn ($r) => $r['payload']['finished_at'])->first();
        });
        $controls = [
            'Approved backup / recovery policy' => $policy['approved'],
            'Current encrypted backup evidence' => $currentBackups->every(fn ($r) => $r && $r['payload']['encrypted']),
            'Current immutable offsite copy evidence' => $currentBackups->every(fn ($r) => $r && $r['payload']['immutable'] && $r['payload']['offsite']),
            'Current independent restore test' => (bool) $freshDrill,
            'Current reviewed replication evidence' => $healthy,
            'Recorded key custody and retention basis' => $policy['approved'] && $p['key_custody_reference'] !== '' && $p['retention_reference'] !== '',
        ];
        $rpo = $latest ? $this->minutes($latest['payload']['restored_commit_at'], $latest['payload']['source_commit_at']) : null;
        $rto = $latest ? $this->minutes($latest['payload']['outage_at'], $latest['payload']['recovered_at']) : null;
        $today = now('Asia/Manila')->toDateString();
        $todayJobs = DB::table('bcdr_evidence')->where('kind', 'backup')->where('payload->finished_at', '>=', C::parse($today, 'Asia/Manila')->startOfDay()->utc()->toIso8601String())->where('payload->finished_at', '<', C::parse($today, 'Asia/Manila')->addDay()->startOfDay()->utc()->toIso8601String());
        $requests = DB::table('bcdr_requests')->orderByDesc('created_at')->limit(100)->get()->map(fn ($r) => $this->row($r));
        $scheduled = $requests->filter(fn ($r) => $r['kind'] === 'drill' && in_array($r['status'], ['pending_review', 'approved']) && ! empty($r['payload']['scheduled_at']) && C::parse($r['payload']['scheduled_at'])->isFuture())->sortBy(fn ($r) => $r['payload']['scheduled_at'])->first();
        $events = DB::table('eis_audit_events')->where('event', 'like', 'bcdr.%')->orderByDesc('id')->limit($limit)->get(['id', 'event', 'subject_id', 'actor_id', 'occurred_at'])->map(function ($r) {
            $r->occurred_at = C::parse($r->occurred_at, 'UTC')->toIso8601String();

            return $r;
        });

        return ['observed_at' => now()->toIso8601String(), 'evidence_window_limit' => 2000, 'policy' => $policy, 'stats' => [
            'successful_today' => (clone $todayJobs)->where('status', 'verified')->where('payload->result', 'successful')->count(),
            'failed_today' => (clone $todayJobs)->where('payload->result', 'failed')->count(),
            'replication_healthy' => $healthy, 'evidence_score' => (int) round(count(array_filter($controls)) / count($controls) * 100),
            'rpo_minutes' => $rpo, 'rto_minutes' => $rto, 'measurement_current' => (bool) $freshDrill,
            'last_restore_at' => $latest['payload']['recovered_at'] ?? null,
            'last_restore_point' => $latestSnapshot['id'] ?? null,
            'pending_requests' => DB::table('bcdr_requests')->where('status', 'pending_review')->count(),
            'pending_evidence' => DB::table('bcdr_evidence')->where('status', 'pending_review')->count(),
        ], 'controls' => collect($controls)->map(fn ($pass, $label) => ['label' => $label, 'met' => $pass])->values(),
            'site' => $site, 'jobs' => $jobs->take($limit)->values(), 'job_total' => DB::table('bcdr_evidence')->where('kind', 'backup')->count(),
            'snapshots' => $snapshotViews->take($limit)->values(), 'snapshot_total' => DB::table('bcdr_evidence')->where('kind', 'snapshot')->count(),
            'drills' => $drills->take($limit)->values(), 'drill_total' => DB::table('bcdr_evidence')->where('kind', 'drill')->count(),
            'drill_summary' => ['last_verified' => $latest, 'next_scheduled' => $scheduled, 'reviewed_passed' => $verifiedDrills->count(), 'reviewed_total' => $verified->where('kind', 'drill')->filter(fn ($r) => $r['payload']['test_type'] !== 'tabletop')->count()],
            'requests' => $requests->take($limit)->values(), 'events' => $events,
            'pending_evidence' => $all->where('status', 'pending_review')->take($limit)->values(),
            'active_legal_holds' => DB::table('document_legal_holds')->whereNull('released_at')->count(),
            'execution' => ['automatic_backups' => false, 'live_restore' => false, 'live_failover' => false, 'description' => 'Requests and evidence are recorded here. An authorized infrastructure operator performs backup, restore and failover using the approved runbook.'],
        ];
    }

    public function minutes(string $start, string $end): float
    {
        return round(C::parse($start)->diffInSeconds(C::parse($end)) / 60, 2);
    }
}
