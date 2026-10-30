<?php
/** Creates a NEW isolated lab only; never loads the project's .env or opens its DB. */
$base=dirname(__DIR__);
$root=sys_get_temp_dir().'/nuers-vapt-'.bin2hex(random_bytes(8));
umask(0077);mkdir($root,0700);mkdir($root.'/storage/framework/views',0700,true);mkdir($root.'/storage/framework/cache/data',0700,true);mkdir($root.'/storage/logs',0700,true);mkdir($root.'/storage/app/private',0700,true);
$db=$root.'/database.sqlite';touch($db);chmod($db,0600);
$env=['APP_ENV'=>'testing','APP_DEBUG'=>'false','APP_URL'=>'http://127.0.0.1:8791','APP_KEY'=>'base64:'.base64_encode(random_bytes(32)), 'APP_CONFIG_CACHE'=>$root.'/unused-config.php','DB_CONNECTION'=>'sqlite','DB_DATABASE'=>$db,'DB_URL'=>'','DB_SOCKET'=>'','CACHE_STORE'=>'database','QUEUE_CONNECTION'=>'database','MAIL_MAILER'=>'array','SESSION_DRIVER'=>'file','NUERS_NOTIFICATION_DISPATCH_ENABLED'=>'false','NUERS_MERCHANT_WEBHOOK_DELIVERY_ENABLED'=>'false','NUERS_ENFORCE_PRIVILEGED_MFA'=>'true'];
foreach($env as $k=>$v){putenv($k.'='.$v);$_ENV[$k]=$v;$_SERVER[$k]=$v;}
file_put_contents($root.'/.env',implode("\n",array_map(fn($k,$v)=>$k.'="'.$v.'"',array_keys($env),$env))."\n");chmod($root.'/.env',0600);
require $base.'/vendor/autoload.php';
$app=require $base.'/bootstrap/app.php';$app->useEnvironmentPath($root);$app->useStoragePath($root.'/storage');$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(!$app->environment('testing')||config('database.default')!=='sqlite'||config('database.connections.sqlite.database')!==$db)throw new RuntimeException('Isolated database guard failed');
Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true]);
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Crypt;
$accounts=[];$users=[];
foreach(['super_admin'=>'super_admin','admin'=>'admin','merchant_a'=>'merchant','merchant_b'=>'merchant','staff_a'=>'merchant','regular'=>'client'] as $name=>$role){
 $password=bin2hex(random_bytes(16)).'Aa!';$email='vapt.'.$name.'@example.test';
 $u=App\Models\User::create(['name'=>'VAPT Synthetic '.str_replace('_',' ',$name),'email'=>$email,'password'=>$password]);$u->forceFill(['email_verified_at'=>now()])->save();
 DB::table('profiles')->insert(['id'=>$u->id,'email'=>$email,'full_name'=>$u->name,'role'=>$role]);$users[$name]=$u;
 $accounts[]=['label'=>$name,'email'=>$email,'role'=>$role,'password'=>$password,'mfa'=>'Enroll through normal account security workflow; never bypassed'];
}
foreach(['a','b'] as $suffix){$id='vapt-synthetic-'.$suffix;$u=$users['merchant_'.$suffix];
 DB::table('merchants')->insert(['id'=>$id,'merchant_account_id'=>$u->id,'business_name'=>'VAPT Synthetic Business '.strtoupper($suffix),'email'=>$u->email,'tin'=>$suffix==='a'?'111222333':'999888777','status'=>'active','city'=>'Synthetic City','region'=>'Synthetic Region']);
 DB::transaction(fn()=>app(App\Services\MerchantWallet::class)->credit($id,100000,'vapt-opening-'.$suffix,'Synthetic fixture only','vapt-lab'));
 for($i=1;$i<=50;$i++)DB::table('transaction_receipts')->insert(['id'=>(string)Str::uuid(),'merchant_id'=>$id,'receipt_number'=>'VAPT-'.strtoupper($suffix).'-'.str_pad((string)$i,6,'0',STR_PAD_LEFT),'merchant_name'=>'VAPT Synthetic Business '.strtoupper($suffix),'merchant_tin'=>$suffix==='a'?'111222333':'999888777','status'=>$i%10===0?'cancelled':'issued','total_due'=>100+$i,'gross_amount'=>100+$i,'created_at'=>now()->subDays($i%7),'updated_at'=>now()]);
}
$role=(string)Str::uuid();$member=(string)Str::uuid();$staff=$users['staff_a'];
DB::table('merchant_team_roles')->insert(['id'=>$role,'merchant_id'=>'vapt-synthetic-a','name'=>'VAPT Read-only Staff','description'=>'Synthetic negative authorization tests','color'=>'blue','permissions'=>'["documents.view"]','created_at'=>now(),'updated_at'=>now()]);
DB::table('merchant_team_members')->insert(['id'=>$member,'merchant_id'=>'vapt-synthetic-a','user_id'=>$staff->id,'role_id'=>$role,'profile_encrypted'=>Crypt::encryptString(json_encode(['name'=>$staff->name,'email'=>$staff->email])),'email_hash'=>hash('sha256',strtolower($staff->email)),'scope'=>'all','branch_ids'=>'[]','terminal_scope'=>'all','terminal_ids'=>'[]','status'=>'active','accepted_at'=>now(),'invited_by'=>$users['merchant_a']->id,'created_at'=>now(),'updated_at'=>now()]);
file_put_contents($root.'/accounts.json',json_encode($accounts,JSON_PRETTY_PRINT));chmod($root.'/accounts.json',0600);
// The router loads only the lab environment; public static files remain read-only.
$router='<?php'."\n".'$base='.var_export($base,true).';$root=__DIR__;'."\n".'$path=realpath($base."/public".parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH));if($path && str_starts_with($path,$base."/public/") && is_file($path) && pathinfo($path,PATHINFO_EXTENSION)!=="php")return false;'."\n".'putenv("APP_CONFIG_CACHE=".$root."/unused-config.php");require $base."/vendor/autoload.php";$app=require $base."/bootstrap/app.php";$app->useEnvironmentPath($root);$app->useStoragePath($root."/storage");$app->handleRequest(Illuminate\\Http\\Request::capture());';
file_put_contents($root.'/router.php',$router);
$manifest=['environment'=>'isolated-local-synthetic','url'=>'http://127.0.0.1:8791','root'=>$root,'credentials_file'=>$root.'/accounts.json','credentials_in_report'=>false,'users'=>array_map(fn($a)=>array_diff_key($a,['password'=>true]),$accounts),'merchants'=>2,'receipt_fixtures'=>100,'mail'=>'array/no outbound delivery','workers'=>'not started','production_data_used'=>false,'created_at'=>date('c')];
file_put_contents($root.'/manifest.json',json_encode($manifest,JSON_PRETTY_PRINT));
echo json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
