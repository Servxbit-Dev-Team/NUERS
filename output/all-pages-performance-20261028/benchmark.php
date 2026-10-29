<?php
require dirname(__DIR__).'/sales-summary-performance-20261021/lab/bootstrap.php';
config(['cache.stores.file.path'=>__DIR__.'/lab-cache','cache.stores.file.lock_path'=>__DIR__.'/lab-cache']);
use Illuminate\Support\Facades\DB;
if(getenv('COLD_BENCH')) Illuminate\Support\Facades\Cache::flush();
use Illuminate\Http\Request;
use Carbon\CarbonImmutable;
use Carbon\Carbon;
CarbonImmutable::setTestNow('2026-10-20 02:00:00');Carbon::setTestNow('2026-10-20 02:00:00');
$actors=json_decode(file_get_contents(dirname(__DIR__).'/sales-summary-performance-20261021/lab/synthetic-actors.json'),true);
$kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);
$queries=[];DB::listen(function($q)use(&$queries){$queries[]=['ms'=>$q->time,'sql'=>$q->sql,'bindings'=>$q->bindings];});
$cases=[
 ['admin_reconciliation','admin','/api/operations-report?mode=reconciliation'],
 ['admin_reconciliation_deep','admin','/api/operations-report?mode=reconciliation&page=100000'],
 ['admin_sales','admin','/api/operations-report?mode=sales'],
 ['admin_transactions_deep','admin','/api/transaction-workspace?page=100000'],
 ['admin_registry','admin','/api/business-registry'],
 ['admin_wallets','admin','/api/admin/wallets'],
 ['merchant_wallet','owner','/api/merchant/wallet'],
 ['admin_api_connections','admin','/api/admin/merchant-connections'],
 ['admin_eis_center','admin','/api/admin/eis-center'],

 ['merchant_dashboard','owner','/api/merchant/overview?days=30'],
 ['merchant_report','owner','/api/merchant/sales-reports?from=2026-09-21&to=2026-10-20'],
 ['merchant_report_deep','owner','/api/merchant/sales-reports?from=2026-09-21&to=2026-10-20&page=100000'],
 ['merchant_documents','owner','/api/merchant/records?mode=documents'],
 ['merchant_payments','owner','/api/merchant/records?mode=transactions'],
 ['merchant_documents_deep','owner','/api/merchant/records?mode=documents&page=100000'],
 ['merchant_payments_deep','owner','/api/merchant/records?mode=transactions&page=100000'],
 ['admin_dashboard','admin','/api/enterprise-dashboards/admin?period=month'],
 ['admin_live_monitor','admin','/api/live-monitoring'],
 ['admin_transactions','admin','/api/transaction-workspace'],
 ['admin_documents','admin','/api/invoice-center'],
 ['other_merchant_report','other','/api/merchant/sales-reports?from=2026-09-21&to=2026-10-20&merchant_id=lab-merchant-a'],
];
if (($argv[1]??'')==='remaining') $cases=array_values(array_filter($cases,fn($c)=>!in_array($c[0],['merchant_dashboard','merchant_report','merchant_report_deep'])));
elseif(isset($argv[1]))$cases=array_values(array_filter($cases,fn($c)=>str_contains($c[0],$argv[1])));
$results=[];
foreach($cases as [$name,$actor,$url]){
 for($i=0;$i<(getenv('BENCH_RUNS')?:2);$i++){
  $queries=[];memory_reset_peak_usage();$start=microtime(true);
  $req=Request::create($url,'GET',server:['HTTP_ACCEPT'=>'application/json','HTTP_AUTHORIZATION'=>'Bearer '.$actors[$actor],'REMOTE_ADDR'=>'127.0.0.20']);
  $res=$kernel->handle($req);$elapsed=round((microtime(true)-$start)*1000,2);$body=json_decode($res->getContent(),true);$kernel->terminate($req,$res);
  $slow=$queries;usort($slow,fn($a,$b)=>$b['ms']<=>$a['ms']);
  $result=['case'=>$name,'run'=>$i+1,'http'=>$res->getStatusCode(),'ms'=>$elapsed,'peak_mb'=>round(memory_get_peak_usage(true)/1048576,2),'bytes'=>strlen($res->getContent()),'queries'=>count($queries),'db_ms'=>round(array_sum(array_column($queries,'ms')),2),'reported_count'=>$body['count']??$body['total']??$body['pagination']['total']??null,'gross_centavos'=>$body['totals']['gross']??null,'error'=>$res->getStatusCode()>=400?($body['message']??'Unknown'):null];
  echo json_encode($result)."\n";flush();$results[]=$result;
  if($i===0 && $slow){try {file_put_contents(__DIR__.'/explain-'.$name.'.json',json_encode(DB::select('EXPLAIN '.$slow[0]['sql'],$slow[0]['bindings']),JSON_PRETTY_PRINT));}catch(Throwable $e){}}
  if($i===0)file_put_contents(__DIR__.'/queries-'.$name.'.json',json_encode(array_slice($slow,0,8),JSON_PRETTY_PRINT));
 }
}
file_put_contents(__DIR__.'/benchmark'.(isset($argv[1])?'-'.$argv[1]:'').'.json',json_encode(['engine'=>DB::selectOne('SELECT VERSION() AS version')->version,'records'=>DB::table('ledger_documents')->count(),'results'=>$results],JSON_PRETTY_PRINT));
