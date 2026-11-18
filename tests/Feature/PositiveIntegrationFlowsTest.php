<?php
namespace Tests\Feature;
use Tests\Support\PositiveFlowCase;
use Illuminate\Support\Facades\{DB,Crypt,Http};
use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;
class PositiveIntegrationFlowsTest extends PositiveFlowCase
{
    private function accreditation($u,bool $approved=false):string {
        config(['bir_compliance.configuration_reference'=>'synthetic-reviewed-build']);$id=(string)Str::uuid();
        DB::table('esp_accreditations')->insert(['id'=>$id,'solution_name'=>config('bir_compliance.solution_name'),'solution_version'=>config('bir_compliance.solution_version'),'configuration_reference'=>'synthetic-reviewed-build','status'=>$approved?'accredited':'not_submitted','certificate_number'=>$approved?'SYNTHETIC-NOT-REAL':null,'issued_at'=>now()->subDay(),'expires_at'=>now()->addYear(),'annual_review_at'=>now()->subDay(),'evidence_reference'=>'Synthetic independent evidence','verified_by'=>(string)$u->id,'verified_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
        foreach(['annex','stages']as$c)foreach(config('bir_compliance.'.$c)as$code=>$label)DB::table('esp_accreditation_controls')->insert(['id'=>(string)Str::uuid(),'accreditation_id'=>$id,'category'=>$c,'code'=>(string)$code,'status'=>'passed','evidence_reference'=>'Synthetic review only','verified_by'=>(string)$u->id,'verified_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);return$id;
    }
    public function test_local_payload_validation_is_not_claimed_as_bir_acceptance_for_all_roles():void {
        $this->allRoles(function($role,$u,$h){$p=['invoice_number'=>'SYN-001','seller_tin'=>'123456789','issue_date'=>now()->toDateString(),'document_type'=>'sales_invoice','total_due'=>112,'is_test'=>true];
            $r=$this->checked($this->postJson('/api/bir-eis/validate',['payload'=>$p],$h),$role,self::REVIEW);
            if(in_array($role,self::REVIEW))$r->assertJsonPath('status','valid')->assertJsonPath('validation_scope','local_preflight_only')->assertJsonPath('official_schema_verified',false);
        });Http::assertNothingSent();
    }
    public function test_certificate_recording_needs_bir_role_with_all_controls_verified():void {
        $this->allRoles(function($role,$u,$h){$id=$this->accreditation($u);$v=['certificate_number'=>'SYNTHETIC-NOT-REAL','issued_at'=>now()->subDay()->toDateString(),'expires_at'=>now()->addYear()->toDateString(),'annual_review_at'=>now()->subDay()->toDateString(),'configuration_reference'=>'synthetic-reviewed-build','evidence_reference'=>'Synthetic externally verified evidence'];
            $this->checked($this->postJson('/api/esp-compliance/applications/'.$id.'/certificate',$v,$h),$role,['bir']);
            $this->assertDatabaseHas('esp_accreditations',['id'=>$id,'status'=>$role==='bir'?'accredited':'not_submitted']);
        });
    }
    public function test_invoice_series_is_registered_only_by_bir_and_cannot_overlap():void {
        $this->allRoles(function($role,$u,$h,$m){$id=$this->accreditation($u,true);$integration=DB::table('eis_integrations')->first();
            DB::table('eis_integrations')->where('id',$integration->id)->update(['merchant_id'=>$m,'environment'=>'production','branch_code'=>'00000','esp_accreditation_id'=>$id,'pti_status'=>'approved','permit_to_issue_number'=>'SYN-PTI','taxpayer_authorization_reference'=>'Synthetic reviewed reference','written_authorization_at'=>now()]);
            DB::table('taxpayer_authorizations')->insert(['id'=>(string)Str::uuid(),'request_id'=>(string)Str::uuid(),'request_hash'=>str_repeat('a',64),'merchant_id'=>$m,'kind'=>'PTI','reference'=>'SYN-PTI','environment'=>'production','branch_code'=>'00000','scope'=>'Synthetic fixture only','issued_at'=>now()->subDay(),'valid_until'=>now()->addYear(),'status'=>'active','evidence'=>'Synthetic authority evidence','reason'=>'Synthetic fixture only','reviewed_by'=>(string)$u->id,'reviewed_at'=>now()]);
            $v=['merchant_id'=>$m,'branch_code'=>'00000','document_family'=>'invoice','prefix'=>'SYN-','range_start'=>1,'range_end'=>99,'authority_reference'=>'Synthetic authority evidence','valid_from'=>now()->subDay()->toDateString(),'valid_until'=>now()->addYear()->toDateString(),'document_registration'=>['system_type'=>'CAS','ac_number'=>'SYNTHETIC-AC','authority_issued_at'=>now()->subDay()->toDateString()]];
            $r=$this->checked($this->postJson('/api/invoice-series',$v,$h),$role,['bir'],201);
            if($role==='bir'){$this->assertDatabaseHas('invoice_series',['id'=>$r->json('id'),'merchant_id'=>$m,'verified_by'=>(string)$u->id]);$this->postJson('/api/invoice-series',$v,$h)->assertConflict();}
            $this->assertDatabaseCount('invoice_series',$role==='bir'?1:0);
        });
    }
    public function test_public_certificate_upload_and_webhook_update_for_all_roles():void {
        $key=openssl_pkey_new(['private_key_bits'=>2048]);$csr=openssl_csr_new(['commonName'=>'synthetic.example.test'],$key);$cert=openssl_csr_sign($csr,null,$key,30);openssl_x509_export($cert,$pem);$fingerprint=openssl_x509_fingerprint($pem,'sha256');
        $this->allRoles(function($role,$u,$h,$m)use($pem,$fingerprint){
            [$owner,$oh]=$this->actor('super_admin');
            $v=['request_id'=>(string)Str::uuid(),'revision'=>0,'name'=>'Synthetic configuration','provider'=>'third_party','merchant_id'=>$m,'environment'=>'certification','base_url'=>'https://example.test','client_id'=>'synthetic-client','api_version'=>'v1','timeout_seconds'=>30,'max_retries'=>3,'status'=>'configured','evidence'=>'Synthetic provider evidence','reason'=>'Synthetic configuration setup'];
            $id=$this->postJson('/api/integration-workspace/connections',$v,$oh)->assertCreated()->json('connection.id');
            $r=$this->checked($this->post('/api/integration-workspace/connections/'.$id.'/certificate',['revision'=>1,'reason'=>'Synthetic certificate rotation','file'=>UploadedFile::fake()->createWithContent('certificate.pem',$pem)],$h),$role,self::ADM);
            $this->assertDatabaseHas('integration_connections',['id'=>$id,'revision'=>in_array($role,self::ADM)?2:1,'certificate_fingerprint'=>in_array($role,self::ADM)?$fingerprint:null]);
            $wid=(string)Str::uuid();$secret=Str::random(48);DB::table('integration_webhooks')->insert(['id'=>$wid,'connection_id'=>$id,'url'=>'https://example.test/old','events'=>'["invoice.issued"]','status'=>'disabled','encrypted_secret'=>Crypt::encryptString($secret),'evidence'=>'Synthetic old evidence','revision'=>1,'created_at'=>now(),'updated_at'=>now()]);
            $body=['connection_id'=>$id,'revision'=>1,'url'=>'https://example.test/new','events'=>['invoice.issued','transmission.failed'],'status'=>'configured','evidence'=>'Synthetic webhook evidence','reason'=>'Synthetic webhook update'];
            $r=$this->checked($this->putJson('/api/integration-workspace/webhooks/'.$wid,$body,$h),$role,self::ADM);$this->assertStringNotContainsString($secret,$r->getContent());
            $after=DB::table('integration_webhooks')->find($wid);$this->assertSame($secret,Crypt::decryptString($after->encrypted_secret));$this->assertSame(in_array($role,self::ADM)?2:1,$after->revision);
            if(in_array($role,self::ADM))$this->putJson('/api/integration-workspace/webhooks/'.$wid,$body,$h)->assertConflict();
        });Http::assertNothingSent();
    }
    public function test_admin_sandbox_detail_decrypts_actual_synthetic_record_for_all_roles():void {
        $this->allRoles(function($role,$u,$h,$m){$id=(string)Str::uuid();$hash=hash('sha256',$id);DB::table('integration_sandbox_events')->insert(['id'=>$id,'merchant_id'=>$m,'api_key_id'=>'synthetic-key','branch_code'=>'00000','source_system'=>'synthetic','source_event_id'=>$id,'external_reference'=>'SYN-RECORD','receipt_number'=>'SYN-RECEIPT','document_type'=>'sales_invoice','event_type'=>'sale','receipt_hash'=>$hash,'namespace_hash'=>$hash,'event_hash'=>$hash,'request_hash'=>$hash,'gross_centavos'=>11200,'details_encrypted'=>Crypt::encryptString(json_encode(['request'=>['customer_name'=>'Synthetic customer'],'response'=>['calculation'=>['discount_amount'=>0,'vat_amount'=>12]]])),'occurred_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
            $r=$this->checked($this->getJson('/api/admin/sandbox-records/'.$id,$h),$role,self::ADM);if(in_array($role,self::ADM))$r->assertJsonPath('record.id',$id)->assertJsonPath('request.customer_name','Synthetic customer')->assertJsonMissingPath('record.details_encrypted');
        });
    }
    public function test_legacy_eis_batch_release_remains_blocked_without_implemented_adapter_for_all_roles():void {
        $this->allRoles(function($role,$u,$h,$m){$id=(string)Str::uuid();$invoice=$this->invoice($m);DB::table('eis_preparation_batches')->insert(['id'=>$id,'actor_id'=>(string)$u->id,'merchant_id'=>$m,'request_hash'=>str_repeat('a',64),'status'=>'completed','document_count'=>1,'reason'=>'Synthetic review only','filters'=>'{}','completed_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
            DB::table('eis_preparation_batch_items')->insert(['id'=>(string)Str::uuid(),'batch_id'=>$id,'merchant_id'=>$m,'document_source'=>'invoice','document_id'=>$invoice,'expected_hash'=>str_repeat('a',64),'status'=>'prepared','created_at'=>now(),'updated_at'=>now()]);
            $r=$this->checked($this->postJson('/api/admin/eis-center/batches/'.$id.'/release',['reason'=>'Synthetic batch release check'],$h),$role,self::ADM,409);
            if(in_array($role,self::ADM))$r->assertJsonPath('message','The verified BIR adapter is not connected. Documents remain safely prepared in NUERS.');
            $this->assertDatabaseHas('eis_preparation_batches',['id'=>$id,'status'=>'completed']);$this->assertDatabaseCount('eis_delivery_jobs',0);
        });Http::assertNothingSent();
    }
}
