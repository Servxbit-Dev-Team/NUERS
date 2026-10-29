<?php
if (PHP_SAPI !== 'cli') exit(1);
umask(0077);
$root=__DIR__.'/httpdocs';
require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\{DB,Schema,Route};
$tables=['infra_state','infra_nodes','infra_samples','infra_proofs','infra_actions','infra_approvals','infra_events'];
foreach($tables as$t)if(!Schema::hasTable($t))throw new RuntimeException('Missing table '.$t);
$routes=collect(Route::getRoutes())->filter(fn($r)=>str_starts_with($r->uri(),'api/admin/infrastructure'))->count();
$codes=DB::table('infra_nodes')->orderBy('code')->pluck('code')->all();
$expected=array_column(App\Services\Infrastructure\Catalog::inventory(),0);sort($expected);
$valid=$codes===$expected && DB::table('infra_nodes')->where('enrollment','planned')->count()===9 && !DB::table('infra_nodes')->whereNotNull('telemetry')->exists();
$disabled=!config('infrastructure.enabled')&&!config('infrastructure.execution_enabled')&&!app(App\Services\Infrastructure\ControllerGateway::class)->ready();
$audit=app(App\Services\Infrastructure\Audit::class)->verify();
$denied=false;try{app(App\Http\Controllers\Api\ServerManagementController::class)->index(Illuminate\Http\Request::create('/api/admin/infrastructure/servers'));}catch(Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e){$denied=in_array($e->getStatusCode(),[401,403],true);}
$proof=['verified'=>$valid&&$disabled&&$audit&&$routes===11&&$denied,'planned_nodes'=>count($codes),'enrolled_nodes'=>DB::table('infra_nodes')->where('enrollment','enrolled')->count(),'controller_disabled'=>$disabled,'execution_disabled'=>!config('infrastructure.execution_enabled'),'routes'=>$routes,'unauthenticated_denied'=>$denied,'audit_valid'=>$audit,'actions'=>DB::table('infra_actions')->count(),'privileged_mfa'=>config('security.enforce_privileged_mfa')===true,'php'=>PHP_VERSION];
echo json_encode($proof,JSON_THROW_ON_ERROR).PHP_EOL;
if(!$proof['verified']||!$proof['privileged_mfa'])exit(1);
