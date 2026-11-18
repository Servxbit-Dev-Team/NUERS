<?php
/** Operator-only fixtures. Never expose this file through a route or deploy it to production. */
namespace Nuers\Staging;
use App\Models\User;
use Illuminate\Support\Facades\{DB,Crypt,Hash};
use Illuminate\Support\Str;
final class TesterAccounts
{
    public const PREFIX='vapt.20261116.';
    public const ACCOUNTS=['super_admin'=>'super_admin','admin'=>'admin','bir'=>'bir','rdo_a'=>'rdo','rdo_b'=>'rdo','merchant_a'=>'merchant','merchant_b'=>'merchant','client_a'=>'client','client_b'=>'client','consumer'=>'consumer'];
    public function guard():void
    {
        $local=app()->environment('testing') && DB::getDriverName()==='sqlite' && DB::connection()->getDatabaseName()===':memory:';
        $stage=app()->environment('staging') && DB::getDriverName()==='mysql' && DB::connection()->getDatabaseName()==='nuers_vapt_multi' && config('database.connections.mysql.host')==='10.85.0.70';
        if(!$local && !$stage) throw new \RuntimeException('Refuse non-isolated tester account database');
        if(config('notifications.dispatch_enabled') || config('infrastructure.execution_enabled') || config('security.merchant_webhook_delivery_enabled')) throw new \RuntimeException('External side effects must be disabled');
    }
    public function create():array
    {
        $this->guard();
        return DB::transaction(function(){
            if(User::where('email','like',self::PREFIX.'%')->exists()) throw new \RuntimeException('Tester batch exists; refusing to overwrite credentials');
            $rows=[];
            foreach(self::ACCOUNTS as $alias=>$role){
                $password=bin2hex(random_bytes(20)).'Aa9!';$secret=app(\App\Services\Totp::class)->secret();
                $u=User::create(['name'=>'VAPT TEST '.strtoupper($alias),'email'=>self::PREFIX.$alias.'@example.test','password'=>$password]);
                $u->forceFill(['email_verified_at'=>now(),'mfa_secret'=>Crypt::encryptString($secret)])->save();
                DB::table('profiles')->insert(['id'=>$u->id,'email'=>$u->email,'role'=>$role,'full_name'=>$u->name,'organization'=>'NUERS synthetic VAPT - not a real taxpayer','created_at'=>now(),'updated_at'=>now()]);
                $rows[$alias]=['id'=>$u->id,'role'=>$role,'email'=>$u->email,'password'=>$password,'totp_secret'=>$secret];
            }
            foreach(['a'=>'VAPT-A','b'=>'VAPT-B'] as $suffix=>$code){
                DB::table('rdo_offices')->insert(['id'=>(string)Str::uuid(),'rdo_code'=>$code,'rdo_name'=>'SYNTHETIC TEST OFFICE '.$suffix,'region'=>'TEST ONLY','user_id'=>$rows['rdo_'.$suffix]['id'],'created_at'=>now(),'updated_at'=>now()]);
                $m=(string)Str::uuid();$alias='merchant_'.$suffix;
                DB::table('merchants')->insert(['id'=>$m,'merchant_account_id'=>$rows[$alias]['id'],'business_name'=>'VAPT SYNTHETIC MERCHANT '.strtoupper($suffix),'tin'=>$suffix==='a'?'999000001000':'999000002000','rdo_code'=>$code,'status'=>'active','email'=>$rows[$alias]['email'],'merchant_account_email'=>$rows[$alias]['email'],'address'=>'Synthetic test address - no real taxpayer','city'=>'Synthetic City','region'=>'TEST ONLY','vat_registered'=>true,'notes'=>'VAPT TEST ONLY. No BIR authority or accreditation.','created_at'=>now(),'updated_at'=>now()]);
                $rows[$alias]['merchant_id']=$m;
            }
            foreach(['client_a','client_b','consumer'] as $idx=>$alias){
                $tin='99910000'.($idx+1).'000';
                DB::table('client_accounts')->insert(['id'=>(string)Str::uuid(),'user_id'=>$rows[$alias]['id'],'account_number'=>'VAPT-20261116-'.$alias,'full_name'=>'VAPT TEST '.$alias,'email'=>$rows[$alias]['email'],'tin'=>$tin,'status'=>'active','created_at'=>now(),'updated_at'=>now()]);
                DB::table('taxpayer_identity_reviews')->insert(['id'=>(string)Str::uuid(),'user_id'=>(string)$rows[$alias]['id'],'tin'=>$tin,'evidence_reference'=>'SYNTHETIC VAPT FIXTURE; not BIR verification','status'=>'approved','reviewed_by'=>(string)$rows['super_admin']['id'],'review_reason'=>'Isolated synthetic test identity','reviewed_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
            }
            DB::table('bir_accounts')->insert(['id'=>(string)Str::uuid(),'user_id'=>$rows['bir']['id'],'employee_id'=>'VAPT-20261116-BIR','full_name'=>'VAPT TEST REGULATOR','email'=>$rows['bir']['email'],'created_at'=>now(),'updated_at'=>now()]);
            return ['batch'=>'20261116','created_at'=>now()->toIso8601String(),'accounts'=>$rows];
        });
    }
    public function revoke():int
    {
        $this->guard();$count=0;
        DB::transaction(function()use(&$count){
            foreach(self::ACCOUNTS as $alias=>$role){
                $u=User::where('email',self::PREFIX.$alias.'@example.test')->lockForUpdate()->first();
                if(!$u)continue;
                $u->forceFill(array_merge(app(\App\Services\ApiAccess::class)->revocation(),['password'=>Hash::make(bin2hex(random_bytes(48))),'remember_token'=>null,'mfa_pending_secret'=>null,'mfa_recovery_hashes'=>null]))->save();$count++;
            }
        });return $count;
    }
}
