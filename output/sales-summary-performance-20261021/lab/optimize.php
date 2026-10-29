<?php
require __DIR__.'/bootstrap.php';
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
foreach(['ledger_sales_summary'] as $i)if(Schema::hasIndex('ledger_documents',$i))Schema::table('ledger_documents',fn(Blueprint $b)=>$b->dropIndex($i));
$m=require base_path('database/migrations/2026_10_21_000001_index_operations_sales_evidence.php');$m->up();
DB::statement('ANALYZE TABLE ledger_documents');
Illuminate\Support\Facades\Cache::flush();
echo "Compact reporting indexes ready; cache cleared.\n";
