<?php
require __DIR__.'/verify-webhook.php';
$root=dirname(__DIR__,3);require $root.'/vendor/autoload.php';
$cfg=['secret'=>'synthetic-secret-only','key_version'=>'1','environment'=>'sandbox','merchant_id'=>'test-merchant'];
$event=['id'=>'44444444-4444-4444-8444-444444444444','environment'=>'sandbox','merchant_id'=>'test-merchant','type'=>'webhook.test'];
$raw=json_encode($event);$ts=(string)time();
$h=['x-nuers-event'=>$event['id'],'x-nuers-timestamp'=>$ts,'x-nuers-signature'=>'v1='.App\Services\SignedWebhook::signature($cfg['secret'],$event['id'],$ts,$raw),'x-nuers-environment'=>'sandbox','x-nuers-key-version'=>'1'];
if(verifyNuers($raw,$h,$cfg)!==$event)throw new RuntimeException('Valid signature rejected');
$cases=[[$raw.' ',$h,$cfg],[$raw,array_replace($h,['x-nuers-environment'=>'live']),$cfg],[$raw,array_replace($h,['x-nuers-key-version'=>'2']),$cfg],[$raw,array_replace($h,['x-nuers-timestamp'=>(string)(time()-301)]),$cfg],[$raw,$h,array_replace($cfg,['merchant_id'=>'other-merchant'])]];
foreach($cases as $args){$failed=false;try{verifyNuers(...$args);}catch(Throwable){$failed=true;}if(!$failed)throw new RuntimeException('Invalid webhook accepted');}
echo json_encode(['webhook_cases_passed'=>count($cases)+1,'matched_current_signer'=>true]).PHP_EOL;
