<?php
if(PHP_SAPI!=='cli')exit(1);
umask(0077);set_time_limit(90);
$root=__DIR__.'/httpdocs';
if(basename(__DIR__)!=='nuers.net'||!is_file($root.'/artisan'))throw new RuntimeException('Wrong target');
require $root.'/vendor/autoload.php';$app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$out=['verified'=>false,'checked_at'=>gmdate('c'),'business_data_read_only'=>true];
try{
 if(parse_url(config('app.url'),PHP_URL_HOST)!=='nuers.net'||!config('security.enforce_privileged_mfa'))throw new RuntimeException('Deployment policy mismatch');
 $manifest=json_decode(file_get_contents(__DIR__.'/nuers-transaction-reports-stage-20261020/audit-release-manifest.json'),true,512,JSON_THROW_ON_ERROR);
 foreach($manifest['files']as$p=>$hash)if(!is_file($root.'/'.$p)||hash_file('sha256',$root.'/'.$p)!==$hash)throw new RuntimeException('Installed checksum mismatch');
 $start=microtime(true);$controller=app(App\Http\Controllers\Api\TransactionWorkspaceController::class);
 $filtered=new ReflectionMethod($controller,'filtered');$normalize=new ReflectionMethod($controller,'reportRow');
 $checks=DB::transaction(function()use($controller,$filtered,$normalize){
  [$q]=$filtered->invoke($controller,Illuminate\Http\Request::create('/api/transaction-workspace','GET',['per_page'=>1]));
  $count=(clone$q)->count();$row=(clone$q)->orderByDesc('created_at')->orderByDesc('id')->first();
  if($row){$normalized=$normalize->invoke($controller,$row);if($normalized['intake_environment']!=='live')throw new RuntimeException('Live isolation mismatch');
   [$one]=$filtered->invoke($controller,Illuminate\Http\Request::create('/api/transaction-workspace','GET',['merchant'=>$row->merchant_id,'ids'=>[$row->id],'min'=>(string)$row->amount,'max'=>(string)$row->amount]));
   if($one->count()!==1)throw new RuntimeException('Selected merchant/amount scope mismatch');
   [$empty]=$filtered->invoke($controller,Illuminate\Http\Request::create('/api/transaction-workspace','GET',['merchant'=>'__nuers_no_such_merchant__','ids'=>[$row->id]]));
   if($empty->count()!==0)throw new RuntimeException('Export filter intersection failed');
  }
  [$discount]=$filtered->invoke($controller,Illuminate\Http\Request::create('/api/transaction-workspace','GET',['discount'=>'manual','vat'=>'mixed','identity'=>'unique','date_basis'=>'received','from'=>'2026-10-01','to'=>'2026-10-31','source'=>'receipt','channel'=>'api','customer'=>'__nuers_synthetic_missing__']));
  $discount->count();
  return ['live_register_query'=>true,'selected_scope_intersection'=>true,'strict_mysql_advanced_filters'=>true,'observed_live_documents'=>$count];
 });
 $report=app(App\Services\TransactionReportExport::class)->report([],['search'=>'SYNTHETIC PRIVATE RUNTIME CHECK'],(object)['id'=>'deployment-verifier','name'=>'Deployment verifier'],'sandbox');
 $pdf=app(App\Services\DocumentPdf::class)->renderHtml(view('reports.admin-transactions',['report'=>$report])->render());
 if(!str_starts_with($pdf,'%PDF-')||strlen($pdf)<10000)throw new RuntimeException('Branded report render failed');
 $out+=$checks+['branded_pdf_render'=>true,'pdf_bytes'=>strlen($pdf),'files_verified'=>count($manifest['files']),'schema_migration'=>false,'mfa_preserved'=>true,'elapsed_ms'=>round((microtime(true)-$start)*1000,2)];$out['verified']=true;
}catch(Throwable$e){$out['error']=$e instanceof RuntimeException?$e->getMessage():get_class($e).': '.$e->getMessage();}
file_put_contents(__DIR__.'/nuers-transaction-reports-verification.json',json_encode($out,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));echo json_encode($out).PHP_EOL;exit($out['verified']?0:1);
