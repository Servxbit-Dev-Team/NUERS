<?php
require dirname(__DIR__,3).'/vendor/autoload.php';
$app=require dirname(__DIR__,3).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app->detectEnvironment(fn () => 'testing');
config(['database.default'=>'mysql', 'database.connections.mysql.host'=>'localhost','database.connections.mysql.port'=>0,'database.connections.mysql.unix_socket'=>'/private/tmp/nuers-sales-lab-20261021.sock','database.connections.mysql.database'=>'nuers_sales_lab_20261021','database.connections.mysql.username'=>'root','database.connections.mysql.password'=>'','cache.default'=>'file','cache.stores.file.path'=>__DIR__.'/cache','cache.stores.file.lock_path'=>__DIR__.'/cache','app.timezone'=>'UTC','security.enforce_privileged_mfa'=>false,'notifications.dispatch_enabled'=>false]);
Illuminate\Support\Facades\DB::purge('mysql');
if (config('database.connections.mysql.unix_socket') !== '/private/tmp/nuers-sales-lab-20261021.sock' || config('database.connections.mysql.database') !== 'nuers_sales_lab_20261021') throw new RuntimeException('Lab guard');
