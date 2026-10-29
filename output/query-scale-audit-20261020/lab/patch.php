<?php
require __DIR__.'/bootstrap.php';
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
$indexes=[
'ledger_merchant_document_order'=>['merchant_id','tenant_identity_rank','business_date','recorded_at','id'],
'ledger_all_document_order'=>['created_at','document_source','document_id'],
'ledger_customer_options'=>['merchant_id','tenant_identity_rank','customer_name','document_source'],
'ledger_record_branch_options'=>['merchant_id','tenant_identity_rank','branch_code','branch','document_source'],
'ledger_channel_options'=>['merchant_id','tenant_identity_rank','channel','document_source'],
'ledger_payment_options'=>['merchant_id','tenant_identity_rank','payment_method','document_source']];
foreach($indexes as $name=>$cols)if(!Schema::hasIndex('ledger_documents',$name))Schema::table('ledger_documents',fn(Blueprint $t)=>$t->index($cols,$name));
foreach ([['merchant_transactions',['created_at','id'],'mt_recent_order'],['merchant_transactions',['status','amount'],'mt_status_amount'],['merchant_transactions',['region'],'mt_region_options'],['merchant_transactions',['branch'],'mt_branch_options'],['merchant_transactions',['payment_method'],'mt_payment_options'],['transaction_receipts',['status'],'tr_status_count'],['merchant_transactions',['merchant_id','transaction_type'],'mt_merchant_type_options'],['merchant_transactions',['merchant_id','channel'],'mt_merchant_channel_options'],['merchant_transactions',['merchant_id','branch'],'mt_merchant_branch_options'],['merchant_transactions',['merchant_id','payment_method'],'mt_merchant_payment_options']] as [$table,$cols,$name]) if(!Schema::hasIndex($table,$name)) Schema::table($table,fn(Blueprint $t)=>$t->index($cols,$name));
app(App\Services\LedgerProjection::class)->dropTriggers();app(App\Services\LedgerProjection::class)->installTriggers();
echo "Lab indexes and triggers updated.\n";
