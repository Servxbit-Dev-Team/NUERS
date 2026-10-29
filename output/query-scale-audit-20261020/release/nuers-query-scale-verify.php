<?php
// CLI-only verification. No credential creation, source writes, test sales or external requests.
if(PHP_SAPI!=='cli')exit(1);
umask(0077);set_time_limit(150);
$root=__DIR__.'/httpdocs';
if(basename(__DIR__)!=='nuers.net'||!is_file($root.'/artisan'))throw new RuntimeException('Wrong target');
require $root.'/vendor/autoload.php';$app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\{DB,Schema};
$out=['verified'=>false,'checked_at'=>gmdate('c'),'business_data_read_only'=>true];
try{
 if(parse_url(config('app.url'),PHP_URL_HOST)!=='nuers.net'||!config('security.enforce_privileged_mfa'))throw new RuntimeException('Deployment policy mismatch');
 $out['database']=DB::selectOne('SELECT VERSION() AS v')->v;
 if(str_contains($out['database'],'MariaDB'))DB::statement('SET SESSION max_statement_time=15');else DB::statement('SET SESSION max_execution_time=15000');
 $projection=app(App\Services\LedgerProjection::class);if(!$projection->ready())throw new RuntimeException('Reporting index not ready');
 $out['backlog']=DB::table('ledger_dirty_documents')->count();if($out['backlog'])throw new RuntimeException('Backlog not cleared');
 $out['triggers']=(int)DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA',DB::connection()->getDatabaseName())->where('TRIGGER_NAME','like','ledger_%')->count();
 if($out['triggers']!==36)throw new RuntimeException('Invalidation trigger count mismatch');
 $out['projection_rows']=DB::table('ledger_documents')->count();$out['merchant_checks']=[];
 foreach(DB::table('merchants')->orderBy('id')->limit(100)->get() as $merchant){
  $start=microtime(true);
  $legacy=app(App\Services\IssuedDocumentLedger::class)->legacyQuery($merchant->id)->orderBy('id')->get(['id','document_source','document_id','identity_count']);
  $indexed=app(App\Services\IssuedDocumentLedger::class)->query($merchant->id)->orderBy('id')->get(['id','document_source','document_id','identity_count']);
  $signature=fn($rows)=>hash('sha256',json_encode($rows->map(fn($r)=>[$r->id,$r->document_source,$r->document_id,(int)$r->identity_count])->all()));
  if($signature($legacy)!==$signature($indexed))throw new RuntimeException('Canonical population mismatch');
  $filters=['from'=>now('Asia/Manila')->startOfMonth()->toDateString(),'to'=>now('Asia/Manila')->toDateString()];
  $full=app(App\Services\MerchantSalesReport::class)->generate($merchant,$filters);
  $page=app(App\Services\IndexedSalesReport::class)->page($merchant,$filters,1,10,'sales');
  if($full['count']!==$page['count']||$full['excluded_count']!==$page['excluded_count']||$full['totals']!==$page['totals'])throw new RuntimeException('Financial totals mismatch');
  if($indexed->contains(fn($r)=>$r->document_id && !DB::table('ledger_documents')->where('document_source',$r->document_source)->where('document_id',$r->document_id)->where('merchant_id',$merchant->id)->exists()))throw new RuntimeException('Tenant mismatch');
  $out['merchant_checks'][]=['records'=>$indexed->count(),'included'=>$page['count'],'excluded'=>$page['excluded_count'],'canonical_parity'=>true,'financial_parity'=>true,'elapsed_ms'=>round((microtime(true)-$start)*1000)];
 }
 $out['indexes']=['merchant_period'=>Schema::hasIndex('ledger_documents','ledger_merchant_period'),'merchant_documents'=>Schema::hasIndex('ledger_documents','ledger_merchant_document_order'),'admin_recent'=>Schema::hasIndex('merchant_transactions','mt_recent_order'),'option_covering'=>Schema::hasIndex('ledger_documents','ledger_type_options')];
 if(in_array(false,$out['indexes'],true))throw new RuntimeException('Missing reporting index');
 $routes=app('router')->getRoutes();$methods=[];foreach($routes as $route)if($route->uri()==='api/merchant/sales-reports/exports')$methods=array_merge($methods,$route->methods());
 if(!in_array('GET',$methods,true)||!in_array('POST',$methods,true))throw new RuntimeException('Export routes unavailable');
 $out['export_routes_registered']=true;$out['mfa_preserved']=true;$out['bir_transport_enabled']=(bool)config('bir_compliance.transport_implemented');
 $out['verified']=true;
}catch(Throwable $e){$out['error_class']=get_class($e);$out['error']=$e instanceof RuntimeException?$e->getMessage():'Database or application verification failed';file_put_contents(__DIR__.'/nuers-query-scale-verification-error.txt',(string)$e);}
file_put_contents(__DIR__.'/nuers-query-scale-verification.json',json_encode($out,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));echo json_encode($out).PHP_EOL;exit($out['verified']?0:1);
