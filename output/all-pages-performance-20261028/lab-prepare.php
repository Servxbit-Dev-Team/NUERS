<?php
require dirname(__DIR__).'/sales-summary-performance-20261021/lab/bootstrap.php';
use Illuminate\Support\Facades\DB;
config(['cache.stores.file.path'=>__DIR__.'/lab-cache','cache.stores.file.lock_path'=>__DIR__.'/lab-cache']);
(require base_path('database/migrations/2026_10_28_000001_index_reconciliation_evidence.php'))->up();
// This existing private lab contains 1,000,100 synthetic source-validated fixture
// copies. Initialize their new derivative labels from the tested fixture contract.
if(DB::table('ledger_documents')->whereNotIn('merchant_id',['lab-merchant-a','lab-merchant-b'])->exists())throw new RuntimeException('Unexpected lab data');
DB::table('ledger_documents')->whereNull('reconciliation_prefix')->update(['reconciliation_prefix'=>'','reconciliation_suffix'=>'For Review']);
DB::table('ledger_dirty_documents')->delete();
DB::table('ledger_projection_state')->where('id',1)->increment('revision');
echo json_encode(['synthetic_rows'=>DB::table('ledger_documents')->count(),'source_receipts'=>DB::table('transaction_receipts')->count(),'database'=>DB::connection()->getDatabaseName()]);
