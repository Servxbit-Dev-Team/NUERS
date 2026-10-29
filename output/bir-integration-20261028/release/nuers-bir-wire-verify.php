<?php
if(PHP_SAPI!=='cli')exit(1);
umask(0077);$root=__DIR__.'/httpdocs';require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\{DB,Schema};
if(parse_url(config('app.url'),PHP_URL_HOST)!=='nuers.net'||!config('security.enforce_privileged_mfa'))throw new RuntimeException('Wrong target or security policy');
$m=json_decode(file_get_contents(__DIR__.'/nuers-bir-wire-stage-20261028/audit-release-manifest.json'),true,512,JSON_THROW_ON_ERROR);
foreach($m['files']as$p=>$h)if(!is_file($root.'/'.$p)||!hash_equals($h,hash_file('sha256',$root.'/'.$p)))throw new RuntimeException('Installed checksum mismatch: '.$p);
$counts=[];foreach(['bir_eis_profiles','bir_eis_batches','bir_eis_documents','bir_eis_wire_attempts','bir_eis_callbacks']as$t){if(!Schema::hasTable($t))throw new RuntimeException('Missing wire table: '.$t);$counts[$t]=DB::table($t)->count();}
foreach(['bir_profile_application_unique']as$i)if(!Schema::hasIndex('bir_eis_profiles',$i))throw new RuntimeException('Missing profile index');
if(!Schema::hasIndex('bir_eis_documents','bir_document_reporting_lookup'))throw new RuntimeException('Missing document index');
if(app(App\Services\EisDelivery::class)->ready()||config('bir_eis.outbound_enabled')||config('bir_eis.callback_enabled')||config('bir_compliance.transport_implemented')||config('bir_compliance.official_interface_verified')||config('bir_compliance.production_response_verification_implemented'))throw new RuntimeException('BIR outbound must remain blocked');
$routes=collect(app('router')->getRoutes()->getRoutes())->filter(fn($r)=>str_starts_with($r->uri(),'api/admin/bir-wire'))->count();
if($routes!==16)throw new RuntimeException('Unexpected BIR wire route count: '.$routes);
try{app(App\Http\Controllers\Api\BirWireController::class)->index(Illuminate\Http\Request::create('/api/admin/bir-wire','GET'));throw new RuntimeException('Anonymous access unexpectedly allowed');}
catch(Symfony\Component\HttpKernel\Exception\HttpException $e){if($e->getStatusCode()!==401)throw $e;}
$plain='NUERS offline cryptographic self-check';$key='0123456789abcdefghijklmnopqrstuv';
if(App\Services\Eis\Protocol::decrypt(App\Services\Eis\Protocol::encrypt($plain,$key),$key)!==$plain)throw new RuntimeException('Crypto self-check failed');
$commands=Illuminate\Support\Facades\Artisan::all();if(!isset($commands['bir:wire-work']))throw new RuntimeException('Worker not registered');
$catalog=app(App\Services\PtiApplications::class)->catalog();if($catalog['direct_submission_available']!==false)throw new RuntimeException('Direct PTI filing must not be enabled');
echo json_encode(['verified'=>true,'release'=>$m['release'],'php'=>PHP_VERSION,'files_verified'=>count($m['files']),'wire_admin_routes'=>$routes,'schema_verified'=>true,'anonymous_access_denied'=>true,'mfa_enabled'=>true,'outbound_enabled'=>false,'callbacks_enabled'=>false,'direct_pti_submission'=>false,'worker_registered'=>true,'offline_crypto_check'=>true,'wire_record_counts'=>$counts,'verified_at'=>gmdate('c')]);
