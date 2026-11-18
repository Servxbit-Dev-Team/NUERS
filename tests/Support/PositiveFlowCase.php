<?php
namespace Tests\Support;
use App\Models\User;
use Illuminate\Support\Facades\{DB,Cache,Crypt,Http,Mail,Queue,Storage};
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class PositiveFlowCase extends TestCase
{
    use IsolatedSecurityDatabase;
    protected const ROLES=['super_admin','admin','bir','rdo','merchant','client','consumer'];
    protected const ADM=['super_admin','admin'];
    protected const REVIEW=['super_admin','admin','bir'];
    protected const BUSINESS=['super_admin','admin','merchant'];
    protected function setUp():void {
        parent::setUp();$this->securityDatabase();$this->securityMigrate();
        config(['cache.default'=>'array','app.debug'=>false,'security.enforce_privileged_mfa'=>true,'notifications.dispatch_enabled'=>false,'infrastructure.execution_enabled'=>false,'infrastructure.enabled'=>false,'infrastructure.aws_bridge'=>null]);
        Http::preventStrayRequests();Mail::fake();Queue::fake();
        foreach(['local','private','public'] as $disk)Storage::fake($disk);
        if(DB::getDriverName()==='sqlite') DB::connection()->getPdo()->sqliteCreateFunction('DATE_FORMAT',fn($v,$f)=>$v===null?null:date(strtr($f,['%Y'=>'Y','%m'=>'m','%d'=>'d','%H'=>'H','%i'=>'i','%s'=>'s']),strtotime($v)));
    }
    protected function actor(string $role):array {
        $token=Str::random(64);$device=(string)Str::uuid();$u=User::factory()->create(['api_token'=>$token]);
        $u->forceFill(['api_device_hash'=>hash('sha256',$device),'mfa_secret'=>Crypt::encryptString('JBSWY3DPEHPK3PXP'),'api_mfa_verified_at'=>now(),'api_last_activity_at'=>now()])->save();
        DB::table('profiles')->insert(['id'=>$u->id,'email'=>$u->email,'full_name'=>'Synthetic '.$role,'role'=>$role]);
        return [$u,['Authorization'=>'Bearer '.$token,'X-NUERS-Device'=>$device,'Accept'=>'application/json']];
    }
    protected function business(User $u):string {
        $id=(string)Str::uuid();DB::table('merchants')->insert(['id'=>$id,'merchant_account_id'=>$u->id,'business_name'=>'Synthetic private '.$id,'tin'=>'123456789000','status'=>'active','address'=>'Synthetic address','vat_registered'=>true,'created_at'=>now(),'updated_at'=>now()]);return $id;
    }
    protected function invoice(string $m,string $buyer='555555555000'):string {
        $id=(string)Str::uuid();DB::table('business_invoices')->insert(['id'=>$id,'merchant_id'=>$m,'invoice_number'=>'SYN-'.$id,'document_type'=>'vat_invoice','merchant_name'=>'Synthetic seller','merchant_tin'=>'123456789000','merchant_address'=>'Synthetic address','seller_branch_code'=>'00000','buyer_name'=>'Synthetic buyer','buyer_tin'=>$buyer,'status'=>'issued','issue_date'=>now()->toDateString(),'total_amount'=>112,'subtotal_amount'=>112,'taxable_amount'=>100,'vat_amount'=>12,'vat_registered'=>true,'prices_include_vat'=>true,'amount_due'=>112,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('business_invoice_items')->insert(['id'=>(string)Str::uuid(),'invoice_id'=>$id,'merchant_id'=>$m,'line_number'=>1,'description'=>'Synthetic item','quantity'=>1,'unit_price'=>112,'line_total'=>112,'taxable_amount'=>100,'vat_amount'=>12,'vat_rate'=>12,'tax_type'=>'vatable']);return $id;
    }
    protected function allRoles(callable $case):void {
        foreach(self::ROLES as $role){ $this->auditScenarioRole=$role; Cache::flush(); DB::beginTransaction();try{[$u,$h]=$this->actor($role);$m=$this->business($u);$case($role,$u,$h,$m);}finally{while(DB::transactionLevel()>0)DB::rollBack();$this->auditScenarioRole=null;} }
    }
    protected function checked($response,string $role,array $allowed,int $success=200,int $denied=403) {
        $want=in_array($role,$allowed,true)?$success:$denied;
        $this->assertSame($want,$response->getStatusCode(),$role.' expected '.$want.': '.substr($response->getContent() ?: '',0,1000));return $response;
    }
}
