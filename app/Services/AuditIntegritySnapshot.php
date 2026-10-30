<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Full chain verification is worker work; browsing a large journal is a page read. */
class AuditIntegritySnapshot
{
    private function key(): string
    {
        return 'audit-integrity:v1:'.hash('sha256', config('database.default').'|'.DB::connection()->getDatabaseName());
    }

    public function verify(): array
    {
        $result = DB::transaction(fn () => [
            'valid' => app(BirCompliance::class)->auditIntegrity(),
            'head' => DB::table('eis_audit_head')->where('id', 1)->value('last_hash'),
            'through_id' => DB::table('eis_audit_events')->max('id'),
            'checked_at' => now()->toIso8601String(),
        ]);
        Cache::put($this->key(), $result, 86400);
        return $result;
    }

    public function status(): array
    {
        // Small installations still get an immediate complete check, bounded at
        // 1,000 events. Larger journals never scan the chain in a page request.
        if (!DB::table('eis_audit_events')->orderBy('id')->offset(1000)->limit(1)->exists()) {
            $result = $this->verify();
        } else {
            $result = Cache::get($this->key());
        }
        if (!$result) {
            return ['integrity' => null, 'state' => 'pending', 'checked_at' => null, 'through_id' => null];
        }
        $sameHead = $result['head'] === DB::table('eis_audit_head')->where('id', 1)->value('last_hash');
        $fresh = \Carbon\CarbonImmutable::parse($result['checked_at'])->gte(now()->subMinutes(10));
        return ['integrity' => !$result['valid'] ? false : ($sameHead && $fresh ? true : null),
            'state' => !$result['valid'] ? 'failed' : ($sameHead && $fresh ? 'checked' : 'pending'),
            'checked_at' => $result['checked_at'], 'through_id' => $result['through_id']];
    }
}
