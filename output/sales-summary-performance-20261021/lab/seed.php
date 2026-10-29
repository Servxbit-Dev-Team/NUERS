<?php
require __DIR__.'/bootstrap.php';
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\User;
use App\Services\LedgerProjection;
$start=microtime(true);
if (DB::table('transaction_receipts')->count()>0) throw new RuntimeException('Seed only an empty lab');
$owner=User::factory()->create(['name'=>'Synthetic merchant A','api_token'=>Str::random(64)]);
$other=User::factory()->create(['name'=>'Synthetic merchant B','api_token'=>Str::random(64)]);
$admin=User::factory()->create(['name'=>'Synthetic admin','api_token'=>Str::random(64)]);
foreach ([$owner,$other,$admin] as $u) DB::table('profiles')->insert(['id'=>$u->id,'email'=>$u->email,'role'=>$u->id===$admin->id?'super_admin':'merchant']);
foreach ([[$owner,'lab-merchant-a','123456789'],[$other,'lab-merchant-b','987654321']] as [$u,$id,$tin]) DB::table('merchants')->insert(['id'=>$id,'merchant_account_id'=>$u->id,'business_name'=>'Synthetic '.$id,'tin'=>$tin,'status'=>'active']);
file_put_contents(__DIR__.'/synthetic-actors.json',json_encode(['owner'=>$owner->api_token,'other'=>$other->api_token,'admin'=>$admin->api_token]));chmod(__DIR__.'/synthetic-actors.json',0600);
DB::table('transaction_receipts')->insert(['id'=>'lab-r-0000000','merchant_id'=>'lab-merchant-a','receipt_number'=>'LAB-0000000','document_type'=>'vat_invoice','merchant_name'=>'Synthetic lab-merchant-a','merchant_tin'=>'123456789','seller_branch_code'=>'00000','issued_at'=>'2026-10-20 01:00:00','created_at'=>'2026-10-20 01:00:00','total_due'=>112,'vatable_sales'=>100,'vat_amount'=>12,'items'=>json_encode([['description'=>'Synthetic item','quantity'=>1,'unit_price'=>112,'line_total'=>112,'tax_type'=>'vatable']])]);
DB::table('merchant_transactions')->insert(['id'=>'lab-t-0000000','merchant_id'=>'lab-merchant-a','receipt_id'=>'lab-r-0000000','transaction_ref'=>'LAB-T-0000000','status'=>'completed','amount'=>112,'vat_amount'=>12,'vatable_sales'=>100,'net_amount'=>100,'region'=>'National Capital Region','created_at'=>'2026-10-20 01:00:00']);
while(app(LedgerProjection::class)->refresh()){}
$template=(array)DB::table('ledger_documents')->first();
if (!$template['financial_valid']) throw new RuntimeException('Template must pass the real financial validator');
DB::statement('CREATE TABLE lab_digits (n INT PRIMARY KEY)');DB::table('lab_digits')->insert(array_map(fn($n)=>['n'=>$n],range(0,9)));
DB::statement('CREATE TABLE lab_numbers (n INT PRIMARY KEY)');
DB::statement('INSERT INTO lab_numbers SELECT a.n+b.n*10+c.n*100+d.n*1000+e.n*10000+f.n*100000 FROM lab_digits a CROSS JOIN lab_digits b CROSS JOIN lab_digits c CROSS JOIN lab_digits d CROSS JOIN lab_digits e CROSS JOIN lab_digits f');
$receiptFields=['id','merchant_id','receipt_number','document_type','merchant_name','merchant_tin','seller_branch_code','issued_at','created_at','total_due','vatable_sales','vat_amount','items'];
$date="DATE_ADD('2026-09-21 01:00:00', INTERVAL MOD(n,30) DAY)";
// Benchmarks measure query execution over full source + read-model populations; SQL fixtures duplicate a source-validated template.
// Backfill correctness and write-trigger invalidation are tested separately. This is not an ingestion throughput benchmark.
app(LedgerProjection::class)->dropTriggers();
for($lo=1;$lo<1000000;$lo+=10000){
 $hi=min(999999,$lo+9999);
 DB::statement("INSERT INTO transaction_receipts (".implode(',',$receiptFields).") SELECT CONCAT('lab-r-',LPAD(n,7,'0')),'lab-merchant-a',CONCAT('LAB-',LPAD(n,7,'0')),'vat_invoice','Synthetic lab-merchant-a','123456789','00000',$date,$date,112,100,12,? FROM lab_numbers WHERE n BETWEEN ? AND ?",[DB::table('transaction_receipts')->where('id','lab-r-0000000')->value('items'),$lo,$hi]);
 DB::statement("INSERT INTO merchant_transactions (id,merchant_id,receipt_id,transaction_ref,status,amount,vat_amount,vatable_sales,net_amount,region,created_at) SELECT CONCAT('lab-t-',LPAD(n,7,'0')),'lab-merchant-a',CONCAT('lab-r-',LPAD(n,7,'0')),CONCAT('LAB-T-',LPAD(n,7,'0')),'completed',112,12,100,100,'National Capital Region',$date FROM lab_numbers WHERE n BETWEEN ? AND ?",[$lo,$hi]);
 $expressions=[];$bindings=[];
 foreach($template as $key=>$v){
  $expr=match($key){
   'projection_id'=>"CONCAT('receipt:lab-r-',LPAD(n,7,'0'))",
   'id','transaction_id'=>"CONCAT('lab-t-',LPAD(n,7,'0'))",
   'document_id','receipt_id'=>"CONCAT('lab-r-',LPAD(n,7,'0'))",
   'transaction_ref'=>"CONCAT('LAB-T-',LPAD(n,7,'0'))",
   'receipt_number','document_number_key'=>"CONCAT('LAB-',LPAD(n,7,'0'))",
   'business_date','business_day','created_at','recorded_at'=>$date,
   'identity_key'=>"SHA2(CONCAT('fixture-global-',n),256)",
   'tenant_identity_key'=>"SHA2(CONCAT('fixture-tenant-',n),256)",
   'rank_key'=>"CONCAT('1:lab-r-',LPAD(n,7,'0'),':lab-t-',LPAD(n,7,'0'))",
   default=>'?',
  };$expressions[]=$expr;if($expr==='?')$bindings[]=$v;
 }
 DB::statement('INSERT INTO ledger_documents (`'.implode('`,`',array_keys($template)).'`) SELECT '.implode(',',$expressions).' FROM lab_numbers WHERE n BETWEEN ? AND ?',[...$bindings,$lo,$hi]);
 if($lo%100000===1) echo json_encode(['seeded_to'=>$hi,'seconds'=>round(microtime(true)-$start,2)])."\n";
}
app(LedgerProjection::class)->installTriggers();
for($i=0;$i<100;$i++)DB::table('transaction_receipts')->insert(['id'=>'foreign-'.$i,'merchant_id'=>'lab-merchant-b','receipt_number'=>'FOREIGN-'.$i,'document_type'=>'vat_invoice','merchant_name'=>'Synthetic foreign business','merchant_tin'=>'987654321','seller_branch_code'=>'00000','issued_at'=>'2026-10-20 01:00:00','created_at'=>'2026-10-20 01:00:00','total_due'=>112,'vatable_sales'=>100,'vat_amount'=>12,'items'=>json_encode([['description'=>'Foreign synthetic item','quantity'=>1,'unit_price'=>112,'line_total'=>112,'tax_type'=>'vatable']])]);
while(app(LedgerProjection::class)->refresh()){}
app(LedgerProjection::class)->activate();
foreach(['ledger_documents','merchant_transactions','transaction_receipts'] as $table) DB::statement('ANALYZE TABLE '.$table);
echo json_encode(['complete'=>true,'rows'=>DB::table('ledger_documents')->count(),'seconds'=>round(microtime(true)-$start,2),'peak_mb'=>memory_get_peak_usage(true)/1048576])."\n";
