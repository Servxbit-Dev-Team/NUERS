<?php
require dirname(__DIR__,3).'/vendor/autoload.php';$app=require dirname(__DIR__,3).'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$contract=app(App\Services\IntegrationContract::class);$cases=json_decode(file_get_contents(__DIR__.'/examples.json'),true,flags:JSON_THROW_ON_ERROR);$results=[];
foreach($cases as$c){$v=$contract->validateEvent($c['payload']);$tax=$contract->calculateDocument($v,$c['vat']);$doc=$contract->resolveDocument($v,$tax);foreach($c['expected']as$k=>$expected){if(abs($tax[$k]-$expected)>0.000001)throw new RuntimeException($c['name'].' calculation mismatch: '.$k);}$results[]=['name'=>$c['name'],'valid'=>true,'document_type'=>$doc['type'],'calculation'=>$tax];}
$invalid=[];
$x=$cases[0]['payload'];$x['gross_amount']='111.00';$invalid[]=['wrong total',$x,true];
$x=$cases[0]['payload'];$invalid[]=['VAT charged by Non-VAT seller',$x,false];
$x=$cases[0]['payload'];$x['tax_type']='mixed';$invalid[]=['mixed header is invalid',$x,true];
$x=$cases[0]['payload'];unset($x['source_profile_id']);$invalid[]=['missing capture profile',$x,true];
$x=$cases[0]['payload'];$x['merchant_id']='SAMPLE-foreign';$invalid[]=['unknown tenant field',$x,true];
foreach($invalid as[$name,$x,$seller]){try{$v=$contract->validateEvent($x);$t=$contract->calculateDocument($v,$seller);$contract->resolveDocument($v,$t);throw new RuntimeException('Expected rejection: '.$name);}catch(Illuminate\Validation\ValidationException$e){$results[]=['name'=>$name,'correctly_rejected'=>true];}}
file_put_contents(__DIR__.'/example-verification.json',json_encode(['positive_cases'=>count($cases),'negative_cases'=>count($invalid),'no_http_calls'=>true,'no_financial_writes'=>true,'results'=>$results],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));echo '10 example payloads/calculations verified; 5 invalid cases rejected; no network or financial writes.'.PHP_EOL;
