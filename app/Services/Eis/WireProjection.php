<?php

namespace App\Services\Eis;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Publish only company-bound production evidence into the existing reporting views. */
class WireProjection
{
    public function sync(string $batchId, array $result = []): void
    {
        $b = DB::table('bir_eis_batches')->find($batchId);
        if (! $b || $b->environment !== 'production') {
            return;
        }
        foreach (DB::table('bir_eis_documents')->where('batch_id', $b->id)->get()->groupBy('outbox_id') as $outbox => $docs) {
            $o = DB::table('eis_document_outbox')->where('id', $outbox)->where('merchant_id', $b->merchant_id)->first();
            if (! $o) {
                throw new \RuntimeException('BIR result source is outside this company.');
            }
            $codes = $docs->pluck('result_code');
            $terminal = in_array($b->state, ['accepted', 'rejected', 'partial'], true);
            $state = $terminal ? ($codes->every(fn ($c) => $c === 'SUC001') ? 'accepted' : ($codes->contains('SUC001') ? 'uncertain' : 'rejected')) : match ($b->state) {
                'queued' => 'queued','sending' => 'sending','processing' => 'sending',default => 'uncertain'
            };
            DB::table('eis_delivery_jobs')->where('outbox_id', $outbox)->where('merchant_id', $b->merchant_id)->update(['state' => $state, 'attempts' => $b->attempts, 'provider_reference' => $b->ack_id, 'completed_at' => $terminal ? now() : null, 'last_error' => $state === 'uncertain' ? 'Reconcile the individual BIR results before further action.' : $b->last_error, 'updated_at' => now()]);
            $pointer = Protocol::json(['wire_batch_id' => $b->id, 'outbox_id' => $outbox, 'invoice_uids' => $docs->pluck('invoice_uid')->all()]);
            DB::table('eis_invoice_payloads')->updateOrInsert(['id' => $outbox], ['merchant_id' => $b->merchant_id, 'receipt_id' => $o->document_source === 'receipt' ? $o->document_id : null, 'source_document_type' => $o->document_source, 'source_document_id' => $o->document_id, 'invoice_number' => $docs->first()->document_number, 'document_type' => 'invoice', 'payload' => $pointer, 'payload_hash' => $o->document_hash, 'payload_size' => strlen($pointer), 'source' => 'bir_eis_wire', 'validation_status' => 'valid', 'status' => $state, 'created_at' => $o->created_at, 'updated_at' => now()]);
            $t = DB::table('eis_transmissions')->where('request_id', $b->id)->where('payload_id', $outbox)->where('transmission_type', 'bir_eis_wire')->first();
            $tid = $t?->id ?? (string) Str::uuid();
            DB::table('eis_transmissions')->updateOrInsert(['id' => $tid], ['merchant_id' => $b->merchant_id, 'payload_id' => $outbox, 'transmission_type' => 'bir_eis_wire', 'status' => $state === 'accepted' ? 'acknowledged' : ($state === 'sending' ? 'processing' : $state), 'invoice_count' => 1, 'payload_hash' => $o->document_hash, 'bir_reference_number' => $b->submit_id, 'bir_acknowledgement' => $b->ack_id, 'response_code' => $terminal ? ($state === 'accepted' ? 'SUC001' : ($state === 'uncertain' ? 'PARTIAL' : $codes->first())) : null, 'response_message' => $terminal ? 'Final authenticated BIR inquiry; inspect tax-class result evidence.' : $b->last_error, 'attempt_number' => min(255, $b->attempts), 'submitted_at' => $b->submitted_at, 'acknowledged_at' => $state === 'accepted' ? $b->completed_at : null, 'environment' => 'production', 'api_version' => '2.3', 'request_id' => $b->id, 'created_at' => $t?->created_at ?? now(), 'updated_at' => now()]);
            if ($terminal && isset($result['attempt_id'],$result['response_hash'])) {
                $proof = Protocol::json(['wire_batch_id' => $b->id, 'outbox_id' => $outbox, 'invoice_uids' => $docs->pluck('invoice_uid')->all(), 'inquiry_attempt_id' => $result['attempt_id'], 'response_sha256' => $result['response_hash']]);
                DB::table('eis_acknowledgements')->insert(['id' => (string) Str::uuid(), 'transmission_id' => $tid, 'bir_reference_number' => $b->submit_id, 'acknowledgement_number' => $b->ack_id, 'response_code' => $state === 'accepted' ? 'SUC001' : ($state === 'uncertain' ? 'PARTIAL' : 'REJECTED'), 'response_message' => 'Authenticated inquiry evidence; encrypted response retained in BIR wire audit.', 'raw_response' => $proof, 'verified_at' => now(), 'verified_response_hash' => hash('sha256', $proof), 'received_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }
}
