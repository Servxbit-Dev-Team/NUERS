<?php
require __DIR__.'/bootstrap.php';
use Illuminate\Support\Facades\DB;
use App\Services\LedgerProjection;
use App\Services\IssuedDocumentLedger;
$p=app(LedgerProjection::class);$l=app(IssuedDocumentLedger::class);
$template=(array)DB::table('transaction_receipts')->where('id','lab-r-0000000')->first();
$before=DB::table('ledger_documents')->count();
DB::beginTransaction();
try{
 foreach(['collation-a'=>'ALPHA-É','collation-b'=>'alpha-e'] as $id=>$number){$r=$template;$r['id']=$id;$r['merchant_id']='lab-consistency';$r['receipt_number']=$number;DB::table('transaction_receipts')->insert($r);}
 $p->catchUp();
 $legacy=$l->legacyQuery('lab-consistency')->orderBy('id')->get()->map(fn($r)=>[$r->id,(int)$r->identity_count])->all();
 $indexed=$l->query('lab-consistency')->orderBy('id')->get()->map(fn($r)=>[$r->id,(int)$r->identity_count])->all();
 if($legacy!==$indexed||count($indexed)!==1||$indexed[0][1]!==2)throw new RuntimeException('Collation mismatch');
 DB::table('transaction_receipts')->where('id','collation-a')->delete();$p->catchUp();
 if($l->query('lab-consistency')->first()->identity_count!==1)throw new RuntimeException('Delete mismatch');
 DB::table('transaction_receipts')->where('id','collation-b')->update(['total_due'=>113]);$p->catchUp();
 if($l->query('lab-consistency')->first()->financial_valid)throw new RuntimeException('Invalid total admitted');
 DB::rollBack();
 if(DB::table('ledger_documents')->count()!==$before||DB::table('ledger_dirty_documents')->exists())throw new RuntimeException('Rollback mismatch');
 $result=['collation_parity'=>true,'raw_write_invalidation'=>true,'delete_repair'=>true,'rollback_preserved'=>true,'source_records_unchanged'=>true];
 file_put_contents(__DIR__.'/consistency-result.json',json_encode($result,JSON_PRETTY_PRINT));echo json_encode($result)."\n";
}catch(Throwable $e){DB::rollBack();throw $e;}
