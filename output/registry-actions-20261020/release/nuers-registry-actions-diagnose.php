<?php
if(PHP_SAPI!=='cli')exit(1);umask(0077);
$root=__DIR__.'/httpdocs';if(basename(__DIR__)!=='nuers.net')exit(1);
require $root.'/vendor/autoload.php';$app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\{DB,Schema};
$snapshot=function()use($root){$r=['env'=>hash_file('sha256',$root.'/.env'),'security'=>hash('sha256',json_encode(config('security')))];foreach(['merchant_wallets'=>'merchant_id','merchant_wallet_entries'=>'id','merchant_wallet_topups'=>'id','profiles'=>'id','merchant_api_keys'=>'id','merchant_verification_documents'=>'id','transaction_receipts'=>'id','business_invoices'=>'id','merchant_transactions'=>'id']as$t=>$key)if(Schema::hasTable($t)){$h=hash_init('sha256');foreach(DB::table($t)->orderBy($key)->lazyById(200,$key) as $row)hash_update($h,json_encode($row)."\n");$r[$t]=hash_final($h);}return$r;};

$before=json_decode(file_get_contents(__DIR__.'/nuers-registry-actions-backup-20261020-045856/preservation-hashes.json'),true);$current=$snapshot();$changed=[];foreach($before as$k=>$v)if($v!==($current[$k]??null))$changed[]=$k;
echo json_encode(['read_only'=>true,'changed_since_attempt'=>$changed,'maintenance_active'=>file_exists($root.'/storage/framework/down'),'deployment_marker'=>file_exists($root.'/.nuers-deploying'),'current_counts'=>['transactions'=>DB::table('merchant_transactions')->count(),'receipts'=>DB::table('transaction_receipts')->count()]]).PHP_EOL;
