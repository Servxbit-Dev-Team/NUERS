<?php
namespace Tests\Feature;
use Tests\Support\PositiveFlowCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB,Crypt,File};
use Illuminate\Support\Str;
class PositiveDocumentFlowsTest extends PositiveFlowCase
{
    public function test_legacy_business_contact_update_preserves_registered_identity_for_all_roles():void {
        $this->allRoles(function($role,$u,$h,$m){
            $body=['revision'=>0,'registry_revision'=>1,'phone'=>'+639123456789','website'=>'https://example.test','details'=>['business_description'=>'Synthetic business description']];
            $before=DB::table('merchants')->find($m);
            $this->checked($this->putJson('/api/business-accounts/current',$body,$h),$role,['merchant']);
            $after=DB::table('merchants')->find($m);$this->assertSame($before->tin,$after->tin);$this->assertSame($before->business_name,$after->business_name);
            $this->assertSame($role==='merchant'?'+639123456789':$before->phone,$after->phone);
        });
    }
    public function test_valid_logo_and_avatar_uploads_are_role_scoped():void {
        $original=public_path();$root=storage_path('framework/testing/positive-images-'.Str::uuid());$this->app->usePublicPath($root);
        try{$this->allRoles(function($role,$u,$h,$m){
            $this->checked($this->post('/api/business-accounts/current/logo',['logo'=>UploadedFile::fake()->image('synthetic.png',32,32),'expected_logo'=>null],$h),$role,['merchant']);
            $this->checked($this->post('/api/client/profile/avatar',['avatar'=>UploadedFile::fake()->image('synthetic.png',32,32)],$h),$role,self::ROLES);
            if($role==='merchant')$this->assertNotEmpty(DB::table('merchants')->where('id',$m)->value('logo_url'));
            $this->assertNotEmpty(DB::table('profiles')->where('id',$u->id)->value('profile_photo_url'));
        });}finally{$this->app->usePublicPath($original);File::deleteDirectory($root);}
    }
    public function test_private_verification_download_with_existing_encrypted_file_and_every_role():void {
        $this->allRoles(function($role,$u,$h,$m){
            $bytes="%PDF-1.4\nSynthetic evidence only";
            $d=app(\App\Services\MerchantVerificationDocuments::class)->store($m,null,'bir_cor',UploadedFile::fake()->createWithContent('evidence.pdf',$bytes),(string)$u->id);
            $r=$this->checked($this->getJson('/api/admin/verification-documents/'.$d->document_id,$h),$role,self::ADM);
            if(in_array($role,self::ADM)){$this->assertSame($bytes,$r->streamedContent());$r->assertHeader('X-Content-Type-Options','nosniff');}
        });
    }
    public function test_invoice_settings_preview_renders_a_real_nonissued_pdf_for_owner_only():void {
        $this->allRoles(function($role,$u,$h,$m){
            $settings=app(\App\Services\MerchantInvoiceSettings::class)->defaults(DB::table('merchants')->find($m));
            $r=$this->checked($this->postJson('/api/merchant/invoice-settings/preview/pdf',['settings'=>$settings,'document_type'=>'invoice'],$h),$role,['merchant']);
            if($role==='merchant'){$this->assertStringStartsWith('%PDF-',$r->getContent());$this->assertGreaterThan(1000,strlen($r->getContent()));}
            $this->assertDatabaseCount('business_invoices',0);
        });
    }
    public function test_authorization_detail_and_device_update_are_role_controlled():void {
        $this->allRoles(function($role,$u,$h,$m){
            $id=(string)Str::uuid();$did=(string)Str::uuid();
            DB::table('taxpayer_authorizations')->insert(['id'=>$id,'request_id'=>(string)Str::uuid(),'request_hash'=>str_repeat('a',64),'merchant_id'=>$m,'kind'=>'PTT','reference'=>'SYN-PTT','environment'=>'production','scope'=>'Synthetic review','issued_at'=>now()->subDay(),'valid_until'=>now()->addYear(),'status'=>'active','evidence'=>'Synthetic original evidence','reason'=>'Synthetic original review','reviewed_by'=>'synthetic-independent','reviewed_at'=>now(),'branch_code'=>'00000','created_at'=>now()]);
            DB::table('taxpayer_devices')->insert(['id'=>$did,'request_id'=>(string)Str::uuid(),'request_hash'=>str_repeat('a',64),'authorization_id'=>$id,'name'=>'Synthetic device','serial_number'=>'SYN-DEVICE','branch'=>'Main','device_type'=>'server','status'=>'registered','reason'=>'Synthetic registration']);
            $r=$this->checked($this->getJson('/api/taxpayer-authorizations/'.$id,$h),$role,self::REVIEW);
            if(in_array($role,self::REVIEW))$r->assertJsonPath('record.id',$id);
            $body=['authorization_id'=>$id,'name'=>'Updated synthetic device','serial_number'=>'SYN-DEVICE','branch'=>'Main','device_type'=>'server','status'=>'maintenance','reason'=>'Synthetic maintenance request','revision'=>1,'request_id'=>(string)Str::uuid()];
            $this->checked($this->putJson('/api/taxpayer-devices/'.$did,$body,$h),$role,self::ADM);
            $this->assertDatabaseHas('taxpayer_devices',['id'=>$did,'revision'=>in_array($role,self::ADM)?2:1,'authorization_id'=>$id]);
        });
    }
    public function test_pti_application_detail_uses_actual_owned_application():void {
        $this->allRoles(function($role,$u,$h,$m){
            $id=(string)Str::uuid();DB::table('pti_applications')->insert(['id'=>$id,'request_id'=>(string)Str::uuid(),'request_hash'=>str_repeat('b',64),'merchant_id'=>$m,'created_by'=>(string)$u->id,'status'=>'draft','payload_encrypted'=>Crypt::encryptString('{}'),'created_at'=>now(),'updated_at'=>now()]);
            $r=$this->checked($this->getJson('/api/pti-applications/'.$id,$h),$role,self::BUSINESS);
            if(in_array($role,self::BUSINESS))$r->assertJsonPath('record.id',$id);
        });
    }
}
