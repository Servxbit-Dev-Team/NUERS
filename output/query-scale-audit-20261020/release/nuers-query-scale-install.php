<?php
if(PHP_SAPI!=='cli')exit(1);
umask(0077);set_time_limit(300);
$base=__DIR__;$root=$base.'/httpdocs';$mode=$argv[1]??'check';
if(!in_array($mode,['check','install'],true))throw new RuntimeException('Invalid mode');
if(basename($base)!=='nuers.net'||!is_file($root.'/artisan'))throw new RuntimeException('Wrong deployment target');
$lock=fopen($base.'/nuers-query-scale.lock','c');if(!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Release already running');
$zipPath=$base.'/nuers-query-scale-20261020.zip';$sha='66870ab2d801efa9bb5cfb6d7936d8fb497e5c17773438b080028c601d68ee90';
if(!hash_equals($sha,hash_file('sha256',$zipPath)))throw new RuntimeException('Package checksum mismatch');
$stage=$base.'/nuers-query-scale-stage-20261020';if(!is_dir($stage))mkdir($stage,0700);
$zip=new ZipArchive;if($zip->open($zipPath)!==true)throw new RuntimeException('Cannot open release');
$manifest=json_decode($zip->getFromName('audit-release-manifest.json'),true,512,JSON_THROW_ON_ERROR);
for($i=0;$i<$zip->numFiles;$i++){$p=$zip->getNameIndex($i);if(str_starts_with($p,'/')||in_array('..',explode('/',$p),true)||($p!=='audit-release-manifest.json'&&!isset($manifest['files'][$p])))throw new RuntimeException('Unexpected package path');}
$zip->extractTo($stage);$zip->close();
foreach($manifest['files'] as$p=>$hash)if(!is_file($stage.'/'.$p)||!hash_equals($hash,hash_file('sha256',$stage.'/'.$p)))throw new RuntimeException('Stage checksum mismatch: '.$p);
require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\{DB,Artisan,Schema};
if(parse_url(config('app.url'),PHP_URL_HOST)!=='nuers.net')throw new RuntimeException('Wrong application domain');
if(config('infrastructure.enabled',false)||config('infrastructure.execution_enabled',false))throw new RuntimeException('Controller must remain disabled');
if(!config('security.enforce_privileged_mfa'))throw new RuntimeException('MFA policy not enabled');
$run=function(array$args,string$log,?string$folder=null)use($root){$p=new Symfony\Component\Process\Process($args,$root);$p->setTimeout(180);$p->run();if($folder)file_put_contents($folder.'/'.$log,$p->getOutput().$p->getErrorOutput());if(!$p->isSuccessful())throw new RuntimeException('Command failed: '.$log);return$p->getOutput();};
$php='/opt/plesk/php/8.4/bin/php';
foreach($manifest['files']as$p=>$hash)if(str_ends_with($p,'.php'))$run([$php,'-l',$stage.'/'.$p],'PHP syntax: '.$p);
$drift=[];foreach($manifest['baseline']as$p=>$hash){$actual=is_file($root.'/'.$p)?hash_file('sha256',$root.'/'.$p):null;if($actual!==$hash&&$actual!==$manifest['files'][$p])$drift[]=['path'=>$p,'sha256'=>$actual];}
if($drift){echo json_encode(['preflight'=>false,'source_drift'=>$drift]).PHP_EOL;exit(1);}
$newPaths=$manifest['new_files'];
foreach($manifest['files']as$p=>$hash)foreach($newPaths as$prefix)if(str_starts_with($p,$prefix)&&is_file($root.'/'.$p)&&!in_array(hash_file('sha256',$root.'/'.$p),[$hash,$manifest['previous_attempt'][$p]??null],true))throw new RuntimeException('New module file already differs: '.$p);
if($mode==='check'){echo json_encode(['preflight'=>true,'target'=>'nuers.net','files'=>count($manifest['files']),'php'=>PHP_VERSION,'schema_migration'=>true,'controller_disabled'=>true,'source_baseline_matches'=>true]).PHP_EOL;exit;}
$resultPath=$base.'/nuers-query-scale-result.json';
if(is_file($resultPath)&&json_decode(file_get_contents($resultPath),true)['deployed']){echo file_get_contents($resultPath);exit;}
$backup=$base.'/nuers-query-scale-backup-'.gmdate('Ymd-His');mkdir($backup,0700);
$previous=[];$old=new ZipArchive;if($old->open($backup.'/previous-files.zip',ZipArchive::CREATE)!==true)throw new RuntimeException('Cannot create backup');
foreach($manifest['files']as$p=>$hash)if(is_file($root.'/'.$p)){$old->addFile($root.'/'.$p,$p);$previous[]=$p;}$old->close();chmod($backup.'/previous-files.zip',0600);
file_put_contents($backup.'/previous-paths.json',json_encode($previous));
$snapshot=function()use($root){$r=['env'=>hash_file('sha256',$root.'/.env'),'security'=>hash('sha256',json_encode(config('security')))];foreach(['merchant_wallets'=>'merchant_id','merchant_wallet_entries'=>'id','merchant_wallet_topups'=>'id','profiles'=>'id','merchant_api_keys'=>'id','merchant_verification_documents'=>'id','transaction_receipts'=>'id','business_invoices'=>'id','merchant_transactions'=>'id']as$t=>$key)if(Schema::hasTable($t)){$h=hash_init('sha256');foreach(DB::table($t)->orderBy($key)->lazyById(200,$key) as $row)hash_update($h,json_encode($row)."\n");$r[$t]=hash_final($h);}return$r;};
if(file_exists($root.'/storage/framework/down')||file_exists($root.'/.nuers-deploying'))throw new RuntimeException('Another maintenance operation is active');
file_put_contents($root.'/.nuers-deploying','Indexed reporting capacity update');Artisan::call('down',['--retry'=>15]);
try{
 $before=$snapshot();file_put_contents($backup.'/preservation-hashes.json',json_encode($before));
 // Promote entry manifests last. Existing hashed assets remain available.
 $names=array_keys($manifest['files']);usort($names,fn($a,$b)=>(int)in_array($a,['index.html','dist/index.html','public/build/manifest.json'])<=>(int)in_array($b,['index.html','dist/index.html','public/build/manifest.json']));
 foreach($names as$p){$dest=$root.'/'.$p;$dir=dirname($dest);if(!is_dir($dir))mkdir($dir,str_starts_with($p,'app/')||str_starts_with($p,'docs/')?0700:0755,true);if(preg_match('#^(assets/|dist/|public/)#',$p)){while($dir!==$root&&str_starts_with($dir,$root.'/')){chmod($dir,0755);$dir=dirname($dir);}}
 $tmp=$dest.'.audit-release';if(!copy($stage.'/'.$p,$tmp))throw new RuntimeException('Copy failed');chmod($tmp,0644);if(!rename($tmp,$dest))throw new RuntimeException('Promotion failed');if(hash_file('sha256',$dest)!==$manifest['files'][$p])throw new RuntimeException('Installed checksum failed');}
 $run([$php,$root.'/artisan','config:clear'],'config-clear.log',$backup);
 $run([$php,$root.'/artisan','migrate','--path=database/migrations/2026_10_20_000003_create_ledger_read_model.php','--force'],'migration.log',$backup);
 $run([$php,'-r','require \'vendor/autoload.php\'; $a=require \'bootstrap/app.php\'; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $p=app(App\Services\LedgerProjection::class); $p->installTriggers();'],'projection-triggers.log',$backup);
 $run([$php,$root.'/artisan','reports:refresh','--rebuild','--activate','--max-seconds=120'],'projection-backfill.log',$backup);
 $run([$php,$root.'/artisan','route:clear'],'route-clear.log',$backup);
 $run([$php,$root.'/artisan','view:clear'],'view-clear.log',$backup);
 $verification=json_decode(trim($run([$php,$base.'/nuers-query-scale-verify.php'],'verification.log',$backup)),true,512,JSON_THROW_ON_ERROR);
 if(empty($verification['verified']))throw new RuntimeException('Fresh verification failed');
 if($before!==$snapshot())throw new RuntimeException('Existing records changed during deployment; review required');
 Artisan::call('up');unlink($root.'/.nuers-deploying');
 $result=['deployed'=>true,'release'=>$manifest['release'],'deployed_at'=>gmdate('c'),'verification'=>$verification,'existing_records_preserved'=>true,'environment_preserved'=>true,'files_verified'=>count($manifest['files']),'old_assets_retained'=>true,'backup'=>basename($backup),'package_sha256'=>$sha];
 file_put_contents($resultPath,json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));chmod($resultPath,0600);echo json_encode($result).PHP_EOL;
}catch(Throwable$e){
 try{$run([$php,'-r','require \'vendor/autoload.php\'; $a=require \'bootstrap/app.php\'; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); if(Illuminate\Support\Facades\Schema::hasTable(\'ledger_projection_state\')){Illuminate\Support\Facades\DB::table(\'ledger_projection_state\')->where(\'id\',1)->update([\'ready\'=>false]);}'],'projection-disabled.log',$backup);}catch(Throwable){}
 $restore=new ZipArchive;if($restore->open($backup.'/previous-files.zip')===true){$restore->extractTo($root);$restore->close();foreach($previous as$p)chmod($root.'/'.$p,0644);}
 // Additive read-model tables/indexes/queue triggers remain for retry; indexed reads are disabled. Original financial records are never rolled back.
 foreach(['route:clear','config:clear','view:clear']as$c){try{Artisan::call($c);}catch(Throwable){}}
 Artisan::call('up');if(file_exists($root.'/.nuers-deploying'))unlink($root.'/.nuers-deploying');
 $result=['deployed'=>false,'previous_files_restored'=>true,'error'=>$e->getMessage(),'backup'=>basename($backup)];file_put_contents($resultPath,json_encode($result,JSON_PRETTY_PRINT));echo json_encode($result).PHP_EOL;exit(1);
}
