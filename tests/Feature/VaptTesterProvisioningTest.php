<?php
namespace Tests\Feature;
use Tests\TestCase;
use Tests\Support\IsolatedSecurityDatabase;
use Illuminate\Support\Facades\{DB,Cache,Hash,Http,Mail};
use App\Models\User;
class VaptTesterProvisioningTest extends TestCase
{
    use IsolatedSecurityDatabase;
    protected function setUp():void{parent::setUp();$this->securityDatabase();$this->securityMigrate();config(['cache.default'=>'array','notifications.dispatch_enabled'=>false,'infrastructure.execution_enabled'=>false,'security.merchant_webhook_delivery_enabled'=>false]);Http::preventStrayRequests();Mail::fake();require_once base_path('deployment/staging/TesterAccounts.php');}
    public function test_all_roles_login_mfa_and_tenant_boundaries_then_revoke():void{
        $service=new \Nuers\Staging\TesterAccounts;$packet=$service->create();$this->assertCount(10,$packet['accounts']);$headers=[];
        foreach($packet['accounts'] as $alias=>$a){
            Cache::flush();$device=(string)\Illuminate\Support\Str::uuid();
            $r=$this->postJson('/api/auth/login',['email'=>$a['email'],'password'=>$a['password']],['X-NUERS-Device'=>$device])->assertOk();
            $h=['Authorization'=>'Bearer '.$r->json('session.access_token'),'X-NUERS-Device'=>$device];$headers[$alias]=$h;
            $r->assertJsonPath('session.mfa_required',true);
            $profileUrl='/api/tables/profiles?maybeSingle=1&eq='.urlencode(json_encode(['id'=>$a['id']]));
            $this->getJson($profileUrl,$h)->assertOk()->assertJsonPath('data.id',$a['id'])->assertJsonPath('data.role',$a['role']);
            $this->postJson('/api/auth/mfa/verify',['code'=>app(\App\Services\Totp::class)->code($a['totp_secret'],intdiv(now()->timestamp,30))],$h)->assertOk();
            $this->assertDatabaseHas('profiles',['id'=>$a['id'],'role'=>$a['role']]);
            $this->getJson('/api/auth/session',$h)->assertOk()->assertJsonPath('user.id',(string)$a['id']);
            $this->getJson($profileUrl,$h)->assertOk()->assertJsonPath('data.role',$a['role']);
            if($a['role']==='rdo'){
                $foreign=$packet['accounts']['super_admin']['id'];
                $this->getJson('/api/tables/profiles?maybeSingle=1&eq='.urlencode(json_encode(['id'=>$foreign])),$h)->assertOk()->assertJsonPath('data',null);
                $this->getJson('/api/tables/profiles',$h)->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$a['id']);
                foreach(['merchants','client_accounts','merchant_transactions','transaction_receipts','notifications']as$table)$this->getJson('/api/tables/'.$table,$h)->assertForbidden();
                $this->patchJson('/api/tables/profiles',['values'=>['role'=>'super_admin']],$h)->assertForbidden();
            }
        }
        foreach(['merchant_a','merchant_b']as$alias){$a=$packet['accounts'][$alias];$u=User::findOrFail($a['id']);$this->assertSame([$a['merchant_id']],app(\App\Services\ApiAccess::class)->merchants($u)->pluck('id')->all());$this->getJson('/api/merchant/wallet',$headers[$alias])->assertOk();$this->getJson('/api/business-registry',$headers[$alias])->assertForbidden();}
        foreach(['client_a','client_b','consumer']as$alias)$this->assertNotNull(app(\App\Services\ApiAccess::class)->taxpayerTin(User::findOrFail($packet['accounts'][$alias]['id'])));
        foreach(['rdo_a','rdo_b']as$alias)$this->getJson('/api/rdo/dashboard',$headers[$alias])->assertOk();
        try{$service->create();$this->fail('Duplicate must fail');}catch(\RuntimeException $e){$this->assertStringContainsString('exists',$e->getMessage());}
        $this->assertSame(10,$service->revoke());
        foreach($packet['accounts']as$alias=>$a){Cache::flush();$this->assertFalse(Hash::check($a['password'],User::findOrFail($a['id'])->password));$this->getJson('/api/auth/session',$headers[$alias])->assertJsonPath('user',null);}
        Mail::assertNothingSent();Http::assertNothingSent();
    }
    public function test_external_delivery_guard_refuses_account_creation():void{config(['notifications.dispatch_enabled'=>true]);$this->expectException(\RuntimeException::class);(new \Nuers\Staging\TesterAccounts)->create();}
}
