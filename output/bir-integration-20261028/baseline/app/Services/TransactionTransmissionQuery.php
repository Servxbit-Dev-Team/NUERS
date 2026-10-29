<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Identical evidence selection for indexed validation and transaction views. */
class TransactionTransmissionQuery
{
    public function enrich(Builder $documents): Builder
    {
        $q = DB::query()->fromSub($documents, 'd');
        $hasEis = Schema::hasTable('eis_transmissions') && Schema::hasTable('eis_invoice_payloads') && Schema::hasTable('eis_acknowledgements') && DB::table('eis_transmissions')->exists();
        if ($hasEis) {
            $invoiceLink = Schema::hasColumn('eis_invoice_payloads', 'source_document_id') ? " OR (d.document_source='invoice' AND ep.source_document_type='invoice' AND ep.source_document_id=d.document_id)" : '';
            $acceptedSort = config('bir_compliance.transport_implemented') && Schema::hasColumn('eis_acknowledgements', 'verified_at')
                ? "CASE WHEN et.status IN ('acknowledged','accepted','success') AND EXISTS (SELECT 1 FROM eis_acknowledgements va WHERE va.transmission_id=et.id AND va.verified_at IS NOT NULL AND va.verified_response_hash IS NOT NULL) THEN 0 ELSE 1 END"
                : 'CASE WHEN 1=1 THEN 1 ELSE 1 END';
            $latest = "(SELECT et.id FROM eis_transmissions et JOIN eis_invoice_payloads ep ON ep.id=et.payload_id WHERE ((d.document_source='receipt' AND ep.receipt_id=d.document_id)$invoiceLink) AND et.merchant_id=d.merchant_id ORDER BY CASE WHEN et.environment='production' THEN 0 ELSE 1 END, $acceptedSort,et.created_at DESC,et.id DESC LIMIT 1)";
            $q->leftJoin('eis_transmissions as e', fn ($j) => $j->on('e.id', '=', DB::raw($latest)));
            $q->leftJoin('eis_acknowledgements as a', fn ($j) => $j->on('a.id', '=', DB::raw('(SELECT ea.id FROM eis_acknowledgements ea WHERE ea.transmission_id=e.id ORDER BY ea.received_at DESC,ea.id DESC LIMIT 1)')));
            $verified = config('bir_compliance.transport_implemented') && Schema::hasColumn('eis_acknowledgements', 'verified_at') ? 'a.verified_at IS NOT NULL AND a.verified_response_hash IS NOT NULL' : '0=1';
            $q->selectRaw('d.*')->addSelect(['e.id as transmission_id', 'e.status as transmission_status', 'e.environment', 'e.submitted_at', 'e.acknowledged_at', 'e.due_at', 'e.recovery_due_at', 'e.incident_id', 'e.response_code', 'e.response_message', 'e.attempt_number', 'e.max_attempts', 'e.next_retry_at', 'e.payload_hash', 'a.acknowledgement_number', 'a.bir_reference_number', 'a.received_at as acknowledgement_received_at']);
            $q->selectRaw("CASE WHEN e.id IS NULL THEN 'not_submitted' WHEN e.environment<>'production' THEN 'test' WHEN e.status IN ('queued','pending','processing','retry_scheduled','submitted','transmitting') THEN 'processing' WHEN ($verified) AND e.status IN ('acknowledged','accepted','success') AND NULLIF(a.acknowledgement_number,'') IS NOT NULL AND NULLIF(a.bir_reference_number,'') IS NOT NULL AND a.raw_response IS NOT NULL THEN 'acknowledged' WHEN ($verified) AND e.status IN ('rejected','failed') THEN 'rejected' ELSE 'unverified' END as bir_status");
        } else {
            $q->selectRaw("d.*,NULL as transmission_id,NULL as transmission_status,NULL as environment,NULL as submitted_at,NULL as acknowledged_at,NULL as due_at,NULL as recovery_due_at,NULL as incident_id,NULL as response_code,NULL as response_message,NULL as attempt_number,NULL as max_attempts,NULL as next_retry_at,NULL as payload_hash,NULL as acknowledgement_number,NULL as bir_reference_number,NULL as acknowledgement_received_at,'not_submitted' as bir_status");
        }

        return DB::query()->fromSub($q, 'ledger');
    }
}
