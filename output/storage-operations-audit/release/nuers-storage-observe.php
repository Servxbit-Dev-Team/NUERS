<?php
if(PHP_SAPI!=='cli'||basename(__DIR__)!=='nuers.net')exit(1);
umask(0077);$root=__DIR__.'/httpdocs';require $root.'/vendor/autoload.php';$app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$report=app(App\Services\OperationsHealth::class)->report();
$deployment=json_decode(file_get_contents(__DIR__.'/nuers-storage-operations-result.json'),true,512,JSON_THROW_ON_ERROR);
$at=$report['runtime']['observed_at'];
$result=['checked_at'=>gmdate('c'),'read_only'=>true,'scheduled_observation_after_deployment'=>$at && strtotime($at)>strtotime($deployment['deployed_at']),'runtime_observed_at'=>$at,'runtime_fresh'=>$report['runtime']['fresh'],'core_storage_checks_passed'=>$report['runtime']['data']['acceptance_ready']??false,'integrity'=>$report['integrity'],'workers'=>$report['runtime']['data']['workers']??[],'queues'=>$report['runtime']['data']['queues']??[],'archive_tool_enabled'=>(bool)config('operations.backup.enabled'),'offsite_storage'=>$report['offsite_storage'],'bir_transmission'=>$report['bir_transmission'],'private_archive_extensions_available'=>extension_loaded('sodium')&&extension_loaded('zip')];
file_put_contents(__DIR__.'/nuers-storage-observation-result.json',json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));echo json_encode($result,JSON_THROW_ON_ERROR).PHP_EOL;
if(!$result['scheduled_observation_after_deployment']||!$result['runtime_fresh'])exit(1);
