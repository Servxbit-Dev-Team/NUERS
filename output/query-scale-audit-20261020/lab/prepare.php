<?php
require dirname(__DIR__,3).'/vendor/autoload.php';
$app=require dirname(__DIR__,3).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$c=config('database.connections.mysql');
if (!in_array($c['host'], ['127.0.0.1','localhost'])) throw new RuntimeException('Local host required');
try { DB::statement('CREATE DATABASE IF NOT EXISTS nuers_scale_lab_20261020 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); echo 'created isolated schema'; } catch (Throwable $e) { echo 'isolated schema creation denied: '.$e->getCode(); }
