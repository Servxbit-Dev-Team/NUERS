<?php
require __DIR__.'/bootstrap.php';
$q=json_decode(file_get_contents(__DIR__.'/queries-all.json'),true)[0]['sql'];
foreach(['use index (ledger_sales_summary,ledger_sales_recent,ledger_sales_amount,PRIMARY)','force index (ledger_sales_summary)']as$hint){$sql=str_replace('`ledger_documents`','`ledger_documents` '.$hint,$q);$t=microtime(true);echo json_encode(Illuminate\Support\Facades\DB::select('EXPLAIN '.$sql,[1,'success']))."\n";Illuminate\Support\Facades\DB::select($sql,[1,'success']);echo round(microtime(true)-$t,3)."s\n";}
