<?php
require __DIR__.'/bootstrap.php';
use Illuminate\Support\Facades\DB;
DB::beginTransaction();
try {
 foreach(['original','credit'] as $id)DB::table('business_invoices')->insert(['id'=>'lab-trigger-'.$id,'merchant_id'=>'lab-merchant-a','merchant_name'=>'Synthetic trigger business','invoice_number'=>'TRIGGER-'.$id,'buyer_name'=>'Synthetic buyer','issue_date'=>'2026-10-21','status'=>'sent','original_invoice_id'=>$id==='credit'?'lab-trigger-original':null]);
 $before=DB::table('ledger_dirty_documents')->where('source','invoice')->where('document_id','lab-trigger-credit')->value('generation');
 DB::table('business_invoices')->where('id','lab-trigger-original')->update(['status'=>'cancelled']);
 $after=DB::table('ledger_dirty_documents')->where('source','invoice')->where('document_id','lab-trigger-credit')->value('generation');
 if($after<=$before)throw new RuntimeException('Original invoice did not invalidate adjustment');
 echo json_encode(['mysql_original_trigger_verified'=>true,'rolled_back'=>true])."\n";
} finally {DB::rollBack();}
