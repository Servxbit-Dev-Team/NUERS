<?php
require dirname(__DIR__).'/sales-summary-performance-20261021/lab/bootstrap.php';
use Illuminate\Support\Facades\DB;
$q=json_decode(file_get_contents(__DIR__.'/queries-merchant_report.json'),true)[0];
foreach(['PRIMARY','ledger_merchant_period'] as $idx){
 $sql=str_replace('from `ledger_documents` where','from `ledger_documents` FORCE INDEX ('.$idx.') where',$q['sql']);
 $t=microtime(true);$r=DB::selectOne($sql,$q['bindings']);
 echo json_encode(['index'=>$idx,'ms'=>round((microtime(true)-$t)*1000,2),'count'=>$r->total,'gross'=>$r->gross])."\n";flush();
}
