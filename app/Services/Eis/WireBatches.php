<?php

namespace App\Services\Eis;

use App\Services\BirCompliance;
use App\Services\EisDocumentReadiness;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Company-bound durable batches; a lost POST is always reconciled with GET, never replayed. */
class WireBatches
{
    public function blockers(object $p, bool $productionAuthority = true): array
    {
        $b = [];
        if (! config('bir_eis.outbound_enabled')) {
            $b[] = 'Outbound connection is disabled in the server deployment configuration.';
        }
        if (! $p->enabled) {
            $b[] = 'This connection has not been enabled by an administrator.';
        }
        if (! $p->accreditation_id || ! $p->application_id || ! $p->encrypted_credentials) {
            $b[] = 'BIR-issued accreditation, application and credential bundle are required.';
        }
        if (! $productionAuthority) {
            return array_values(array_unique($b));
        }
        if (! $p->key_expires_at || now()->gte(CarbonImmutable::parse($p->key_expires_at, 'UTC'))) {
            $b[] = 'A current BIR signing-key validity date is required.';
        }
        if (! $p->contract_reference) {
            $b[] = 'Confirm the endpoint prefix, schema dialect and result fields against the issued BIR contract.';
        }
        if (! $p->ptu_number || ($p->system_type === 'POS' && (! $p->machine_id || ! $p->machine_serial))) {
            $b[] = 'The source-system registration number and applicable POS identifiers are required.';
        }
        if (! $p->authority_reference) {
            $b[] = 'Record taxpayer authorization / certification-testing evidence.';
        }
        if (DB::table('merchants')->where('id', $p->merchant_id)->value('status') !== 'active') {
            $b[] = 'This merchant is not active.';
        }
        if ($p->environment === 'production' && $productionAuthority) {
            $policy = app(BirCompliance::class);
            $integration = $policy->integration($p->merchant_id, $p->branch_code, 'production');
            $b = array_merge($b, $policy->gate($integration, true, true)['blockers']);
        }

        return array_values(array_unique($b));
    }

    public function prepare(object $p, array $sources, array $metadata, string $requestId, string $actor): array
    {
        abort_unless($p->environment === 'production', 422, 'Certification uses synthetic fixtures. Live merchant documents cannot enter certification batches.');

        return DB::transaction(function () use ($p, $sources, $metadata, $requestId, $actor) {
            $p = DB::table('bir_eis_profiles')->where('id', $p->id)->lockForUpdate()->first();
            $fingerprint = hash('sha256', Protocol::json([$p->id, $sources, $metadata]));
            $existing = DB::table('bir_eis_batches')->where('request_id', $requestId)->first();
            if ($existing) {
                abort_unless($existing->profile_id === $p->id && hash_equals($existing->request_fingerprint, $fingerprint), 409, 'Request identity already used.');

                return ['id' => $existing->id, 'duplicate' => true];
            }
            $records = [];
            $wire = [];
            foreach ($sources as $key) {
                [$source,$id] = array_pad(explode(':', $key, 2), 2, '');
                abort_unless(in_array($source, ['invoice', 'receipt'], true) && DB::table($source === 'invoice' ? 'business_invoices' : 'transaction_receipts')->where('id', $id)->where('merchant_id', $p->merchant_id)->exists(), 422, 'A selected document is outside this company.');
                $current = app(EisDocumentReadiness::class)->inspect($source, $id);
                abort_unless($current['local_validation_passed'], 422, 'Document '.$current['model']['number'].' requires source validation.');
                abort_unless($current['submission_applicability'] === 'required', 422, 'Reviewed reporting coverage is required for each original issue date.');
                $m = $current['model'];
                abort_if(($m['record_mode'] ?? '') === 'source_capture' && (! $p->source_copies_authorized || ! $p->authority_reference), 422, 'Review source-system reporting responsibility and duplicate-reporting reconciliation before transmitting captured originals.');
                $meta = $metadata[$key] ?? [];
                foreach ($meta['previous_uids'] ?? [] as $previous) {
                    abort_unless(DB::table('bir_eis_documents')->where('merchant_id', $p->merchant_id)->where('environment', $p->environment)->where('invoice_uid', $previous)->where('source_id', $m['original_invoice_id'] ?? 'missing-original')->where('result_code', 'SUC001')->exists(), 422, 'Correction reference must be an accepted document belonging to this merchant and environment.');
                }
                $mapped = app(InvoiceMapper::class)->map($m, $p, $meta);
                $outbox = app(EisDocumentReadiness::class)->prepare($source, $id);
                foreach ($mapped as $class => $d) {
                    $identity = hash('sha256', Protocol::json([$p->environment, $p->merchant_id, $source, $id, (string) $class]));
                    abort_if(DB::table('bir_eis_documents')->where('reporting_identity', $identity)->exists(), 409, 'This source/tax class already belongs to a BIR batch. Inspect its result; do not duplicate it.');
                    $wire[] = $d;
                    $records[] = ['outbox_id' => $outbox['id'], 'source' => $source, 'source_id' => $id, 'tax_class' => (string) $class, 'invoice_uid' => $d['EisUniqueId'], 'document_number' => $d['CompInvoiceId'], 'source_hash' => $current['document_hash'], 'reporting_identity' => $identity];
                }
            }
            abort_if(count($wire) < 1 || count($wire) > 100, 422, 'The mapped batch must contain 1–100 BIR invoices. Mixed CAS documents count once per tax class.');

            return $this->save($p, $wire, $records, $requestId, $actor, $fingerprint);
        }, 3);
    }

