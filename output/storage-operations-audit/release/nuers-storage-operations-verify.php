<?php
if(PHP_SAPI!=='cli')exit(1);
umask(0077);
$root=__DIR__.'/httpdocs';
if(basename(__DIR__)!=='nuers.net')throw new RuntimeException('Wrong domain');
require $root.'/vendor/autoload.php';$app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\{DB,Schema};
$checks=[];
foreach(['/api/operations/readiness','/api/verify-receipt/AUDIT-NONEXISTENT','/api/business-accounts/lookup?tin=000000000','/api/admin/infrastructure/servers','/api/tables/profiles','/api/merchant/api-keys']as$path){
 $r=Illuminate\Http\Request::create('https://nuers.net'.$path,'GET',[],[],[],['HTTP_ACCEPT'=>'application/json','REMOTE_ADDR'=>'127.0.0.1']);
 try{app(App\Http\Middleware\ApiBoundary::class)->handle($r,fn()=>response()->json(['unexpected_dispatch'=>true]));$checks[$path]=200;}catch(Symfony\Component\HttpKernel\Exception\HttpExceptionInterface$e){$checks[$path]=$e->getStatusCode();}
}
$health=app(App\Services\OperationsHealth::class)->collect();
$integrity=app(App\Services\OperationsHealth::class)->integrity();
$proof=['verified'=>Schema::hasTable('operations_observations')&&count(array_filter($checks,fn($s)=>$s!==401))===0&&config('security.enforce_privileged_mfa')===true&&config('app.debug')===false&&$health['acceptance_ready'], 'software'=>config('bir_compliance.solution_name'),'version'=>config('bir_compliance.solution_version'),'anonymous_protected_responses'=>$checks,'storage_operations'=>$health,'integrity'=>$integrity,'privileged_mfa'=>config('security.enforce_privileged_mfa'),'production_debug_disabled'=>!config('app.debug'),'database_query'=>DB::selectOne('SELECT 1 AS ok')->ok==1,'cache_driver'=>config('cache.default'),'queue_driver'=>config('queue.default'),'controller_execution_disabled'=>!config('infrastructure.execution_enabled'),'backup_tool_enabled'=>(bool)config('operations.backup.enabled'),'offsite_storage'=>'not_provisioned','bir_transmission'=>'deferred','php'=>PHP_VERSION];
echo json_encode($proof,JSON_THROW_ON_ERROR).PHP_EOL;
if(!$proof['verified'])exit(1);
