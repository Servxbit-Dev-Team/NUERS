<?php
require __DIR__.'/bootstrap.php';
$c = config('database.connections.mysql');
$pdo = new PDO('mysql:unix_socket='.$c['unix_socket'], 'root', '');
$pdo->exec('CREATE DATABASE IF NOT EXISTS nuers_scale_lab_20261020 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
Illuminate\Support\Facades\Artisan::call('migrate', ['--force'=>true]);
echo Illuminate\Support\Facades\Artisan::output();