    public function save(object $p, array $wire, array $records, string $requestId, string $actor, ?string $fingerprint = null): array
    {
        $id = (string) Str::uuid();
        $raw = Protocol::json($wire);
        $submit = $p->accreditation_id.'-'.now('Asia/Manila')->format('Ymd').'-'.bin2hex(random_bytes(6));
        DB::table('bir_eis_batches')->insert(['id' => $id, 'profile_id' => $p->id, 'merchant_id' => $p->merchant_id, 'environment' => $p->environment, 'submit_id' => $submit, 'request_id' => $requestId, 'request_fingerprint' => $fingerprint ?? hash('sha256', Protocol::json([$p->id, 'fixture'])), 'profile_revision' => $p->revision, 'state' => 'prepared', 'document_count' => count($wire), 'payload_hash' => hash('sha256', $raw), 'encrypted_payload' => Crypt::encryptString($raw), 'created_at' => now(), 'updated_at' => now()]);
        foreach ($records as $r) {
            DB::table('bir_eis_documents')->insert($r + ['id' => (string) Str::uuid(), 'batch_id' => $id, 'merchant_id' => $p->merchant_id, 'environment' => $p->environment, 'created_at' => now(), 'updated_at' => now()]);
        }
        app(BirCompliance::class)->audit('bir.wire_batch.prepared', $id, $actor, ['profile_id' => $p->id, 'environment' => $p->environment, 'document_count' => count($wire), 'payload_hash' => hash('sha256', $raw)]);

        return ['id' => $id, 'duplicate' => false];
    }

    public function release(string $id, string $actor): void
    {
        DB::transaction(function () use ($id, $actor) {
            $b = DB::table('bir_eis_batches')->where('id', $id)->lockForUpdate()->first();
            abort_unless($b, 404);
            $p = DB::table('bir_eis_profiles')->where('id', $b->profile_id)->lockForUpdate()->first();
            abort_unless($b->state === 'prepared', 409, 'Only a never-sent prepared batch can be released.');
            abort_unless($b->profile_revision === $p->revision, 409, 'Connection changed. Review or cancel the unsent batch before rebuilding.');
            $blockers = $this->blockers($p);
            abort_if((bool) $blockers, 409, implode(' ', $blockers));
            $this->verifySources($b, $p);
            DB::table('bir_eis_batches')->where('id', $id)->update(['state' => 'queued', 'available_at' => now(), 'updated_at' => now()]);
            app(WireProjection::class)->sync($id);
            app(BirCompliance::class)->audit('bir.wire_batch.released', $id, $actor, ['environment' => $b->environment, 'submit_id' => $b->submit_id, 'document_count' => $b->document_count]);
        }, 3);
    }

    private function verifySources(object $b, object $p): void
    {
        $raw = Crypt::decryptString($b->encrypted_payload);
        if (! hash_equals($b->payload_hash, hash('sha256', $raw))) {
            throw new RuntimeException('Prepared BIR payload integrity check failed.');
        }
        if ($b->environment === 'certification') {
            return;
        }
        $sources = DB::table('bir_eis_documents')->where('batch_id', $b->id)->get()->unique(fn ($d) => $d->source.':'.$d->source_id);
        foreach ($sources as $d) {
            $current = app(EisDocumentReadiness::class)->inspect($d->source, $d->source_id);
            if (! $current['local_validation_passed'] || ! hash_equals($d->source_hash, $current['document_hash']) || $current['submission_applicability'] !== 'required' || $current['model']['seller']['branch_code'] !== $p->branch_code) {
                throw new RuntimeException('A source snapshot, branch or reporting coverage changed. Review the prepared batch.');
            }
            if (($current['model']['record_mode'] ?? '') === 'source_capture' && ! $p->source_copies_authorized) {
                throw new RuntimeException('Captured original reporting responsibility is not authorized.');
            }
        }
    }

