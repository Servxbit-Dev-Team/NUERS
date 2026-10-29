<?php
if(PHP_SAPI!=='cli')exit(1);
umask(0077);$root=__DIR__.'/httpdocs';require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\{DB,Schema};
if(parse_url(config('app.url'),PHP_URL_HOST)!=='nuers.net'||!config('security.enforce_privileged_mfa'))throw new RuntimeException('Wrong target or security policy');
$m=json_decode(file_get_contents(__DIR__.'/nuers-pti-applications-stage-20261028/audit-release-manifest.json'),true,512,JSON_THROW_ON_ERROR);
foreach($m['files']as$p=>$h)if(!is_file($root.'/'.$p)||!hash_equals($h,hash_file('sha256',$root.'/'.$p)))throw new RuntimeException('Installed checksum mismatch: '.$p);
foreach(['pti_applications','pti_application_documents','pti_application_events']as$t)if(!Schema::hasTable($t))throw new RuntimeException('Missing application table: '.$t);
foreach(['pti_app_merchant_date','pti_app_status_date']as$i)if(!Schema::hasIndex('pti_applications',$i))throw new RuntimeException('Missing application index');
if(app(App\Services\EisDelivery::class)->ready()||config('bir_compliance.transport_implemented')||config('bir_compliance.official_interface_verified')||config('bir_compliance.production_response_verification_implemented'))throw new RuntimeException('BIR transport must remain blocked');
$catalog=app(App\Services\PtiApplications::class)->catalog();
if($catalog['direct_submission_available']!==false)throw new RuntimeException('Direct filing must not be enabled');
$routes=collect(app('router')->getRoutes()->getRoutes())->filter(fn($r)=>str_starts_with($r->uri(),'api/pti-applications'))->count();
if($routes!==9)throw new RuntimeException('Unexpected PTI route count: '.$routes);
try{app(App\Http\Controllers\Api\PtiApplicationController::class)->index(Illuminate\Http\Request::create('/api/pti-applications','GET'));throw new RuntimeException('Anonymous access unexpectedly allowed');}
catch(Symfony\Component\HttpKernel\Exception\HttpException $e){if($e->getStatusCode()!==401)throw $e;}
$html=view('reports.pti-application',['record'=>['reference'=>'DEPLOYMENT READ CHECK','revision'=>1,'status_label'=>'Synthetic rendering check','id'=>'not-a-record','updated_at'=>'not-a-record','payload'=>['identity'=>['business_name'=>'Synthetic verification'],'form'=>['scope'=>'No actual application','notes'=>'Not saved']]],'documents'=>collect(),'catalog'=>$catalog])->render();
if(!str_contains($html,'data:image/png;base64,')||!str_contains($html,'not an official BIR application'))throw new RuntimeException('Packet template rendering failed');
echo json_encode(['verified'=>true,'release'=>$m['release'],'php'=>PHP_VERSION,'files_verified'=>count($m['files']),'pti_routes'=>$routes,'schema_verified'=>true,'anonymous_access_denied'=>true,'mfa_enabled'=>true,'production_transport_blocked'=>true,'direct_pti_submission'=>false,'application_records'=>DB::table('pti_applications')->count(),'packet_template_verified'=>true,'verified_at'=>gmdate('c')]);
