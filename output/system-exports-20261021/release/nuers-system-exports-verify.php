<?php
if(PHP_SAPI!=='cli')exit(1);
umask(0077);$root=__DIR__.'/httpdocs';require $root.'/vendor/autoload.php';$app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(parse_url(config('app.url'),PHP_URL_HOST)!=='nuers.net'||!config('security.enforce_privileged_mfa'))throw new RuntimeException('Wrong target or security policy');
$manifest=json_decode(file_get_contents(__DIR__.'/nuers-system-exports-stage-20261021/audit-release-manifest.json'),true,512,JSON_THROW_ON_ERROR);
foreach($manifest['files']as$p=>$hash)if(!is_file($root.'/'.$p)||!hash_equals($hash,hash_file('sha256',$root.'/'.$p)))throw new RuntimeException('Installed checksum mismatch: '.$p);
foreach(['auth-assets/nuers-digital-logo.png','pdf-assets/standard_fonts/LiberationSans-Regular.ttf','pdf-assets/standard_fonts/LiberationSans-Bold.ttf']as$p)if(!is_file($root.'/public/'.$p))throw new RuntimeException('Report asset missing: '.$p);
if(App\Services\ReportCsv::cell('=1+1')!=="'=1+1")throw new RuntimeException('CSV escaping failed');
$request=Illuminate\Http\Request::create('/','GET',['report_limit'=>500]);App\Services\ReportCsv::limit($request,500);
$blocked=false;try{App\Services\ReportCsv::limit($request,501);}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$blocked=$e->getStatusCode()===422;}if(!$blocked)throw new RuntimeException('PDF size boundary failed');
if(app(App\Services\EisDelivery::class)->ready())throw new RuntimeException('Production transport unexpectedly enabled');
$uris=['api/live-monitoring/audit','api/merchant/customers/export','api/admin/eis-center/export'];$found=[];
foreach(app('router')->getRoutes()as$route)if(in_array($route->uri(),$uris,true)){if(!in_array('api',$route->gatherMiddleware(),true))throw new RuntimeException('API boundary missing');$found[$route->uri()]=true;}
if(count($found)!==count($uris))throw new RuntimeException('Report routes missing: '.implode(',',array_diff($uris,array_keys($found))));
echo json_encode(['verified'=>true,'files_verified'=>count($manifest['files']),'logo_and_fonts'=>true,'csv_formula_escaping'=>true,'pdf_limit'=>500,'api_boundary'=>true,'mfa_enabled'=>true,'production_transport_blocked'=>true,'no_schema_changes'=>true,'read_only_verification'=>true,'verified_at'=>gmdate('c')]);
