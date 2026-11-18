<?php
namespace Tests\Feature;
use Tests\Support\PositiveFlowCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
class PositiveLegacyTenantTest extends PositiveFlowCase
{
    private function records(string $m,string $label):array {
        $r=(string)Str::uuid();$t=(string)Str::uuid();DB::table('transaction_receipts')->insert(['id'=>$r,'merchant_id'=>$m,'transaction_id'=>$t,'receipt_number'=>$label,'receipt_type'=>'api_transaction_receipt','merchant_name'=>'Synthetic seller','merchant_tin'=>'123456789000','status'=>'issued','total_due'=>112,'gross_amount'=>112,'issued_at'=>now(),'created_at'=>now()]);
        DB::table('merchant_transactions')->insert(['id'=>$t,'merchant_id'=>$m,'receipt_id'=>$r,'transaction_ref'=>$label.'-TXN','source_system'=>'synthetic-api','amount'=>112,'status'=>'completed','created_at'=>now()]);return[$r,$t];
    }
    public function test_existing_records_cannot_cross_tenants_or_survive_role_downgrade():void {
        $this->allRoles(function($role,$u,$h,$m){[$r,$t]=$this->records($m,'SYN-OWN');[$other]=$this->actor('merchant');$foreign=$this->business($other);$this->records($foreign,'SYN-FOREIGN');
            foreach(['receipts'=>$r,'transactions'=>$t]as$resource=>$id){$v=$this->checked($this->getJson('/api/merchant/'.$resource.'?merchant_id='.$foreign,$h),$role,self::BUSINESS);if(in_array($role,self::BUSINESS))$v->assertJsonPath($resource.'.0.db_id',$id)->assertJsonCount(1,$resource)->assertDontSee('SYN-FOREIGN');}
            $this->getJson('/api/merchant/receipts/verify?receipt=SYN-FOREIGN',$h)->assertStatus(in_array($role,self::BUSINESS)?404:403);
            if(in_array($role,self::BUSINESS)){
                DB::table('profiles')->where('id',$u->id)->update(['role'=>'client']);
                foreach(['/api/merchant/receipts','/api/merchant/transactions','/api/merchant/receipts/verify?receipt=SYN-OWN']as$url)$this->getJson($url,$h)->assertForbidden();
            }
        });
    }
}
