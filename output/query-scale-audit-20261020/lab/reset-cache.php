<?php
require __DIR__.'/bootstrap.php';
if(config('cache.stores.file.path')!==__DIR__.'/cache')throw new RuntimeException('Lab cache guard');
Illuminate\Support\Facades\Cache::store('file')->flush();
