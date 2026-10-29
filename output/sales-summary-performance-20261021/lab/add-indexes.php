<?php
require __DIR__.'/bootstrap.php';
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
$common=['identity_rank','status','operations_sales_valid','identity_count','document_family','document_source','business_date','record_status','original_invoice_id','merchant_id','amount','vat_amount','vatable_sales','created_at'];
foreach(['ledger_sales_totals'=>[], 'ledger_sales_business'=>['business'], 'ledger_sales_type'=>['document_type']] as $name=>$extra){
$t=microtime(true);if(!Schema::hasIndex('ledger_documents',$name))Schema::table('ledger_documents',fn(Blueprint $b)=>$b->index([...$common,...$extra],$name));echo "$name ".round(microtime(true)-$t,2)."s\n";
}
