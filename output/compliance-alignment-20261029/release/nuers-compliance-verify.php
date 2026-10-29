<?php
if(PHP_SAPI!=='cli')exit(1);
umask(0077);$root=__DIR__.'/httpdocs';require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\{DB,Schema};
if(parse_url(config('app.url'),PHP_URL_HOST)!=='nuers.net'||!config('security.enforce_privileged_mfa'))throw new RuntimeException('Wrong target or security policy');
$m=json_decode(file_get_contents(__DIR__.'/nuers-compliance-stage-20261029/audit-release-manifest.json'),true,512,JSON_THROW_ON_ERROR);
foreach($m['files']as$p=>$h)if(!is_file($root.'/'.$p)||!hash_equals($h,hash_file('sha256',$root.'/'.$p)))throw new RuntimeException('Installed checksum mismatch: '.$p);
foreach(['merchant_compliance_records','service_fee_invoice_entries']as$t)if(!Schema::hasTable($t)||DB::table($t)->count()!==0)throw new RuntimeException('New evidence table unexpectedly missing or populated');
foreach(['system_reference','branch_notification_reference']as$c)if(!Schema::hasColumn('taxpayer_authorizations',$c))throw new RuntimeException('Missing permit field');
foreach(['withholding_details','manual_recovery_id']as$c)if(!Schema::hasColumn('business_invoices',$c))throw new RuntimeException('Missing invoice field');
if(!Schema::hasIndex('taxpayer_authorizations','permit_branch_identity'))throw new RuntimeException('Missing permit identity index');
if(config('bir_eis.outbound_enabled')||config('bir_eis.callback_enabled')||app(App\Services\EisDelivery::class)->ready()||config('bir_compliance.source_capture_accreditation_enforced'))throw new RuntimeException('BIR / intake policy changed');
app(App\Services\BirCompliance::class)->assertSourceCaptureAllowed();
$routes=collect(app('router')->getRoutes()->getRoutes())->filter(fn($r)=>str_starts_with($r->uri(),'api/compliance-operations'))->count();
if($routes!==6)throw new RuntimeException('Unexpected compliance routes: '.$routes);
try{app(App\Http\Controllers\Api\ComplianceOperationsController::class)->index(Illuminate\Http\Request::create('/api/compliance-operations','GET'));throw new RuntimeException('Anonymous access unexpectedly allowed');}
catch(Symfony\Component\HttpKernel\Exception\HttpException $e){if($e->getStatusCode()!==401)throw $e;}
$fields=json_decode(file_get_contents($root.'/resources/data/developer-openapi.json'),true)['paths']['/api/integrations/transactions']['post']['requestBody']['content']['application/json']['schema']['properties'];
if(!isset($fields['withholding_details'],$fields['manual_recovery_id'],$fields['manual_invoice_number']))throw new RuntimeException('OpenAPI contract incomplete');
if(config('infrastructure.enabled')||config('infrastructure.execution_enabled'))throw new RuntimeException('Infrastructure controller changed');
echo json_encode(['verified'=>true,'release'=>$m['release'],'php'=>PHP_VERSION,'files_verified'=>count($m['files']),'compliance_routes'=>$routes,'schema_verified'=>true,'anonymous_access_denied'=>true,'mfa_enabled'=>true,'outbound_enabled'=>false,'callbacks_enabled'=>false,'source_intake_enforced'=>false,'source_intake_available'=>true,'billing_issuer_configured'=>(bool)config('bir_compliance.service_invoice_issuer_id'),'openapi_aligned'=>true,'guide_sha256'=>hash_file('sha256',$root.'/public/guides/NUERS-API-Connection-Guide.pdf'),'verified_at'=>gmdate('c')]);
