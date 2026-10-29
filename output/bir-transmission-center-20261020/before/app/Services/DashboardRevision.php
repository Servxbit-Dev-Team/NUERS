<?php

namespace App\Services;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** A change hint only. Each data endpoint still authenticates and scopes its reads. */
class DashboardRevision
{
    private bool $publishing = false;
    private bool $dirty = false;

    private function key(): string
    {
        return 'dashboard-revision:v1:'.hash('sha256', DB::connection()->getDatabaseName());
    }

    public function changed(): void
    {
        if ($this->publishing) return;
        $this->publishing = true;
        try {
            Cache::put($this->key(), (string) Str::uuid(), 86400);
        } finally {
            $this->publishing = false;
        }
    }

    public function publishIfChanged(): void
    {
        if (!$this->dirty) return;
        $this->dirty = false;
        $this->changed();
    }

    /** Worker changes (mail/export/telemetry) are published only after commit. */
    public function observe(QueryExecuted $event): void
    {
        if ($this->publishing) return;
        if (!preg_match('/^\s*(?:insert(?:\s+or\s+\w+|\s+ignore)?\s+into|update|delete\s+from)\s+[`"]?([a-z_]+)/i', $event->sql, $match)) return;
        if (in_array(strtolower($match[1]), ['cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'migrations', 'ledger_documents', 'ledger_dirty_documents', 'ledger_projection_state'], true)) return;
        if (!app()->runningInConsole()) {
            $this->dirty = true;
            return;
        }
        $event->connection->afterCommit(fn () => $this->changed());
    }

    public function token(string $actor): string
    {
        // These are primary-key/end-of-index reads, independent of ledger size.
        $ledger = Schema::hasTable('ledger_projection_state')
            ? DB::table('ledger_projection_state')->where('id', 1)->value('revision') : null;
        $dirty = Schema::hasTable('ledger_dirty_documents')
            ? DB::table('ledger_dirty_documents')->orderByDesc('sequence')->value('sequence') : null;
        // Reconcile clocks, expiry and changes made outside the application each minute.
        $value = [Cache::get($this->key(), 'initial'), $ledger, $dirty, intdiv(now()->timestamp, 60), $actor];
        return hash_hmac('sha256', json_encode($value, JSON_THROW_ON_ERROR), config('app.key'));
    }
}
