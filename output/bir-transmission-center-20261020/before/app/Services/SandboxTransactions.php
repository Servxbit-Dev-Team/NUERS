<?php
namespace App\Services;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{DB,Crypt};
use Illuminate\Support\Str;
class SandboxTransactions {
    public function accept(array $v, object $key, object $merchant): array {
        return DB::transaction(function()use($v,$key,$merchant){
            $m=DB::table('merchants')->where('id',$merchant->id)->lockForUpdate()->first();
            $k=DB::table('merchant_api_keys')->where('id',$key->id)->lockForUpdate()->first();
            abort_unless($m && $m->status==='active' && $k && $k->merchant_id===$m->id && $k->environment==='sandbox' && $k->status==='active' && $k->expires_at && now()->lt($k->expires_at) && $k->branch_code===$key->branch_code && $k->ip_whitelist===$key->ip_whitelist && in_array('transactions:write',json_decode($k->scopes??'[]',true)?:[],true),403,'Sandbox key policy changed before acceptance.');
            abort_unless($v['branch_code']===$k->branch_code,403,'This key does not authorize the supplied registered location.');
            abort_if(($v['record_mode']??'nuers_issuance')==='nuers_issuance' && !empty($v['source_profile_id']),422,'A source profile is only used for source_capture.');
            $family=in_array($v['document_type'],['payment_receipt','official_receipt'],true)?'payment':'invoice';
            abort_unless($v['event_type']===($family==='payment'?'payment.recorded':'invoice.finalized'),422,'Event trigger and document family conflict.');
            $receipt=hash('sha256',json_encode([$m->id,$v['branch_code'],$family,$v['receipt_number']]));
            $hash=app(IntegrationContract::class)->canonicalHash($v);
            $ns=hash('sha256',json_encode([$m->id,$v['branch_code'],$v['source_system'],$family,$v['external_reference']]));
            $event=hash('sha256',json_encode([$m->id,$v['branch_code'],$v['source_system'],$v['source_event_id']]));
            $old=DB::table('integration_sandbox_events')->where('namespace_hash',$ns)->orWhere('event_hash',$event)->orWhere('receipt_hash',$receipt)->first();
            if($old){abort_unless(hash_equals($old->request_hash,$hash),409,'Sandbox source identity conflicts with its saved test content.');return app(IntegrationStatus::class)->accepted(['duplicate'=>true,...$this->details($old)['response'],'customer_receipt'=>app(ApiCustomerReceipt::class)->sandbox()]);}
            $tax=app(IntegrationContract::class)->calculateDocument($v,(bool)$m->vat_registered);
            $doc=app(IntegrationContract::class)->resolveDocument($v,$tax);
            $id=(string)Str::uuid();$policy=app(TransactionPricing::class)->offer(true);
            $gross=app(InvoiceCalculator::class)->cents($tax['gross_amount'],'gross_amount');
            $fee=$family==='invoice' && $gross>=$policy->minimum_transaction_centavos?(int)$policy->fee_centavos:0;
            $body=['id'=>$id,'transaction_id'=>'SBX-'.$id,'receipt_id'=>'SBX-DOC-'.$id,'receipt_number'=>$v['receipt_number'],'external_reference'=>$v['external_reference'],'source_event_id'=>$v['source_event_id'],'record_mode'=>$v['record_mode']??'nuers_issuance','source_profile_id'=>$v['source_profile_id']??null,'environment'=>'sandbox','test_only'=>true,'status'=>'durably_recorded','occurred_at'=>$v['occurred_at'],'accepted_at'=>now()->toIso8601String(),'calculation'=>$tax,'wallet'=>['charged'=>false,'fee_centavos'=>0,'simulated_fee_centavos'=>$fee,'pricing_revision'=>(int)$policy->revision],'status_url'=>url('/api/integrations/transactions/'.$id),'notice'=>'Sandbox test only. No original invoice, live wallet debit, legal series allocation or BIR submission. Tax registration is taken from the merchant; source-profile and issuance authority reviews are not simulated approvals.'];
            $body['customer_receipt']=app(ApiCustomerReceipt::class)->sandbox();
            $body=app(IntegrationStatus::class)->accepted($body);
            DB::table('integration_sandbox_events')->insert([...app(StatutorySales::class)->storage($v['sales_benefit'] ?? null, $tax), 'sales_benefit'=>null, 'id'=>$id,'merchant_id'=>$m->id,'api_key_id'=>$k->id,'environment'=>'sandbox','branch_code'=>$v['branch_code'],'source_system'=>$v['source_system'],'source_event_id'=>$v['source_event_id'],'external_reference'=>$v['external_reference'],'receipt_number'=>$v['receipt_number'],'document_type'=>$doc['type'],'event_type'=>$v['event_type'],'receipt_hash'=>$receipt,'namespace_hash'=>$ns,'event_hash'=>$event,'request_hash'=>$hash,'gross_centavos'=>$gross,'details_encrypted'=>Crypt::encryptString(json_encode(['request'=>$v,'response'=>$body],JSON_THROW_ON_ERROR)),'occurred_at'=>CarbonImmutable::parse($v['occurred_at'])->setTimezone(config('app.timezone')),'created_at'=>now(),'updated_at'=>now()]);
            app(BirCompliance::class)->audit('integration.sandbox.accepted',$id,'api-key:'.$k->id,['merchant_id'=>$m->id,'environment'=>'sandbox','request_hash'=>$hash]);
            app(MerchantWebhooks::class)->accepted((string)$m->id,'sandbox',$id,$body,($v['record_mode']??'nuers_issuance')==='source_capture'?'source.received':($family==='payment'?'payment.recorded':'invoice.issued'));
            return ['duplicate'=>false,...$body];
        },3);
    }
    public function details(object $row): array { return json_decode(Crypt::decryptString($row->details_encrypted),true,flags:JSON_THROW_ON_ERROR); }
    public function show(object $key,string $id): array {
        $row=DB::table('integration_sandbox_events')->where('id',$id)->where('merchant_id',$key->merchant_id)->where('branch_code',$key->branch_code)->first();abort_unless($row,404);
        return app(IntegrationStatus::class)->accepted([...$this->details($row)['response'],'customer_receipt'=>app(ApiCustomerReceipt::class)->sandbox()]);
    }
}
