<?php
require __DIR__.'/bootstrap.php';
use Illuminate\Support\Facades\DB;
$ids=DB::table('transaction_receipts')->where('merchant_id','lab-merchant-a')->orderBy('id')->limit(1000)->pluck('id');
if($ids->count()!==1000)throw new RuntimeException('Synthetic fixture mismatch');
$hash=fn()=>hash('sha256',json_encode(DB::table('transaction_receipts')->whereIn('id',$ids)->orderBy('id')->get()));
$before=$hash();
foreach($ids->chunk(100) as $chunk)DB::table('ledger_dirty_documents')->insertOrIgnore($chunk->map(fn($id)=>['source'=>'receipt','document_id'=>$id,'generation'=>1])->all());
$start=microtime(true);memory_reset_peak_usage();$processed=app(App\Services\LedgerProjection::class)->refresh(1000);
$out=['processed'=>$processed,'seconds'=>round(microtime(true)-$start,3),'peak_mb'=>round(memory_get_peak_usage(true)/1048576,2),'remaining'=>DB::table('ledger_dirty_documents')->count(),'source_unchanged'=>hash_equals($before,$hash())];
file_put_contents(__DIR__.'/refresh-batch-result.json',json_encode($out,JSON_PRETTY_PRINT));
echo json_encode($out).PHP_EOL;
if($processed!==1000||$out['remaining']||!$out['source_unchanged'])exit(1);
