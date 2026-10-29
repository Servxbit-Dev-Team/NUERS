<?php
// Private CLI-only checks. No account, credential, transaction or wallet writes.
if (PHP_SAPI !== 'cli') exit(1);
umask(0077); set_time_limit(90);
$root=__DIR__.'/httpdocs';
if(basename(__DIR__)!=='nuers.net'||!is_file($root.'/artisan'))throw new RuntimeException('Wrong target');
require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$out=['verified'=>false,'checked_at'=>gmdate('c'),'business_data_read_only'=>true];
try{
 if(parse_url(config('app.url'),PHP_URL_HOST)!=='nuers.net'||!config('security.enforce_privileged_mfa'))throw new RuntimeException('Deployment policy mismatch');
 $manifest=json_decode(file_get_contents(__DIR__.'/nuers-registry-overview-stage-20261020/audit-release-manifest.json'),true,512,JSON_THROW_ON_ERROR);
 foreach($manifest['files']as$p=>$hash)if(!is_file($root.'/'.$p)||hash_file('sha256',$root.'/'.$p)!==$hash)throw new RuntimeException('Installed checksum mismatch');
 $start=microtime(true);
 $checks=DB::transaction(function(){
  $service=app(App\Services\RegistryMerchantOverview::class);
  $q=DB::table('merchants as m');$summary=$service->summary($q);
  $expected=['available_centavos'=>(int)DB::table('merchant_wallets')->whereIn('merchant_id',(clone$q)->select('m.id'))->sum('available_centavos'),
   'transactions'=>DB::table('merchant_transactions')->whereIn('merchant_id',(clone$q)->select('m.id'))->count(),
   'charged_centavos'=>-(int)DB::table('merchant_wallet_entries')->whereIn('merchant_id',(clone$q)->select('m.id'))->where('kind','fee')->sum('amount_centavos')];
  foreach($expected as$k=>$v)if($summary[$k]!==$v)throw new RuntimeException('Portfolio parity check failed: '.$k);
  $ids=(clone$q)->orderBy('m.id')->limit(5)->pluck('m.id')->all();$rows=$service->rows($ids);
  foreach($ids as$id){$row=$rows[$id];if($row['transactions']!==DB::table('merchant_transactions')->where('merchant_id',$id)->count())throw new RuntimeException('Per-merchant transaction count mismatch');if($row['available_centavos']!==(int)DB::table('merchant_wallets')->where('merchant_id',$id)->value('available_centavos'))throw new RuntimeException('Per-merchant wallet mismatch');}
  $controller=app(App\Http\Controllers\Api\BusinessRegistryController::class);
  $method=new ReflectionMethod($controller,'filteredQuery');
  [$filtered]=$method->invoke($controller,Illuminate\Http\Request::create('/api/business-registry','GET',['stage'=>'onboarded','sort'=>'name','per_page'=>1]));
  $filtered->select('m.id')->limit(1)->get();
  $empty=$service->summary(DB::table('merchants as m')->whereRaw('1=0'));
  if($empty['transactions']!==0||$empty['available_centavos']!==0)throw new RuntimeException('Empty scope leaked totals');
  return ['portfolio_parity'=>true,'merchants_checked'=>count($ids),'per_merchant_parity'=>true,'mysql_stage_filter'=>true,'empty_scope_isolated'=>true];
 });
 $out+= $checks+['elapsed_ms'=>round((microtime(true)-$start)*1000,2),'files_verified'=>count($manifest['files']),'schema_migration'=>false,'mfa_preserved'=>true,'bir_transport_enabled'=>(bool)config('bir_compliance.transport_implemented')];$out['verified']=true;
}catch(Throwable$e){$out['error']=$e instanceof RuntimeException?$e->getMessage():'Application verification failed';}
file_put_contents(__DIR__.'/nuers-registry-overview-verification.json',json_encode($out,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));echo json_encode($out).PHP_EOL;exit($out['verified']?0:1);
