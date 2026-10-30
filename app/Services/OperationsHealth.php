<?php

namespace App\Services;

use App\Services\Infrastructure\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Private observations only. No customer payloads, credentials, probes to external hosts or BIR calls. */
class OperationsHealth
{
    public function record(string $id, array $payload): void
    {
        DB::table('operations_observations')->updateOrInsert(['id' => $id], [
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'observed_at' => now(),
        ]);
    }

    public function observation(string $id, int $maxAge): array
    {
        $row = Schema::hasTable('operations_observations') ? DB::table('operations_observations')->where('id', $id)->first() : null;
        $age = $row ? (int) CarbonImmutable::parse($row->observed_at, config('app.timezone'))->diffInSeconds(now(), false) : null;

        return ['observed_at' => $row ? CarbonImmutable::parse($row->observed_at, config('app.timezone'))->toIso8601String() : null,
            'fresh' => $age !== null && $age >= -30 && $age <= $maxAge,
            'age_seconds' => $age, 'data' => $row ? json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR) : null];
    }

    public function collect(): array
    {
        $checks = [];
        $check = function (string $id, string $name, bool $pass, string $detail, bool $critical = false) use (&$checks) {
            $checks[] = compact('id', 'name', 'detail', 'critical') + ['state' => $pass ? 'observed' : 'action_required'];
        };
        $check('database', 'Database connection', (int) DB::selectOne('SELECT 1 AS healthy')->healthy === 1, 'Application database query completed.', true);
        $key = 'nuers-ops-probe:'.Str::uuid();
        $cacheOk = false;
        try {
            $lock = Cache::lock($key.':lock', 10);
            if ($lock->get()) {
                try {
                    Cache::put($key, $key, 30);
                    $cacheOk = hash_equals($key, (string) Cache::get($key));
                } finally {
                    Cache::forget($key);
                    $lock->release();
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
        $check('cache', 'Shared cache and locking', $cacheOk && in_array(config('cache.default'), ['database', 'redis'], true), 'Read/write and lock probe; configured store: '.config('cache.default').'.', true);
        $root = storage_path('app/private');
        $probe = $root.'/.ops-'.Str::uuid();
        $storageOk = false;
        try {
            if (! is_dir($root)) {
                mkdir($root, 0700, true);
            }
            $h = @fopen($probe, 'x+b');
            if ($h) {
                chmod($probe, 0600);
                $payload = random_bytes(32);
                $written = fwrite($h, $payload);
                fflush($h);
                rewind($h);
                $storageOk = $written === 32 && hash_equals($payload, fread($h, 32));
                fclose($h);
            }
        } finally {
            if (is_file($probe)) {
                unlink($probe);
            }
        }
        $free = @disk_free_space($root);
        $check('storage', 'Private storage', $storageOk && $free !== false && $free >= config('operations.minimum_free_bytes'), $free === false ? 'Free capacity could not be measured.' : round($free / 1024 ** 3, 2).' GiB free; private read/write probe '.($storageOk ? 'passed.' : 'failed.'), true);
        $check('debug', 'Production debug', ! config('app.debug'), config('app.debug') ? 'Debug must be disabled.' : 'Debug disabled.', true);
        $check('mfa', 'Privileged MFA', config('security.enforce_privileged_mfa') === true, 'Login-session MFA enforcement.', true);
        $check('https', 'HTTPS application URL', parse_url(config('app.url'), PHP_URL_SCHEME) === 'https', 'Configured public application scheme; origin TLS is assessed separately.', true);
        $secret = (string) config('app.key');
        $rawKey = str_starts_with($secret, 'base64:') ? base64_decode(substr($secret, 7), true) : $secret;
        $check('encryption', 'Application encryption key', is_string($rawKey) && strlen($rawKey) === 32, 'Key length check only; secret values are never included in this report.', true);
        $check('queue', 'Durable queue configuration', in_array(config('queue.default'), ['database', 'redis', 'sqs'], true), 'Configured queue: '.config('queue.default').'.');
        $backup = $this->observation('backup', config('operations.backup.max_age_hours') * 3600);
        $check(
            'backup',
            'Encrypted local recovery capture',
            config('operations.backup.enabled') && $backup['fresh'],
            'Requires enabled capture and a recent successful archive. Offsite custody and restore validation are separate controls.'
        );
        $check(
            'audit_checkpoint',
            'Audit checkpoint signing key',
            strlen((string) config('security.audit_checkpoint_key')) >= 32,
            'An independent signing key is required; external custody must be verified separately.'
        );

        $workers = [];
        foreach ([
            ['mail', 'Transactional email worker', 'notification_runtime', 'worker_seen_at', 1, (bool) config('notifications.dispatch_enabled')],
            ['webhooks', 'Merchant webhook worker', 'merchant_webhook_runtime', 'last_seen_at', 'dispatcher', (bool) config('security.merchant_webhook_delivery_enabled')],
        ] as [$id, $name, $table, $column, $rowId, $enabled]) {
            $at = Schema::hasTable($table) ? DB::table($table)->where('id', $rowId)->value($column) : null;
            $age = $at ? (int) CarbonImmutable::parse($at, config('app.timezone'))->diffInSeconds(now(), false) : null;
            $fresh = $age !== null && $age >= -30 && $age <= config('operations.worker_stale_seconds');
            $workers[] = ['id' => $id, 'name' => $name, 'enabled' => $enabled, 'state' => ! $enabled ? 'paused' : ($fresh ? 'observed' : 'stale'),
                'last_seen_at' => $at ? CarbonImmutable::parse($at, config('app.timezone'))->toIso8601String() : null];
            $check('worker_'.$id, $name, $enabled && $fresh, ! $enabled ? 'Delivery is paused in deployment configuration.' : ($fresh ? 'Recent worker heartbeat observed; receiver delivery is a separate result.' : 'No recent worker heartbeat. Check the scheduler and worker logs.'));
        }
        $queues = [];
        foreach ([['mail', 'notification_outbox', ['queued'], ['failed', 'uncertain']], ['webhooks', 'merchant_webhook_deliveries', ['pending', 'retry'], ['failed', 'dead_letter']]] as [$id, $table, $pending, $failed]) {
            $q = DB::table($table);
            $due = (clone $q)->whereIn('state', $pending)->where('available_at', '<=', now());
            $oldest = (clone $due)->min('available_at');
            $age = $oldest ? max(0, (int) CarbonImmutable::parse($oldest, config('app.timezone'))->diffInSeconds(now(), false)) : 0;
            $bad = (clone $q)->whereIn('state', $failed)->where('updated_at', '>=', now()->subDay())->count();
            $queues[] = ['id' => $id, 'due' => (clone $due)->count(), 'oldest_due_seconds' => $age, 'attention_last_24h' => $bad];
            $check('queue_'.$id, ucfirst($id).' delivery backlog', $age <= config('operations.queue_warning_seconds') && $bad === 0, "Oldest due work: {$age}s. Failed or uncertain outcomes in 24h: {$bad}. Review recorded outcomes before retrying.");
        }
        $incomplete = DB::table('integration_inbox')->whereNull('response')->where('created_at', '<', now()->subMinutes(5))->count();
        $check('intake', 'Merchant intake commits', $incomplete === 0, "{$incomplete} incomplete intake records older than five minutes.", true);
        // Intake uses physically separate Live and Sandbox tables, not an environment column.
        $counts = [['environment' => 'live', 'total' => DB::table('integration_inbox')->count()],
            ['environment' => 'sandbox', 'total' => DB::table('integration_sandbox_events')->count()]];
        $snapshot = ['mode' => 'nuers_storage', 'bir_transmission' => 'deferred', 'checks' => $checks, 'workers' => $workers, 'queues' => $queues, 'intake_records' => $counts,
            'acceptance_ready' => ! collect($checks)->contains(fn ($c) => $c['critical'] && $c['state'] !== 'observed')];
        $this->record('runtime', $snapshot);
        $this->notify($checks);

        return $snapshot;
    }

    public function integrity(): array
    {
        // Set the next transaction only; do not change pooled session isolation permanently.
        if (DB::connection()->getDriverName() === 'mysql' && DB::transactionLevel() === 0) {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
        // Consistent reads give the event stream and its head one snapshot during concurrent writes.
        $result = DB::transaction(function () {
            $audit = app(BirCompliance::class)->auditIntegrity();
            $mismatches = DB::table('merchant_wallet_entries as e')->leftJoin('merchant_wallets as w', 'e.merchant_id', '=', 'w.merchant_id')->whereNull('w.merchant_id')->distinct()->count('e.merchant_id');
            foreach (DB::table('merchant_wallets')->orderBy('merchant_id')->cursor() as $wallet) {
                if (! app(MerchantWallet::class)->audit($wallet->merchant_id)['valid']) {
                    $mismatches++;
                }
            }
            $infrastructure = app(Audit::class)->verify();

            return ['audit_chain_valid' => $audit, 'infrastructure_audit_valid' => $infrastructure,
                'wallet_mismatches' => $mismatches, 'valid' => $audit && $infrastructure && $mismatches === 0];
        });
        $this->record('integrity', $result);
        $this->notify([['id' => 'integrity', 'name' => 'Stored record integrity', 'state' => $result['valid'] ? 'observed' : 'action_required', 'detail' => 'Audit-chain and wallet-ledger reconciliation. Review discrepancies; this check never changes financial records.', 'critical' => true]]);

        return $result;
    }

    private function notify(array $checks): void
    {
        foreach ($checks as $c) {
            $key = hash('sha256', 'operations-health|'.$c['id'].'|'.now()->toDateString());
            if ($c['state'] !== 'observed') {
                DB::table('operational_notifications')->insertOrIgnore(['id' => (string) Str::uuid(), 'dedupe_key' => $key, 'category' => 'platform_health', 'subject_id' => $c['id'], 'severity' => $c['critical'] ? 'critical' : 'high', 'message' => $c['name'].': '.$c['detail'], 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function report(): array
    {
        return ['mode' => 'nuers_storage', 'bir_transmission' => 'deferred',
            'runtime' => $this->observation('runtime', config('operations.stale_seconds')),
            'integrity' => $this->observation('integrity', config('operations.integrity_stale_seconds')),
            'backup' => $this->observation('backup', config('operations.backup.max_age_hours') * 3600),
            'recovery_test' => $this->observation('recovery_test', 30 * 86400),
            'offsite_storage' => 'not_provisioned', 'failover' => 'not_provisioned'];
    }
}
