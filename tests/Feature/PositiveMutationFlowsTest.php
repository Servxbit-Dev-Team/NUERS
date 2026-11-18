<?php
namespace Tests\Feature;
use Tests\Support\PositiveFlowCase;
use Illuminate\Support\Facades\{DB,Crypt,Http,Mail,Queue};
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
class PositiveMutationFlowsTest extends PositiveFlowCase
{
    public function test_rdo_creation_and_owner_team_role_creation():void {
        $this->allRoles(function($role,$u,$h,$m){
            $r=$this->checked($this->postJson('/api/rdos',['rdo_code'=>'TEST-999','rdo_name'=>'Synthetic district','region'=>'Synthetic region','coverage_area'=>['Synthetic locality']],$h),$role,self::ADM,201);
            $this->assertSame(in_array($role,self::ADM)?1:0,DB::table('rdo_offices')->where('rdo_code','TEST-999')->count());
            $this->checked($this->postJson('/api/merchant/team/roles',['name'=>'Synthetic observer','description'=>'Read-only test role','color'=>'blue','permissions'=>['documents.view'],'reason'=>'Synthetic authorized test'],$h),$role,self::BUSINESS);
            $this->assertSame(in_array($role,self::BUSINESS)?1:0,DB::table('merchant_team_roles')->where('merchant_id',$m)->where('name','Synthetic observer')->count());
        });
    }
    public function test_verification_queues_without_delivering_for_every_role():void {
        DB::table('platform_mail_settings')->updateOrInsert(['id'=>1],['updated_by'=>'synthetic-test','updated_at'=>now(),'connection_hash'=>str_repeat('a',64),'revision'=>1,'encrypted_settings'=>Crypt::encryptString(json_encode(['enabled'=>true,'host'=>'smtp.example.test','port'=>465,'username'=>'synthetic','password'=>'synthetic','from_address'=>'notice@example.test','from_name'=>'Synthetic']))]);
        $this->allRoles(function($role,$u,$h){
            $u->forceFill(['email_verified_at'=>null])->save();
            $this->postJson('/api/auth/resend-verification',[],$h)->assertOk();
            $this->assertDatabaseHas('notification_outbox',['user_id'=>(string)$u->id]);
        });Mail::assertNothingSent();Http::assertNothingSent();
    }
    public static function supportOperations():array{return [['delete'],['restore'],['purge'],['read']];}
    #[DataProvider('supportOperations')]
    public function test_support_lifecycle_valid_resources_and_all_roles(string $op):void {
        $this->allRoles(function($role,$u,$h)use($op){
            $id=(string)Str::uuid();$deleted=in_array($op,['restore','purge']);
            DB::table('support_conversations')->insert(['id'=>$id,'visitor_token_hash'=>hash('sha256',Str::random(64)),'visitor_name'=>'Synthetic visitor','visitor_email'=>'synthetic@example.test','last_message_at'=>now(),'subject'=>'Synthetic case','status'=>$deleted?'deleted':'open','revision'=>1,'token_expires_at'=>now()->addDay(),'created_at'=>now(),'updated_at'=>now()]);
            $mid=DB::table('support_messages')->insertGetId(['conversation_id'=>$id,'request_id'=>(string)Str::uuid(),'sender_type'=>'visitor','body'=>'Synthetic support message','created_at'=>now()]);
            $before=(array)DB::table('support_conversations')->find($id);$p=['confirmed'=>true,'revision'=>1,'message_id'=>$mid];
            $path='/api/admin/support/'.$id.(['delete'=>'','restore'=>'/restore','purge'=>'/permanent','read'=>'/read'][$op]);
            $r=in_array($op,['delete','purge'])?$this->deleteJson($path,$p,$h):$this->postJson($path,$p,$h);
            $this->checked($r,$role,self::ADM);
            if(in_array($role,self::ADM)){
                if($op==='read')$this->assertDatabaseHas('support_conversations',['id'=>$id,'admin_read_id'=>$mid]);
                else $this->assertDatabaseHas('support_conversations',['id'=>$id,'status'=>['delete'=>'deleted','restore'=>'open','purge'=>'purged'][$op]]);
                if($op==='purge')$this->assertSame(0,DB::table('support_messages')->where('conversation_id',$id)->count());
            }else $this->assertSame($before,(array)DB::table('support_conversations')->find($id));
        });
    }
    public function test_original_supplier_expense_is_scoped_idempotent_and_financially_reconciled():void {
        $this->allRoles(function($role,$u,$h,$m){
            [$seller]=$this->actor('merchant');$sm=$this->business($seller);$id=$this->invoice($sm);
            DB::table('merchants')->where('id',$m)->update(['tin'=>'555555555000']);
            $body=['source_invoice_id'=>$id,'expense_category'=>'Synthetic office','evidence_reference'=>'SYNTHETIC-SOURCE-EVIDENCE'];
            $r=$this->checked($this->postJson('/api/merchant/expenses',$body,$h),$role,self::BUSINESS,202);
            if(in_array($role,self::BUSINESS)){
                $this->assertDatabaseHas('merchant_expenses',['id'=>$r->json('id'),'merchant_id'=>$m,'source_invoice_id'=>$id,'input_vat_amount'=>12,'claim_status'=>'pending_review']);
                $this->postJson('/api/merchant/expenses',$body,$h)->assertOk()->assertJsonPath('duplicate',true);
            }else $this->assertDatabaseCount('merchant_expenses',0);
        });
    }
    public function test_api_receipt_verify_requires_business_role_and_own_real_receipt():void {
        $this->allRoles(function($role,$u,$h,$m){
            $id=(string)Str::uuid();DB::table('transaction_receipts')->insert(['id'=>$id,'merchant_id'=>$m,'receipt_number'=>'SYN-VERIFY','receipt_type'=>'api_transaction_receipt','merchant_name'=>'Synthetic seller','merchant_tin'=>'123456789000','status'=>'issued','total_due'=>112,'gross_amount'=>112,'issued_at'=>now(),'created_at'=>now()]);
            $r=$this->checked($this->getJson('/api/merchant/receipts/verify?receipt=SYN-VERIFY',$h),$role,self::BUSINESS);
            if(in_array($role,self::BUSINESS)){$r->assertJsonPath('receipt.id','SYN-VERIFY');$this->getJson('/api/merchant/receipts/verify?receipt%5B%5D=SYN-VERIFY',$h)->assertUnprocessable();}
        });
    }
    public function test_retired_and_generic_mutation_contracts_are_fail_closed():void {
        $this->allRoles(function($role,$u,$h){
            $this->checked($this->putJson('/api/admin/subscription-plans/1',['name'=>'Synthetic','fee'=>2],$h),$role,self::ADM,422);
            $this->checked($this->postJson('/api/merchant-accounts',['email'=>'synthetic@example.test','password'=>'Synthetic-Test-123'],$h),$role,self::ADM,410);
            foreach(['profiles','client_accounts','merchants','merchant_transactions','transaction_receipts'] as $table){
                foreach(['POST','DELETE'] as $method)$this->checked($this->json($method,'/api/tables/'.$table,['values'=>['full_name'=>'Synthetic']],$h),$role,array_diff(self::ROLES,['rdo']),409);
            }
        });
    }
    public function test_bearer_role_never_replaces_team_invitation_capability():void {
        $this->allRoles(function($role,$u,$h,$m){
            $rid=(string)Str::uuid();$id=(string)Str::uuid();$token=Str::random(64);
            DB::table('merchant_team_roles')->insert(['id'=>$rid,'merchant_id'=>$m,'name'=>'Synthetic reader','description'=>'Synthetic read only','color'=>'blue','permissions'=>'["documents.view"]','created_at'=>now(),'updated_at'=>now()]);
            DB::table('merchant_team_members')->insert(['id'=>$id,'merchant_id'=>$m,'role_id'=>$rid,'profile_encrypted'=>Crypt::encryptString(json_encode(['name'=>'Synthetic invitee','email'=>'invitee@example.test'])),'email_hash'=>hash('sha256','invitee@example.test'),'scope'=>'all','branch_ids'=>'[]','terminal_scope'=>'all','terminal_ids'=>'[]','status'=>'invited','invite_hash'=>hash('sha256',$token),'expires_at'=>now()->addDay(),'invited_by'=>(string)$u->id,'created_at'=>now(),'updated_at'=>now()]);
            $this->postJson('/api/team-invitations/preview',['token'=>$token],$h)->assertOk()->assertJsonPath('email','invitee@example.test');
            $this->postJson('/api/team-invitations/preview',['token'=>Str::random(64)],$h)->assertUnprocessable();
            $this->assertDatabaseHas('merchant_team_members',['id'=>$id,'status'=>'invited','user_id'=>null]);
        });
    }
}
