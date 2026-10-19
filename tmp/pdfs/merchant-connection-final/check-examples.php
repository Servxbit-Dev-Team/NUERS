<?php
$root=dirname(__DIR__,3);
require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$contract=app(App\Services\IntegrationContract::class);
$rows=json_decode(file_get_contents(__DIR__.'/examples.json'),true,flags:JSON_THROW_ON_ERROR);
$results=[];
foreach($rows as $row){
 $v=$contract->validateEvent($row['payload']);
 $tax=$contract->calculateDocument($v,$row['vat']);
 $doc=$contract->resolveDocument($v,$tax);
 foreach($row['expected']as$key=>$val){
  if(abs($tax[$key]-$val)>0.00001)throw new RuntimeException($row['name'].' mismatched '.$key);
 }
 $results[]=['case'=>$row['name'],'document'=>$doc['type'],'totals_match'=>true];
}
echo json_encode(['passed'=>count($results),'checks'=>$results],JSON_PRETTY_PRINT).PHP_EOL;
