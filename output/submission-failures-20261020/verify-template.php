<?php
if(PHP_SAPI!=='cli')exit(1);
umask(0077);$root=__DIR__.'/httpdocs';require $root.'/vendor/autoload.php';$app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\{DB,Schema};
if(parse_url(config('app.url'),PHP_URL_HOST)!=='nuers.net'||!config('security.enforce_privileged_mfa'))throw new RuntimeException('Wrong target or security policy');
foreach(['environment','source_event_id','error_code','outcome']as$c)if(!Schema::hasColumn('merchant_api_request_logs',$c))throw new RuntimeException('Missing outcome column');
$live=app(App\Services\SubmissionAttempts::class)->failures('live');$sandbox=app(App\Services\SubmissionAttempts::class)->failures('sandbox');
$counts=['live_failed_attempts'=>(clone$live)->count(),'sandbox_failed_attempts'=>(clone$sandbox)->count(),'recorded_invalid_transactions'=>DB::table('merchant_transactions')->whereIn('status',['invalid','failed','rejected','voided','cancelled'])->count()];
$byStatus=(clone$live)->select('l.status_code')->selectRaw('COUNT(*) as attempts')->groupBy('l.status_code')->get();
$request=Illuminate\Http\Request::create('https://nuers.net/auth/google/session','GET',[],[],[],['HTTP_ACCEPT'=>'text/html','DOCUMENT_ROOT'=>$root]);$app->instance('request',$request);
$response=$app->make(Illuminate\Contracts\Debug\ExceptionHandler::class)->render($request,new Symfony\Component\HttpKernel\Exception\HttpException(429,'PRIVATE CHECK',null,['Retry-After'=>37]));
if($response->getStatusCode()!==429||$response->headers->get('Retry-After')!=='37'||!str_contains($response->getContent(),'Try again in 37 seconds')||!str_contains($response->getContent(),'/public/auth-assets/nuers-digital-white.png'))throw new RuntimeException('429 renderer failed');
if(str_contains($response->getContent(),'PRIVATE CHECK'))throw new RuntimeException('Unexpected exception disclosure');
$protected=false;foreach(app('router')->getRoutes()as$route)if($route->uri()==='api/admin/submission-attempts')$protected=in_array('api',$route->gatherMiddleware(),true);
if(!$protected)throw new RuntimeException('Endpoint API boundary missing');
echo json_encode(['verified'=>true,'outcome_schema'=>true,'counts'=>$counts,'live_http_breakdown'=>$byStatus,'branded_429'=>true,'retry_header_preserved'=>true,'api_boundary'=>true,'mfa_enabled'=>true,'read_only_verification'=>true,'verified_at'=>gmdate('c')]);
