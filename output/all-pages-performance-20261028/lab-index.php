<?php
require dirname(__DIR__).'/sales-summary-performance-20261021/lab/bootstrap.php';
use Illuminate\Support\Facades\Schema;use Illuminate\Database\Schema\Blueprint;
Schema::table('ledger_documents',fn(Blueprint $t)=>$t->dropIndex('ledger_reconciliation_summary'));
Schema::table('ledger_documents',fn(Blueprint $t)=>$t->index(['identity_rank','record_status','reconciliation_prefix','reconciliation_suffix','identity_count','document_family','status','amount','vat_amount','vatable_sales','id','created_at','business'],'ledger_reconciliation_summary'));
echo 'Synthetic covering index updated';
