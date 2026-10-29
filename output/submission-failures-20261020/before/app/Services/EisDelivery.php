<?php

namespace App\Services;

use App\Contracts\EisProvider;
use App\Services\Eis\BlockedProvider;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Durable state machine. Network calls happen outside locks; expired sends require inquiry, never blind replay. */
class EisDelivery
{
    public function __construct(private ?EisProvider $provider = null)
    {
        $this->provider ??= new BlockedProvider;
    }

    public function enqueue(string $outboxId): string
    {
        $o = DB::table('eis_document_outbox')->where('id', $outboxId)->first();
        abort_unless($o, 404);
        $id = (string) Str::uuid();
        DB::table('eis_delivery_jobs')->insertOrIgnore(['id' => $id, 'outbox_id' => $outboxId, 'merchant_id' => $o->merchant_id, 'state' => 'blocked', 'available_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('eis_delivery_jobs')->where('outbox_id', $outboxId)->value('id');
    }

    public function runOne(): ?array
    {
        // Default provider cannot be enabled by changing a database flag or environment toggle.
        if (! $this->provider->ready()) {
            return ['state' => 'blocked', 'reason' => 'An authorized interface, verified adapter and authentic-response verification are required.'];
        }
        $claim = DB::transaction(function () {
            $q = DB::table('eis_delivery_jobs')->where(function ($q) {
                $q->whereIn('state', ['blocked', 'queued', 'retry', 'uncertain'])->orWhere(fn ($q) => $q->where('state', 'sending')->where('lease_until', '<', now()));
            })->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<', now()))->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now()))
                ->orderBy('updated_at')->orderBy('id')->lockForUpdate()->first();
            if (! $q) {
                return null;
            }
            $o = DB::table('eis_document_outbox')->where('id', $q->outbox_id)->first();
            $lease = (string) Str::uuid();
            $operation = in_array($q->state, ['uncertain', 'sending'], true) ? 'inquiry' : 'send';
            DB::table('eis_delivery_jobs')->where('id', $q->id)->update(['state' => 'sending', 'lease_token' => $lease, 'lease_until' => now()->addMinutes(2), 'updated_at' => now()]);

            return [$q, $o, $lease, $operation];
        }, 3);
        if (! $claim) {
            return null;
        }
        [$j,$o,$lease,$operation] = $claim;
        $attempt = (string) Str::uuid();
        try {
            $document = json_decode(Crypt::decryptString($o->encrypted_document), true);
            // Imported originals must never be submitted again by a future enabled adapter.
            if (($document['record_mode'] ?? null) === 'source_capture') {
                $this->settle($j->id, $lease, ['state' => 'blocked', 'last_error' => 'External source copy requires separate reviewed reporting coverage and duplicate-reporting reconciliation.', 'available_at' => now()->addMinutes(5)]);
                return ['state' => 'blocked'];
            }
            $gate = app(BirCompliance::class)->gate(app(BirCompliance::class)->integration($j->merchant_id, $document['seller']['branch_code'] ?? null, 'production'));
            if (! $gate['can_transmit']) {
                $this->settle($j->id, $lease, ['state' => 'blocked', 'last_error' => implode(' ', $gate['blockers']), 'available_at' => now()->addMinutes(5)]);

                return ['state' => 'blocked'];
            }
            $raw = Crypt::decryptString($o->encrypted_document);
            if (! hash_equals($o->document_hash, hash('sha256', $raw))) {
                $this->settle($j->id, $lease, ['state' => 'dead_letter', 'last_error' => 'Prepared payload integrity failure']);

                return ['state' => 'dead_letter'];
            }
            $current = app(EisDocumentReadiness::class)->inspect($o->document_source, $o->document_id);
            if (! $current['local_validation_passed'] || ! hash_equals($o->document_hash, $current['document_hash'])) {
                $this->settle($j->id, $lease, ['state' => 'blocked', 'last_error' => 'Current source review or authority does not match the immutable preparation.', 'available_at' => now()->addMinutes(5)]);

                return ['state' => 'blocked'];
            }
            if ($j->attempts >= 8) {
                $this->settle($j->id, $lease, ['state' => 'dead_letter', 'last_error' => 'Delivery/inquiry retry budget exhausted. Preserve the uncertain outcome for manual reconciliation; never blind replay.']);

                return ['state' => 'dead_letter'];
            }
            // Persist intent before the provider can observe a request. A crash here is ambiguous and needs inquiry.
            DB::table('eis_delivery_attempts')->insert(['id' => $attempt, 'job_id' => $j->id, 'lease_token' => $lease, 'operation' => $operation, 'outcome' => 'uncertain', 'payload_hash' => $o->document_hash, 'started_at' => now()]);
            DB::table('eis_delivery_jobs')->where('id', $j->id)->where('lease_token', $lease)->increment('attempts');
            $key = 'nuers:'.$o->id.':'.$o->document_hash;
            $result = $operation === 'inquiry' ? $this->provider->inquire($key) : $this->provider->send($key, $raw);
            $outcome = $result['outcome'] ?? 'uncertain';
            $authenticated = ($result['authenticated'] ?? false) === true;
            // Retry permission after inquiry is an explicit provider guarantee, not an HTTP status interpretation.
            if (in_array($outcome, ['accepted', 'rejected'], true) && (! $authenticated || empty($result['reference']) || empty($result['raw_response']))) {
                $outcome = 'uncertain';
            }
            if ($operation === 'inquiry' && $outcome === 'not_found' && (! $authenticated || ! ($result['safe_to_retry'] ?? false))) {
                $outcome = 'uncertain';
            }
            $state = match ($outcome) {
                'accepted' => 'accepted','rejected' => 'rejected','not_found' => $operation === 'inquiry' ? 'retry' : 'uncertain','retryable' => $operation === 'send' && ($result['definitely_not_accepted'] ?? false) ? 'retry' : 'uncertain',default => 'uncertain'
            };
            $response = json_encode($result, JSON_THROW_ON_ERROR);
            DB::transaction(function () use ($j, $lease, $attempt, $result, $response, $state, $authenticated, $outcome) {
                $owned = DB::table('eis_delivery_jobs')->where('id', $j->id)->where('lease_token', $lease)->lockForUpdate()->first();
                if (! $owned) {
                    return;
                } // A stale worker cannot publish terminal state over a newer lease.
                DB::table('eis_delivery_attempts')->where('id', $attempt)->update(['outcome' => $outcome, 'authenticated' => $authenticated, 'response_hash' => hash('sha256', $response), 'encrypted_response' => Crypt::encryptString($response), 'provider_reference' => $result['reference'] ?? null, 'finished_at' => now()]);
                $this->settle($j->id, $lease, ['state' => $state, 'provider_reference' => $result['reference'] ?? null, 'completed_at' => in_array($state, ['accepted', 'rejected'], true) ? now() : null, 'available_at' => now()->addSeconds(min(3600, 2 ** min(10, (int) $owned->attempts)) + random_int(1, 30)), 'last_error' => $state === 'uncertain' ? 'Authentic inquiry required before replay.' : null]);
                app(BirCompliance::class)->audit('eis.delivery.'.$state, $j->id, 'worker', ['outbox_id' => $j->outbox_id, 'attempt_id' => $attempt, 'response_hash' => hash('sha256', $response)]);
            });

            return ['state' => $state, 'id' => $j->id];
        } catch (\Throwable $e) {
            $this->settle($j->id, $lease, ['state' => 'uncertain', 'last_error' => 'Worker outcome is uncertain; inquiry required.', 'available_at' => now()->addMinute()]);
            report($e);

            return ['state' => 'uncertain', 'id' => $j->id];
        }
    }

    private function settle(string $id, string $lease, array $data): void
    {
        DB::table('eis_delivery_jobs')->where('id', $id)->where('lease_token', $lease)->update($data + ['lease_token' => null, 'lease_until' => null, 'updated_at' => now()]);
    }
}
