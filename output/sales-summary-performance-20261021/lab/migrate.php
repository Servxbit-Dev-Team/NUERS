<?php
require __DIR__.'/bootstrap.php';
Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true]);echo Illuminate\Support\Facades\Artisan::output();