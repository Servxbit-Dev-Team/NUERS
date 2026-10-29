<?php
if(PHP_SAPI!=='cli')exit(1);
umask(0077);$root=__DIR__.'/httpdocs';require $root.'/vendor/autoload.php';$app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\{DB,Schema};
if(parse_url(config('app.url'),PHP_URL_HOST)!=='nuers.net'||!config('security.enforce_privileged_mfa'))throw new RuntimeException('Wrong target or security policy');
foreach(['eis_preparation_batches','eis_preparation_batch_items']as$t)if(!Schema::hasTable($t))throw new RuntimeException('Missing batch table');
$center=app(App\Services\EisTransmissionCenter::class);$q=$center->query([]);$total=(clone$q)->count();$rows=(clone$q)->limit(3)->get();foreach($rows as$row){$safe=$center->row($row);if(isset($safe['encrypted_document']))throw new RuntimeException('Payload disclosure');}
$company=DB::table('merchants')->orderBy('id')->value('id');$companyTotal=$company?$center->query(['merchant'=>$company])->count():0;
if(app(App\Services\EisDelivery::class)->ready())throw new RuntimeException('Production transport unexpectedly enabled');
$uris=['api/admin/eis-center','api/admin/eis-center/export','api/admin/eis-center/preview','api/admin/eis-center/batches','api/admin/eis-center/batches/{id}','api/admin/eis-center/batches/{id}/release','api/admin/eis-center/documents/{source}/{id}'];$found=[];
foreach(app('router')->getRoutes()as$route)if(in_array($route->uri(),$uris,true)){if(!in_array('api',$route->gatherMiddleware(),true))throw new RuntimeException('API boundary missing');$found[$route->uri()]=true;}
if(count($found)!==count($uris))throw new RuntimeException('Missing center route');
$schedules=app(Illuminate\Console\Scheduling\Schedule::class)->events();$worker=false;foreach($schedules as$event)if(str_contains($event->command??'','eis:prepare-batches'))$worker=true;if(!$worker)throw new RuntimeException('Preparation schedule missing');
echo json_encode(['verified'=>true,'batch_tables'=>true,'canonical_documents'=>$total,'company_filter_documents'=>$companyTotal,'safe_metadata'=>true,'production_transport_blocked'=>true,'api_routes'=>count($found),'mfa_enabled'=>true,'preparation_worker_scheduled'=>true,'read_only_verification'=>true,'verified_at'=>gmdate('c')]);
