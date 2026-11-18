<?php
namespace Tests\Support;
use App\Models\User;
use Illuminate\Support\Facades\{DB,Cache,Crypt};
use Illuminate\Support\Str;
trait SevenRoleSessions {
    private function sevenRoles(callable $case):void {
        $old=config('security.enforce_privileged_mfa');config(['security.enforce_privileged_mfa'=>true]);
        try{foreach(['super_admin','admin','bir','rdo','merchant','client','consumer'] as $role){
             $this->auditScenarioRole=$role;Cache::flush();DB::beginTransaction();try{
                $token=Str::random(64);$device=(string)Str::uuid();$u=User::factory()->create(['api_token'=>$token]);
                $u->forceFill(['api_device_hash'=>hash('sha256',$device),'mfa_secret'=>Crypt::encryptString('JBSWY3DPEHPK3PXP'),'api_mfa_verified_at'=>now(),'api_last_activity_at'=>now()])->save();
                DB::table('profiles')->insert(['id'=>$u->id,'email'=>$u->email,'role'=>$role]);
                $case($role,$u,['Authorization'=>'Bearer '.$token,'X-NUERS-Device'=>$device]);
            }finally{while(DB::transactionLevel()>0)DB::rollBack();$this->auditScenarioRole=null;}
        }}finally{config(['security.enforce_privileged_mfa'=>$old]);}
    }
}