    public function runOne(): ?array
    {
        if (! config('bir_eis.outbound_enabled')) {
            return null;
        }
        $claim = DB::transaction(function () {
            $b = DB::table('bir_eis_batches')->whereIn('state', ['queued', 'sending', 'processing', 'uncertain'])->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<', now()))->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now()))->orderBy('available_at')->orderBy('id')->lockForUpdate()->first();
            if (! $b) {
                return null;
            }
            $p = DB::table('bir_eis_profiles')->where('id', $b->profile_id)->first();
            $send = in_array($b->state, ['queued', 'sending'], true) && ! $b->submitted_at;
            // Once a send may have occurred, revoked issuance authority must not prevent safe inquiry.
            $blockers = $this->blockers($p, $send);
            if ($blockers || $p->revision !== $b->profile_revision) {
                DB::table('bir_eis_batches')->where('id', $b->id)->update(['last_error' => substr(implode(' ', $blockers) ?: 'Connection revision changed.', 0, 500), 'available_at' => now()->addMinutes(5)]);

                return ['blocked' => $b->id];
            }
            if ($b->attempts >= 24) {
                DB::table('bir_eis_batches')->where('id', $b->id)->update(['state' => 'reconciliation', 'last_error' => 'Polling budget reached. Preserve the Submit ID and request an inquiry after review.', 'updated_at' => now()]);

                return ['blocked' => $b->id];
            }
            $lease = (string) Str::uuid();
            DB::table('bir_eis_batches')->where('id', $b->id)->update(['state' => $send ? 'sending' : 'processing', 'lease_token' => $lease, 'lease_until' => now()->addMinutes(3), 'updated_at' => now()]);

            return [$b, $p, $lease, $send];
        }, 3);
        if (! $claim) {
            return null;
        }if (isset($claim['blocked'])) {
            return ['state' => 'blocked', 'id' => $claim['blocked']];
        }
        [$b,$p,$lease,$send] = $claim;
        if ($send) {
            try {
                $this->verifySources($b, $p);
            } catch (\Throwable) {
                DB::table('bir_eis_batches')->where('id', $b->id)->where('lease_token', $lease)->update(['state' => 'prepared', 'lease_token' => null, 'lease_until' => null, 'last_error' => 'Source validation changed before sending. Cancel this unsent batch and prepare it again.', 'updated_at' => now()]);

                return ['id' => $b->id, 'state' => 'prepared'];
            }
        }
        try {
            DB::table('bir_eis_batches')->where('id', $b->id)->where('lease_token', $lease)->update(['attempts' => $b->attempts + 1]);
            $client = app(WireClient::class);
            $result = $send ? $client->send($p, $b, json_decode(Crypt::decryptString($b->encrypted_payload), true, 128, JSON_THROW_ON_ERROR)) : $client->inquire($p, $b, DB::table('bir_eis_documents')->where('batch_id', $b->id)->pluck('invoice_uid')->all());
            DB::transaction(function () use ($b, $lease, $result) {
                if (! DB::table('bir_eis_batches')->where('id', $b->id)->where('lease_token', $lease)->lockForUpdate()->exists()) {
                    return;
                }
                $terminal = in_array($result['state'], ['accepted', 'rejected', 'partial'], true);
                foreach ($result['documents'] ?? [] as $d) {
                    DB::table('bir_eis_documents')->where('batch_id', $b->id)->where('invoice_uid', $d['invoiceUid'])->update(['result_code' => $d['resultStatusCode'], 'description' => mb_substr((string) ($d['description'] ?? ''), 0, 1000), 'updated_at' => now()]);
                }
                DB::table('bir_eis_batches')->where('id', $b->id)->update(['state' => $result['state'], 'ack_id' => $result['ack_id'] ?? $b->ack_id, 'error_code' => $result['error_code'] ?? null, 'last_error' => $result['error_code'] ?? null, 'completed_at' => $terminal ? now() : null, 'lease_token' => null, 'lease_until' => null, 'available_at' => now()->addSeconds(min(3600, 60 * (2 ** min(6, (int) $b->attempts)))), 'updated_at' => now()]);
                app(WireProjection::class)->sync($b->id, $result);
                app(BirCompliance::class)->audit('bir.wire_batch.'.$result['state'], $b->id, 'worker', ['environment' => $b->environment, 'submit_id' => $b->submit_id, 'ack_id' => $result['ack_id'] ?? null]);
            }, 3);

            return ['id' => $b->id, 'state' => $result['state']];
        } catch (\Throwable $e) {
            $sent = (bool) DB::table('bir_eis_batches')->where('id',$b->id)->value('submitted_at');
            DB::table('bir_eis_batches')->where('id',$b->id)->where('lease_token',$lease)->update(['state' => $sent ? 'uncertain' : 'prepared', 'lease_token' => null, 'lease_until' => null, 'available_at' => now()->addMinutes(2), 'last_error' => $sent ? 'Outcome needs inquiry. Review configuration and the preserved Submit ID; do not resubmit.' : 'Authentication or envelope preparation failed before invoice submission. Verify credentials and review this unsent batch.', 'updated_at' => now()]);

            return ['id' => $b->id, 'state' => $sent ? 'uncertain' : 'prepared'];
        }
    }
}
