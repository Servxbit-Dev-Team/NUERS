<?php

namespace App\Services\Infrastructure;

use Illuminate\Support\Facades\DB;

class Audit
{
    // No update/delete API. Operational DB admin access still requires WORM checkpoints.
    public function record(string $event, ?string $subject, string $actor, array $details = []): void
    {
        DB::transaction(function () use ($event, $subject, $actor, $details) {
            $head = DB::table('infra_state')->where('id', 1)->lockForUpdate()->first();
            $at = now('UTC')->format('Y-m-d\TH:i:s.u\Z');
            if (! app()->runningInConsole()) {
                $details['request_context'] = ['source_ip' => request()->ip(), 'device_hash' => hash('sha256', (string) request()->header('X-NUERS-Device'))];
            }
            $json = json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $hash = hash('sha256', json_encode([$head->audit_head, $event, $subject, $actor, $json, $at], JSON_THROW_ON_ERROR));
            DB::table('infra_events')->insert(['event' => $event, 'subject_id' => $subject, 'actor_id' => $actor, 'details' => $json, 'occurred_at' => $at, 'previous_hash' => $head->audit_head, 'entry_hash' => $hash]);
            DB::table('infra_state')->where('id', 1)->update(['audit_head' => $hash]);
        });
    }

    public function verify(): bool
    {
        $previous = str_repeat('0', 64);
        foreach (DB::table('infra_events')->orderBy('id')->cursor() as $e) {
            $hash = hash('sha256', json_encode([$previous, $e->event, $e->subject_id, $e->actor_id, $e->details, $e->occurred_at], JSON_THROW_ON_ERROR));
            if ($previous !== $e->previous_hash || ! hash_equals($hash, $e->entry_hash)) {
                return false;
            }
            $previous = $hash;
        }

        return hash_equals($previous, DB::table('infra_state')->where('id', 1)->value('audit_head'));
    }
}
