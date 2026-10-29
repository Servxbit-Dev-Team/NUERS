<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('ledger_documents', 'operations_sales_valid')) {
            Schema::table('ledger_documents', fn (Blueprint $t) => $t->boolean('operations_sales_valid')->nullable());
        }
        foreach ([
            'ledger_sales_recent' => ['identity_rank', 'status', 'operations_sales_valid', 'identity_count', 'created_at', 'id'],
            'ledger_sales_amount' => ['identity_rank', 'status', 'operations_sales_valid', 'identity_count', 'amount', 'id'],
            'ledger_sales_summary' => ['identity_rank','status','operations_sales_valid','identity_count','document_family','created_at','amount','vat_amount','vatable_sales','business','document_type','merchant_id','business_date'],
            'ledger_sales_evidence' => ['operations_sales_valid', 'projection_id'],
        ] as $name => $columns) {
            if (!Schema::hasIndex('ledger_documents', $name)) Schema::table('ledger_documents', fn (Blueprint $t) => $t->index($columns, $name));
        }
        app(\App\Services\LedgerProjection::class)->installTriggers();
        // Resumable derivative backfill; originals and balances are never changed.
        DB::table('ledger_dirty_documents')->insertOrIgnoreUsing(['source', 'document_id'],
            DB::table('ledger_documents')->whereNull('operations_sales_valid')->selectRaw('document_source,COALESCE(document_id,transaction_id)'));
    }

    public function down(): void
    {
        Schema::table('ledger_documents', function (Blueprint $t) {
            foreach (['ledger_sales_recent', 'ledger_sales_amount', 'ledger_sales_evidence', 'ledger_sales_summary'] as $name) $t->dropIndex($name);
            $t->dropColumn('operations_sales_valid');
        });
    }
};
