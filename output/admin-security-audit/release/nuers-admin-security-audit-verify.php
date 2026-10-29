<?php
if(PHP_SAPI!=='cli')exit(1);
umask(0077);
$root=__DIR__.'/httpdocs';
if(basename(__DIR__)!=='nuers.net')throw new RuntimeException('Wrong domain');
require $root.'/vendor/autoload.php';$app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$checks=[];
$kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);
foreach(['/api/verify-receipt/AUDIT-NONEXISTENT','/api/business-accounts/lookup?tin=000000000','/api/admin/infrastructure/servers','/api/tables/profiles','/api/merchant/api-keys'] as $path){
 $request=Illuminate\Http\Request::create('https://nuers.net'.$path,'GET',[],[],[],['HTTP_ACCEPT'=>'application/json','REMOTE_ADDR'=>'127.0.0.1']);
 $response=$kernel->handle($request);$checks[$path]=$response->getStatusCode();
 // Maintenance middleware returns 503 while the installer holds maintenance mode;
 // invoke the API boundary directly with the same anonymous request as a second check.
 if($checks[$path]===503){try{app(App\Http\Middleware\ApiBoundary::class)->handle($request,fn()=>response()->json(['unexpected_dispatch'=>true]));$checks[$path]=200;}catch(Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e){$checks[$path]=$e->getStatusCode();}}
}
$receipts=collect(Illuminate\Support\Facades\Route::getRoutes())->first(fn($r)=>$r->uri()==='api/verify-receipt/{receiptNumber}');
$mfa=config('security.enforce_privileged_mfa')===true;$debug=config('app.debug')===false;
$proof=['verified'=>count(array_filter($checks,fn($s)=>$s!==401))===0&&$mfa&&$debug&&str_contains($receipts?->getActionName()??'','ReceiptVerificationController'),'software'=>config('bir_compliance.solution_name'),'version'=>config('bir_compliance.solution_version'),'anonymous_protected_responses'=>$checks,'privileged_mfa'=>$mfa,'production_debug_disabled'=>$debug,'database_query'=>Illuminate\Support\Facades\DB::selectOne('SELECT 1 AS ok')->ok==1,'cache_driver'=>config('cache.default'),'queue_driver'=>config('queue.default'),'controller_execution_disabled'=>!config('infrastructure.execution_enabled'),'schema_migration'=>false,'php'=>PHP_VERSION];
echo json_encode($proof,JSON_THROW_ON_ERROR).PHP_EOL;
if(!$proof['verified']||!$proof['database_query'])exit(1);
