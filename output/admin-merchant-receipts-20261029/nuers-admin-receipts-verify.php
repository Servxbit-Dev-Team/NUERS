<?php
if (PHP_SAPI !== 'cli') exit(1);
umask(0077);
$root=__DIR__.'/httpdocs';
require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(parse_url(config('app.url'),PHP_URL_HOST)!=='nuers.net'||!config('security.enforce_privileged_mfa'))throw new RuntimeException('Wrong target or security policy');
$manifest=json_decode(file_get_contents(__DIR__.'/nuers-admin-receipts-stage-20261029/audit-release-manifest.json'),true,512,JSON_THROW_ON_ERROR);
foreach($manifest['files'] as $path=>$hash)if(!is_file($root.'/'.$path)||!hash_equals($hash,hash_file('sha256',$root.'/'.$path)))throw new RuntimeException('Installed checksum mismatch: '.$path);
if(config('bir_eis.outbound_enabled')||config('bir_eis.callback_enabled')||config('bir_compliance.source_capture_accreditation_enforced'))throw new RuntimeException('BIR / intake policy changed');
app(App\Services\BirCompliance::class)->assertSourceCaptureAllowed();
if(config('infrastructure.enabled')||config('infrastructure.execution_enabled'))throw new RuntimeException('Infrastructure controller changed');
$request=Illuminate\Http\Request::create('/api/invoice-center/receipt/verification-placeholder/pdf','GET');
$route=$app['router']->getRoutes()->match($request);
if($route->getActionName()!=='App\\Http\\Controllers\\Api\\InvoiceCenterController@pdf')throw new RuntimeException('PDF route missing');
if(!in_array('throttle:20,1,admin-document-pdf:',$route->gatherMiddleware(),true))throw new RuntimeException('PDF throttle missing');
echo json_encode(['verified'=>true,'release'=>$manifest['release'],'files_verified'=>count($manifest['files']),'pdf_route_verified'=>true,'mfa_enabled'=>true,'outbound_enabled'=>false,'source_intake_available'=>true,'verified_at'=>gmdate('c')]);
