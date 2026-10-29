<?php
if(PHP_SAPI!=='cli')exit(1);
umask(0077);$root=__DIR__.'/httpdocs';require $root.'/vendor/autoload.php';$app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(parse_url(config('app.url'),PHP_URL_HOST)!=='nuers.net')exit(2);
$probe=new class extends App\Http\Controllers\Api\OperationsReportController {public function read($r){return $this->indexedSales($r,false);}};
$out=[];
foreach(['daily','monthly','business','type','payment','branch'] as $g){try{$r=Illuminate\Http\Request::create('/','GET',['mode'=>'sales','per_page'=>10,'group'=>$g]);$t=microtime(true);$b=$probe->read($r)->getData(true);$out[$g]=['ok'=>true,'total'=>$b['total'],'groups'=>count($b['groups']),'ms'=>round((microtime(true)-$t)*1000,2)];}catch(Throwable$e){$out[$g]=['ok'=>false,'error'=>$e->getMessage()];}}
file_put_contents(__DIR__.'/nuers-sales-groups-probe-result.json',json_encode($out,JSON_PRETTY_PRINT));echo json_encode($out);
