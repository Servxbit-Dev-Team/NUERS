<?php
require __DIR__.'/bootstrap.php';
$rows=json_decode(file_get_contents(__DIR__.'/queries-all.json'),true);
foreach(array_slice($rows,0,2) as $r){echo json_encode(Illuminate\Support\Facades\DB::select('EXPLAIN '.$r['sql'],[1,'success']),JSON_PRETTY_PRINT)."\n";}
