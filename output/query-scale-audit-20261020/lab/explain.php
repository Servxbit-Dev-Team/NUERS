<?php
require __DIR__.'/bootstrap.php';
use Illuminate\Support\Facades\DB;
$m=DB::table('merchants')->where('id','lab-merchant-a')->first();
$q=app(App\Services\IndexedSalesReport::class)->query($m,['from'=>'2026-09-21','to'=>'2026-10-20'])->where('included',1)->orderByDesc('business_day')->orderByDesc('projection_id')->limit(10);
echo json_encode(DB::select('EXPLAIN '.$q->toSql(),$q->getBindings()),JSON_PRETTY_PRINT)."\n";
