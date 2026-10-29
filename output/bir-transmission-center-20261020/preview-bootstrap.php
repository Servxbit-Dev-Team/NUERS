<?php
$root=dirname(__DIR__,2);require $root.'/vendor/autoload.php';$app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default'=>'sqlite','database.connections.sqlite.database'=>__DIR__.'/preview.sqlite','database.connections.sqlite.url'=>null,'app.url'=>'http://127.0.0.1:8792','cache.default'=>'database','cache.stores.database.connection'=>'sqlite','session.driver'=>'file','session.cookie'=>'nuers_eis_center_qa','session.secure'=>false,'mail.default'=>'array','notifications.dispatch_enabled'=>false,'security.enforce_privileged_mfa'=>false]);
Illuminate\Support\Facades\DB::purge('sqlite');
return $app;
