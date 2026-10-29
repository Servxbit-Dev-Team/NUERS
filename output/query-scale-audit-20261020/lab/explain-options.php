<?php
require __DIR__.'/bootstrap.php';
use Illuminate\Support\Facades\DB;
$sql=file_get_contents(__DIR__.'/options-sql.txt');
echo json_encode(DB::select('EXPLAIN FORMAT=JSON '.$sql,['lab-merchant-a',1,'invoice','receipt','']),JSON_PRETTY_PRINT)."\n";
