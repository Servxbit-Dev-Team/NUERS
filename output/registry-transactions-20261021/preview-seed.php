<?php
umask(0077);$db=__DIR__.'/preview.sqlite';if(file_exists($db))throw new RuntimeException('Preview exists');touch($db);$app=require __DIR__.'/preview-bootstrap.php';
use Illuminate\Support\Facades\{DB,Artisan,Hash};
Artisan::call('migrate:fresh',['--force'=>true]);
$u=App\Models\User::factory()->create(['email'=>'preview-admin@example.test','password'=>Hash::make('PreviewOnly-2026!'),'email_verified_at'=>now()]);
DB::table('profiles')->insert(['id'=>$u->id,'email'=>$u->email,'full_name'=>'NUERS Lab Administrator','role'=>'super_admin']);
foreach(['demo'=>'DEMO · Harbor Foods Corporation','other'=>'DEMO · Pacific Retail Inc.'] as$id=>$name) DB::table('merchants')->insert(['id'=>$id,'business_name'=>$name,'tin'=>$id==='demo'?'123456789':'987654321','status'=>'active','vat_registered'=>true]);
for($i=1;$i<=32;$i++) {
 $m=$i<=30?'demo':'other';$amt=$i*1120;$id='sample-'.$i;
 DB::table('business_invoices')->insert(['id'=>$id,'merchant_id'=>$m,'invoice_number'=>'DEMO-INV-2026-'.str_pad($i,4,'0',STR_PAD_LEFT),'merchant_name'=>$m==='demo'?'DEMO · Harbor Foods Corporation':'DEMO · Pacific Retail Inc.','merchant_tin'=>$m==='demo'?'123456789':'987654321','merchant_address'=>'Synthetic demonstration address','seller_branch_code'=>'00000','seller_rdo_code'=>'027','document_type'=>'vat_invoice','status'=>'sent','issue_date'=>now()->subDays($i-1)->toDateString(),'created_at'=>now(),'total_amount'=>$amt,'vat_amount'=>$i*120,'taxable_amount'=>$i*1000,'buyer_name'=>'Synthetic Buyer','vat_registered'=>true]);
 if($i!==6)DB::table('business_invoice_items')->insert(['id'=>'line-'.$id,'invoice_id'=>$id,'merchant_id'=>$m,'description'=>'Synthetic sample product','quantity'=>$i,'unit_price'=>1120,'line_total'=>$amt,'vat_amount'=>$i*120,'taxable_amount'=>$i*1000,'tax_type'=>'vatable']);

}
$p=app(App\Services\LedgerProjection::class);$p->enqueueAll();while($p->refresh()){}$p->activate();
echo 'Synthetic EIS workspace ready'.PHP_EOL;
