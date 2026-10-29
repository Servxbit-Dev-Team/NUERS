<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** All production acceptance readers share the same fail-closed evidence predicate. */
class SubmissionEvidence
{
    public function verified(string $transmission): bool
    {
        if (Schema::hasTable('bir_eis_batches') && DB::table('eis_transmissions')->where('id',$transmission)->where('transmission_type','bir_eis_wire')->exists()) return $this->wireVerified($transmission);
        if (! config('bir_compliance.transport_implemented') || ! config('bir_compliance.official_interface_verified') || ! config('bir_compliance.production_response_verification_implemented') || ! Schema::hasColumn('eis_acknowledgements', 'verified_at')) {
            return false;
        }
        $t = DB::table('eis_transmissions')->where('id', $transmission)->where('environment', 'production')->whereIn('status', ['accepted', 'acknowledged', 'success'])->first();
        if (! $t || ! $t->submitted_at || ! $t->bir_reference_number || str_starts_with($t->bir_reference_number, 'CERT-')) {
            return false;
        }
        foreach (DB::table('eis_acknowledgements')->where('transmission_id', $transmission)->whereNotNull('verified_at')->cursor() as $a) {
            if (! $a->acknowledgement_number || ! $a->raw_response || ! $a->verified_response_hash || $a->bir_reference_number !== $t->bir_reference_number) {
                continue;
            }
            if (hash_equals($a->verified_response_hash, hash('sha256', $a->raw_response))) {
                return true;
            }
        }

        return false;
    }

    private function wireVerified(string $id): bool
    {
        $t=DB::table('eis_transmissions')->where('id',$id)->where('environment','production')->where('status','acknowledged')->first();
        if(!$t || !$t->submitted_at)return false;
        $b=DB::table('bir_eis_batches')->where('id',$t->request_id)->where('merchant_id',$t->merchant_id)->where('environment','production')->whereIn('state',['accepted','partial'])->first();
        if(!$b || !$b->completed_at || $b->submit_id!==$t->bir_reference_number)return false;
        $docs=DB::table('bir_eis_documents')->where('batch_id',$b->id)->where('outbox_id',$t->payload_id)->where('merchant_id',$t->merchant_id)->get();
        if($docs->isEmpty() || !$docs->every(fn($d)=>$d->result_code==='SUC001'))return false;
        foreach(DB::table('eis_acknowledgements')->where('transmission_id',$id)->whereNotNull('verified_at')->cursor() as $a){
            try{
                if(!$a->verified_response_hash || !hash_equals($a->verified_response_hash,hash('sha256',$a->raw_response)))continue;
                $proof=json_decode($a->raw_response,true,16,JSON_THROW_ON_ERROR);
                if(($proof['wire_batch_id']??null)!==$b->id || ($proof['outbox_id']??null)!==$t->payload_id)continue;
                $attempt=DB::table('bir_eis_wire_attempts')->where('id',$proof['inquiry_attempt_id'])->where('batch_id',$b->id)->where('profile_id',$b->profile_id)->where('operation','inquiry')->where('http_status',200)->first();
                if(!$attempt || !$attempt->encrypted_response || $attempt->response_hash!==$proof['response_sha256'])continue;
                $raw=\Illuminate\Support\Facades\Crypt::decryptString($attempt->encrypted_response);
                if(!hash_equals($attempt->response_hash,hash('sha256',$raw)))continue;
                $response=json_decode($raw,true,64,JSON_THROW_ON_ERROR);if(($response['status']??null)!=='1')continue;
                $profile=DB::table('bir_eis_profiles')->find($b->profile_id);
                $v=app(\App\Services\Eis\ResultVerifier::class)->verify($response['data'],(object)['accreditation_id'=>$profile->accreditation_id,'user_id'=>$response['data']['userId']],$b->submit_id,DB::table('bir_eis_documents')->where('batch_id',$b->id)->pluck('invoice_uid')->all(),$b->ack_id);
                $accepted=collect($v['documents'])->where('resultStatusCode','SUC001')->pluck('invoiceUid')->all();
                if(!array_diff($docs->pluck('invoice_uid')->all(),$accepted))return true;
            }catch(\Throwable){continue;}
        }
        return false;
    }

    /** SQL readers use the immutable, authenticated result projection; detailed review rechecks ciphertext hashes. */
    public static function wireSql(string $alias): string
    {
        if(!Schema::hasTable('bir_eis_batches'))return '0=1';
        return "($alias.transmission_type='bir_eis_wire' AND EXISTS (SELECT 1 FROM bir_eis_batches wb WHERE wb.id=$alias.request_id AND wb.merchant_id=$alias.merchant_id AND wb.environment='production' AND wb.completed_at IS NOT NULL AND wb.state IN ('accepted','partial','rejected')) AND EXISTS (SELECT 1 FROM bir_eis_documents wd WHERE wd.batch_id=$alias.request_id AND wd.outbox_id=$alias.payload_id AND wd.merchant_id=$alias.merchant_id AND wd.result_code IS NOT NULL))";
    }

    public function count($query): int
    {
        if (! config('bir_compliance.production_response_verification_implemented') && ! Schema::hasTable('bir_eis_batches')) {
            return 0;
        }
        $count = 0;
        foreach ($query->select('id')->cursor() as $row) {
            if ($this->verified($row->id)) {
                $count++;
            }
        }

        return $count;
    }
}
