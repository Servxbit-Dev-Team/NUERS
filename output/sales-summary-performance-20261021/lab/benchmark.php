<?php
require __DIR__.'/bootstrap.php';
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
$actors=json_decode(file_get_contents(__DIR__.'/synthetic-actors.json'),true);
$kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);
$queries=[];DB::listen(function($q)use(&$queries){$queries[]=['ms'=>$q->time,'sql'=>$q->sql];});
$cases=[['all','/api/operations-report?mode=sales&per_page=10'],['page2','/api/operations-report?mode=sales&per_page=10&page=2'],['deep','/api/operations-report?mode=sales&per_page=10&page=100000'],['merchant_dates','/api/operations-report?mode=sales&merchant=lab-merchant-a&from=2026-10-20&to=2026-10-20'],['search','/api/operations-report?mode=sales&search=LAB-0000999'],['business','/api/operations-report?mode=sales&group=business']];
$results=[];
foreach($cases as [$name,$url])for($i=0;$i<2;$i++){
 $queries=[];memory_reset_peak_usage();$start=microtime(true);
 $req=Request::create($url,'GET',server:['HTTP_ACCEPT'=>'application/json','HTTP_AUTHORIZATION'=>'Bearer '.$actors['admin'],'REMOTE_ADDR'=>'127.0.0.21']);
 $res=$kernel->handle($req);$ms=round((microtime(true)-$start)*1000,2);$body=json_decode($res->getContent(),true);$kernel->terminate($req,$res);
 $row=['case'=>$name,'run'=>$i+1,'http'=>$res->getStatusCode(),'ms'=>$ms,'peak_mb'=>round(memory_get_peak_usage(true)/1048576,2),'bytes'=>strlen($res->getContent()),'queries'=>count($queries),'db_ms'=>round(array_sum(array_column($queries,'ms')),2),'total'=>$body['total']??null,'rows'=>count($body['data']??[]),'sales'=>$body['summary']['sales']??null,'query_mode'=>$body['query_mode']??null,'error'=>$res->getStatusCode()>=400?($body['message']??'Unknown'):null];
 echo json_encode($row)."\n";flush();$results[]=$row;
 if($i===0){$slow=$queries;usort($slow,fn($a,$b)=>$b['ms']<=>$a['ms']);file_put_contents(__DIR__.'/queries-'.$name.'.json',json_encode(array_slice($slow,0,10),JSON_PRETTY_PRINT));}
 file_put_contents(__DIR__.'/benchmark.json',json_encode(['engine'=>DB::selectOne('SELECT VERSION() as version')->version,'records'=>DB::table('ledger_documents')->count(),'results'=>$results],JSON_PRETTY_PRINT));
}
